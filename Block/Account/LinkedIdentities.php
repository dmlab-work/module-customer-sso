<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Block\Account;

use DmLab\CustomerSso\Model\ActiveProviderResolver;
use DmLab\CustomerSso\Model\Config;
use DmLab\CustomerSso\Model\PresetRegistry;
use DmLab\CustomerSso\Model\SubjectLink;
use Magento\Customer\Model\Session;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

/**
 * My Account → Single Sign-On: the signed-in customer's linked identities plus the
 * link/unlink form targets.
 */
class LinkedIdentities extends Template
{
    private const LINK_ROUTE = 'customersso/account/link';
    private const UNLINK_ROUTE = 'customersso/account/unlink';

    /** @var list<array{provider_code:string,label:string,created_at:string}>|null */
    private ?array $links = null;

    /**
     * @param Context $context
     * @param Session $customerSession
     * @param SubjectLink $subjectLink
     * @param PresetRegistry $presetRegistry
     * @param ActiveProviderResolver $activeProviderResolver
     * @param Config $config
     * @param array<string,mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly Session $customerSession,
        private readonly SubjectLink $subjectLink,
        private readonly PresetRegistry $presetRegistry,
        private readonly ActiveProviderResolver $activeProviderResolver,
        private readonly Config $config,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Current customer's links with a display label per provider.
     *
     * @return list<array{provider_code:string,label:string,created_at:string}>
     */
    public function getLinks(): array
    {
        if ($this->links === null) {
            $this->links = [];
            foreach ($this->subjectLink->getLinksByCustomerId($this->getCustomerId()) as $link) {
                $this->links[] = [
                    'provider_code' => $link['provider_code'],
                    'label' => $this->getProviderLabel($link['provider_code']),
                    'created_at' => $link['created_at'],
                ];
            }
        }

        return $this->links;
    }

    /**
     * Registered preset label for the provider code, else the code itself (plugin removed).
     *
     * @param string $providerCode
     * @return string
     */
    public function getProviderLabel(string $providerCode): string
    {
        return $this->presetRegistry->has($providerCode)
            ? $this->presetRegistry->get($providerCode)->getLabel()
            : $providerCode;
    }

    /**
     * Whether the customer can link the active provider.
     *
     * True when SSO is enabled, a registered provider is active and nothing is linked for it yet.
     *
     * @return bool
     */
    public function canLink(): bool
    {
        if (!$this->config->isEnabled() || $this->activeProviderResolver->getActive() === null) {
            return false;
        }

        $activeCode = (string)$this->activeProviderResolver->getActiveCode();
        foreach ($this->getLinks() as $link) {
            if ($link['provider_code'] === $activeCode) {
                return false;
            }
        }

        return true;
    }

    /**
     * Label of the active provider, or empty when none is active.
     *
     * @return string
     */
    public function getActiveProviderLabel(): string
    {
        $preset = $this->activeProviderResolver->getActive();

        return $preset === null ? '' : $preset->getLabel();
    }

    /**
     * POST target that starts linking.
     *
     * @return string
     */
    public function getLinkUrl(): string
    {
        return $this->getUrl(self::LINK_ROUTE);
    }

    /**
     * POST target that removes a link.
     *
     * @return string
     */
    public function getUnlinkUrl(): string
    {
        return $this->getUrl(self::UNLINK_ROUTE);
    }

    /**
     * Signed-in customer id, 0 when anonymous.
     *
     * @return int
     */
    private function getCustomerId(): int
    {
        return (int)$this->customerSession->getCustomerId();
    }
}
