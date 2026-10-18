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
 * mirroring {@see AuthorizationStateStorage}. Consuming an entry removes it.
 */
class SsoIntentStorage
{
    /** Session key holding the map of pending intents, keyed by `state` token. */
    private const SESSION_KEY = 'dmlab_customer_sso_intents';

    /**
     * @param Session $session
     */
    public function __construct(
        private readonly Session $session
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
        $intents = $this->load();
        $intents[$state] = [
            'mode' => $intent->getMode(),
            'customer_id' => $intent->getCustomerId(),
            'return_url' => $intent->getReturnUrl(),
        ];
        $this->session->setData(self::SESSION_KEY, $intents);
    }

    /**
     * Load and remove the intent for the given `state` token.
     *
     * @param string $state
     * @return SsoIntent|null null when unknown or malformed
     */
    public function consume(string $state): ?SsoIntent
    {
        $intents = $this->load();
        if (!isset($intents[$state])) {
            return null;
        }

        $entry = $intents[$state];
        unset($intents[$state]);
        $this->session->setData(self::SESSION_KEY, $intents);

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
     * @return array<string,array{mode:string,customer_id:?int,return_url:?string}>
     */
    private function load(): array
    {
        $intents = $this->session->getData(self::SESSION_KEY);

        return is_array($intents) ? $intents : [];
    }
}
