# Changelog

## 0.2.1

- Fixed: guests opening My Account → Single Sign-On got HTTP 500 instead of the login redirect.
- Fixed: header greeting and mini-cart stayed stale after SSO sign-in in a fresh browser.
- Tested on Magento 2.4.9 and PHP 8.5.

## 0.2.0

- **Email adoption and JIT creation require an IdP-verified email** (`email_verified`); unverified → login refused, nothing created or linked. `auto_link_policy` values: `require_verification` (default; legacy `auto` maps here) and `disabled`.
- My Account → Single Sign-On page: list, link and unlink identities; one identity per provider.
- Subject links keyed by `(customer_id, provider_code)` with `created_at`; existing rows are kept. Linking never overwrites an existing link.
- SSO intent (`login`/`link` + return URL) carried from start to callback; same-origin `referer` honoured; link-mode failures return to My Account → Single Sign-On.
- SSO login resets private content (`mage-cache-sessid`).
- SSO button in the Luma checkout and minicart login popups (returns to checkout); Hyvä template `login/sso-button-hyva.phtml`.
- Button label translatable; client secret marked sensitive (kept out of `app:config:dump`).
- Requires `dmlab/module-sso-core ^0.2`; honours optional preset capabilities: extra authorization params, discovery issuer policy, JWKS refetch on key rotation.
- Packaging: Magento module requirements declared, OSL-3.0 headers, i18n phrases.

## 0.1.1

- Rebrand to the `dmlab` vendor and `DmLab` namespace.
