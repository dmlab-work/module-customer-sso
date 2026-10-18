<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Test\Unit\Model\Config\Source;

use DmLab\CustomerSso\Model\Config;
use DmLab\CustomerSso\Model\Config\Source\AutoLinkPolicy;
use PHPUnit\Framework\TestCase;

class AutoLinkPolicyTest extends TestCase
{
    public function testExposesVerifiedAndDisabledPoliciesWithVerifiedFirst(): void
    {
        $options = (new AutoLinkPolicy())->toOptionArray();

        self::assertSame(
            [Config::AUTO_LINK_REQUIRE_VERIFICATION, Config::AUTO_LINK_DISABLED],
            array_column($options, 'value')
        );
        self::assertSame(
            ['Link when the IdP verified the email', 'Never (link from My Account only)'],
            array_map(static fn (array $option): string => (string)$option['label'], $options)
        );
    }
}
