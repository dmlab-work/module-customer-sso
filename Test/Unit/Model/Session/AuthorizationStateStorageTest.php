<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Test\Unit\Model\Session;

use DmLab\CustomerSso\Model\AuthorizationState;
use DmLab\CustomerSso\Model\Session\AuthorizationStateStorage;
use DmLab\SsoCore\Api\Data\AuthorizationStateInterface;
use DmLab\CustomerSso\Model\Session\PendingEntries;
use DmLab\CustomerSso\Test\Unit\Helper\PartialMockTrait;
use Magento\Customer\Model\Session;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

// The customer Session is mocked only to back the store with an in-memory array;
// its `setData` is a magic method, so a stub cannot express it.
#[AllowMockObjectsWithoutExpectations]
class AuthorizationStateStorageTest extends TestCase
{
    use PartialMockTrait;

    /** @var array<string,mixed> */
    private array $store = [];

    /** @var int clock the pending-entry TTL reads */
    private int $now = 1_000_000;

    /** @var AuthorizationStateStorage */
    private AuthorizationStateStorage $storage;

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

        $this->storage = new AuthorizationStateStorage($session, new PendingEntries($dateTime));
    }

    private function state(string $state, string $nonce, string $verifier): AuthorizationStateInterface
    {
        return new AuthorizationState($state, $nonce, $verifier);
    }

    public function testSavedStateIsConsumedOnce(): void
    {
        $this->storage->save($this->state('s1', 'n1', 'v1'));

        $loaded = $this->storage->consume('s1');

        self::assertNotNull($loaded);
        self::assertSame('s1', $loaded->getState());
        self::assertSame('n1', $loaded->getNonce());
        self::assertSame('v1', $loaded->getCodeVerifier());

        // Replay protection: a second consume returns null.
        self::assertNull($this->storage->consume('s1'));
    }

    public function testIssuerRoundTrips(): void
    {
        $this->storage->save(new AuthorizationState('s1', 'n1', 'v1', 'https://idp.example'));

        self::assertSame('https://idp.example', $this->storage->consume('s1')?->getIssuer());
    }

    public function testExpiredStateReturnsNull(): void
    {
        $this->storage->save($this->state('s1', 'n1', 'v1'));
        $this->now += PendingEntries::TTL - 1;
        $this->storage->save($this->state('s2', 'n2', 'v2'));
        $this->now += 1;

        self::assertNull($this->storage->consume('s1'));
        self::assertSame('n2', $this->storage->consume('s2')?->getNonce());
    }

    public function testStateWithoutTimestampIsTreatedAsExpired(): void
    {
        $this->store['dmlab_customer_sso_auth_states'] = ['s1' => ['nonce' => 'n1', 'code_verifier' => 'v1']];

        self::assertNull($this->storage->consume('s1'));
    }

    public function testKeepsOnlyTheNewestPendingStates(): void
    {
        for ($i = 0; $i <= PendingEntries::MAX_ENTRIES; $i++) {
            $this->storage->save($this->state('s' . $i, 'n' . $i, 'v' . $i));
        }

        self::assertCount(PendingEntries::MAX_ENTRIES, $this->store['dmlab_customer_sso_auth_states']);
        self::assertNull($this->storage->consume('s0'));
        self::assertNotNull($this->storage->consume('s' . PendingEntries::MAX_ENTRIES));
    }

    public function testConsumeUnknownStateReturnsNull(): void
    {
        self::assertNull($this->storage->consume('missing'));
    }

    public function testConcurrentStatesDoNotClobberEachOther(): void
    {
        $this->storage->save($this->state('s1', 'n1', 'v1'));
        $this->storage->save($this->state('s2', 'n2', 'v2'));

        $second = $this->storage->consume('s2');
        self::assertNotNull($second);
        self::assertSame('n2', $second->getNonce());

        // Consuming s2 leaves s1 intact.
        $first = $this->storage->consume('s1');
        self::assertNotNull($first);
        self::assertSame('v1', $first->getCodeVerifier());
    }
}
