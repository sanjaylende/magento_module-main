<?php
declare(strict_types=1);

namespace Flipick\VideoGenerator\Block\Adminhtml;

use Flipick\VideoGenerator\Model\AdapterClient;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;

/** Content of the Connect page. */
class Connect extends Template
{
    /**
     * @var AdapterClient
     */
    private $adapter;

    public function __construct(Context $context, AdapterClient $adapter, array $data = [])
    {
        parent::__construct($context, $data);
        $this->adapter = $adapter;
    }

    public function getSaveUrl(): string
    {
        return $this->getUrl('flipick_videogenerator/index/connectsave');
    }

    public function getConfigUrl(): string
    {
        return $this->getUrl('adminhtml/system_config/edit', ['section' => 'flipick_videogenerator']);
    }

    public function isAdapterUrlSet(): bool
    {
        return $this->adapter->getBrowserUrl() !== '';
    }

    public function isConnected(): bool
    {
        return $this->adapter->isConnected();
    }
}
