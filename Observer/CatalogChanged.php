<?php
declare(strict_types=1);

namespace Flipick\VideoGenerator\Observer;

use Flipick\VideoGenerator\Model\AdapterClient;
use Flipick\VideoGenerator\Model\CatalogChangeQueue;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * Notes that catalog data changed (product saved or deleted, mass attribute update such as a price change, import, stock change) so that
 * the adapter can be told by the scheduled job. Magento has no webhooks, which is why the adapter is told this way.
 *
 * Rules this observer keeps, because it runs inside every product save in the admin, in imports and while orders are placed:
 *  - it never throws (any failure is logged and the merchant's save carries on);
 *  - it does no network call, and at most one small database write per website per few seconds;
 *  - it does nothing at all when the store is not connected to Flipick;
 *  - it reads nothing but website ids from the event.
 */
class CatalogChanged implements ObserverInterface
{
    /**
     * @var AdapterClient
     */
    private $client;

    /**
     * @var CatalogChangeQueue
     */
    private $queue;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(AdapterClient $client, CatalogChangeQueue $queue, LoggerInterface $logger)
    {
        $this->client = $client;
        $this->queue = $queue;
        $this->logger = $logger;
    }

    public function execute(Observer $observer): void
    {
        try {
            if ($this->client->getInstallKey() === '') {
                return;
            }
            $event = $observer->getEvent();
            $name = (string)$event->getName();
            if ($name === 'catalog_product_save_commit_after' || $name === 'catalog_product_delete_commit_after') {
                $this->markProduct($event->getData('product'));
                return;
            }
            // mass attribute update, import and stock changes do not say which websites they touch: all of them
            $this->queue->markAll();
        } catch (\Throwable $e) {
            $this->logger->warning('Flipick: could not note a catalog change (the product was saved normally)', [
                'event' => isset($name) ? $name : null, 'error' => $e->getMessage(),
            ]);
        }
    }

    /** @param mixed $product */
    private function markProduct($product): void
    {
        if (!is_object($product) || !method_exists($product, 'getWebsiteIds')) {
            $this->queue->markAll();
            return;
        }
        $ids = (array)$product->getWebsiteIds();
        // a product taken off a website changed that website too
        $before = method_exists($product, 'getOrigData') ? $product->getOrigData('website_ids') : null;
        if (is_array($before)) {
            $ids = array_merge($ids, $before);
        }
        if ($ids) {
            $this->queue->markWebsites($ids);
        }
    }
}
