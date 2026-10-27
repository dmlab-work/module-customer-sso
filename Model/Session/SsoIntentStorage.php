<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Model\Session;

use DmLab\CustomerSso\Model\SsoIntent;
use Magento\Customer\Model\Session;

/**
 * Customer-session store for {@see SsoIntent}s keyed by the OIDC `state` token,
 * mirroring {@see AuthorizationStateStorage}. Consuming an entry removes it; entries
 * expire and are capped by {@see PendingEntries}.
 */
class SsoIntentStorage
{
    /** Session key holding the map of pending intents, keyed by `state` token. */
    private const SESSION_KEY = 'dmlab_customer_sso_intents';

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
     * Persist the intent for the given `state` token.
     *
     * @param string $state
     * @param SsoIntent $intent
     * @return void
     */
    public function save(string $state, SsoIntent $intent): void
    {
        $intents = $this->pendingEntries->add($this->load(), $state, [
            'mode' => $intent->getMode(),
            'customer_id' => $intent->getCustomerId(),
            'return_url' => $intent->getReturnUrl(),
        ]);
        $this->session->setData(self::SESSION_KEY, $intents);
    }

    /**
     * Load and remove the intent for the given `state` token.
     *
     * @param string $state
     * @return SsoIntent|null null when unknown, expired or malformed
     */
    public function consume(string $state): ?SsoIntent
    {
        $stored = $this->load();
        $intents = $this->pendingEntries->prune($stored);
        $entry = $intents[$state] ?? null;
        unset($intents[$state]);
        if ($intents !== $stored) {
            $this->session->setData(self::SESSION_KEY, $intents);
        }
        if ($entry === null) {
            return null;
        }

        try {
            return new SsoIntent(
                (string)($entry['mode'] ?? ''),
                isset($entry['customer_id']) ? (int)$entry['customer_id'] : null,
                isset($entry['return_url']) ? (string)$entry['return_url'] : null
            );
        } catch (\InvalidArgumentException $e) {
            return null;
        }
    }

    /**
     * Load the pending-intent map from the session.
     *
     * @return array<string,mixed>
     */
    private function load(): array
    {
        $intents = $this->session->getData(self::SESSION_KEY);

        return is_array($intents) ? $intents : [];
    }
}
