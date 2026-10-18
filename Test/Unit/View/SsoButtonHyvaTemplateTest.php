<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Test\Unit\View;

use DmLab\CustomerSso\Block\Login\Sso;
use Magento\Framework\Escaper;
use PHPUnit\Framework\TestCase;

class SsoButtonHyvaTemplateTest extends TestCase
{
    private const TEMPLATE = __DIR__ . '/../../../view/frontend/templates/login/sso-button-hyva.phtml';

    private function render(Sso $block): string
    {
        $escaper = $this->createStub(Escaper::class);
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES);
        $escaper->method('escapeUrl')->willReturnCallback(static fn ($url): string => 'url:' . $escape((string)$url));
        $escaper->method('escapeHtml')
            ->willReturnCallback(static fn ($html): string => 'html:' . $escape((string)$html));

        return (static function (Sso $block, Escaper $escaper): string {
            ob_start();
            include self::TEMPLATE;

            return (string)ob_get_clean();
        })($block, $escaper);
    }

    private function block(bool $available, ?string $iconUrl = null): Sso
    {
        $block = $this->createStub(Sso::class);
        $block->method('isAvailable')->willReturn($available);
        $block->method('getStartUrl')->willReturn('https://shop.test/customersso/sso/start/?a=1&b=2');
        $block->method('getButtonLabel')->willReturn('Sign in with <Okta>');
        $block->method('getButtonIconUrl')->willReturn($iconUrl);

        return $block;
    }

    public function testAvailableRendersEscapedLinkWithIcon(): void
    {
        $html = $this->render($this->block(true, 'https://cdn.test/okta.svg?"x'));

        self::assertStringContainsString(
            'href="url:https://shop.test/customersso/sso/start/?a=1&amp;b=2"',
            $html
        );
        self::assertStringContainsString('src="url:https://cdn.test/okta.svg?&quot;x"', $html);
        self::assertStringContainsString('<span>html:Sign in with &lt;Okta&gt;</span>', $html);
        self::assertStringContainsString('class="btn btn-secondary w-full justify-center gap-2"', $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('x-data', $html);
    }

    public function testAvailableWithoutIconOmitsImage(): void
    {
        $html = $this->render($this->block(true));

        self::assertStringContainsString('<a class="btn', $html);
        self::assertStringNotContainsString('<img', $html);
    }

    public function testUnavailableRendersNothing(): void
    {
        self::assertSame('', trim($this->render($this->block(false, 'https://cdn.test/okta.svg'))));
    }
}
