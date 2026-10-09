<?php
declare(strict_types=1);

namespace Flipick\VideoGenerator\Ui\DataProvider;

use Flipick\VideoGenerator\Model\AdapterClient;
use Magento\Framework\Api\Filter;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\DataProvider\DataProviderInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Feeds the flipick_video_listing grid from the adapter's GET /api/products. The adapter returns the whole catalog
 * (a few hundred rows at most), so search, sorting and paging are applied here in memory.
 */
class VideoProducts implements DataProviderInterface
{
    private const TYPES = ['hero_product' => 'hero', 'lifestyle' => 'lifestyle', 'image_transition' => 'transition'];

    /** @var Filter[] */
    /**
     * @var array
     */
    private $filters = [];
    /**
     * @var ?string
     */
    private $orderField = null;
    /**
     * @var string
     */
    private $orderDirection = 'ASC';
    /**
     * @var int
     */
    private $offset = 0;
    /**
     * @var int
     */
    private $size = 20;

    /**
     * @var string
     */
    private $name;

    /**
     * @var string
     */
    private $primaryFieldName;

    /**
     * @var string
     */
    private $requestFieldName;

    /**
     * @var AdapterClient
     */
    private $adapter;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var UrlInterface
     */
    private $urlBuilder;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var array
     */
    private $meta;

    /**
     * @var array
     */
    private $data;

    public function __construct(
        string $name,
        string $primaryFieldName,
        string $requestFieldName,
        AdapterClient $adapter,
        StoreManagerInterface $storeManager,
        UrlInterface $urlBuilder,
        LoggerInterface $logger,
        array $meta = [],
        array $data = []
    )
    {
        $this->name = $name;
        $this->primaryFieldName = $primaryFieldName;
        $this->requestFieldName = $requestFieldName;
        $this->adapter = $adapter;
        $this->storeManager = $storeManager;
        $this->urlBuilder = $urlBuilder;
        $this->logger = $logger;
        $this->meta = $meta;
        $this->data = $data;
    }

    /** The website being shown: the filter's choice, else the default website. */
    private function websiteId(): string
    {
        foreach ($this->filters as $filter) {
            if ($filter->getField() === 'website_id' && $filter->getValue() !== '') {
                return (string)$filter->getValue();
            }
        }
        return (string)$this->storeManager->getDefaultStoreView()->getWebsiteId();
    }

    public function getData(): array
    {
        $websiteId = $this->websiteId();
        try {
            $products = $this->adapter->get('/api/products', $websiteId)['products'] ?? [];
        } catch (\Throwable $e) {
            $this->logger->warning('Flipick: could not load the product grid from the adapter', ['website' => $websiteId, 'error' => $e->getMessage()]);
            return ['totalRecords' => 0, 'items' => []]; // the page controller already shows the reason as a message
        }
        $launchUrl = $this->urlBuilder->getUrl('flipick_videogenerator/index/launch');
        $rows = [];
        foreach ($products as $product) {
            $rows[] = $this->toRow($product) + ['website_id' => $websiteId, 'launch_url' => $launchUrl];
        }

        foreach ($this->filters as $filter) {
            if ($filter->getField() === 'fulltext') {
                $needle = mb_strtolower(trim((string)$filter->getValue(), '%'));
                if ($needle !== '') {
                    $rows = array_filter($rows, function ($r) use ($needle) {
                        return strpos(mb_strtolower($r['name'] . ' ' . $r['sku'] . ' ' . $r['category']), $needle) !== false;
                    });
                }
            }
        }

        if ($this->orderField !== null && $rows) {
            $sortKey = ['price' => 'price_value', 'last_generated' => 'last_generated_raw'][$this->orderField]
                ?? $this->orderField;
            usort($rows, function ($a, $b) use ($sortKey) {
                $cmp = ($a[$sortKey] ?? '') <=> ($b[$sortKey] ?? '');
                return $this->orderDirection === 'DESC' ? -$cmp : $cmp;
            });
        }

        return [
            'totalRecords' => count($rows),
            'items' => array_values(array_slice($rows, $this->offset, $this->size)),
        ];
    }

    /** One adapter product -> one grid row. */
    private function toRow(array $p): array
    {
        $row = [
            'uniqueTag' => $p['uniqueTag'],
            'image_src' => $p['image'] ?? '',
            'image_orig_src' => $p['image'] ?? '',
            'image_alt' => $p['name'] ?? '',
            'name' => $p['name'] ?? '',
            'sku' => $p['sku'] ?? '',
            'category' => $p['category'] ?? '',
            'price_value' => (float)($p['price'] ?? 0),
            'price' => ($p['price'] ?? null) === null ? '' : sprintf('%s %.2f', $p['currencyCode'] ?? '', $p['price']),
            'last_generated_raw' => $p['lastGeneratedAt'] ?? '',
            'last_generated' => !empty($p['lastGeneratedAt']) ? date('Y-m-d H:i', strtotime($p['lastGeneratedAt'])) : '',
            'actions' => ['manage' => ['label' => (string)__('Generate / Manage')]],
        ];
        foreach (self::TYPES as $type => $column) {
            $row['video_' . $column] = $this->statusBadge($p['videos'][$type] ?? null);
        }
        return $row;
    }

    /** Magento's own grid-severity badge markup (rendered by the html cell template). */
    private function statusBadge(?array $video): string
    {
        if ($video === null) {
            return '<span class="flipick-video-none">&mdash;</span>';
        }
        $esc = function ($s) {
            return htmlspecialchars((string)$s, ENT_QUOTES);
        };
        switch ($video['status']) {
            case 'generating':
                $label = __('Rendering') . (($video['progressPct'] ?? null) !== null ? ' ' . (int)$video['progressPct'] . '%' : '');
                return '<span class="grid-severity-minor"><span>' . $esc($label) . '</span></span>';
            case 'ready':
                $published = !empty($video['pushedToMagento']);
                $label = ($published ? __('Published') : __('Ready')) . ' v' . (int)$video['versionNo'];
                if (!empty($video['staleFromLtxEdit'])) {
                    $label .= ' · ' . __('edited in LTX');
                }
                $class = $published ? 'grid-severity-notice' : 'grid-severity-major';
                return '<span class="' . $class . '"><span>' . $esc($label) . '</span></span>';
            case 'canceled':
                return '<span class="grid-severity-minor"><span>' . $esc(__('Canceled')) . '</span></span>';
            default:
                return '<span class="grid-severity-critical" title="' . $esc($video['error'] ?? '') . '"><span>'
                    . $esc(__('Failed')) . '</span></span>';
        }
    }

    /** Columns ask the provider to select their field; every field is always present in a row, so nothing to do. */
    public function addField($field, $alias = null): void
    {
    }

    public function addFilter(Filter $filter): void
    {
        $this->filters[] = $filter;
    }

    public function addOrder($field, $direction): void
    {
        $this->orderField = (string)$field;
        $this->orderDirection = strtoupper((string)$direction) === 'DESC' ? 'DESC' : 'ASC';
    }

    public function setLimit($offset, $size): void
    {
        $this->offset = (int)$offset;
        $this->size = max(1, (int)$size);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPrimaryFieldName(): string
    {
        return $this->primaryFieldName;
    }

    public function getRequestFieldName(): string
    {
        return $this->requestFieldName;
    }

    public function getMeta(): array
    {
        return $this->meta;
    }

    public function getFieldMetaInfo($fieldSetName, $fieldName): array
    {
        return [];
    }

    public function getFieldSetMetaInfo($fieldSetName): array
    {
        return [];
    }

    public function getFieldsMetaInfo($fieldSetName): array
    {
        return [];
    }

    public function getConfigData(): array
    {
        return $this->data['config'] ?? [];
    }

    public function setConfigData($config): void
    {
        $this->data['config'] = $config;
    }

    public function getSearchCriteria()
    {
        return null;
    }

    public function getSearchResult()
    {
        return null;
    }
}
