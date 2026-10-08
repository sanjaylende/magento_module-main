<?php
namespace Flipick\VideoGenerator\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

// Adds the two custom product attributes the Node adapter writes to via
// `PUT /rest/V1/products/{sku}` and this module's own storefront block
// (Block/ProductVideo.php) reads back to render the <video> element. Only
// assigned to the "Default" attribute set below -- a catalog using other
// attribute sets needs those attributes added to them too (Admin -> Stores
// -> Attribute Set, or extend this patch).
class AddVideoAttributes implements DataPatchInterface
{
    /**
     * @var ModuleDataSetupInterface
     */
    private $moduleDataSetup;
    /**
     * @var EavSetupFactory
     */
    private $eavSetupFactory;

    public function __construct(ModuleDataSetupInterface $moduleDataSetup, EavSetupFactory $eavSetupFactory)
    {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->eavSetupFactory = $eavSetupFactory;
    }

    public function apply()
    {
        $this->moduleDataSetup->getConnection()->startSetup();
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        $attributes = [
            'generated_video_url' => 'Generated Video URL',
            'generated_video_thumbnail' => 'Generated Video Thumbnail',
        ];

        foreach ($attributes as $code => $label) {
            $eavSetup->addAttribute(Product::ENTITY, $code, [
                'type' => 'varchar',
                'label' => $label,
                'input' => 'text',
                'required' => false,
                'sort_order' => 100,
                'global' => ScopedAttributeInterface::SCOPE_GLOBAL,
                'visible' => true,
                'user_defined' => true,
                'visible_on_front' => false,
                'used_in_product_listing' => false,
                'is_used_in_grid' => false,
                'is_visible_in_grid' => false,
                'is_filterable_in_grid' => false,
                'group' => 'General',
            ]);
            $eavSetup->addAttributeToGroup(Product::ENTITY, 'Default', 'General', $code);
        }

        $this->moduleDataSetup->getConnection()->endSetup();
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
