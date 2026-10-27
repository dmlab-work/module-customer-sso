<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Model\Oidc;

use DmLab\CustomerSso\Model\ActiveProviderResolver;
use DmLab\CustomerSso\Model\Config;
use DmLab\SsoCore\Api\AuthorizationStateStorageInterface;
use DmLab\SsoCore\Api\Data\IdentityInterface;
use DmLab\SsoCore\Api\DiscoveryIssuerPolicyInterface;
use DmLab\SsoCore\Model\Oidc\AuthorizationResponseValidator;
use DmLab\SsoCore\Model\Oidc\DiscoveryClient;
use DmLab\SsoCore\Model\Oidc\IdentityFactory;
use DmLab\SsoCore\Model\Oidc\IdTokenValidator;
use DmLab\SsoCore\Model\Oidc\TokenClient;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\UrlInterface;

/**
 * Orchestrates the storefront OIDC callback: validates the one-time `state` and the
 * response issuer (RFC 9207), exchanges the authorization code for tokens (via
 * sso-core), validates the returned ID token against the IdP's JWKS + the stored
 * nonce, and normalizes its claims into a provider-agnostic {@see IdentityInterface}.
 *
 * Provider-neutral: the concrete IdP arrives only through the active preset, so
 * this stays free of any provider-specific code (open/closed). Establishing the
 * customer session, JIT provisioning, linking, and group assignment happen in
 * later tasks; this only produces the verified identity.
 *
 * Mirrors {@see AuthorizationStarter} so start and callback resolve the same IdP
 * endpoints and use the same callback URL.
 */
class CallbackHandler
{
    /** Storefront route the IdP calls back to; must match the start controller's redirect_uri. */
    private const CALLBACK_ROUTE = 'customersso/sso/callback';

    /**
     * @param Config $config
     * @param ActiveProviderResolver $activeProviderResolver
     * @param DiscoveryClient $discoveryClient
     * @param TokenClient $tokenClient
     * @param IdTokenValidator $idTokenValidator
     * @param IdentityFactory $identityFactory
     * @param AuthorizationStateStorageInterface $stateStorage
     * @param UrlInterface $url
     * @param AuthorizationResponseValidator $responseValidator
     */
    public function __construct(
        private readonly Config $config,
        private readonly ActiveProviderResolver $activeProviderResolver,
        private readonly DiscoveryClient $discoveryClient,
        private readonly TokenClient $tokenClient,
        private readonly IdTokenValidator $idTokenValidator,
        private readonly IdentityFactory $identityFactory,
        private readonly AuthorizationStateStorageInterface $stateStorage,
        private readonly UrlInterface $url,
        private readonly AuthorizationResponseValidator $responseValidator
    ) {
    }

    /**
     * Complete the OIDC callback and return the verified identity.
     *
     * @param string $code authorization code returned by the IdP
     * @param string $state opaque state returned by the IdP; matched against the
     *        one-time value persisted at start
     * @param string|null $iss RFC 9207 `iss` response param; null when absent
     * @throws LocalizedException when SSO is disabled, no provider is active, the
     *         client id is unset, or the state is unknown/expired (replay).
     * @throws \DmLab\SsoCore\Exception\DiscoveryException
     * @throws \DmLab\SsoCore\Exception\AuthorizationResponseException on an issuer mix-up
     * @throws \DmLab\SsoCore\Exception\TokenException
     * @throws \DmLab\SsoCore\Exception\IdTokenValidationException
     */
    public function handle(string $code, string $state, ?string $iss = null): IdentityInterface
    {
        if (!$this->config->isEnabled()) {
            throw new LocalizedException(__('Customer SSO is disabled.'));
        }

        $preset = $this->activeProviderResolver->getActive();
        if ($preset === null) {
            throw new LocalizedException(__('No customer SSO provider is configured.'));
        }

        $clientId = $this->config->getClientId();
        if ($clientId === null) {
            throw new LocalizedException(__('The customer SSO client ID is not configured.'));
        }

        // Consuming invalidates the state — an unknown value means an expired,
        // forged, or replayed callback; reject before any network call.
        $authState = $this->stateStorage->consume($state);
        if ($authState === null) {
            throw new LocalizedException(
                __('The SSO sign-in session is invalid or has expired. Please try again.')
            );
        }

        $presetConfig = $this->presetConfig();

        $metadata = $this->discoveryClient->discover(
            $preset->buildDiscoveryUrl($presetConfig),
            $preset instanceof DiscoveryIssuerPolicyInterface ? $preset->requiresIssuerMatch($presetConfig) : true
        );

        $this->responseValidator->validateIssuer($iss, $authState, $metadata);

        $tokenResponse = $this->tokenClient->exchangeCode(
            $metadata->getTokenEndpoint(),
            $code,
            $this->callbackUrl(),
            $clientId,
            $authState->getCodeVerifier(),
            $this->config->getClientSecret(),
            $metadata->getTokenEndpointAuthMethodsSupported()
        );

        $claims = $this->idTokenValidator->validateForProvider(
            $tokenResponse->getIdToken(),
            $metadata,
            $clientId,
            $authState->getNonce()
        );

        return $this->identityFactory->create($claims, $preset);
    }

    /**
     * Config array handed to the preset for building its discovery URL. Mirrors
     * {@see AuthorizationStarter::presetConfig()} so start and callback resolve
     * the same IdP endpoints.
     *
     * @return array<string,mixed>
     */
    private function presetConfig(): array
    {
        return [
            'client_id' => $this->config->getClientId(),
        ];
    }

    /**
     * Absolute storefront callback URL used as the `redirect_uri` in the token
     * exchange. Built without a session id so it matches the value the start
     * controller registered with the IdP.
     */
    private function callbackUrl(): string
    {
        return $this->url->getUrl(self::CALLBACK_ROUTE, ['_nosid' => true]);
    }
}
