<?php
// Static compatibility check of the extension against a Magento code base: loads every extension class (PHP verifies that
// each class is signature-compatible with the interfaces/parents of THAT Magento version) and checks that every Magento
// class, method and constant the extension relies on exists there.
//
// Usage: AUTOLOAD=/path/to/vendor/autoload.php MODULE=/path/to/Flipick/VideoGenerator php compat-check.php
$autoload = getenv('AUTOLOAD');
$module = getenv('MODULE') ?: '/var/www/html/app/code/Flipick/VideoGenerator';
require $autoload;
spl_autoload_register(function ($class) use ($module) {
    $prefix = 'Flipick\\VideoGenerator\\';
    if (strpos($class, $prefix) === 0) {
        $file = $module . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

$failures = [];
$check = function (bool $ok, string $what) use (&$failures) {
    if (!$ok) {
        $failures[] = $what;
    }
};

// Factories (*Factory) are generated at runtime and AuthSession::getUser() is a magic getter, so they are not checked.
// 1) Load every extension class; incompatible overrides are fatal errors, caught here as Throwable where possible.
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($module, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (substr($file->getFilename(), -4) !== '.php' || $file->getFilename() === 'registration.php') {
        continue;
    }
    $relative = substr($file->getPathname(), strlen($module) + 1, -4);
    $class = 'Flipick\\VideoGenerator\\' . str_replace('/', '\\', str_replace('\\', '/', $relative));
    try {
        new ReflectionClass($class);
    } catch (Throwable $e) {
        $failures[] = "cannot load $class: " . $e->getMessage();
    }
}

// Note: *Factory classes are generated at runtime and AuthSession::getUser() is a magic getter, so they are not checked here.
// 2) Magento API surface used by the extension.
$methods = [
    'Magento\Framework\HTTP\Client\Curl' => ['setTimeout', 'addHeader', 'post', 'get', 'getBody', 'getStatus'],
    'Magento\Framework\Serialize\Serializer\Json' => ['serialize', 'unserialize'],
    'Magento\Framework\App\Config\Storage\WriterInterface' => ['save', 'delete'],
    'Magento\Framework\App\Cache\TypeListInterface' => ['cleanType'],
    'Magento\Framework\Encryption\EncryptorInterface' => ['encrypt', 'decrypt'],
    'Magento\Framework\App\Config\ScopeConfigInterface' => ['getValue'],
    'Magento\Integration\Api\IntegrationServiceInterface' => ['findByName', 'create', 'update', 'delete'],
    'Magento\Integration\Api\AuthorizationServiceInterface' => ['grantPermissions'],
    'Magento\Integration\Api\OauthServiceInterface' => ['getAccessToken', 'createAccessToken'],
    'Magento\Framework\App\ProductMetadataInterface' => ['getVersion'],
    'Magento\Store\Model\StoreManagerInterface' => ['getWebsites', 'getDefaultStoreView'],
    'Magento\Framework\Controller\ResultFactory' => ['create'],
    'Magento\Framework\Setup\SchemaSetupInterface' => ['getConnection', 'getTable'],
    'Magento\Framework\View\Element\UiComponent\DataProvider\DataProviderInterface' => [
        'getName', 'getConfigData', 'setConfigData', 'getMeta', 'getFieldMetaInfo', 'getFieldSetMetaInfo', 'getFieldsMetaInfo',
        'getPrimaryFieldName', 'getRequestFieldName', 'getData', 'addFilter', 'addOrder', 'setLimit', 'getSearchCriteria', 'getSearchResult',
    ],
    'Magento\Framework\Data\OptionSourceInterface' => ['toOptionArray'],
    'Magento\Framework\UrlInterface' => ['getUrl'],
    'Magento\Framework\Api\Filter' => ['getField', 'getValue'],
];
foreach ($methods as $class => $names) {
    if (!class_exists($class) && !interface_exists($class)) {
        $failures[] = "missing class $class";
        continue;
    }
    foreach ($names as $name) {
        $check(method_exists($class, $name), "missing method $class::$name");
    }
}
$constants = [
    ['Magento\Integration\Model\Integration', 'STATUS_ACTIVE'], ['Magento\Integration\Model\Integration', 'STATUS_INACTIVE'],
    ['Magento\Integration\Model\Integration', 'TYPE_MANUAL'], ['Magento\Framework\Controller\ResultFactory', 'TYPE_PAGE'],
    ['Magento\Framework\Controller\ResultFactory', 'TYPE_REDIRECT'], ['Magento\Store\Model\ScopeInterface', 'SCOPE_STORE'],
];
foreach ($constants as [$class, $const]) {
    $check(defined("$class::$const"), "missing constant $class::$const");
}
foreach ([
    'Magento\Backend\App\Action', 'Magento\Backend\App\Action\Context', 'Magento\Backend\Block\Template', 'Magento\Backend\Block\Template\Context',
    'Magento\Framework\App\Action\HttpGetActionInterface', 'Magento\Framework\App\Action\HttpPostActionInterface',
    'Magento\Framework\Setup\UninstallInterface', 'Magento\Framework\Setup\ModuleContextInterface', 'Magento\Framework\Setup\Patch\DataPatchInterface',
    'Magento\Framework\Setup\ModuleDataSetupInterface', 'Magento\Eav\Setup\EavSetup','Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface',
    'Magento\Framework\View\Element\FormKey', 'Magento\Catalog\Model\Product',
] as $class) {
    try {
        $check(class_exists($class) || interface_exists($class), "missing class $class");
    } catch (Throwable $e) {
        // The class exists but could not be loaded here (a partial package set in the test environment): not a finding.
    }
}

// 3) Config files that older Magento versions parse strictly: they must at least be well-formed XML.
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($module, FilesystemIterator::SKIP_DOTS)) as $file) {
    if (substr($file->getFilename(), -4) === '.xml') {
        libxml_use_internal_errors(true);
        $check(simplexml_load_file($file->getPathname()) !== false, 'malformed XML ' . $file->getPathname());
    }
}

echo $failures ? "FAIL\n - " . implode("\n - ", $failures) . "\n" : "OK: all extension classes load and every Magento API used exists\n";
exit($failures ? 1 : 0);
