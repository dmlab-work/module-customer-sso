<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Test\Unit\Etc;

use DmLab\CustomerSso\Block\Checkout\SsoButtonProcessor;
use DmLab\CustomerSso\Plugin\Customer\EnforceSso;
use DmLab\CustomerSso\Plugin\Customer\IdpLogout;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DiConfigTest extends TestCase
{
    public function testClientSecretIsMarkedSensitive(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/etc/di.xml');
        self::assertNotFalse($xml);

        $items = $xml->xpath(
            '/config/type[@name="Magento\Config\Model\Config\TypePool"]'
            . '/arguments/argument[@name="sensitive"]'
            . '/item[@name="dmlab_customer_sso/general/client_secret"]'
        );

        self::assertCount(1, $items);
        self::assertSame('1', trim((string)$items[0]));
    }

    public function testPasswordLoginEnforcedOnAccountManagementInAllAreas(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/etc/di.xml');
        self::assertNotFalse($xml);

        $plugins = $xml->xpath(
            '/config/type[@name="Magento\Customer\Api\AccountManagementInterface"]/plugin'
        );

        self::assertCount(1, $plugins);
        self::assertSame(EnforceSso::class, (string)$plugins[0]['type']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function checkoutPopups(): array
    {
        return [
            'minicart popup' => [
                'Magento\\Customer\\Block\\Account\\AuthenticationPopup',
                'components/authenticationPopup/children',
            ],
            'checkout step' => [
                'Magento\\Checkout\\Block\\Onepage',
                'components/checkout/children/authentication/children',
            ],
        ];
    }

    #[DataProvider('checkoutPopups')]
    public function testSsoButtonProcessorRegisteredForPopup(string $block, string $childrenPath): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/etc/frontend/di.xml');
        self::assertNotFalse($xml);

        $items = $xml->xpath(sprintf(
            '/config/type[@name="%s"]/arguments/argument[@name="layoutProcessors"]/item',
            $block
        ));
        self::assertCount(1, $items);

        $virtualTypes = $xml->xpath(sprintf(
            '/config/virtualType[@name="%s"][@type="%s"]/arguments/argument[@name="childrenPath"]',
            trim((string)$items[0]),
            SsoButtonProcessor::class
        ));
        self::assertCount(1, $virtualTypes);
        self::assertSame($childrenPath, trim((string)$virtualTypes[0]));
    }

    public function testIdpLogoutIsOffByDefaultAndPluginsCustomerLogout(): void
    {
        $config = simplexml_load_file(dirname(__DIR__, 3) . '/etc/config.xml');
        self::assertNotFalse($config);
        self::assertSame('0', trim((string)$config->default->dmlab_customer_sso->general->idp_logout));

        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/etc/frontend/di.xml');
        self::assertNotFalse($xml);
        $plugins = $xml->xpath(
            '/config/type[@name="Magento\Customer\Controller\Account\Logout"]'
            . '/plugin[@name="dmlab_customer_sso_idp_logout"]'
        );
        self::assertCount(1, $plugins);
        self::assertSame(IdpLogout::class, (string)$plugins[0]['type']);
    }
}
