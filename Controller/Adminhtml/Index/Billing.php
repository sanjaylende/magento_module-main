<?php
declare(strict_types=1);

namespace Flipick\VideoGenerator\Controller\Adminhtml\Index;

use Flipick\VideoGenerator\Model\AdapterClient;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Psr\Log\LoggerInterface;

/** Plans & Billing page: the adapter's billing screens for one website, framed in the admin. */
class Billing extends Action implements HttpGetActionInterface
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
        try {
            $resultPage = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
            $resultPage->setActiveMenu('Flipick_VideoGenerator::video_generator_billing');
            $resultPage->getConfig()->getTitle()->prepend(__('Plans & Billing'));

            return $resultPage;
        } catch (\Throwable $e) {
            $this->logger->error('Flipick: Plans & Billing page failed', ['exception' => $e]);
            $this->messageManager->addErrorMessage(__('Plans & Billing could not be opened. The details are in var/log/system.log.'));
            return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setPath('*/*/index');
        }
    }
}
