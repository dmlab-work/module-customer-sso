<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Model;

/**
 * What an SSO round-trip is for, carried from start to callback under the OIDC
 * `state`: a storefront `login`, or `link`ing an identity to a signed-in customer.
 */
class SsoIntent
{
    public const MODE_LOGIN = 'login';
    public const MODE_LINK = 'link';

    /**
     * @param string $mode
     * @param int|null $customerId customer the link is bound to (link mode only)
     * @param string|null $returnUrl validated own-origin URL to land on afterwards
     * @throws \InvalidArgumentException on an unknown mode or a link without customer
     */
    public function __construct(
        private readonly string $mode,
        private readonly ?int $customerId = null,
        private readonly ?string $returnUrl = null
    ) {
        if ($mode !== self::MODE_LOGIN && $mode !== self::MODE_LINK) {
            throw new \InvalidArgumentException(sprintf('Unknown SSO intent mode "%s".', $mode));
        }
        if ($mode === self::MODE_LINK && $customerId === null) {
            throw new \InvalidArgumentException('A link intent requires a customer id.');
        }
    }

    /**
     * Round-trip mode, one of the MODE_* constants.
     *
     * @return string
     */
    public function getMode(): string
    {
        return $this->mode;
    }

    /**
     * Whether the round-trip links an identity to a signed-in customer.
     *
     * @return bool
     */
    public function isLink(): bool
    {
        return $this->mode === self::MODE_LINK;
    }

    /**
     * Customer the link is bound to; null for login.
     *
     * @return int|null
     */
    public function getCustomerId(): ?int
    {
        return $this->customerId;
    }

    /**
     * Validated own-origin URL to land on afterwards.
     *
     * @return string|null
     */
    public function getReturnUrl(): ?string
    {
        return $this->returnUrl;
    }
}
