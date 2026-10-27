<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Model\Oidc;

use DmLab\SsoCore\Api\Data\IdentityInterface;

/**
 * Outcome of a completed OIDC callback: the normalized identity plus the raw ID token
 * and provider code, kept for IdP logout (`id_token_hint`).
 */
class CallbackResult
{
    /**
     * @param IdentityInterface $identity
     * @param string $idToken raw validated ID token
     * @param string $providerCode code of the preset that issued it
     */
    public function __construct(
        private readonly IdentityInterface $identity,
        private readonly string $idToken = '',
        private readonly string $providerCode = ''
    ) {
    }

    /**
     * Verified, provider-agnostic identity.
     */
    public function getIdentity(): IdentityInterface
    {
        return $this->identity;
    }

    /**
     * Raw validated ID token; empty when not provided.
     */
    public function getIdToken(): string
    {
        return $this->idToken;
    }

    /**
     * Code of the provider preset that completed the login; empty when not provided.
     */
    public function getProviderCode(): string
    {
        return $this->providerCode;
    }
}
