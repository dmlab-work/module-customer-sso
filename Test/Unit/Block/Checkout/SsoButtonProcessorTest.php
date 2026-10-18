<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Test\Unit\Block\Checkout;

use DmLab\CustomerSso\Block\Checkout\SsoButtonProcessor;
use DmLab\CustomerSso\Controller\Sso\Start;
use DmLab\CustomerSso\Model\ActiveProviderResolver;
use DmLab\CustomerSso\Model\Config;
use DmLab\CustomerSso\Model\Oidc\AuthorizationStarter;
use DmLab\CustomerSso\Model\SsoIntent;
use DmLab\SsoCore\Api\ProviderPresetInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Phrase;
use Magento\Framework\Phrase\RendererInterface;
use Magento\Framework\Url\Decoder;
use Magento\Framework\Url\Encoder;
use Magento\Framework\Url\HostChecker;
use Magento\Framework\Url\ScopeInterface;
use Magento\Framework\Url\ScopeResolverInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\Validator\Url as UrlValidator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SsoButtonProcessorTest extends TestCase
{
    private const POPUP_PATH = 'components/authenticationPopup/children';
    private const CHECKOUT_URL = 'https://magento.loc/checkout/';

    /** @var RendererInterface Global phrase renderer restored after each test. */
    private RendererInterface $previousRenderer;

    /** @var array<string,string> Params of the last start-URL build. */
    private array $startParams = [];

    protected function setUp(): void
    {
        $this->previousRenderer = Phrase::getRenderer();
    }

    protected function tearDown(): void
    {
        Phrase::setRenderer($this->previousRenderer);
    }

    private function processor(bool $enabled, bool $withProvider, string $path = self::POPUP_PATH): SsoButtonProcessor
    {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);

        $preset = $this->createStub(ProviderPresetInterface::class);
        $preset->method('getButtonLabel')->willReturn('Sign in with Okta');
        $preset->method('getButtonIconUrl')->willReturn('https://cdn/okta.svg');

        $resolver = $this->createStub(ActiveProviderResolver::class);
        $resolver->method('getActive')->willReturn($withProvider ? $preset : null);

        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(function (string $route, ?array $params = null): string {
            if ($route === 'checkout') {
                return self::CHECKOUT_URL;
            }
            $this->startParams = $params ?? [];
            return 'https://magento.loc/' . $route . '/referer/' . ($params[Start::PARAM_REFERER] ?? '') . '/';
        });

        return new SsoButtonProcessor($config, $resolver, $url, new Encoder(), $path);
    }

    /**
     * @return array<string,mixed>
     */
    private function popupLayout(): array
    {
        return [
            'components' => [
                'authenticationPopup' => [
                    'component' => 'Magento_Customer/js/view/authentication-popup',
                    'children' => [
                        'messages' => ['component' => 'Magento_Ui/js/view/messages', 'displayArea' => 'messages'],
                    ],
                ],
            ],
        ];
    }

    public function testInjectsButtonAtPathWhenAvailable(): void
    {
        $result = $this->processor(true, true)->process($this->popupLayout());

        $children = $result['components']['authenticationPopup']['children'];
        self::assertArrayHasKey('messages', $children);
        $button = $children[SsoButtonProcessor::CHILD_NAME];
        self::assertSame('DmLab_CustomerSso/js/view/sso-button', $button['component']);
        self::assertSame('before', $button['displayArea']);
        self::assertSame('Sign in with Okta', $button['config']['label']);
        self::assertSame('https://cdn/okta.svg', $button['config']['iconUrl']);
        self::assertStringStartsWith('https://magento.loc/customersso/sso/start/', $button['config']['startUrl']);
        self::assertSame(
            self::CHECKOUT_URL,
            base64_decode(strtr($this->startParams[Start::PARAM_REFERER], '-_~', '+/='))
        );
    }

    public function testInjectsAtCheckoutAuthenticationPath(): void
    {
        $layout = ['components' => ['checkout' => ['children' => ['authentication' => ['children' => []]]]]];

        $result = $this->processor(true, true, 'components/checkout/children/authentication/children')
            ->process($layout);

        self::assertArrayHasKey(
            SsoButtonProcessor::CHILD_NAME,
            $result['components']['checkout']['children']['authentication']['children']
        );
    }

    public function testLabelIsTranslated(): void
    {
        $renderer = $this->createStub(RendererInterface::class);
        $renderer->method('render')->willReturnCallback(
            static fn (array $source): string => $source[0] === 'Sign in with Okta' ? 'Mit Okta anmelden' : $source[0]
        );
        Phrase::setRenderer($renderer);

        $result = $this->processor(true, true)->process($this->popupLayout());

        self::assertSame(
            'Mit Okta anmelden',
            $result['components']['authenticationPopup']['children'][SsoButtonProcessor::CHILD_NAME]['config']['label']
        );
    }

    public function testUntouchedWhenDisabled(): void
    {
        self::assertSame($this->popupLayout(), $this->processor(false, true)->process($this->popupLayout()));
    }

    public function testUntouchedWhenNoProvider(): void
    {
        self::assertSame($this->popupLayout(), $this->processor(true, false)->process($this->popupLayout()));
    }

    public function testUntouchedWhenPathMissing(): void
    {
        $processor = $this->processor(true, true, 'components/checkout/children/authentication/children');

        self::assertSame($this->popupLayout(), $processor->process($this->popupLayout()));
    }

    public function testRefererRoundTripsThroughStartValidation(): void
    {
        $this->processor(true, true)->process($this->popupLayout());

        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(
            fn (string $name) => $this->startParams[$name] ?? null
        );

        $scope = $this->createStub(ScopeInterface::class);
        $scope->method('getBaseUrl')->willReturn('https://magento.loc/');
        $scopeResolver = $this->createStub(ScopeResolverInterface::class);
        $scopeResolver->method('getScopes')->willReturn([$scope]);

        $captured = null;
        $starter = $this->createStub(AuthorizationStarter::class);
        $starter->method('start')->willReturnCallback(
            static function (SsoIntent $intent) use (&$captured): string {
                $captured = $intent;
                return 'https://idp.example/authorize';
            }
        );

        $redirect = $this->createStub(Redirect::class);
        $redirect->method('setUrl')->willReturnSelf();
        $redirectFactory = $this->createStub(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($redirect);

        $urlForDecoder = $this->createStub(UrlInterface::class);
        $urlForDecoder->method('sessionUrlVar')->willReturnArgument(0);

        (new Start(
            $starter,
            $request,
            new Decoder($urlForDecoder),
            new UrlValidator(),
            new HostChecker($scopeResolver),
            $redirectFactory,
            $this->createStub(ManagerInterface::class),
            $this->createStub(LoggerInterface::class)
        ))->execute();

        self::assertInstanceOf(SsoIntent::class, $captured);
        self::assertSame(self::CHECKOUT_URL, $captured->getReturnUrl());
    }
}
