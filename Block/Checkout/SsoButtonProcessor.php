<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Block\Checkout;

use DmLab\CustomerSso\Controller\Sso\Start;
use DmLab\CustomerSso\Model\ActiveProviderResolver;
use DmLab\CustomerSso\Model\Config;
use Magento\Checkout\Block\Checkout\LayoutProcessorInterface;
use Magento\Framework\Url\EncoderInterface;
use Magento\Framework\UrlInterface;

/**
 * Adds the "Sign in with SSO" button to a Luma checkout login popup jsLayout.
 *
 * The button starts SSO with the checkout URL as `referer`, so the shopper lands
 * back in checkout. Registered per popup via virtual types that set `$childrenPath`.
 */
class SsoButtonProcessor implements LayoutProcessorInterface
{
    /** jsLayout child name of the injected button component. */
    public const CHILD_NAME = 'dmlab-customer-sso';

    private const START_AUTH_ROUTE = 'customersso/sso/start';

    /**
     * @param Config $config
     * @param ActiveProviderResolver $activeProviderResolver
     * @param UrlInterface $url
     * @param EncoderInterface $urlEncoder
     * @param string $childrenPath slash-separated jsLayout path of the popup's `children` node
     */
    public function __construct(
        private readonly Config $config,
        private readonly ActiveProviderResolver $activeProviderResolver,
        private readonly UrlInterface $url,
        private readonly EncoderInterface $urlEncoder,
        private readonly string $childrenPath
    ) {
    }

    /**
     * Inject the button at `$childrenPath`; unchanged when SSO is unavailable or the path is missing.
     *
     * @param array $jsLayout
     * @return array
     */
    public function process($jsLayout)
    {
        $preset = $this->config->isEnabled() ? $this->activeProviderResolver->getActive() : null;
        if ($preset === null) {
            return $jsLayout;
        }

        $node = &$jsLayout;
        foreach (explode('/', $this->childrenPath) as $segment) {
            if (!isset($node[$segment]) || !is_array($node[$segment])) {
                return $jsLayout;
            }
            $node = &$node[$segment];
        }

        $node[self::CHILD_NAME] = [
            'component' => 'DmLab_CustomerSso/js/view/sso-button',
            'displayArea' => 'before',
            'config' => [
                'startUrl' => $this->url->getUrl(self::START_AUTH_ROUTE, [
                    Start::PARAM_REFERER => $this->urlEncoder->encode($this->url->getUrl('checkout')),
                ]),
                'label' => (string)__($preset->getButtonLabel()),
                'iconUrl' => $preset->getButtonIconUrl(),
            ],
        ];
        unset($node);

        return $jsLayout;
    }
}
