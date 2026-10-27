<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Test\Unit\Model\Session;

use DmLab\CustomerSso\Model\Session\IdpSessionStorage;
use Magento\Customer\Model\Session;
use Magento\Framework\TestFramework\Unit\Helper\MockCreationTrait;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

// The customer Session is mocked only to back the store with an in-memory array (`setData` is magic).
#[AllowMockObjectsWithoutExpectations]
class IdpSessionStorageTest extends TestCase
{
    use MockCreationTrait;

    /** @var array<string,mixed> */
    private array $store = [];

    /** @var int|null signed-in customer id */
    private ?int $customerId = 7;

    /** @var IdpSessionStorage */
    private IdpSessionStorage $storage;

    protected function setUp(): void
    {
        $session = $this->createPartialMockWithReflection(Session::class, ['getData', 'setData', 'getCustomerId']);
        $session->expects($this->any())->method('getData')
            ->willReturnCallback(fn($key) => $this->store[$key] ?? null);
        $session->expects($this->any())->method('setData')
            ->willReturnCallback(function ($key, $value): void {
                $this->store[$key] = $value;
            });
        $session->expects($this->any())->method('getCustomerId')
            ->willReturnCallback(fn() => $this->customerId);

        $this->storage = new IdpSessionStorage($session);
    }

    public function testReturnsSavedTokenForSameCustomer(): void
    {
        $this->storage->save('raw-id-token', 'okta');

        self::assertSame('raw-id-token', $this->storage->getIdToken());
        self::assertSame('okta', $this->storage->getProviderCode());
    }

    public function testIgnoresEntryOfAnotherOrNoCustomer(): void
    {
        $this->storage->save('raw-id-token', 'okta');

        $this->customerId = 8;
        self::assertNull($this->storage->getIdToken());

        $this->customerId = null;
        self::assertNull($this->storage->getIdToken());
        self::assertNull($this->storage->getProviderCode());
    }

    public function testClearForgetsEntry(): void
    {
        $this->storage->save('raw-id-token', 'okta');
        $this->storage->clear();

        self::assertNull($this->storage->getIdToken());
    }
}
