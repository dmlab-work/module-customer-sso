<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Controller\Sso;

use DmLab\CustomerSso\Model\Oidc\AuthorizationStarter;
use DmLab\CustomerSso\Model\SsoIntent;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Url\DecoderInterface;
use Magento\Framework\Url\HostChecker;
use Magento\Framework\Validator\Url as UrlValidator;
use Psr\Log\LoggerInterface;

/**
 * Starts the storefront OIDC login: redirects the browser to the IdP
 * authorization URL built by {@see AuthorizationStarter}.
 *
 * A public storefront action (the login page is already unauthenticated). An
 * optional base64 `referer` param becomes the post-login return URL when it is an
 * absolute own-origin URL. When SSO is disabled or misconfigured the starter throws
 * and the shopper is sent back to the native customer login with an error message,
 * so the login page is never left in a broken state.
 */
class Start implements HttpGetActionInterface
{
    /** Request param carrying the base64-encoded return URL. */
    public const PARAM_REFERER = 'referer';

    /**
     * @param AuthorizationStarter $authorizationStarter
     * @param RequestInterface $request
     * @param DecoderInterface $urlDecoder
     * @param UrlValidator $urlValidator
     * @param HostChecker $hostChecker
     * @param RedirectFactory $resultRedirectFactory
     * @param ManagerInterface $messageManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly AuthorizationStarter $authorizationStarter,
        private readonly RequestInterface $request,
        private readonly DecoderInterface $urlDecoder,
        private readonly UrlValidator $urlValidator,
        private readonly HostChecker $hostChecker,
        private readonly RedirectFactory $resultRedirectFactory,
        private readonly ManagerInterface $messageManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Redirect to the IdP, or back to the customer login on failure.
     *
     * @return Redirect
     */
    public function execute(): Redirect
    {
        $result = $this->resultRedirectFactory->create();

        try {
            $intent = new SsoIntent(SsoIntent::MODE_LOGIN, null, $this->returnUrl());

            return $result->setUrl($this->authorizationStarter->start($intent));
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->critical($e);
            $this->messageManager->addErrorMessage(
                __('Could not start SSO sign-in. Please try again or contact the store owner.')
            );
        }

        return $result->setPath('customer/account/login');
    }

    /**
     * Decoded `referer`, kept only when it is an absolute http(s) URL on a store host.
     *
     * @return string|null
     */
    private function returnUrl(): ?string
    {
        $encoded = (string)$this->request->getParam(self::PARAM_REFERER);
        if ($encoded === '') {
            return null;
        }

        $url = (string)$this->urlDecoder->decode($encoded);
        // HostChecker treats host-less URLs as own-origin, so require an absolute URL first.
        if (!$this->urlValidator->isValid($url, ['http', 'https'])
            || str_contains($url, '\\')
            || !$this->hostChecker->isOwnOrigin($url)
        ) {
            return null;
        }

        return $url;
    }
}
