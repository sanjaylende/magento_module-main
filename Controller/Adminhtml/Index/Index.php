<?php
declare(strict_types=1);

namespace Flipick\VideoGenerator\Controller\Adminhtml\Index;

use Flipick\VideoGenerator\Model\AdapterClient;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Psr\Log\LoggerInterface;

/**
 * Video Generator page: a standard admin grid (ui_component flipick_video_listing) of the products of one website and their
 * generated videos. Row actions open the adapter's per-product page in a modal. Until the store is connected to Flipick,
 * this sends the admin to the Connect page instead.
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Flipick_VideoGenerator::video_generator';

    /**
     * @var AdapterClient
     */
    private $adapter;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(Context $context, AdapterClient $adapter, LoggerInterface $logger)
    {
        parent::__construct($context);
        $this->adapter = $adapter;
        $this->logger = $logger;
    }

    public function execute()
    {
        if (!$this->adapter->isConnected()) {
            return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setPath('*/*/connect');
        }
        // Surface a connection problem as a normal admin message instead of an unexplained empty grid.
        try {
            $this->adapter->post('/api/v1/ping', ['extensionVersion' => AdapterClient::EXTENSION_VERSION]);
        } catch (\Throwable $e) {
            $this->logger->warning('Flipick: adapter ping failed when opening Video Generator', ['error' => $e->getMessage()]);
            $this->messageManager->addErrorMessage($e->getMessage());
        }
        $resultPage = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $resultPage->setActiveMenu('Flipick_VideoGenerator::video_generator');
        $resultPage->getConfig()->getTitle()->prepend(__('Video Generator'));

        return $resultPage;
    }
}
