<?php
declare(strict_types=1);

namespace Flipick\VideoGenerator\Controller\Adminhtml\Index;

use Flipick\VideoGenerator\Model\AdapterClient;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;

/** Returns a fresh one-time launch URL for the adapter UI (called by the grid's row action). */
class Launch extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Flipick_VideoGenerator::video_generator';

    /**
     * @var AdapterClient
     */
    private $adapter;

    /**
     * @var JsonFactory
     */
    private $jsonFactory;

    public function __construct(Context $context, AdapterClient $adapter, JsonFactory $jsonFactory)
    {
        parent::__construct($context);
        $this->adapter = $adapter;
        $this->jsonFactory = $jsonFactory;
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();
        if (!$this->adapter->isConnected()) {
            return $result->setHttpResponseCode(400)->setData(['error' => (string)__('This store is not connected to Flipick yet.')]);
        }
        $website = (string)$this->getRequest()->getParam('website');
        if ($website === '') {
            return $result->setHttpResponseCode(400)->setData(['error' => (string)__('Choose a website first.')]);
        }
        return $result->setData(['url' => $this->adapter->launchUrl($website, (string)$this->getRequest()->getParam('product'))]);
    }
}
