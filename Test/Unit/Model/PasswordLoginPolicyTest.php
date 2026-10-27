<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Test\Unit\Model;

use DmLab\CustomerSso\Model\ActiveProviderResolver;
use DmLab\CustomerSso\Model\Config;
use DmLab\CustomerSso\Model\PasswordLoginPolicy;
use DmLab\SsoCore\Api\ProviderPresetInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PasswordLoginPolicyTest extends TestCase
{
    /**
     * @return array<string, array{bool, bool, bool, bool}>
     */
    public static function cases(): array
    {
        return [
            'disabled under live SSO' => [true, false, true, true],
            'password login kept' => [true, true, true, false],
            'module disabled' => [false, false, true, false],
            'no active provider (break-glass)' => [true, false, false, false],
        ];
    }

    #[DataProvider('cases')]
    public function testIsPasswordLoginDisabled(
        bool $enabled,
        bool $passwordAllowed,
        bool $providerActive,
        bool $expected
    ): void {
        $config = $this->createMock(Config::class);
        $config->method('isEnabled')->with(4)->willReturn($enabled);
        $config->method('isPasswordLoginAllowed')->with(4)->willReturn($passwordAllowed);

        $resolver = $this->createStub(ActiveProviderResolver::class);
        $resolver->method('getActive')->willReturn(
            $providerActive ? $this->createStub(ProviderPresetInterface::class) : null
        );

        self::assertSame($expected, (new PasswordLoginPolicy($config, $resolver))->isPasswordLoginDisabled(4));
    }
}
