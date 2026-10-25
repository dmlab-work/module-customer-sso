<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Test\Unit\Controller\Account;

use DmLab\CustomerSso\Controller\Account\Unlink;
use DmLab\CustomerSso\Model\SubjectLink;
use Magento\Customer\Controller\AccountInterface;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class UnlinkTest extends TestCase
{
    /** @var Redirect&MockObject */
    private $redirect;

    /** @var Session&Stub */
    private $customerSession;

    /** @var SubjectLink&MockObject */
    private $subjectLink;

    /** @var ManagerInterface&MockObject */
    private $messageManager;

    /** @var array<string,string> */
    private array $params = [];

    /** @var Unlink */
    private Unlink $controller;

    protected function setUp(): void
    {
        $this->redirect = $this->createMock(Redirect::class);
        $redirectFactory = $this->createStub(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($this->redirect);

        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(fn(string $name) => $this->params[$name] ?? null);

        $this->customerSession = $this->createStub(Session::class);
        $this->subjectLink = $this->createMock(SubjectLink::class);
        $this->messageManager = $this->createMock(ManagerInterface::class);

        $this->controller = new Unlink(
            $request,
            $this->customerSession,
            $this->subjectLink,
            $redirectFactory,
            $this->messageManager
        );
    }

    public function testRemovesSessionCustomersOwnLink(): void
    {
        $this->customerSession->method('getCustomerId')->willReturn('42');
        $this->params['provider_code'] = ' okta ';

        $this->subjectLink->expects(self::once())->method('unlink')->with(42, 'okta')->willReturn(true);
        $this->messageManager->expects(self::once())->method('addSuccessMessage');
        $this->messageManager->expects(self::never())->method('addErrorMessage');
        $this->redirect->expects(self::once())->method('setPath')->with('customersso/account')->willReturnSelf();

        self::assertInstanceOf(HttpPostActionInterface::class, $this->controller);
        self::assertNotInstanceOf(AccountInterface::class, $this->controller);
        self::assertSame($this->redirect, $this->controller->execute());
    }

    public function testUnknownProviderShowsError(): void
    {
        $this->customerSession->method('getCustomerId')->willReturn(42);
        $this->params['provider_code'] = 'google';

        $this->subjectLink->expects(self::once())->method('unlink')->with(42, 'google')->willReturn(false);
        $this->messageManager->expects(self::never())->method('addSuccessMessage');
        $this->messageManager->expects(self::once())
            ->method('addErrorMessage')
            ->with(self::callback(
                static fn($message): bool => (string)$message === 'No single sign-on link was found for this provider.'
            ));
        $this->redirect->expects(self::once())->method('setPath')->with('customersso/account')->willReturnSelf();

        $this->controller->execute();
    }

    public function testAnonymousSessionIsRefused(): void
    {
        $this->customerSession->method('getCustomerId')->willReturn(null);
        $this->params['provider_code'] = 'okta';

        $this->subjectLink->expects(self::never())->method('unlink');
        $this->messageManager->expects(self::once())->method('addErrorMessage');
        $this->redirect->expects(self::once())->method('setPath')->with('customer/account/login')->willReturnSelf();

        $this->controller->execute();
    }
}
