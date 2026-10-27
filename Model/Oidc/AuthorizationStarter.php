<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Model\Oidc;

use DmLab\CustomerSso\Model\ActiveProviderResolver;
use DmLab\CustomerSso\Model\Config;
use DmLab\CustomerSso\Model\Session\SsoIntentStorage;
use DmLab\CustomerSso\Model\SsoIntent;
use DmLab\SsoCore\Api\AuthorizationParamsProviderInterface;
use DmLab\SsoCore\Api\AuthorizationStateStorageInterface;
use DmLab\SsoCore\Api\DiscoveryIssuerPolicyInterface;
use DmLab\SsoCore\Model\Oidc\AuthorizationRequestFactory;
use DmLab\SsoCore\Model\Oidc\DiscoveryClient;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\UrlInterface;

/**
 * Orchestrates the start of the storefront OIDC login: resolves the active
 * provider preset + config, discovers the IdP's authorization endpoint (via
 * sso-core), builds an auth-code + PKCE authorization request, persists its
 * one-time state and the {@see SsoIntent} for the callback, and returns the IdP
 * URL to redirect the browser to.
 *
 * Provider-neutral: the concrete IdP arrives only through the active preset, so
 * this stays free of any provider-specific code (open/closed).
 */
class AuthorizationStarter
{
    /** Storefront route the IdP calls back to (frontName/controller/action). */
    private const CALLBACK_ROUTE = 'customersso/sso/callback';

    /**
     * @param Config $config
     * @param ActiveProviderResolver $activeProviderResolver
     * @param DiscoveryClient $discoveryClient
     * @param AuthorizationRequestFactory $authorizationRequestFactory
     * @param AuthorizationStateStorageInterface $stateStorage
     * @param SsoIntentStorage $intentStorage
     * @param UrlInterface $url
     */
    public function __construct(
        private readonly Config $config,
        private readonly ActiveProviderResolver $activeProviderResolver,
        private readonly DiscoveryClient $discoveryClient,
        private readonly AuthorizationRequestFactory $authorizationRequestFactory,
        private readonly AuthorizationStateStorageInterface $stateStorage,
        private readonly SsoIntentStorage $intentStorage,
        private readonly UrlInterface $url
    ) {
    }

    /**
     * Build the IdP authorization URL and persist its one-time state and intent.
     *
     * @param SsoIntent $intent
     * @return string
     * @throws LocalizedException when SSO is disabled, no provider is active, or
     *         the client id is not configured.
     * @throws \InvalidArgumentException when the preset returns invalid extra params.
     */
    public function start(SsoIntent $intent): string
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

        $presetConfig = $this->presetConfig();

        $metadata = $this->discoveryClient->discover(
            $preset->buildDiscoveryUrl($presetConfig),
            $preset instanceof DiscoveryIssuerPolicyInterface ? $preset->requiresIssuerMatch($presetConfig) : true
        );

        $request = $this->authorizationRequestFactory->create(
            $metadata->getAuthorizationEndpoint(),
            $clientId,
            $this->callbackUrl(),
            $preset->getDefaultScopes(),
            $preset instanceof AuthorizationParamsProviderInterface
                ? $preset->getAuthorizationParams($presetConfig)
                : [],
            $metadata->getIssuer()
        );

        $this->stateStorage->save($request);
        $this->intentStorage->save($request->getState(), $intent);

        return $request->getUrl();
    }

    /**
     * Config array handed to the preset for building its discovery URL. The core
     * stays provider-neutral, so it forwards only the generic fields it owns; a
     * preset needing IdP-specific values reads them from its own config.
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
     * Absolute storefront callback URL registered with the IdP as the redirect
     * URI. Built without a session id so it stays stable and matches the value
     * registered at the IdP.
     */
    private function callbackUrl(): string
    {
        return $this->url->getUrl(self::CALLBACK_ROUTE, ['_nosid' => true]);
    }
}
