<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Test\Unit\Controller\Sso;

use DmLab\CustomerSso\Controller\Sso\Start;
use DmLab\CustomerSso\Model\Oidc\AuthorizationStarter;
use DmLab\CustomerSso\Model\SsoIntent;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Url\DecoderInterface;
use Magento\Framework\Url\HostChecker;
use Magento\Framework\Url\ScopeInterface;
use Magento\Framework\Url\ScopeResolverInterface;
use Magento\Framework\Validator\Url as UrlValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class StartTest extends TestCase
{
    /** @var Redirect&MockObject */
    private $redirect;

    /** @var ManagerInterface&MockObject */
    private $messageManager;

    /** @var LoggerInterface&MockObject */
    private $logger;

    /** @var AuthorizationStarter&Stub */
    private $starter;

    /** @var array<string,string> */
    private array $params = [];

    /** @var Start */
    private Start $controller;

    protected function setUp(): void
    {
        $this->redirect = $this->createMock(Redirect::class);

        $redirectFactory = $this->createStub(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($this->redirect);

        $this->messageManager = $this->createMock(ManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->starter = $this->createStub(AuthorizationStarter::class);

        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(fn(string $name) => $this->params[$name] ?? null);

        // Mirrors Magento\Framework\Url\Decoder minus the session-id rewrite.
        $decoder = $this->createStub(DecoderInterface::class);
        $decoder->method('decode')->willReturnCallback(
            static fn(string $url): string => (string)base64_decode(strtr($url, '-_~', '+/='))
        );

        $scope = $this->createStub(ScopeInterface::class);
        $scope->method('getBaseUrl')->willReturn('https://magento.loc/');
        $scopeResolver = $this->createStub(ScopeResolverInterface::class);
        $scopeResolver->method('getScopes')->willReturn([$scope]);

        $this->controller = new Start(
            $this->starter,
            $request,
            $decoder,
            new UrlValidator(),
            new HostChecker($scopeResolver),
            $redirectFactory,
            $this->messageManager,
            $this->logger
        );
    }

    /**
     * Run the controller and return the intent handed to the starter.
     */
    private function startedIntent(): SsoIntent
    {
        $captured = null;
        $this->starter->method('start')->willReturnCallback(
            static function (SsoIntent $intent) use (&$captured): string {
                $captured = $intent;
                return 'https://idp.example/authorize?state=abc';
            }
        );
        $this->redirect->expects(self::once())->method('setUrl')->willReturnSelf();
        $this->messageManager->expects(self::never())->method('addErrorMessage');
        $this->logger->expects(self::never())->method('critical');

        $this->controller->execute();
        self::assertInstanceOf(SsoIntent::class, $captured);

        return $captured;
    }

    public function testRedirectsToIdpUrl(): void
    {
        $this->starter->method('start')->willReturn('https://idp.example/authorize?state=abc');

        $this->redirect->expects(self::once())
            ->method('setUrl')
            ->with('https://idp.example/authorize?state=abc')
            ->willReturnSelf();
        $this->redirect->expects(self::never())->method('setPath');
        $this->messageManager->expects(self::never())->method('addErrorMessage');
        $this->logger->expects(self::never())->method('critical');

        self::assertSame($this->redirect, $this->controller->execute());
    }

    public function testStartsLoginIntentWithoutReturnUrlByDefault(): void
    {
        $intent = $this->startedIntent();

        self::assertSame(SsoIntent::MODE_LOGIN, $intent->getMode());
        self::assertNull($intent->getReturnUrl());
    }

    public function testKeepsOwnOriginReferer(): void
    {
        $this->params['referer'] = base64_encode('https://magento.loc/checkout/');

        $intent = $this->startedIntent();

        self::assertSame(SsoIntent::MODE_LOGIN, $intent->getMode());
        self::assertSame('https://magento.loc/checkout/', $intent->getReturnUrl());
    }

    /**
     * @return array<string,array{string}>
     */
    public static function rejectedReferers(): array
    {
        return [
            'foreign origin' => [base64_encode('https://evil.example/phish')],
            'protocol-relative foreign' => [base64_encode('//evil.example/phish')],
            'host-less path' => [base64_encode('/checkout/')],
            'javascript scheme' => [base64_encode('javascript:alert(1)')],
            'backslash host trick' => [base64_encode('https://magento.loc\\@evil.example/')],
            'malformed base64' => ['%%%not-base64%%%'],
            'binary garbage' => [base64_encode("\x8f\x00\xff")],
        ];
    }

    #[DataProvider('rejectedReferers')]
    public function testDropsUnsafeReferer(string $referer): void
    {
        $this->params['referer'] = $referer;

        self::assertNull($this->startedIntent()->getReturnUrl());
    }

    public function testRedirectsBackToLoginOnLocalizedError(): void
    {
        $this->starter->method('start')
            ->willThrowException(new LocalizedException(__('Customer SSO is disabled.')));

        $this->messageManager->expects(self::once())
            ->method('addErrorMessage')
            ->with('Customer SSO is disabled.');
        $this->redirect->expects(self::never())->method('setUrl');
        $this->redirect->expects(self::once())
            ->method('setPath')
            ->with('customer/account/login')
            ->willReturnSelf();
        $this->logger->expects(self::never())->method('critical');

        self::assertSame($this->redirect, $this->controller->execute());
    }

    public function testRedirectsBackToLoginAndLogsOnUnexpectedError(): void
    {
        $error = new \RuntimeException('discovery down');
        $this->starter->method('start')->willThrowException($error);

        $this->logger->expects(self::once())->method('critical')->with($error);
        $this->messageManager->expects(self::once())->method('addErrorMessage');
        $this->redirect->expects(self::never())->method('setUrl');
        $this->redirect->expects(self::once())
            ->method('setPath')
            ->with('customer/account/login')
            ->willReturnSelf();

        self::assertSame($this->redirect, $this->controller->execute());
    }

    public function testInvalidPresetParamsSurfaceAsGenericError(): void
    {
        $error = new \InvalidArgumentException('Reserved authorization param "state".');
        $this->starter->method('start')->willThrowException($error);

        $this->logger->expects(self::once())->method('critical')->with($error);
        $this->messageManager->expects(self::once())
            ->method('addErrorMessage')
            ->with(self::callback(static fn($message): bool => (string)$message
                === 'Could not start SSO sign-in. Please try again or contact the store owner.'));
        $this->redirect->expects(self::once())
            ->method('setPath')
            ->with('customer/account/login')
            ->willReturnSelf();

        self::assertSame($this->redirect, $this->controller->execute());
    }
}
