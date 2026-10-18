<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Test\Unit\Model;

use DmLab\CustomerSso\Model\CustomerSessionCreator;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\Session;
use Magento\Framework\Stdlib\Cookie\CookieMetadata;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CustomerSessionCreatorTest extends TestCase
{
    /** @var Session&MockObject */
    private $customerSession;

    /** @var CookieManagerInterface&MockObject */
    private $cookieManager;

    /** @var CookieMetadataFactory&MockObject */
    private $cookieMetadataFactory;

    /** @var CustomerSessionCreator */
    private CustomerSessionCreator $creator;

    protected function setUp(): void
    {
        $this->customerSession = $this->createMock(Session::class);
        $this->cookieManager = $this->createMock(CookieManagerInterface::class);
        $this->cookieMetadataFactory = $this->createMock(CookieMetadataFactory::class);
        $this->creator = new CustomerSessionCreator(
            $this->customerSession,
            $this->cookieManager,
            $this->cookieMetadataFactory
        );
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testCreateEstablishesLoggedInSession(): void
    {
        $customer = $this->createStub(CustomerInterface::class);

        $this->customerSession->expects(self::once())
            ->method('setCustomerDataAsLoggedIn')
            ->with($customer);

        $this->creator->create($customer);
    }

    public function testDeletesPrivateContentCookieWhenPresent(): void
    {
        $metadata = new CookieMetadata();
        $this->customerSession->expects(self::once())->method('setCustomerDataAsLoggedIn');
        $this->cookieManager->method('getCookie')->with('mage-cache-sessid')->willReturn('1');
        $this->cookieMetadataFactory->expects(self::once())
            ->method('createCookieMetadata')
            ->willReturn($metadata);
        $this->cookieManager->expects(self::once())
            ->method('deleteCookie')
            ->with('mage-cache-sessid', self::callback(
                static fn (CookieMetadata $actual): bool => $actual === $metadata && $actual->getPath() === '/'
            ));

        $this->creator->create($this->createStub(CustomerInterface::class));
    }

    public function testLeavesCookiesAloneWhenPrivateContentCookieAbsent(): void
    {
        $this->customerSession->expects(self::once())->method('setCustomerDataAsLoggedIn');
        $this->cookieManager->method('getCookie')->with('mage-cache-sessid')->willReturn(null);
        $this->cookieMetadataFactory->expects(self::never())->method('createCookieMetadata');
        $this->cookieManager->expects(self::never())->method('deleteCookie');

        $this->creator->create($this->createStub(CustomerInterface::class));
    }
}
