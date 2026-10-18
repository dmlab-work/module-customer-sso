<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Model\Config\Source;

use DmLab\CustomerSso\Model\Config;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Dropdown source for the email account-linking policy.
 *
 * Governs what happens when an SSO identity's email matches an existing
 * customer that isn't yet linked by `sub`: adopt it when the IdP verified the
 * email (default), or never (link from My Account only).
 */
class AutoLinkPolicy implements OptionSourceInterface
{
    /**
     * @inheritDoc
     *
     * @return array<int,array{value:string,label:\Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            [
                'value' => Config::AUTO_LINK_REQUIRE_VERIFICATION,
                'label' => __('Link when the IdP verified the email'),
            ],
            [
                'value' => Config::AUTO_LINK_DISABLED,
                'label' => __('Never (link from My Account only)'),
            ],
        ];
    }
}
