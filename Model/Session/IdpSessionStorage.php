<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Model\Session;

use Magento\Customer\Model\Session;

/**
 * Keeps the raw ID token of an SSO login in the customer session, for `id_token_hint` on logout.
 *
 * Bound to the customer it was saved for: customer logout keeps session storage
 * (`clear_storage => false`), so an entry is only returned while that customer is signed in.
 */
class IdpSessionStorage
{
    private const SESSION_KEY = 'dmlab_customer_sso_idp_session';

    /**
     * @param Session $session
     */
    public function __construct(
        private readonly Session $session
    ) {
    }

    /**
     * Remember the ID token and its provider for the currently signed-in customer.
     *
     * @param string $idToken
     * @param string $providerCode
     */
    public function save(string $idToken, string $providerCode): void
    {
        $this->session->setData(self::SESSION_KEY, [
            'id_token' => $idToken,
            'provider' => $providerCode,
            'customer_id' => (int)$this->session->getCustomerId(),
        ]);
    }

    /**
     * ID token of the signed-in customer's SSO login, or null.
     */
    public function getIdToken(): ?string
    {
        return $this->read('id_token');
    }

    /**
     * Provider code of the signed-in customer's SSO login, or null.
     */
    public function getProviderCode(): ?string
    {
        return $this->read('provider');
    }

    /**
     * Forget the stored entry.
     */
    public function clear(): void
    {
        $this->session->setData(self::SESSION_KEY, null);
    }

    /**
     * Read one non-empty string field of an entry that belongs to the current customer.
     *
     * @param string $field
     */
    private function read(string $field): ?string
    {
        $entry = $this->session->getData(self::SESSION_KEY);
        $customerId = (int)$this->session->getCustomerId();
        if (!is_array($entry) || $customerId === 0 || ($entry['customer_id'] ?? null) !== $customerId) {
            return null;
        }
        $value = $entry[$field] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
