<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Test\Unit\Model;

use DmLab\CustomerSso\Model\ActiveProviderResolver;
use DmLab\CustomerSso\Model\IdentityLinker;
use DmLab\CustomerSso\Model\SubjectLink;
use DmLab\SsoCore\Model\Data\Identity;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class IdentityLinkerTest extends TestCase
{
    private const CUSTOMER_ID = 42;

    /** @var SubjectLink&MockObject */
    private $subjectLink;

    /** @var ActiveProviderResolver&MockObject */
    private $resolver;

    /** @var IdentityLinker */
    private IdentityLinker $linker;

    protected function setUp(): void
    {
        $this->subjectLink = $this->createMock(SubjectLink::class);
        $this->resolver = $this->createMock(ActiveProviderResolver::class);

        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(3);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $this->linker = new IdentityLinker($this->subjectLink, $this->resolver, $storeManager);
    }

    private function withProvider(?string $code): void
    {
        $this->resolver->expects(self::once())->method('getActiveCode')->with(3)->willReturn($code);
    }

    public function testLinksNewIdentityUnderActiveProvider(): void
    {
        $this->withProvider('okta');
        $this->subjectLink->method('findCustomerIdBySubject')->with('okta', 'sub-1')->willReturn(null);
        $this->subjectLink->method('getSubjectForProvider')->with(self::CUSTOMER_ID, 'okta')->willReturn(null);
        $this->subjectLink->expects(self::once())->method('link')->with(self::CUSTOMER_ID, 'okta', 'sub-1');

        $this->linker->link(self::CUSTOMER_ID, new Identity('sub-1', 'a@example.com', null, [], true));
    }

    public function testLinksWhenEmailIsNotVerified(): void
    {
        $this->withProvider('okta');
        $this->subjectLink->method('findCustomerIdBySubject')->willReturn(null);
        $this->subjectLink->method('getSubjectForProvider')->willReturn(null);
        $this->subjectLink->expects(self::once())->method('link')->with(self::CUSTOMER_ID, 'okta', 'sub-1');

        $this->linker->link(self::CUSTOMER_ID, new Identity('sub-1', 'a@example.com', null, [], false));
    }

    public function testIsIdempotentForTheSamePair(): void
    {
        $this->withProvider('okta');
        $this->subjectLink->method('findCustomerIdBySubject')->with('okta', 'sub-1')->willReturn(self::CUSTOMER_ID);
        $this->subjectLink->expects(self::never())->method('link');

        $this->linker->link(self::CUSTOMER_ID, new Identity('sub-1'));
    }

    public function testRefusesIdentityOwnedByAnotherCustomer(): void
    {
        $this->withProvider('okta');
        $this->subjectLink->method('findCustomerIdBySubject')->willReturn(7);
        $this->subjectLink->expects(self::never())->method('link');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('This sign-in identity is already linked to another account.');

        $this->linker->link(self::CUSTOMER_ID, new Identity('sub-1'));
    }

    public function testRefusesOtherSubjectForTheSameProvider(): void
    {
        $this->withProvider('okta');
        $this->subjectLink->method('findCustomerIdBySubject')->willReturn(null);
        $this->subjectLink->method('getSubjectForProvider')->with(self::CUSTOMER_ID, 'okta')->willReturn('sub-old');
        $this->subjectLink->expects(self::never())->method('link');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(
            'Your account is already linked to another identity from this provider. Unlink it first.'
        );

        $this->linker->link(self::CUSTOMER_ID, new Identity('sub-1'));
    }

    public function testRefusesWithoutActiveProvider(): void
    {
        $this->withProvider(null);
        $this->subjectLink->expects(self::never())->method('findCustomerIdBySubject');
        $this->subjectLink->expects(self::never())->method('link');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Single sign-on is not configured for this store.');

        $this->linker->link(self::CUSTOMER_ID, new Identity('sub-1'));
    }
}
