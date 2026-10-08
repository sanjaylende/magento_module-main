<?php
declare(strict_types=1);

namespace Flipick\VideoGenerator\Controller\Adminhtml\Index;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Psr\Log\LoggerInterface;
use Magento\Backend\App\Action\Context;

/** Connect page: one button that registers this Magento installation with the central Flipick adapter. */
class Connect extends Action implements HttpGetActionInterface
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    public const ADMIN_RESOURCE = 'Flipick_VideoGenerator::video_generator';

    public function __construct(Context $context, LoggerInterface $logger)
    {
        parent::__construct($context);
        $this->logger = $logger;
    }

    public function execute()
    {
        try {
            $resultPage = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
            $resultPage->setActiveMenu('Flipick_VideoGenerator::video_generator');
            $resultPage->getConfig()->getTitle()->prepend(__('Connect to Flipick'));

            return $resultPage;
        } catch (\Throwable $e) {
            $this->logger->error('Flipick: Connect page failed', ['exception' => $e]);
            $this->messageManager->addErrorMessage(__('The Connect page could not be opened. The details are in var/log/system.log.'));
            return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setPath('adminhtml/dashboard');
        }
    }
}
