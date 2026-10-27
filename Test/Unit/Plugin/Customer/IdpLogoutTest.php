<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Test\Unit\Plugin\Customer;

use DmLab\CustomerSso\Model\Oidc\LogoutUrlResolver;
use DmLab\CustomerSso\Model\Session\IdpSessionStorage;
use DmLab\CustomerSso\Plugin\Customer\IdpLogout;
use Magento\Customer\Controller\Account\Logout;
use Magento\Framework\Controller\Result\Redirect;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class IdpLogoutTest extends TestCase
{
    /** @var Redirect&MockObject */
    private $redirect;

    protected function setUp(): void
    {
        $this->redirect = $this->createMock(Redirect::class);
    }

    /**
     * @param IdpSessionStorage $storage
     * @param LogoutUrlResolver $resolver
     * @param string[] $order
     */
    private function execute(IdpSessionStorage $storage, LogoutUrlResolver $resolver, array &$order = []): mixed
    {
        $proceed = function () use (&$order) {
            $order[] = 'logout';
            return $this->redirect;
        };

        return (new IdpLogout($storage, $resolver))->aroundExecute($this->createStub(Logout::class), $proceed);
    }

    public function testRedirectsToIdpAfterLocalLogout(): void
    {
        $order = [];
        $storage = $this->createMock(IdpSessionStorage::class);
        $storage->method('getIdToken')->willReturnCallback(function () use (&$order) {
            $order[] = 'read';
            return 'raw-id-token';
        });
        $storage->method('getProviderCode')->willReturn('okta');
        $storage->expects(self::once())->method('clear')->willReturnCallback(function () use (&$order): void {
            $order[] = 'clear';
        });
        $resolver = $this->createMock(LogoutUrlResolver::class);
        $resolver->expects(self::once())
            ->method('resolve')
            ->with('raw-id-token', 'okta')
            ->willReturn('https://idp.example/logout?id_token_hint=raw-id-token');
        $this->redirect->expects(self::once())
            ->method('setUrl')
            ->with('https://idp.example/logout?id_token_hint=raw-id-token');

        self::assertSame($this->redirect, $this->execute($storage, $resolver, $order));
        // Read while signed in; cleared because customer logout keeps session storage.
        self::assertSame(['read', 'logout', 'clear'], $order);
    }

    public function testKeepsCoreRedirectWhenResolverDeclines(): void
    {
        $storage = $this->createStub(IdpSessionStorage::class);
        $storage->method('getIdToken')->willReturn('raw-id-token');
        $storage->method('getProviderCode')->willReturn('okta');
        $resolver = $this->createStub(LogoutUrlResolver::class);
        $resolver->method('resolve')->willReturn(null);
        $this->redirect->expects(self::never())->method('setUrl');

        self::assertSame($this->redirect, $this->execute($storage, $resolver));
    }

    public function testNonSsoSessionLogsOutLocally(): void
    {
        $storage = $this->createMock(IdpSessionStorage::class);
        $storage->method('getIdToken')->willReturn(null);
        $storage->expects(self::once())->method('clear');
        $resolver = $this->createMock(LogoutUrlResolver::class);
        $resolver->expects(self::never())->method('resolve');
        $this->redirect->expects(self::never())->method('setUrl');

        self::assertSame($this->redirect, $this->execute($storage, $resolver));
    }
}
