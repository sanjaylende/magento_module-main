<?php
declare(strict_types=1);

namespace Flipick\VideoGenerator\Model\Csp;

use Magento\Csp\Api\PolicyCollectorInterface;
use Magento\Csp\Model\Policy\FetchPolicy;
use Magento\Framework\App\Config\ScopeConfigInterface;

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

    public function __construct(ScopeConfigInterface $scopeConfig)
    {
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * @inheritDoc
     */
    public function collect(array $defaultPolicies = []): array
    {
        $parts = parse_url(trim((string)$this->scopeConfig->getValue('flipick_videogenerator/general/adapter_url')));
        if (empty($parts['scheme']) || empty($parts['host']) || !in_array($parts['scheme'], ['http', 'https'], true)) {
            return $defaultPolicies;
        }
        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $defaultPolicies[] = new FetchPolicy('frame-src', false, [$origin]);

        return $defaultPolicies;
    }
}
