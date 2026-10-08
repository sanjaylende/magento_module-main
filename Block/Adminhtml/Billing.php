<?php
declare(strict_types=1);

namespace Flipick\VideoGenerator\Block\Adminhtml;

use Flipick\VideoGenerator\Model\AdapterClient;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/** Content of the Plans & Billing page: a website picker and the adapter's billing screens in a frame. */
class Billing extends Template
{
    /**
     * @var AdapterClient
     */
    private $adapter;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        Context $context,
        AdapterClient $adapter,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger,
        array $data = []
    )
    {
        parent::__construct($context, $data);
        $this->adapter = $adapter;
        $this->storeManager = $storeManager;
        $this->logger = $logger;
    }

    /** @return array<int, array{id: string, name: string, url: string, current: bool}> */
    public function getWebsites(): array
    {
        $current = $this->getCurrentWebsiteId();
        $list = [];
        foreach ($this->storeManager->getWebsites() as $website) {
            $list[] = [
                'id' => (string)$website->getId(),
                'name' => (string)$website->getName(),
                'url' => $this->getUrl('*/*/billing', ['website' => $website->getId()]),
                'current' => (string)$website->getId() === $current,
            ];
        }
        return $list;
    }

    public function getCurrentWebsiteId(): string
    {
        $requested = (string)$this->getRequest()->getParam('website');
        return $requested !== '' ? $requested : (string)$this->storeManager->getDefaultStoreView()->getWebsiteId();
    }

    /** One-time launch URL for the adapter UI. */
    public function getFrameUrl(): string
    {
        try {
            return $this->adapter->launchUrl($this->getCurrentWebsiteId());
        } catch (\Throwable $e) {
            // The page shows a message instead of an empty frame (see billing.phtml).
            $this->logger->error('Flipick: could not build the Plans & Billing URL', ['exception' => $e]);
            return '';
        }
    }
}
