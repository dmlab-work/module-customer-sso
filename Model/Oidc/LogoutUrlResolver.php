<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Model\Oidc;

use DmLab\CustomerSso\Model\ActiveProviderResolver;
use DmLab\CustomerSso\Model\Config;
use DmLab\SsoCore\Api\DiscoveryIssuerPolicyInterface;
use DmLab\SsoCore\Model\Oidc\DiscoveryClient;
use DmLab\SsoCore\Model\Oidc\LogoutUrlBuilder;
use Magento\Framework\UrlInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves the IdP end-session URL (OIDC RP-Initiated Logout) for an SSO customer logout
 * in the current store.
 *
 * Returns null whenever IdP logout does not apply or fails, so the caller falls back to a local logout.
 */
class LogoutUrlResolver
{
    /** Post-logout landing page (the core logout's own target); must be registered at the IdP. */
    private const POST_LOGOUT_ROUTE = 'customer/account/logoutSuccess';

    /**
     * @param Config $config
     * @param ActiveProviderResolver $activeProviderResolver
     * @param DiscoveryClient $discoveryClient
     * @param LogoutUrlBuilder $logoutUrlBuilder
     * @param UrlInterface $url
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Config $config,
        private readonly ActiveProviderResolver $activeProviderResolver,
        private readonly DiscoveryClient $discoveryClient,
        private readonly LogoutUrlBuilder $logoutUrlBuilder,
        private readonly UrlInterface $url,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * End-session URL for the given SSO login, or null to log out locally only.
     *
     * @param string $idToken raw ID token of the SSO login (`id_token_hint`)
     * @param string $providerCode provider that issued it; must still be the store's active one
     */
    public function resolve(string $idToken, string $providerCode): ?string
    {
        if (!$this->config->isEnabled() || !$this->config->isIdpLogoutEnabled() || $idToken === '') {
            return null;
        }

        $preset = $this->activeProviderResolver->getActive();
        $clientId = $this->config->getClientId();
        if ($preset === null || $preset->getCode() !== $providerCode || $clientId === null) {
            return null;
        }

        // Mirrors AuthorizationStarter/CallbackHandler so logout resolves the same IdP.
        $presetConfig = ['client_id' => $clientId];
        try {
            $endpoint = $this->discoveryClient->discover(
                $preset->buildDiscoveryUrl($presetConfig),
                $preset instanceof DiscoveryIssuerPolicyInterface ? $preset->requiresIssuerMatch($presetConfig) : true
            )->getEndSessionEndpoint();
            if ($endpoint === null) {
                return null;
            }

            return $this->logoutUrlBuilder->build(
                $endpoint,
                $idToken,
                $clientId,
                $this->url->getUrl(self::POST_LOGOUT_ROUTE, ['_nosid' => true])
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Customer SSO: IdP logout skipped: ' . $e->getMessage());

            return null;
        }
    }
}
