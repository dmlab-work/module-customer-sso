<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Model;

/**
 * Decides whether native email/password login is off ("Keep Password Login" = No).
 *
 * Only while SSO is live (enabled with an active provider), so a mis-set toggle on an
 * unconfigured or broken install can never lock shoppers out with no way in.
 */
class PasswordLoginPolicy
{
    /**
     * @param Config $config
     * @param ActiveProviderResolver $activeProviderResolver
     */
    public function __construct(
        private readonly Config $config,
        private readonly ActiveProviderResolver $activeProviderResolver
    ) {
    }

    /**
     * Whether SSO is live and the admin turned native password login off.
     *
     * @param int|string|null $storeId null = current store
     * @return bool
     */
    public function isPasswordLoginDisabled($storeId = null): bool
    {
        return $this->config->isEnabled($storeId)
            && !$this->config->isPasswordLoginAllowed($storeId)
            && $this->activeProviderResolver->getActive($storeId) !== null;
    }
}
