<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Model;

use DmLab\SsoCore\Api\Data\IdentityInterface;
use Magento\Customer\Model\Config\Share;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Link an IdP identity to an already signed-in customer (My Account link mode).
 *
 * The storefront session proves account ownership, so the IdP's email-verified flag
 * is not consulted here — unlike email adoption in {@see CustomerProvisioner}.
 */
class IdentityLinker
{
    /**
     * @param SubjectLink $subjectLink
     * @param ActiveProviderResolver $activeProviderResolver
     * @param StoreManagerInterface $storeManager
     * @param Share $shareConfig
     */
    public function __construct(
        private readonly SubjectLink $subjectLink,
        private readonly ActiveProviderResolver $activeProviderResolver,
        private readonly StoreManagerInterface $storeManager,
        private readonly Share $shareConfig
    ) {
    }

    /**
     * Bind the identity to the customer under the active provider; a no-op when already bound.
     *
     * @param int $customerId signed-in customer
     * @param IdentityInterface $identity verified identity from sso-core
     * @throws LocalizedException when no provider is active, the identity belongs to another
     *         customer, or the customer already holds a different identity for the provider
     */
    public function link(int $customerId, IdentityInterface $identity): void
    {
        $store = $this->storeManager->getStore();
        $providerCode = (string)$this->activeProviderResolver->getActiveCode((int)$store->getId());
        if ($providerCode === '') {
            throw new LocalizedException(__('Single sign-on is not configured for this store.'));
        }

        $subjectId = $identity->getSubjectId();
        $ownerId = $this->subjectLink->findCustomerIdBySubject(
            $providerCode,
            $identity->getIssuer(),
            $subjectId,
            $this->shareConfig->isWebsiteScope() ? (int)$store->getWebsiteId() : null
        );
        if ($ownerId === $customerId) {
            return;
        }
        if ($ownerId !== null) {
            throw new LocalizedException(__('This sign-in identity is already linked to another account.'));
        }

        if ($this->subjectLink->getSubjectForProvider($customerId, $providerCode) !== null) {
            throw new LocalizedException(
                __('Your account is already linked to another identity from this provider. Unlink it first.')
            );
        }

        $this->subjectLink->link($customerId, $providerCode, $identity->getIssuer(), $subjectId);
    }
}
