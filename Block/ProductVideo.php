<?php
namespace Flipick\VideoGenerator\Block;

use Magento\Framework\Registry;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

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

    public function __construct(Context $context, Registry $registry, array $data = [])
    {
        $this->registry = $registry;
        parent::__construct($context, $data);
    }

    private function getProduct()
    {
        return $this->registry->registry('current_product');
    }

    public function hasVideo(): bool
    {
        $product = $this->getProduct();
        return $product !== null && (string) $product->getData('generated_video_url') !== '';
    }

    public function getVideoUrl(): string
    {
        $product = $this->getProduct();
        return $product ? (string) $product->getData('generated_video_url') : '';
    }

    public function getThumbnailUrl(): string
    {
        $product = $this->getProduct();
        return $product ? (string) $product->getData('generated_video_thumbnail') : '';
    }
}
