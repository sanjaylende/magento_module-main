<?php
namespace Flipick\VideoGenerator\Block;

use Magento\Framework\Registry;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Psr\Log\LoggerInterface;

// Reads the two custom attributes the Node adapter writes via the Magento
// REST API (src/index.js's pushVideoToProduct) and exposes them to
// view/frontend/templates/video.phtml. Deliberately minimal -- no admin
// configuration, no JS -- this exists purely to get the Flipick-hosted
// video playing on the storefront product page.
class ProductVideo extends Template
{
    /**
     * @var Registry
     */
    private $registry;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(Context $context, Registry $registry, LoggerInterface $logger, array $data = [])
    {
        $this->registry = $registry;
        $this->logger = $logger;
        parent::__construct($context, $data);
    }

    private function getProduct()
    {
        return $this->registry->registry('current_product');
    }

    public function hasVideo(): bool
    {
        return $this->getVideoUrl() !== '';
    }

    public function getVideoUrl(): string
    {
        return $this->safeAttribute('generated_video_url');
    }

    public function getThumbnailUrl(): string
    {
        return $this->safeAttribute('generated_video_thumbnail');
    }

    /**
     * Reads a product attribute for the storefront. Anything unexpected is logged and answered with "no video", so the
     * product page always renders. Only absolute http(s) URLs are returned: the value is written through the REST API and is
     * never trusted as markup or as a javascript: link.
     */
    private function safeAttribute(string $code): string
    {
        try {
            $product = $this->getProduct();
            if ($product === null) {
                return '';
            }
            $value = trim((string) $product->getData($code));
            if ($value === '') {
                return '';
            }
            $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
            if (!in_array($scheme, ['http', 'https'], true)) {
                $this->logger->warning('Flipick: ignored a video attribute that is not an http(s) URL', ['attribute' => $code, 'product_id' => $product->getId()]);
                return '';
            }
            return $value;
        } catch (\Throwable $e) {
            $this->logger->error('Flipick: could not read the product video', ['attribute' => $code, 'exception' => $e]);
            return '';
        }
    }
}
