<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Plugin\Customer;

use DmLab\CustomerSso\Model\Oidc\LogoutUrlResolver;
use DmLab\CustomerSso\Model\Session\IdpSessionStorage;
use Magento\Customer\Controller\Account\Logout;
use Magento\Framework\Controller\Result\Redirect;

/**
 * After the core customer logout, sends an SSO-established session on to the IdP's end-session
 * endpoint (OIDC RP-Initiated Logout) instead of the logout success page.
 */
class IdpLogout
{
    /**
     * @param IdpSessionStorage $idpSessionStorage
     * @param LogoutUrlResolver $logoutUrlResolver
     */
    public function __construct(
        private readonly IdpSessionStorage $idpSessionStorage,
        private readonly LogoutUrlResolver $logoutUrlResolver
    ) {
    }

    /**
     * Run the local logout, then redirect to the IdP when it applies.
     *
     * @param Logout $subject
     * @param callable $proceed
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundExecute(Logout $subject, callable $proceed)
    {
        // Read while the customer is still signed in; the entry is bound to them.
        $idToken = $this->idpSessionStorage->getIdToken();
        $providerCode = $this->idpSessionStorage->getProviderCode();

        $result = $proceed();
        $this->idpSessionStorage->clear();
        if ($idToken === null || !$result instanceof Redirect) {
            return $result;
        }

        $logoutUrl = $this->logoutUrlResolver->resolve($idToken, (string)$providerCode);
        if ($logoutUrl !== null) {
            $result->setUrl($logoutUrl);
        }

        return $result;
    }
}
