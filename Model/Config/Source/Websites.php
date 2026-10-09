<?php
declare(strict_types=1);

namespace Flipick\VideoGenerator\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/** Websites of this Magento installation, for the grid's Website filter. Each website is a separately billed store. */
class Websites implements OptionSourceInterface
{
    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(StoreManagerInterface $storeManager, LoggerInterface $logger)
    {
        $this->storeManager = $storeManager;
        $this->logger = $logger;
    }

    public function toOptionArray(): array
    {
        $options = [];
        try {
            foreach ($this->storeManager->getWebsites() as $website) {
                $options[] = ['value' => (string)$website->getId(), 'label' => $website->getName()];
            }
        } catch (\Throwable $e) {
            $this->logger->error('Flipick: could not list the websites', ['exception' => $e]);
        }
        return $options;
    }
}
