<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Plugin\Customer;

use DmLab\CustomerSso\Model\PasswordLoginPolicy;
use Magento\Customer\Api\AccountManagementInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Rejects native email/password customer login while "Keep Password Login" is off.
 *
 * Hooks {@see AccountManagementInterface::authenticate()}, which the login form, the
 * checkout login popup, the REST customer-token endpoint and GraphQL
 * `generateCustomerToken` all go through; SSO logins never call it (they establish the
 * session via {@see \DmLab\CustomerSso\Model\CustomerSessionCreator}).
 */
class EnforceSso
{
    /**
     * @param PasswordLoginPolicy $passwordLoginPolicy
     */
    public function __construct(
        private readonly PasswordLoginPolicy $passwordLoginPolicy
    ) {
    }

    /**
     * Reject the login before credentials are checked when password login is disabled.
     *
     * @param AccountManagementInterface $subject
     * @param string $username
     * @param string $password
     * @return array{0: string, 1: string}
     * @throws LocalizedException
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeAuthenticate(AccountManagementInterface $subject, $username, $password): array
    {
        if ($this->passwordLoginPolicy->isPasswordLoginDisabled()) {
            throw new LocalizedException(
                __('Password sign-in is disabled for this store. Please sign in with single sign-on.')
            );
        }

        return [$username, $password];
    }
}
