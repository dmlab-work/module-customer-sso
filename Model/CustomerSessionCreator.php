<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Model;

use DmLab\CustomerSso\Model\Session\IdpSessionStorage;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\Session;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;

/**
 * Establishes an authenticated storefront session for a provisioned customer,
 * without a password — the identity was already verified by the OIDC callback.
 *
 * Delegates to {@see Session::setCustomerDataAsLoggedIn()}, which regenerates the
 * session id, flags the HTTP auth context, and fires the `customer_login` /
 * `customer_data_object_login` events so core listeners react exactly as they
 * would for a native storefront login.
 *
 * Mirrors {@see \DmLab\AdminSso\Model\AdminSessionCreator} for the customer
 * domain.
 */
class CustomerSessionCreator
{
    /**
     * Private-content cookie; `customer-data.js` drops all cached sections when it is missing.
     */
    private const PRIVATE_CONTENT_COOKIE = 'mage-cache-sessid';

    /**
     * Read and cleared by `customer-data.js`, which then reloads every section (core store switch uses it too).
     */
    private const SECTION_DATA_CLEAN_COOKIE = 'section_data_clean';

    /**
     * Safety-net lifetime (seconds) in case no page runs `customer-data.js` to clear it.
     */
    private const SECTION_DATA_CLEAN_LIFETIME = 60;

    /**
     * Must be a non-numeric string: the JS JSON-decodes cookies and `_.isEmpty()` treats numbers as empty.
     */
    private const SECTION_DATA_CLEAN_VALUE = 'customer_sso';

    /**
     * @param Session $customerSession
     * @param CookieManagerInterface $cookieManager
     * @param CookieMetadataFactory $cookieMetadataFactory
     * @param IdpSessionStorage $idpSessionStorage
     */
    public function __construct(
        private readonly Session $customerSession,
        private readonly CookieManagerInterface $cookieManager,
        private readonly CookieMetadataFactory $cookieMetadataFactory,
        private readonly IdpSessionStorage $idpSessionStorage
    ) {
    }

    /**
     * Log the given customer into the current storefront session.
     *
     * Also resets private content, as native `LoginPost` does, and forces a reload of all customer
     * sections: the GET callback is not covered by `sections.xml`, and a browser that never loaded
     * sections has no `section_data_ids` to invalidate.
     *
     * @param CustomerInterface $customer persisted customer to authenticate
     * @param string $idToken raw ID token, kept for IdP logout; empty keeps none
     * @param string $providerCode provider preset that issued the ID token
     */
    public function create(CustomerInterface $customer, string $idToken = '', string $providerCode = ''): void
    {
        $this->customerSession->setCustomerDataAsLoggedIn($customer);
        if ($idToken !== '') {
            $this->idpSessionStorage->save($idToken, $providerCode);
        }

        if ($this->cookieManager->getCookie(self::PRIVATE_CONTENT_COOKIE)) {
            $metadata = $this->cookieMetadataFactory->createCookieMetadata();
            $metadata->setPath('/');
            $this->cookieManager->deleteCookie(self::PRIVATE_CONTENT_COOKIE, $metadata);
        }

        $this->cookieManager->setPublicCookie(
            self::SECTION_DATA_CLEAN_COOKIE,
            self::SECTION_DATA_CLEAN_VALUE,
            $this->cookieMetadataFactory->createPublicCookieMetadata()
                ->setHttpOnly(false)
                ->setDuration(self::SECTION_DATA_CLEAN_LIFETIME)
                ->setPath('/')
        );
    }
}
