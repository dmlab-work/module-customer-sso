<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Test\Unit\Controller\Account;

use DmLab\CustomerSso\Controller\Account\Index;
use Magento\Customer\Controller\AccountInterface;
use Magento\Framework\App\Action\HttpGetActionInterface;
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

        $controller = new Index($pageFactory);

        self::assertInstanceOf(HttpGetActionInterface::class, $controller);
        self::assertInstanceOf(AccountInterface::class, $controller);
        self::assertSame($page, $controller->execute());
    }
}
