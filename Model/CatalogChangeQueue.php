<?php
declare(strict_types=1);

namespace Flipick\VideoGenerator\Model;

use Magento\Framework\FlagManager;

/**
 * Remembers which websites' catalog data changed since the adapter was last told, so that one scheduled call
 * (Cron\NotifyCatalogChanges) can tell it, instead of a call for every product that is saved.
 *
 * One flag row per website ("flipick_vg_dirty_<websiteId>") and one for "everything" ("flipick_vg_dirty_all"), each holding the time
 * of the latest change ("at") and of the first change since the adapter was last told ("first"). Each website has its own row, so two requests
 * changing different websites never touch the same data (a shared list that is read, changed and written back would lose marks). Two requests
 * creating the very same row at the very same moment can at worst leave a duplicate row, which costs one extra call to the adapter, and a mark
 * written between the adapter being told and the row being deleted can be lost, which costs a stale catalog until the next change or a manual Refresh. Nothing here talks to the network and
 * nothing throws into the caller: a failure to remember a change is logged by the observer and the store carries on.
 */
class CatalogChangeQueue
{
    public const FLAG_PREFIX = 'flipick_vg_dirty_';
    public const FLAG_ALL = 'flipick_vg_dirty_all';
    public const FLAG_BACKOFF = 'flipick_vg_signal_backoff';

    /**
     * @var FlagManager
     */
    private $flags;

    /**
     * When this PHP process last wrote each mark. An import saves thousands of products in one request, so a mark written in the last few
     * seconds is not written again; after that it is, because a long-lived process (a queue consumer) must still be able to mark a website
     * again after the scheduled call has cleared it.
     *
     * @var array<string, int>
     */
    private $written = [];

    public function __construct(FlagManager $flags)
    {
        $this->flags = $flags;
    }

    /**
     * @param array<int|string, mixed> $websiteIds anything that is not a positive whole number is ignored (0 is the admin "website")
     */
    public function markWebsites(array $websiteIds): void
    {
        foreach ($websiteIds as $id) {
            $id = $this->cleanId($id);
            if ($id !== null) {
                $this->write(self::FLAG_PREFIX . $id);
            }
        }
    }

    public function markAll(): void
    {
        $this->write(self::FLAG_ALL);
    }

    /**
     * What is waiting: the time of the latest change for every website named, and for "everything".
     *
     * @param int[] $knownWebsiteIds the ids of the websites that exist (the flag rows are looked up by id; a row of a deleted website is never read)
     * @return array{all: int|null, websites: array<int, int>, oldest: int|null} "oldest" is the first change of anything still waiting
     */
    public function pending(array $knownWebsiteIds): array
    {
        $all = $this->readTime(self::FLAG_ALL);
        $oldest = $all === null ? null : $this->readTime(self::FLAG_ALL, 'first');
        $websites = [];
        foreach ($knownWebsiteIds as $id) {
            $id = $this->cleanId($id);
            $at = $id === null ? null : $this->readTime(self::FLAG_PREFIX . $id);
            if ($at !== null) {
                $websites[$id] = $at;
                $first = $this->readTime(self::FLAG_PREFIX . $id, 'first');
                $oldest = $oldest === null ? $first : min($oldest, $first);
            }
        }
        return ['all' => $all, 'websites' => $websites, 'oldest' => $oldest];
    }

    public function isEmpty(array $pending): bool
    {
        return $pending['all'] === null && !$pending['websites'];
    }

    /**
     * Forgets what was just sent, but only where nothing newer was marked in the meantime (that change has not been sent yet).
     *
     * @param array{all: int|null, websites: array<int, int>} $sent the snapshot returned by pending()
     */
    public function clearSent(array $sent): void
    {
        if ($sent['all'] !== null && $this->readTime(self::FLAG_ALL) === $sent['all']) {
            $this->flags->deleteFlag(self::FLAG_ALL);
        }
        foreach ($sent['websites'] as $id => $at) {
            if ($this->readTime(self::FLAG_PREFIX . $id) === $at) {
                $this->flags->deleteFlag(self::FLAG_PREFIX . $id);
            }
        }
    }

    /** Throws the whole queue away (the store is not connected to Flipick: nobody is waiting for this). */
    public function clearAll(array $knownWebsiteIds): void
    {
        $this->flags->deleteFlag(self::FLAG_ALL);
        foreach ($knownWebsiteIds as $id) {
            $id = $this->cleanId($id);
            if ($id !== null) {
                $this->flags->deleteFlag(self::FLAG_PREFIX . $id);
            }
        }
        $this->flags->deleteFlag(self::FLAG_BACKOFF);
    }

    /** @return array{fails: int, next: int} */
    public function backoff(): array
    {
        $data = $this->flags->getFlagData(self::FLAG_BACKOFF);
        return [
            'fails' => is_array($data) ? max(0, (int)($data['fails'] ?? 0)) : 0,
            'next' => is_array($data) ? max(0, (int)($data['next'] ?? 0)) : 0,
        ];
    }

    public function saveBackoff(int $fails, int $next): void
    {
        $this->flags->saveFlag(self::FLAG_BACKOFF, ['fails' => $fails, 'next' => $next]);
    }

    public function resetBackoff(): void
    {
        $this->flags->deleteFlag(self::FLAG_BACKOFF);
    }

    private function write(string $code): void
    {
        $now = time();
        if (isset($this->written[$code]) && $now - $this->written[$code] < 5) {
            return;
        }
        $data = $this->flags->getFlagData($code);
        $at = is_array($data) && isset($data['at']) && is_numeric($data['at']) ? (int)$data['at'] : null;
        if ($at !== null && $now - $at >= 0 && $now - $at < 5) {
            $this->written[$code] = $now; // marked a moment ago by another request: one cheap read, no write
            return;
        }
        // the first change since the adapter was last told is kept, so that a store that is never quiet is still told after the maximum wait
        $first = is_array($data) && isset($data['first']) && is_numeric($data['first']) ? (int)$data['first'] : ($at ?? $now);
        $this->flags->saveFlag($code, ['at' => $now, 'first' => $first]);
        $this->written[$code] = $now;
    }

    private function readTime(string $code, string $field = 'at'): ?int
    {
        $data = $this->flags->getFlagData($code);
        if (is_array($data) && isset($data[$field]) && is_numeric($data[$field])) {
            return (int)$data[$field];
        }
        // a mark written without "first" counts as first = at
        return $field === 'first' && is_array($data) && isset($data['at']) && is_numeric($data['at']) ? (int)$data['at'] : null;
    }

    /** @param mixed $id */
    private function cleanId($id): ?int
    {
        if (is_int($id) || (is_string($id) && ctype_digit($id))) {
            $int = (int)$id;
            return $int > 0 && $int <= 1000000 ? $int : null;
        }
        return null;
    }
}
