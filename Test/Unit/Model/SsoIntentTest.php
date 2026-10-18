<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Test\Unit\Model;

use DmLab\CustomerSso\Model\SsoIntent;
use PHPUnit\Framework\TestCase;

class SsoIntentTest extends TestCase
{
    public function testLoginIntent(): void
    {
        $intent = new SsoIntent(SsoIntent::MODE_LOGIN, null, 'https://magento.loc/checkout/');

        self::assertSame(SsoIntent::MODE_LOGIN, $intent->getMode());
        self::assertFalse($intent->isLink());
        self::assertNull($intent->getCustomerId());
        self::assertSame('https://magento.loc/checkout/', $intent->getReturnUrl());
    }

    public function testLinkIntent(): void
    {
        $intent = new SsoIntent(SsoIntent::MODE_LINK, 7);

        self::assertSame(SsoIntent::MODE_LINK, $intent->getMode());
        self::assertTrue($intent->isLink());
        self::assertSame(7, $intent->getCustomerId());
        self::assertNull($intent->getReturnUrl());
    }

    public function testUnknownModeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SsoIntent('logout');
    }

    public function testLinkWithoutCustomerIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SsoIntent(SsoIntent::MODE_LINK);
    }
}
