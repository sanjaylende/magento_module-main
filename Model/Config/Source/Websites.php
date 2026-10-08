<?php
declare(strict_types=1);

namespace Flipick\VideoGenerator\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Store\Model\StoreManagerInterface;

/** Websites of this Magento installation, for the grid's Website filter. Each website is a separately billed store. */
class Websites implements OptionSourceInterface
{
    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    public function __construct(StoreManagerInterface $storeManager)
    {
        $this->storeManager = $storeManager;
    }

    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->storeManager->getWebsites() as $website) {
            $options[] = ['value' => (string)$website->getId(), 'label' => $website->getName()];
        }
        return $options;
    }
}
