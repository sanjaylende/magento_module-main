<?php
declare(strict_types=1);

namespace Flipick\VideoGenerator\Controller\Adminhtml\Index;

use Flipick\VideoGenerator\Model\AdapterClient;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Store\Model\StoreManagerInterface;

/** "Refresh from Magento": makes the adapter re-read one website's catalog, then returns to the grid. */
class Refresh extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Flipick_VideoGenerator::video_generator';

    /**
     * @var AdapterClient
     */
    private $adapter;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    public function __construct(
        Context $context,
        AdapterClient $adapter,
        StoreManagerInterface $storeManager
    )
    {
        parent::__construct($context);
        $this->adapter = $adapter;
        $this->storeManager = $storeManager;
    }

    public function execute()
    {
        try {
            $website = (string)($this->getRequest()->getParam('website') ?: $this->storeManager->getDefaultStoreView()->getWebsiteId());
            $result = $this->adapter->post('/api/refresh', [], $website);
            $this->messageManager->addSuccessMessage(__('Reloaded %1 products from Magento.', (int)($result['count'] ?? 0)));
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }
        return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setPath('*/*/index');
    }
}
