<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Test\Unit\View;

use DmLab\CustomerSso\Block\Account\LinkedIdentities;
use DmLab\CustomerSso\Controller\Account\Unlink;
use Magento\Framework\Escaper;
use PHPUnit\Framework\TestCase;

class AccountIdentitiesTemplateTest extends TestCase
{
    private const TEMPLATE = __DIR__ . '/../../../view/frontend/templates/account/identities.phtml';
    private const FORM_KEY = '<input name="form_key" type="hidden" value="fk"/>';

    private function render(LinkedIdentities $block): string
    {
        $escaper = $this->createStub(Escaper::class);
        $escape = static fn ($value): string => htmlspecialchars((string)$value, ENT_QUOTES);
        $escaper->method('escapeUrl')->willReturnCallback(static fn ($url): string => 'url:' . $escape($url));
        $escaper->method('escapeHtml')->willReturnCallback(static fn ($html): string => 'html:' . $escape($html));
        $escaper->method('escapeHtmlAttr')->willReturnCallback(static fn ($attr): string => 'attr:' . $escape($attr));

        return (static function (LinkedIdentities $block, Escaper $escaper): string {
            ob_start();
            include self::TEMPLATE;

            return (string)ob_get_clean();
        })($block, $escaper);
    }

    /**
     * @param list<array{provider_code:string,label:string,created_at:string}> $links
     */
    private function block(array $links, bool $canLink): LinkedIdentities
    {
        $block = $this->createStub(LinkedIdentities::class);
        $block->method('getLinks')->willReturn($links);
        $block->method('canLink')->willReturn($canLink);
        $block->method('getActiveProviderLabel')->willReturn('Okta');
        $block->method('getLinkUrl')->willReturn('https://shop.test/customersso/account/link/');
        $block->method('getUnlinkUrl')->willReturn('https://shop.test/customersso/account/unlink/');
        $block->method('formatDate')->willReturnArgument(0);
        $block->method('getBlockHtml')->willReturnMap([['formkey', self::FORM_KEY]]);

        return $block;
    }

    public function testLinkedIdentityRendersEscapedUnlinkFormWithFormKey(): void
    {
        $html = $this->render($this->block(
            [['provider_code' => 'ok"ta', 'label' => '<Okta>', 'created_at' => '2026-10-01 10:00:00']],
            false
        ));

        self::assertStringContainsString('action="url:https://shop.test/customersso/account/unlink/"', $html);
        self::assertMatchesRegularExpression(
            '/name="' . Unlink::PARAM_PROVIDER_CODE . '"\s+value="attr:ok&quot;ta"/',
            $html
        );
        self::assertStringContainsString('html:&lt;Okta&gt;', $html);
        self::assertSame(1, substr_count($html, self::FORM_KEY));
        self::assertStringNotContainsString('customersso/account/link/', $html);
    }

    public function testLinkFormRendersWithFormKeyWhenLinkingIsPossible(): void
    {
        $html = $this->render($this->block([], true));

        self::assertStringContainsString('action="url:https://shop.test/customersso/account/link/"', $html);
        self::assertStringContainsString('html:Link Okta', $html);
        self::assertSame(1, substr_count($html, self::FORM_KEY));
        self::assertStringContainsString('html:No single sign-on identity is linked to your account.', $html);
    }

    public function testNoFormsWithoutLinksOrLinkableProvider(): void
    {
        $html = $this->render($this->block([], false));

        self::assertStringNotContainsString('<form', $html);
        self::assertStringContainsString('html:No single sign-on identity is linked to your account.', $html);
    }
}
