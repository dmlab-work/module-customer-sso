# Changelog

## 0.2.5

- Supports Magento 2.4.7+ (`magento/framework ^103.0.7`) and PHP 8.2–8.5.
- Unit tests run on PHPUnit 11.5 and 12.5.
- CI on PHP 8.2–8.5; CI installs are no longer counted as Packagist downloads.
- composer.json: keywords, homepage, support links, suggests `dmlab/module-customer-passkey`.
- README rewritten: screenshot, requirements, Hyvä status.

## 0.2.4

- Added: **Sign Out of IdP on Logout** (`dmlab_customer_sso/general/idp_logout`, store-scoped, default No). Customer logout of an SSO session redirects to the IdP `end_session_endpoint` with `id_token_hint`, returning to `customer/account/logoutSuccess/`; register that URL per store at the IdP.
- Added: `CallbackHandler::complete()` returning `CallbackResult` (identity + ID token + provider code).
- Requires `dmlab/module-sso-core ^0.2.3`.

## 0.2.3

- Fixed: `setup:upgrade` from 0.2.1 kept the old `(provider_code, subject_id)` unique index, so the issuer and per-website links of 0.2.2 still collided. Run `setup:upgrade`.

## 0.2.2

- Requires `dmlab/module-sso-core ^0.2.2`; the callback verifies the RFC 9207 `iss` response parameter and the issuer the sign-in started with, and authenticates at the token endpoint per discovered methods (default `client_secret_basic`).
- **Identity links are keyed by issuer + subject** (`issuer` column): two IdPs under one provider code (e.g. two Okta orgs) no longer share a namespace. Existing links adopt the issuer on their next matching login.
- **Per-website customer accounts can each link the same IdP identity** (`website_id` column, backfilled by a data patch).
- **"Keep Password Login = No" is enforced server-side**: login form, checkout popup, REST `integration/customer/token` and GraphQL `generateCustomerToken` refuse passwords once SSO is live.
- Groups overage (distributed groups claim) leaves the customer group unchanged.
- Group map: the first matching line wins; a duplicate group keeps its first line.
- SSO login is refused while a customer is already signed in; email adoption is refused for an account awaiting email confirmation.
- Pending sign-in states and intents expire after 10 minutes, at most 10 per session (sign-ins in flight during the upgrade must be restarted).
- Upgrade: run `setup:upgrade` (schema change + data patch).

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
