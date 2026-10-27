<?php
// Unit test of the catalog change signal (Model\CatalogChangeQueue, Model\CatalogChangeNotifier, Observer\CatalogChanged) without a database or a
// network: the flag storage, the adapter client, the lock and the store manager are replaced by small fakes. Magento's autoloader is still needed
// (the classes extend and implement Magento types).
//
// Usage: AUTOLOAD=/path/to/vendor/autoload.php MODULE=/path/to/Flipick/VideoGenerator php catalog-change-test.php
$autoload = getenv('AUTOLOAD');
$module = getenv('MODULE') ?: '/var/www/html/app/code/Flipick/VideoGenerator';
require $autoload;
spl_autoload_register(function ($class) use ($module) {
    $prefix = 'Flipick\\VideoGenerator\\';
    if (strpos($class, $prefix) === 0) {
        $file = $module . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
}, true, true); // prepend: the copy under test must win over an installed copy of the extension that Composer's autoloader may know

use Flipick\VideoGenerator\Model\AdapterClient;
use Flipick\VideoGenerator\Model\CatalogChangeNotifier;
use Flipick\VideoGenerator\Model\CatalogChangeQueue;
use Flipick\VideoGenerator\Observer\CatalogChanged;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\FlagManager;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Store\Model\StoreManagerInterface;

$failures = [];
$passed = 0;
$check = function (bool $ok, string $what) use (&$failures, &$passed) {
    if ($ok) {
        $passed++;
        echo "  ok    $what\n";
    } else {
        $failures[] = $what;
        echo "  FAIL  $what\n";
    }
};
$build = function (string $class) {
    return (new ReflectionClass($class))->newInstanceWithoutConstructor();
};

// ---- fakes
class FakeFlags extends FlagManager
{
    public $data = [];
    public $writes = 0;
    public function __construct()
    {
    }
    public function getFlagData($flagCode)
    {
        return $this->data[$flagCode] ?? null;
    }
    public function saveFlag($flagCode, $value)
    {
        $this->data[$flagCode] = $value;
        $this->writes++;
        return true;
    }
    public function deleteFlag($flagCode)
    {
        unset($this->data[$flagCode]);
        return true;
    }
}
class FakeClient extends AdapterClient
{
    public $connected = true;
    public $key = 'fk_test';
    public $calls = [];
    public $fail = null;
    public function __construct()
    {
    }
    public function isConnected(): bool
    {
        return $this->connected;
    }
    public function getInstallKey(): string
    {
        return $this->connected ? $this->key : '';
    }
    public function post(string $path, array $body = [], ?string $websiteId = null, int $timeoutSeconds = 90): array
    {
        $this->calls[] = ['path' => $path, 'body' => $body, 'website' => $websiteId, 'timeout' => $timeoutSeconds];
        if ($this->fail !== null) {
            throw new LocalizedException(__($this->fail));
        }
        return ['ok' => true];
    }
}
class FakeLock implements LockManagerInterface
{
    public $held = false;
    public $refuse = false;
    public function isLocked(string $name): bool
    {
        return $this->held;
    }
    public function lock(string $name, int $timeout = -1): bool
    {
        if ($this->refuse || $this->held) {
            return false;
        }
        $this->held = true;
        return true;
    }
    public function unlock(string $name): bool
    {
        $this->held = false;
        return true;
    }
}
class FakeWebsite
{
    private $id;
    public function __construct(int $id)
    {
        $this->id = $id;
    }
    public function getId()
    {
        return $this->id;
    }
}
class FakeStoreManager extends \Magento\Store\Model\StoreManager
{
    public function __construct()
    {
    }
    public function getWebsites($withDefault = false, $codeKey = false)
    {
        return [new FakeWebsite(1), new FakeWebsite(2), new FakeWebsite(3)];
    }
}
class FakeLogger extends Psr\Log\AbstractLogger
{
    public $lines = [];
    public function log($level, $message, array $context = []): void
    {
        $this->lines[] = [$level, (string)$message, $context];
    }
}
class FakeProduct
{
    private $websites;
    private $orig;
    public function __construct(array $websites, $orig = null)
    {
        $this->websites = $websites;
        $this->orig = $orig;
    }
    public function getWebsiteIds()
    {
        return $this->websites;
    }
    public function getOrigData($key = null)
    {
        return $this->orig;
    }
}
$fire = function (CatalogChanged $observer, string $name, array $data = []) {
    $event = new Event($data);
    $event->setName($name);
    $o = new Observer();
    $o->setEvent($event);
    $observer->execute($o);
};
$make = function () {
    $flags = new FakeFlags();
    $queue = new CatalogChangeQueue($flags);
    $client = new FakeClient();
    $logger = new FakeLogger();
    $lock = new FakeLock();
    $stores = new FakeStoreManager();
    $notifier = new CatalogChangeNotifier($queue, $client, $stores, $lock, $logger);
    $observer = new CatalogChanged($client, $queue, $logger);
    return compact('flags', 'queue', 'client', 'logger', 'lock', 'notifier', 'observer');
};

echo "queue\n";
extract($make());
$queue->markWebsites([1, '2', 0, -3, '4x', 'abc', 2000000, null, 1.5]);
$check(array_keys($flags->data) === ['flipick_vg_dirty_1', 'flipick_vg_dirty_2'], 'only positive whole website ids are remembered (0, negatives, text, floats, huge ids are ignored)');
$before = $flags->writes;
$queue->markWebsites([1, 2]);
$check($flags->writes === $before, 'the same website marked again within a few seconds is not written again');
$p = $queue->pending([1, 2, 3]);
$check(array_keys($p['websites']) === [1, 2] && $p['all'] === null, 'pending() lists the marked websites');
$check(array_keys($queue->pending([2, 3])['websites']) === [2], 'a mark of a website that no longer exists is not read');
$queue->markAll();
$check($queue->pending([1])['all'] !== null, 'markAll() is remembered');
// a newer mark that arrives while a call is in flight must survive clearSent()
$snapshot = $queue->pending([1, 2, 3]);
$flags->data['flipick_vg_dirty_1'] = ['at' => $snapshot['websites'][1] + 5];
$queue->clearSent($snapshot);
$check(isset($flags->data['flipick_vg_dirty_1']) && !isset($flags->data['flipick_vg_dirty_2']) && !isset($flags->data['flipick_vg_dirty_all']), 'clearSent() keeps a mark that is newer than what was sent');

echo "notifier\n";
extract($make());
$check($notifier->flush(1000) === 'nothing' && !$client->calls, 'nothing queued: no call');
$queue->markWebsites([2]);
$at = $flags->data['flipick_vg_dirty_2']['at'];
$check($notifier->flush($at + 3) === 'waiting' && !$client->calls, 'a fresh change waits a few seconds so that a burst is one call');
$check($notifier->flush($at + 20) === 'sent', 'after the wait the change is sent');
$check(count($client->calls) === 1 && $client->calls[0]['path'] === '/api/v1/catalog-changed', 'one call, to the catalog-changed endpoint');
$check($client->calls[0]['body'] === ['reason' => 'extension', 'websiteIds' => [2]], 'the body holds only the website ids and a fixed reason');
$check($client->calls[0]['website'] === null, 'no website header: the call is about the installation');
$check($client->calls[0]['timeout'] === 10, 'a short timeout: a hung adapter does not hold Magento\'s cron');
$check(!$flags->data || array_keys($flags->data) === [], 'the queue is empty after a successful call');
// every website (markAll) is sent without ids
extract($make());
$queue->markAll();
$queue->markWebsites([1]);
$t = $flags->data['flipick_vg_dirty_all']['at'];
$notifier->flush($t + 30);
$check($client->calls[0]['body'] === ['reason' => 'extension'], 'a change to everything is sent without website ids');
// a burst that never pauses is still sent after the maximum wait
extract($make());
$queue->markWebsites([1]);
$t = $flags->data['flipick_vg_dirty_1']['at'];
$flags->data['flipick_vg_dirty_1'] = ['at' => $t + 299, 'first' => $t]; // changed again and again: the latest change is always fresh
$check($notifier->flush($t + 299) === 'waiting', 'changes that keep coming are held back at first');
$check($notifier->flush($t + 300) === 'sent', 'but are sent after at most five minutes since the first one');
// failure: queued changes stay, and the next try is later
extract($make());
$queue->markWebsites([1]);
$t = $flags->data['flipick_vg_dirty_1']['at'] + 60;
$client->fail = 'Video adapter error (500): boom';
$check($notifier->flush($t) === 'failed', 'an adapter that fails is not an exception');
$check(isset($flags->data['flipick_vg_dirty_1']), 'the change stays queued');
$b = $queue->backoff();
$check($b['fails'] === 1 && $b['next'] === $t + 30, 'first retry in 30 seconds');
$check($notifier->flush($t + 10) === 'backoff' && count($client->calls) === 1, 'no call during the wait');
$check($notifier->flush($t + 31) === 'failed' && $queue->backoff()['next'] === $t + 31 + 60, 'the wait doubles');
for ($i = 0; $i < 12; $i++) {
    $now = $queue->backoff()['next'];
    $notifier->flush($now);
}
$last = $queue->backoff();
$check($last['fails'] > 5 && $last['next'] - $now === CatalogChangeNotifier::MAX_BACKOFF_SECONDS, 'the wait never exceeds fifteen minutes');
$client->fail = null;
$now = $queue->backoff()['next'];
$check($notifier->flush($now) === 'sent' && $queue->backoff() === ['fails' => 0, 'next' => 0], 'when the adapter is back the change is sent and the wait is reset');
$check(!$flags->data, 'nothing is left queued');
$check(count(array_filter($logger->lines, function ($l) { return $l[0] === 'warning'; })) >= 2, 'failures are logged as warnings');
// not connected: nothing is sent and the queue does not grow
extract($make());
$queue->markWebsites([1]);
$client->connected = false;
$check($notifier->flush(5000) === 'not_connected' && !$client->calls && !$flags->data, 'a store that is not connected sends nothing and drops the queue');
$flags->writes = 0;
$notifier->flush(5001);
$check($flags->writes === 0, 'and then costs no writes at all');
// the server clock jumped: a stored wait far in the future is ignored
extract($make());
$queue->markWebsites([1]);
$queue->saveBackoff(3, time() + 10 * 3600);
$check($notifier->flush(time() + 100) === 'sent', 'a wait that is longer than the longest possible one (the clock jumped) is ignored');
// another request marked the same website a moment ago: no second write
extract($make());
$flags->data['flipick_vg_dirty_2'] = ['at' => time(), 'first' => time() - 100];
$queue->markWebsites([2]);
$check($flags->writes === 0 && $flags->data['flipick_vg_dirty_2']['first'] < time() - 50, 'a mark written a moment ago by another request is not written again');
// more than 100 websites become "all"
extract($make());
$queue->markAll();
$check($notifier->flush(time() + 100) === 'sent' && !isset($client->calls[0]['body']['websiteIds']), 'all websites: no id list');
// lock: a second run at the same time does nothing
extract($make());
$queue->markWebsites([1]);
$lock->refuse = true;
$check($notifier->flush(time() + 100) === 'busy' && !$client->calls, 'a run that overlaps another does nothing');
$lock->refuse = false;
$notifier->flush(time() + 100);
$check($lock->held === false, 'the lock is released after a run');
$client->fail = 'x';
$queue->markWebsites([1]);
$notifier->flush(time() + 200);
$check($lock->held === false, 'the lock is released after a failed run too');

echo "observer\n";
extract($make());
$fire($observer, 'catalog_product_save_commit_after', ['product' => new FakeProduct([1, 3], [2])]);
$check(array_keys($flags->data) === ['flipick_vg_dirty_1', 'flipick_vg_dirty_3', 'flipick_vg_dirty_2'], 'a product save marks its websites, and the websites it was taken off');
extract($make());
$fire($observer, 'catalog_product_delete_commit_after', ['product' => new FakeProduct([2])]);
$check(array_keys($flags->data) === ['flipick_vg_dirty_2'], 'a product delete marks its websites');
extract($make());
foreach (['catalog_product_attribute_update_before', 'catalog_product_import_bunch_save_after', 'cataloginventory_stock_item_save_commit_after'] as $name) {
    extract($make());
    $fire($observer, $name, ['anything' => ['at' => 'all']]);
    $check(array_keys($flags->data) === ['flipick_vg_dirty_all'], "$name marks everything");
}
extract($make());
$fire($observer, 'catalog_product_save_commit_after', ['product' => null]);
$check(array_keys($flags->data) === ['flipick_vg_dirty_all'], 'an event without a product marks everything instead of failing');
extract($make());
$client->connected = false;
$fire($observer, 'catalog_product_save_commit_after', ['product' => new FakeProduct([1])]);
$check(!$flags->data && $flags->writes === 0, 'a store that is not connected does no work at all');
extract($make());
$boom = new class ($flags) extends FakeFlags {
    public function __construct($ignored)
    {
    }
    public function saveFlag($flagCode, $value)
    {
        throw new RuntimeException('database is gone');
    }
};
$queue2 = new CatalogChangeQueue($boom);
$observer2 = new CatalogChanged($client, $queue2, $logger);
$threw = false;
try {
    $fire($observer2, 'catalog_product_save_commit_after', ['product' => new FakeProduct([1])]);
} catch (Throwable $e) {
    $threw = true;
}
$check(!$threw, 'a database failure never reaches the merchant\'s product save');
$check(count(array_filter($logger->lines, function ($l) { return $l[0] === 'warning'; })) === 1, 'it is logged as a warning');
$sensitive = json_encode($logger->lines);
$check(strpos($sensitive, 'secret') === false, 'nothing secret is logged');

echo ($failures ? "FAILED: " . count($failures) . " check(s)\n" : "all $passed checks passed\n");
exit($failures ? 1 : 0);
