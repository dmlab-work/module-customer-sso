<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Model\Session;

use DmLab\CustomerSso\Model\AuthorizationState;
use DmLab\SsoCore\Api\AuthorizationStateStorageInterface;
use DmLab\SsoCore\Api\Data\AuthorizationStateInterface;
use Magento\Customer\Model\Session;

/**
 * Customer-session-backed store for the per-request OIDC authorization state.
 *
 * The start controller {@see save}s the state (keyed by its `state` token) before
 * redirecting to the IdP; the callback controller {@see consume}s it once. Entries
 * are held in the storefront customer session under a single session key so several
 * in-flight logins from one browser don't clobber each other. Consuming an entry
 * removes it, giving replay protection; entries expire and are capped by {@see PendingEntries}.
 */
class AuthorizationStateStorage implements AuthorizationStateStorageInterface
{
    /** Session key holding the map of pending states, keyed by `state` token. */
    private const SESSION_KEY = 'dmlab_customer_sso_auth_states';

    /**
     * @param Session $session
     * @param PendingEntries $pendingEntries
     */
    public function __construct(
        private readonly Session $session,
        private readonly PendingEntries $pendingEntries
    ) {
    }

    /**
     * @inheritDoc
     */
    public function save(AuthorizationStateInterface $state): void
    {
        $states = $this->pendingEntries->add($this->load(), $state->getState(), [
            'nonce' => $state->getNonce(),
            'code_verifier' => $state->getCodeVerifier(),
            'issuer' => $state->getIssuer(),
        ]);
        $this->session->setData(self::SESSION_KEY, $states);
    }

    /**
     * @inheritDoc
     */
    public function consume(string $state): ?AuthorizationStateInterface
    {
        $stored = $this->load();
        $states = $this->pendingEntries->prune($stored);
        $entry = $states[$state] ?? null;
        unset($states[$state]);
        if ($states !== $stored) {
            $this->session->setData(self::SESSION_KEY, $states);
        }
        if ($entry === null) {
            return null;
        }

        return new AuthorizationState(
            $state,
            (string)($entry['nonce'] ?? ''),
            (string)($entry['code_verifier'] ?? ''),
            (string)($entry['issuer'] ?? '')
        );
    }

    /**
     * Load the pending-state map from the session.
     *
     * @return array<string,mixed>
     */
    private function load(): array
    {
        $states = $this->session->getData(self::SESSION_KEY);

        return is_array($states) ? $states : [];
    }
}
