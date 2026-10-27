<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Typed reader over the module's storefront configuration.
 *
 * Wraps {@see ScopeConfigInterface} so the rest of the module never touches raw
 * config paths, and decrypts the OIDC client secret (stored encrypted via the
 * Magento `Encrypted` backend model). Reads are store-scoped: storefront SSO can
 * be enabled/tuned per store view. Provider-neutral — the concrete IdP comes from
 * the active preset, resolved by {@see ActiveProviderResolver}.
 */
class Config
{
    /** Whether customer SSO is enabled. */
    public const XML_PATH_ENABLED = 'dmlab_customer_sso/general/enabled';

    /** OIDC client (application) id issued by the IdP. */
    public const XML_PATH_CLIENT_ID = 'dmlab_customer_sso/general/client_id';

    /** OIDC client secret, stored encrypted. */
    public const XML_PATH_CLIENT_SECRET = 'dmlab_customer_sso/general/client_secret';

    /** Whether the native email/password login form stays visible alongside SSO. */
    public const XML_PATH_ALLOW_PASSWORD_LOGIN = 'dmlab_customer_sso/general/allow_password_login';

    /** Email account-linking policy (adopt on IdP-verified email vs never). */
    public const XML_PATH_AUTO_LINK_POLICY = 'dmlab_customer_sso/general/auto_link_policy';

    /** IdP-group → customer-group rules, one `group=customer_group_id` per line. */
    public const XML_PATH_GROUP_MAP = 'dmlab_customer_sso/general/group_customer_group_map';

    /** Whether customer logout also ends the IdP session (OIDC RP-Initiated Logout). */
    public const XML_PATH_IDP_LOGOUT = 'dmlab_customer_sso/general/idp_logout';

    /** Adopt a matching customer by email only when the IdP verified the email (default). */
    public const AUTO_LINK_REQUIRE_VERIFICATION = 'require_verification';

    /** Never adopt by email; linking happens from My Account only. */
    public const AUTO_LINK_DISABLED = 'disabled';

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param EncryptorInterface $encryptor
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    /**
     * Whether customer SSO is enabled for the given store.
     *
     * @param int|string|null $storeId
     */
    public function isEnabled($storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Configured OIDC client id, or null when unset.
     *
     * @param int|string|null $storeId
     */
    public function getClientId($storeId = null): ?string
    {
        return $this->readNonEmptyString(self::XML_PATH_CLIENT_ID, $storeId);
    }

    /**
     * Decrypted OIDC client secret, or null when unset.
     *
     * @param int|string|null $storeId
     */
    public function getClientSecret($storeId = null): ?string
    {
        $encrypted = $this->scopeConfig->getValue(
            self::XML_PATH_CLIENT_SECRET,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        if (!is_string($encrypted) || $encrypted === '') {
            return null;
        }

        $decrypted = $this->encryptor->decrypt($encrypted);

        return $decrypted === '' ? null : $decrypted;
    }

    /**
     * Whether the native email/password login form stays visible alongside SSO.
     *
     * @param int|string|null $storeId
     */
    public function isPasswordLoginAllowed($storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ALLOW_PASSWORD_LOGIN,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Configured email-linking policy; anything other than {@see self::AUTO_LINK_DISABLED}
     * (unset, legacy `auto`, unknown) resolves to {@see self::AUTO_LINK_REQUIRE_VERIFICATION}.
     *
     * @param int|string|null $storeId
     */
    public function getAutoLinkPolicy($storeId = null): string
    {
        $value = $this->readNonEmptyString(self::XML_PATH_AUTO_LINK_POLICY, $storeId);

        return $value === self::AUTO_LINK_DISABLED ? self::AUTO_LINK_DISABLED : self::AUTO_LINK_REQUIRE_VERIFICATION;
    }

    /**
     * Whether a matching customer may be adopted by an IdP-verified email.
     *
     * @param int|string|null $storeId
     */
    public function isEmailLinkingEnabled($storeId = null): bool
    {
        return $this->getAutoLinkPolicy($storeId) !== self::AUTO_LINK_DISABLED;
    }

    /**
     * Parsed IdP-group → customer-group-id map.
     *
     * Reads the `group=customer_group_id` lines in order (line order is rule priority);
     * blank lines and `#` comments are ignored, and a duplicate group keeps its first
     * line. The actual assignment is applied by {@see GroupAssigner}; this only exposes the rules.
     *
     * @param int|string|null $storeId
     * @return array<string,string> group name → customer group id
     */
    public function getGroupCustomerGroupMap($storeId = null): array
    {
        $raw = $this->scopeConfig->getValue(
            self::XML_PATH_GROUP_MAP,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $map = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$group, $groupId] = explode('=', $line, 2);
            $group = trim($group);
            $groupId = trim($groupId);
            if ($group !== '' && $groupId !== '' && !array_key_exists($group, $map)) {
                $map[$group] = $groupId;
            }
        }

        return $map;
    }

    /**
     * Whether customer logout redirects to the IdP's end-session endpoint.
     *
     * @param int|string|null $storeId
     */
    public function isIdpLogoutEnabled($storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_IDP_LOGOUT,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Read a store-scoped config value as a trimmed non-empty string, or null.
     *
     * @param string $path
     * @param int|string|null $storeId
     */
    private function readNonEmptyString(string $path, $storeId = null): ?string
    {
        $value = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }
}
