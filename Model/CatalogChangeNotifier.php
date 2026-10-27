<?php
declare(strict_types=1);

namespace Flipick\VideoGenerator\Model;

use Magento\Framework\Lock\LockManagerInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tells the Flipick adapter that catalog data changed (the adapter keeps a cached copy of each website's catalog and has no webhook
 * from Magento to learn it). Run once a minute by Cron\NotifyCatalogChanges; never called while a merchant saves a product.
 *
 *  - Changes are collected by Model\CatalogChangeQueue; this class sends them as ONE signed call (POST /api/v1/catalog-changed, the same
 *    HMAC signing as every other call of AdapterClient), however many products changed.
 *  - A change is held back for a few seconds (DEBOUNCE_SECONDS) so that a burst of saves is one call; nothing waits longer than
 *    MAX_WAIT_SECONDS even if changes keep coming.
 *  - If the adapter cannot be reached or refuses the call, the changes stay queued and the next try is later and later (30 s, 1 min, 2 min
 *    ... at most 15 min): an adapter that is down is not hammered, and nothing is lost.
 *  - Only whole numbers (website ids) and a fixed word are sent: no product, customer or order data.
 *  - Never throws: a scheduled job that fails would only fill Magento's cron log.
 */
class CatalogChangeNotifier
{
    public const PATH = '/api/v1/catalog-changed';
    public const DEBOUNCE_SECONDS = 15;
    public const MAX_WAIT_SECONDS = 300;
    public const MAX_BACKOFF_SECONDS = 900;
    /** The adapter accepts at most this many website ids in one call; more than that is sent as "all websites". */
    public const MAX_WEBSITE_IDS = 100;
    private const LOCK = 'flipick_vg_catalog_signal';

    /**
     * @var CatalogChangeQueue
     */
    private $queue;

    /**
     * @var AdapterClient
     */
    private $client;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var LockManagerInterface
     */
    private $locks;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        CatalogChangeQueue $queue,
        AdapterClient $client,
        StoreManagerInterface $storeManager,
        LockManagerInterface $locks,
        LoggerInterface $logger
    ) {
        $this->queue = $queue;
        $this->client = $client;
        $this->storeManager = $storeManager;
        $this->locks = $locks;
        $this->logger = $logger;
    }

    /**
     * @param int|null $now injectable for tests
     * @return string what happened: not_connected | nothing | waiting | backoff | busy | sent | failed
     */
    public function flush(?int $now = null): string
    {
        $now = $now ?? time();
        $locked = false;
        try {
            // Two runs at once (a slow call overlapping the next minute) would send the same changes twice.
            if (!$this->locks->lock(self::LOCK, 0)) {
                return 'busy';
            }
            $locked = true;
            $websiteIds = $this->websiteIds();
            if (!$this->client->isConnected()) {
                $this->queue->clearAll($websiteIds); // nobody to tell, and the queue must not grow for ever
                return 'not_connected';
            }
            $pending = $this->queue->pending($websiteIds);
            if ($this->queue->isEmpty($pending)) {
                return 'nothing';
            }
            $backoff = $this->queue->backoff();
            if ($backoff['next'] > $now) {
                return 'backoff';
            }
            $times = array_merge($pending['all'] !== null ? [$pending['all']] : [], array_values($pending['websites']));
            if ($now - max($times) < self::DEBOUNCE_SECONDS && $now - (int)$pending['oldest'] < self::MAX_WAIT_SECONDS) {
                return 'waiting';
            }
            $body = ['reason' => 'extension'];
            $named = array_keys($pending['websites']);
            if ($pending['all'] === null && $named && count($named) <= self::MAX_WEBSITE_IDS) {
                $body['websiteIds'] = array_values($named);
            }
            $this->client->post(self::PATH, $body);
            $this->queue->clearSent($pending);
            $this->queue->resetBackoff();
            $this->logger->info('Flipick: adapter told that the catalog changed', ['websites' => $body['websiteIds'] ?? 'all']);
            return 'sent';
        } catch (\Throwable $e) {
            return $this->failed($e, $now);
        } finally {
            if ($locked) {
                try {
                    $this->locks->unlock(self::LOCK);
                } catch (\Throwable $ignored) {
                    // the lock is released with the database session anyway
                }
            }
        }
    }

    private function failed(\Throwable $e, int $now): string
    {
        try {
            $fails = $this->queue->backoff()['fails'] + 1;
            $wait = min(self::MAX_BACKOFF_SECONDS, 30 * (2 ** min($fails - 1, 10)));
            $this->queue->saveBackoff($fails, $now + $wait);
            $this->logger->warning('Flipick: could not tell the adapter that the catalog changed; the changes stay queued', [
                'attempt' => $fails, 'retry_in_seconds' => $wait, 'error' => $e->getMessage(),
            ]);
        } catch (\Throwable $inner) {
            $this->logger->error('Flipick: could not record the failed catalog notification', ['error' => $inner->getMessage()]);
        }
        return 'failed';
    }

    /** @return int[] */
    private function websiteIds(): array
    {
        $ids = [];
        foreach ($this->storeManager->getWebsites() as $website) {
            $ids[] = (int)$website->getId();
        }
        return $ids;
    }
}
