<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Model;

use DmLab\SsoCore\Api\Data\IdentityInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Customer\Model\Config\Share;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Resolve the storefront customer for a verified OIDC identity, creating one when
 * needed and keeping the IdP-subject link that makes re-login stable.
 *
 * Resolution order mirrors the suite contract:
 *  1. match by the stored IdP identity (issuer + `sub`) — the stable re-login key;
 *     with per-website accounts only links on the current website count;
 *  2. else match by email and adopt the existing customer, but only when the IdP
 *     verified the email, email linking is enabled, and the account is not bound to
 *     another identity of the same provider (takeover guard);
 *  3. else create a fresh `customer_entity` (JIT) and link it to the subject — only
 *     for an IdP-verified email, so an unverified identity cannot squat an address.
 *
 * Re-login by subject (1) ignores the verified flag.
 *
 * Provider-neutral — the identity is already normalized by sso-core, so no
 * IdP-specific claim handling lives here. Claim → customer-group mapping is applied
 * separately by {@see GroupAssigner}; this establishes the account and its subject
 * link only.
 *
 * The new customer carries no password: SSO is the entry point. Native password
 * login remains available only via the store's normal reset flow when the admin
 * keeps it enabled.
 *
 * Mirrors {@see \DmLab\AdminSso\Model\UserProvisioner} for the customer
 * domain; the subject link lives in {@see SubjectLink} rather than a table column
 * because customers are EAV/API entities.
 */
class CustomerProvisioner
{
    /** Lastname used when the IdP releases no name / only a single-word name. */
    private const DEFAULT_LAST_NAME = 'SSO';

    /**
     * @param CustomerInterfaceFactory $customerFactory
     * @param CustomerRepositoryInterface $customerRepository
     * @param StoreManagerInterface $storeManager
     * @param SubjectLink $subjectLink
     * @param Config $config
     * @param ActiveProviderResolver $activeProviderResolver
     * @param Share $shareConfig
     */
    public function __construct(
        private readonly CustomerInterfaceFactory $customerFactory,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly SubjectLink $subjectLink,
        private readonly Config $config,
        private readonly ActiveProviderResolver $activeProviderResolver,
        private readonly Share $shareConfig
    ) {
    }

    /**
     * Return the storefront customer for the given identity, creating one if needed.
     *
     * @param IdentityInterface $identity verified identity from sso-core
     * @return CustomerInterface the linked or newly created customer (persisted)
     * @throws LocalizedException when an existing email match may not be linked
     *         (email unverified, linking disabled, or bound to another subject), or
     *         when a new customer must be created but the email is missing or unverified
     * @throws \Magento\Framework\Exception\InputException
     * @throws \Magento\Framework\Exception\State\InputMismatchException
     */
    public function provision(IdentityInterface $identity): CustomerInterface
    {
        $subjectId = $identity->getSubjectId();
        $issuer = $identity->getIssuer();
        $providerCode = $this->currentProviderCode();

        $linked = $this->findLinkedCustomer($providerCode, $issuer, $subjectId);
        if ($linked !== null) {
            return $linked;
        }

        $email = $this->normalizeEmail($identity->getEmail());
        if ($email !== null) {
            $existing = $this->findByEmail($email);
            if ($existing !== null) {
                return $this->adoptByEmail($existing, $identity, $providerCode);
            }
        }

        return $this->createNew($identity, $email, $providerCode);
    }

    /**
     * Load the customer already linked to this subject, or null when none.
     *
     * Also returns null when the link is stale (the customer was deleted).
     *
     * @param string $providerCode active SSO provider code
     * @param string $issuer
     * @param string $subjectId
     * @throws LocalizedException when accounts are per-website and the linked customer belongs to another website
     */
    private function findLinkedCustomer(string $providerCode, string $issuer, string $subjectId): ?CustomerInterface
    {
        $customerId = $this->subjectLink->findCustomerIdBySubject(
            $providerCode,
            $issuer,
            $subjectId,
            $this->shareConfig->isWebsiteScope() ? $this->currentWebsiteId() : null
        );
        if ($customerId === null) {
            return null;
        }

        try {
            $customer = $this->customerRepository->getById($customerId);
        } catch (NoSuchEntityException $e) {
            return null;
        }

        // Guards a link whose stored website went stale (customer moved to another website).
        if ($this->shareConfig->isWebsiteScope() && (int)$customer->getWebsiteId() !== $this->currentWebsiteId()) {
            throw new LocalizedException(
                __('This sign-in identity is linked to a customer account on another website.')
            );
        }

        return $customer;
    }

    /**
     * Load an existing customer by email in the current website, or null when none.
     *
     * @param string $email already-normalized email
     */
    private function findByEmail(string $email): ?CustomerInterface
    {
        try {
            return $this->customerRepository->get($email, $this->currentWebsiteId());
        } catch (NoSuchEntityException $e) {
            return null;
        }
    }

    /**
     * Adopt an existing customer matched by email, storing the identity link so future
     * logins resolve by it. Refuses when the account already holds an identity for the
     * current provider (takeover guard — a match on this identity resolved in step 1),
     * when the IdP did not verify the email, or when email linking is disabled. Links
     * under other providers do not block adoption.
     *
     * @param CustomerInterface $customer customer matched by email
     * @param IdentityInterface $identity
     * @param string $providerCode active SSO provider code
     * @throws LocalizedException when linking is not permitted
     */
    private function adoptByEmail(
        CustomerInterface $customer,
        IdentityInterface $identity,
        string $providerCode
    ): CustomerInterface {
        $customerId = (int)$customer->getId();

        if ($this->subjectLink->getSubjectForProvider($customerId, $providerCode) !== null) {
            throw new LocalizedException(
                __('A customer account with this email is already linked to a different single sign-on identity.')
            );
        }

        if (!$identity->isEmailVerified() || !$this->config->isEmailLinkingEnabled($this->currentStoreId())) {
            throw new LocalizedException(
                __(
                    'An account with this email address already exists. Sign in with your password, '
                    . 'then link single sign-on under My Account → Single Sign-On.'
                )
            );
        }

        $this->subjectLink->link($customerId, $providerCode, $identity->getIssuer(), $identity->getSubjectId());

        return $customer;
    }

    /**
     * Create, persist, and subject-link a fresh storefront customer.
     *
     * @param IdentityInterface $identity
     * @param string|null $email already-normalized email, or null
     * @param string $providerCode active SSO provider code
     * @throws LocalizedException when the IdP released no email, or did not verify it
     */
    private function createNew(
        IdentityInterface $identity,
        ?string $email,
        string $providerCode
    ): CustomerInterface {
        if ($email === null) {
            throw new LocalizedException(
                __('The identity provider did not release an email address required to create a customer account.')
            );
        }
        if (!$identity->isEmailVerified()) {
            throw new LocalizedException(
                __(
                    'Your identity provider has not verified your email address, so a store account cannot be '
                    . 'created. Verify the email with your provider, or sign in with your password and link '
                    . 'single sign-on under My Account → Single Sign-On.'
                )
            );
        }

        $store = $this->storeManager->getStore();
        [$firstName, $lastName] = $this->splitName($identity->getName(), $email);

        $customer = $this->customerFactory->create();
        $customer->setWebsiteId((int)$store->getWebsiteId());
        $customer->setStoreId((int)$store->getId());
        $customer->setEmail($email);
        $customer->setFirstname($firstName);
        $customer->setLastname($lastName);

        $saved = $this->customerRepository->save($customer);
        $this->subjectLink->link(
            (int)$saved->getId(),
            $providerCode,
            $identity->getIssuer(),
            $identity->getSubjectId()
        );

        return $saved;
    }

    /**
     * Website id of the current store (customers are website-scoped).
     */
    private function currentWebsiteId(): int
    {
        return (int)$this->storeManager->getStore()->getWebsiteId();
    }

    /**
     * Id of the current store (config policy is store-scoped).
     */
    private function currentStoreId(): int
    {
        return (int)$this->storeManager->getStore()->getId();
    }

    /**
     * Active SSO provider code for the current store, or '' when none is selected.
     *
     * Scopes the subject link so a `sub` issued by one provider can never resolve a
     * customer linked under another (`sub` is only unique per issuer).
     */
    private function currentProviderCode(): string
    {
        return (string)$this->activeProviderResolver->getActiveCode($this->currentStoreId());
    }

    /**
     * Trimmed, lower-cased email, or null when the IdP released none.
     *
     * @param string|null $email
     */
    private function normalizeEmail(?string $email): ?string
    {
        $email = strtolower(trim((string)$email));

        return $email === '' ? null : $email;
    }

    /**
     * Split a display name into first/last, guaranteeing both are non-empty
     * (Magento requires both). With no name the email local part seeds the first
     * name; a single-word name gets the {@see self::DEFAULT_LAST_NAME} placeholder.
     *
     * @param string|null $name
     * @param string $email used to derive a first name when the IdP released none
     * @return array{0:string,1:string}
     */
    private function splitName(?string $name, string $email): array
    {
        $name = trim((string)$name);
        if ($name === '') {
            $local = strstr($email, '@', true);
            $first = trim(is_string($local) ? $local : $email);

            return [$first !== '' ? $first : $email, self::DEFAULT_LAST_NAME];
        }

        $parts = preg_split('/\s+/', $name) ?: [$name];
        $first = (string)array_shift($parts);
        $last = $parts === [] ? self::DEFAULT_LAST_NAME : implode(' ', $parts);

        return [$first, $last];
    }
}
