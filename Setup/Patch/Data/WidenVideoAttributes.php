<?php
namespace Flipick\VideoGenerator\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

// AddVideoAttributes created generated_video_url/generated_video_thumbnail
// as backend_type 'varchar' -- catalog_product_entity_varchar.value is a
// VARCHAR(255) column, and Flipick's signed GCS URLs (with their
// X-Goog-Algorithm/Credential/Signature query string) routinely run well
// past 400 characters, so every save silently truncated the value mid
// signature, leaving a broken URL neither field nor frontend could load.
// Switches both to 'text' (catalog_product_entity_text, MEDIUMTEXT) so the
// full signed URL survives a save.
class WidenVideoAttributes implements DataPatchInterface
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

        foreach (['generated_video_url', 'generated_video_thumbnail'] as $code) {
            $eavSetup->updateAttribute(Product::ENTITY, $code, 'backend_type', 'text');
        }

        $this->moduleDataSetup->getConnection()->endSetup();
    }

    public static function getDependencies(): array
    {
        return [AddVideoAttributes::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
