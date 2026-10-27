<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Model\Session;

use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Bounds the session maps of pending SSO round-trips (keyed by `state`): an entry
 * expires after {@see self::TTL} seconds and only the newest {@see self::MAX_ENTRIES}
 * are kept, so abandoned logins cannot pile up in the session.
 */
class PendingEntries
{
    /** Seconds a pending round-trip stays valid. */
    public const TTL = 600;

    /** Pending round-trips kept per session. */
    public const MAX_ENTRIES = 10;

    /**
     * @param DateTime $dateTime
     */
    public function __construct(
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * Add a timestamped entry, dropping expired and the oldest over the cap.
     *
     * @param array<string,mixed> $entries
     * @param string $key
     * @param array<string,mixed> $entry
     * @return array<string,array<string,mixed>>
     */
    public function add(array $entries, string $key, array $entry): array
    {
        $entries = $this->prune($entries);
        unset($entries[$key]);
        $entries[$key] = ['created_at' => $this->now()] + $entry;

        return array_slice($entries, -self::MAX_ENTRIES, null, true);
    }

    /**
     * Drop expired and malformed entries (an entry without `created_at` counts as expired).
     *
     * @param array<string,mixed> $entries
     * @return array<string,array<string,mixed>>
     */
    public function prune(array $entries): array
    {
        $now = $this->now();

        return array_filter(
            $entries,
            static fn($entry): bool => is_array($entry)
                && is_int($entry['created_at'] ?? null)
                && $now - $entry['created_at'] < self::TTL
        );
    }

    /**
     * Current UTC timestamp.
     */
    private function now(): int
    {
        return (int)$this->dateTime->gmtTimestamp();
    }
}
