<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Test\Unit\Model;

use DmLab\CustomerSso\Model\CustomerSessionCreator;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\Session;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CustomerSessionCreatorTest extends TestCase
{
    /** @var Session&MockObject */
    private $customerSession;

    /** @var CustomerSessionCreator */
    private CustomerSessionCreator $creator;

    protected function setUp(): void
    {
        $this->customerSession = $this->createMock(Session::class);
        $this->creator = new CustomerSessionCreator($this->customerSession);
    }

    public function testCreateEstablishesLoggedInSession(): void
    {
        $customer = $this->createStub(CustomerInterface::class);

        $this->customerSession->expects(self::once())
            ->method('setCustomerDataAsLoggedIn')
            ->with($customer);

        $this->creator->create($customer);
    }
}
