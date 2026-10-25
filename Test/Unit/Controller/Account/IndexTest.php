<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Test\Unit\Controller\Account;

use DmLab\CustomerSso\Controller\Account\Index;
use Magento\Customer\Controller\AccountInterface;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use PHPUnit\Framework\TestCase;

class IndexTest extends TestCase
{
    public function testReturnsResultPage(): void
    {
        $page = $this->createStub(Page::class);
        $pageFactory = $this->createMock(PageFactory::class);
        $pageFactory->expects(self::once())->method('create')->willReturn($page);
        $session = $this->createStub(Session::class);
        $session->method('authenticate')->willReturn(true);

        $controller = new Index($pageFactory, $session, $this->createStub(ResponseInterface::class));

        self::assertInstanceOf(HttpGetActionInterface::class, $controller);
        self::assertSame($page, $controller->execute());
    }

    public function testGuestGetsLoginRedirectResponse(): void
    {
        $pageFactory = $this->createMock(PageFactory::class);
        $pageFactory->expects(self::never())->method('create');
        $session = $this->createMock(Session::class);
        $session->expects(self::once())->method('authenticate')->willReturn(false);
        $response = $this->createStub(ResponseInterface::class);

        $controller = new Index($pageFactory, $session, $response);

        self::assertSame($response, $controller->execute());
    }

    public function testIsNotGatedByCoreAccountPlugin(): void
    {
        // Core's AccountInterface plugin returns null for guests, which breaks non-Action controllers.
        $controller = new Index(
            $this->createStub(PageFactory::class),
            $this->createStub(Session::class),
            $this->createStub(ResponseInterface::class)
        );

        self::assertNotInstanceOf(AccountInterface::class, $controller);
    }
}
