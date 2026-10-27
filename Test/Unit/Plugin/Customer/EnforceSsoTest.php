<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Test\Unit\Plugin\Customer;

use DmLab\CustomerSso\Model\PasswordLoginPolicy;
use DmLab\CustomerSso\Plugin\Customer\EnforceSso;
use Magento\Customer\Api\AccountManagementInterface;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

class EnforceSsoTest extends TestCase
{
    private function plugin(bool $passwordLoginDisabled): EnforceSso
    {
        $policy = $this->createStub(PasswordLoginPolicy::class);
        $policy->method('isPasswordLoginDisabled')->willReturn($passwordLoginDisabled);

        return new EnforceSso($policy);
    }

    public function testRejectsPasswordLoginWhenDisabled(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(
            'Password sign-in is disabled for this store. Please sign in with single sign-on.'
        );

        $this->plugin(true)->beforeAuthenticate(
            $this->createStub(AccountManagementInterface::class),
            'jane@example.com',
            'secret'
        );
    }

    public function testPassesCredentialsThroughWhenAllowed(): void
    {
        self::assertSame(
            ['jane@example.com', 'secret'],
            $this->plugin(false)->beforeAuthenticate(
                $this->createStub(AccountManagementInterface::class),
                'jane@example.com',
                'secret'
            )
        );
    }
}
