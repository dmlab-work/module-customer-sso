<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Test\Unit\Model\Session;

use DmLab\CustomerSso\Model\Session\SsoIntentStorage;
use DmLab\CustomerSso\Model\SsoIntent;
use DmLab\CustomerSso\Model\Session\PendingEntries;
use Magento\Customer\Model\Session;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\TestFramework\Unit\Helper\MockCreationTrait;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

// The customer Session is mocked only to back the store with an in-memory array;
// its `setData` is a magic method, so a stub cannot express it.
#[AllowMockObjectsWithoutExpectations]
class SsoIntentStorageTest extends TestCase
{
    use MockCreationTrait;

    private const SESSION_KEY = 'dmlab_customer_sso_intents';

    /** @var array<string,mixed> */
    private array $store = [];

    /** @var int clock the pending-entry TTL reads */
    private int $now = 1_000_000;

    /** @var SsoIntentStorage */
    private SsoIntentStorage $storage;

    protected function setUp(): void
    {
        $this->store = [];

        $session = $this->createPartialMockWithReflection(Session::class, ['getData', 'setData']);
        $session->expects($this->any())->method('getData')
            ->willReturnCallback(fn($key) => $this->store[$key] ?? null);
        $session->expects($this->any())->method('setData')
            ->willReturnCallback(function ($key, $value): void {
                $this->store[$key] = $value;
            });

        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturnCallback(fn(): int => $this->now);

        $this->storage = new SsoIntentStorage($session, new PendingEntries($dateTime));
    }

    public function testLinkIntentRoundTripsOnce(): void
    {
        $this->storage->save('s1', new SsoIntent(SsoIntent::MODE_LINK, 7, 'https://magento.loc/customersso/account/'));

        $loaded = $this->storage->consume('s1');

        self::assertNotNull($loaded);
        self::assertSame(SsoIntent::MODE_LINK, $loaded->getMode());
        self::assertSame(7, $loaded->getCustomerId());
        self::assertSame('https://magento.loc/customersso/account/', $loaded->getReturnUrl());

        // Single use: a second consume returns null.
        self::assertNull($this->storage->consume('s1'));
    }

    public function testLoginIntentWithoutReturnUrlRoundTrips(): void
    {
        $this->storage->save('s1', new SsoIntent(SsoIntent::MODE_LOGIN));

        $loaded = $this->storage->consume('s1');

        self::assertNotNull($loaded);
        self::assertSame(SsoIntent::MODE_LOGIN, $loaded->getMode());
        self::assertNull($loaded->getCustomerId());
        self::assertNull($loaded->getReturnUrl());
    }

    public function testConsumeUnknownStateReturnsNull(): void
    {
        self::assertNull($this->storage->consume('missing'));
    }

    public function testConcurrentIntentsDoNotClobberEachOther(): void
    {
        $this->storage->save('s1', new SsoIntent(SsoIntent::MODE_LOGIN, null, 'https://magento.loc/a'));
        $this->storage->save('s2', new SsoIntent(SsoIntent::MODE_LOGIN, null, 'https://magento.loc/b'));

        self::assertSame('https://magento.loc/b', $this->storage->consume('s2')?->getReturnUrl());
        self::assertSame('https://magento.loc/a', $this->storage->consume('s1')?->getReturnUrl());
    }

    public function testExpiredIntentReturnsNull(): void
    {
        $this->storage->save('s1', new SsoIntent(SsoIntent::MODE_LOGIN));
        $this->now += PendingEntries::TTL;

        self::assertNull($this->storage->consume('s1'));
        self::assertSame([], $this->store[self::SESSION_KEY]);
    }

    public function testMalformedEntryIsDroppedAndReturnsNull(): void
    {
        $this->store[self::SESSION_KEY] = ['s1' => ['created_at' => $this->now, 'mode' => 'bogus']];

        self::assertNull($this->storage->consume('s1'));
        self::assertSame([], $this->store[self::SESSION_KEY]);
    }
}
