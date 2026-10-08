<?php
declare(strict_types=1);

namespace Flipick\VideoGenerator\Controller\Adminhtml\Index;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;

/** Connect page: one button that registers this Magento installation with the central Flipick adapter. */
class Connect extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Flipick_VideoGenerator::video_generator';

    public function execute()
    {
        $resultPage = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $resultPage->setActiveMenu('Flipick_VideoGenerator::video_generator');
        $resultPage->getConfig()->getTitle()->prepend(__('Connect to Flipick'));

        return $resultPage;
    }
}
