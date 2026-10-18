<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Test\Unit\Block\Account;

use DmLab\CustomerSso\Block\Account\LinkedIdentities;
use DmLab\CustomerSso\Model\ActiveProviderResolver;
use DmLab\CustomerSso\Model\Config;
use DmLab\CustomerSso\Model\PresetRegistry;
use DmLab\CustomerSso\Model\SubjectLink;
use DmLab\SsoCore\Api\ProviderPresetInterface;
use Magento\Customer\Model\Session;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Template\Context;
use PHPUnit\Framework\TestCase;

class LinkedIdentitiesTest extends TestCase
{
    private function preset(string $code, string $label): ProviderPresetInterface
    {
        $preset = $this->createStub(ProviderPresetInterface::class);
        $preset->method('getCode')->willReturn($code);
        $preset->method('getLabel')->willReturn($label);

        return $preset;
    }

    /**
     * @param list<array{provider_code:string,subject_id:string,created_at:string}> $links
     */
    private function block(
        array $links,
        bool $enabled = true,
        ?string $activeCode = 'okta',
        ?UrlInterface $url = null
    ): LinkedIdentities {
        $session = $this->createStub(Session::class);
        $session->method('getCustomerId')->willReturn('42');

        $subjectLink = $this->createMock(SubjectLink::class);
        $subjectLink->expects(self::atMost(1))
            ->method('getLinksByCustomerId')
            ->with(42)
            ->willReturn($links);

        $registry = new PresetRegistry([
            $this->preset('okta', 'Okta'),
            $this->preset('google', 'Google'),
        ]);

        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn($enabled);

        $resolver = $this->createStub(ActiveProviderResolver::class);
        $resolver->method('getActiveCode')->willReturn($activeCode);
        $resolver->method('getActive')->willReturn(
            $activeCode !== null && $registry->has($activeCode) ? $registry->get($activeCode) : null
        );

        $context = $this->createStub(Context::class);
        $context->method('getUrlBuilder')->willReturn($url ?? $this->createStub(UrlInterface::class));

        return (new ObjectManager($this))->getObject(LinkedIdentities::class, [
            'context' => $context,
            'customerSession' => $session,
            'subjectLink' => $subjectLink,
            'presetRegistry' => $registry,
            'activeProviderResolver' => $resolver,
            'config' => $config,
        ]);
    }

    public function testListsLinksWithPresetLabelFallingBackToCode(): void
    {
        $block = $this->block([
            ['provider_code' => 'okta', 'subject_id' => 'sub-1', 'created_at' => '2026-10-01 10:00:00'],
            ['provider_code' => 'removed', 'subject_id' => 'sub-2', 'created_at' => '2026-10-02 10:00:00'],
        ]);

        self::assertSame([
            ['provider_code' => 'okta', 'label' => 'Okta', 'created_at' => '2026-10-01 10:00:00'],
            ['provider_code' => 'removed', 'label' => 'removed', 'created_at' => '2026-10-02 10:00:00'],
        ], $block->getLinks());
        // Memoized: the storage is read once.
        $block->getLinks();
    }

    public function testCanLinkWhenActiveProviderNotLinkedYet(): void
    {
        $block = $this->block([
            ['provider_code' => 'google', 'subject_id' => 'g-1', 'created_at' => '2026-10-01 10:00:00'],
        ]);

        self::assertTrue($block->canLink());
        self::assertSame('Okta', $block->getActiveProviderLabel());
    }

    public function testCannotLinkWhenActiveProviderAlreadyLinked(): void
    {
        $block = $this->block([
            ['provider_code' => 'okta', 'subject_id' => 'sub-1', 'created_at' => '2026-10-01 10:00:00'],
        ]);

        self::assertFalse($block->canLink());
    }

    public function testCannotLinkWhenDisabled(): void
    {
        self::assertFalse($this->block([], false)->canLink());
    }

    public function testCannotLinkWithoutActiveProvider(): void
    {
        $block = $this->block([], true, null);

        self::assertFalse($block->canLink());
        self::assertSame('', $block->getActiveProviderLabel());
    }

    public function testCannotLinkWhenActiveProviderNotRegistered(): void
    {
        self::assertFalse($this->block([], true, 'azure')->canLink());
    }

    public function testFormUrlsBuiltFromRoutes(): void
    {
        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(
            static fn(string $route): string => 'https://magento.loc/' . $route . '/'
        );
        $block = $this->block([], true, 'okta', $url);

        self::assertSame('https://magento.loc/customersso/account/link/', $block->getLinkUrl());
        self::assertSame('https://magento.loc/customersso/account/unlink/', $block->getUnlinkUrl());
    }
}
