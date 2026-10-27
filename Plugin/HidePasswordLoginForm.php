<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Plugin;

use DmLab\CustomerSso\Model\PasswordLoginPolicy;
use Magento\Customer\Block\Form\Login;

/**
 * Hide the native storefront email/password form when "Keep Password Login" is off
 * under live SSO ({@see PasswordLoginPolicy}). The login itself is refused by
 * {@see \DmLab\CustomerSso\Plugin\Customer\EnforceSso}.
 */
class HidePasswordLoginForm
{
    /**
     * @param PasswordLoginPolicy $passwordLoginPolicy
     */
    public function __construct(
        private readonly PasswordLoginPolicy $passwordLoginPolicy
    ) {
    }

    /**
     * Suppress the login form's markup when password login is disabled under SSO.
     *
     * @param Login $subject
     * @param string $result
     */
    public function afterToHtml(Login $subject, string $result): string
    {
        if ($this->passwordLoginPolicy->isPasswordLoginDisabled()) {
            return '';
        }

        return $result;
    }
}
