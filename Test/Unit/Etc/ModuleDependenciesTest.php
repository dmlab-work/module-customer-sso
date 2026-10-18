<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Test\Unit\Etc;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ModuleDependenciesTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function sequencedModules(): array
    {
        return [
            'customer' => ['Magento_Customer'],
            'store' => ['Magento_Store'],
            'config' => ['Magento_Config'],
            'checkout' => ['Magento_Checkout'],
            'sso-core' => ['DmLab_SsoCore'],
        ];
    }

    #[DataProvider('sequencedModules')]
    public function testModuleXmlSequencesModule(string $module): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/etc/module.xml');
        self::assertNotFalse($xml);

        $items = $xml->xpath(sprintf('/config/module[@name="DmLab_CustomerSso"]/sequence/module[@name="%s"]', $module));

        self::assertCount(1, $items);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function requiredPackages(): array
    {
        return [
            'customer' => ['magento/module-customer'],
            'store' => ['magento/module-store'],
            'config' => ['magento/module-config'],
            'backend' => ['magento/module-backend'],
            'checkout' => ['magento/module-checkout'],
            'sso-core' => ['dmlab/module-sso-core'],
        ];
    }

    #[DataProvider('requiredPackages')]
    public function testComposerRequiresPackage(string $package): void
    {
        $composer = json_decode(
            (string)file_get_contents(dirname(__DIR__, 3) . '/composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertArrayHasKey($package, $composer['require']);
    }
}
