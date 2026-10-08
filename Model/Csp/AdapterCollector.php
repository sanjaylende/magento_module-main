<?php
declare(strict_types=1);

namespace Flipick\VideoGenerator\Model\Csp;

use Magento\Csp\Api\PolicyCollectorInterface;
use Magento\Csp\Model\Policy\FetchPolicy;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Psr\Log\LoggerInterface;

/**
 * Lets the admin page frame the Flipick adapter wherever it is configured to live (Stores > Configuration > Video
 * Generator > Adapter URL), so changing that address, for example to a new tunnel address, needs no code or XML change.
 * Registered for the admin area only (etc/adminhtml/di.xml).
 */
class AdapterCollector implements PolicyCollectorInterface
{
    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(ScopeConfigInterface $scopeConfig, LoggerInterface $logger)
    {
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
    }

    /**
     * @inheritDoc
     */
    public function collect(array $defaultPolicies = []): array
    {
        try {
            $url = trim((string)$this->scopeConfig->getValue('flipick_videogenerator/general/adapter_url'));
            if ($url === '') {
                return $defaultPolicies; // not configured yet: nothing to allow
            }
            $parts = parse_url($url);
            if (empty($parts['scheme']) || empty($parts['host']) || !in_array($parts['scheme'], ['http', 'https'], true)) {
                $this->logger->warning('Flipick: the Adapter URL is not a valid http(s) address; the admin frame will be blocked by CSP');
                return $defaultPolicies;
            }
            $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
            $defaultPolicies[] = new FetchPolicy('frame-src', false, [$origin]);
        } catch (\Throwable $e) {
            $this->logger->error('Flipick: could not build the frame-src policy', ['exception' => $e]);
        }
        return $defaultPolicies;
    }
}
