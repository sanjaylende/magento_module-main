<?php
declare(strict_types=1);

namespace Flipick\VideoGenerator\Controller\Adminhtml\Index;

use Flipick\VideoGenerator\Model\AdapterClient;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;

/** Plans & Billing page: the adapter's billing screens for one website, framed in the admin. */
class Billing extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Flipick_VideoGenerator::video_generator';

    /**
     * @var AdapterClient
     */
    private $adapter;

    public function __construct(Context $context, AdapterClient $adapter)
    {
        parent::__construct($context);
        $this->adapter = $adapter;
    }

    public function execute()
    {
        if (!$this->adapter->isConnected()) {
            return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setPath('*/*/connect');
        }
        $resultPage = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $resultPage->setActiveMenu('Flipick_VideoGenerator::video_generator_billing');
        $resultPage->getConfig()->getTitle()->prepend(__('Plans & Billing'));

        return $resultPage;
    }
}
