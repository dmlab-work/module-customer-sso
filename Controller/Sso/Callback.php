<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Controller\Sso;

use DmLab\CustomerSso\Model\CustomerProvisioner;
use DmLab\CustomerSso\Model\CustomerSessionCreator;
use DmLab\CustomerSso\Model\GroupAssigner;
use DmLab\CustomerSso\Model\IdentityLinker;
use DmLab\CustomerSso\Model\Oidc\CallbackHandler;
use DmLab\CustomerSso\Model\Session\SsoIntentStorage;
use DmLab\CustomerSso\Model\SsoIntent;
use DmLab\SsoCore\Api\AuthorizationStateStorageInterface;
use DmLab\SsoCore\Api\Data\IdentityInterface;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Escaper;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Phrase;
use Psr\Log\LoggerInterface;

/**
 * Handles the storefront OIDC redirect back from the IdP: surfaces IdP error
 * responses, then hands the `code`/`state` to {@see CallbackHandler} for a verified,
 * provider-agnostic identity.
 *
 * A public storefront action (the shopper is not yet logged in). On success it
 * resolves the customer — by IdP subject, then email under the auto-link policy,
 * else JIT create — assigns its customer group from the IdP groups, and establishes
 * the storefront session, then lands the shopper on the intent's return URL or the
 * account area. A `link` intent instead binds the identity to the signed-in customer
 * via {@see IdentityLinker} and returns to My Account → Single Sign-On. Any failure
 * is turned into an error message so the shopper is never left on a broken page.
 */
class Callback implements HttpGetActionInterface
{
    /** My Account → Single Sign-On page. */
    private const ACCOUNT_SSO_PATH = 'customersso/account';

    /**
     * @param RequestInterface $request
     * @param CallbackHandler $callbackHandler
     * @param SsoIntentStorage $intentStorage
     * @param AuthorizationStateStorageInterface $stateStorage
     * @param CustomerProvisioner $customerProvisioner
     * @param GroupAssigner $groupAssigner
     * @param CustomerSessionCreator $customerSessionCreator
     * @param IdentityLinker $identityLinker
     * @param Session $customerSession
     * @param RedirectFactory $resultRedirectFactory
     * @param ManagerInterface $messageManager
     * @param Escaper $escaper
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly CallbackHandler $callbackHandler,
        private readonly SsoIntentStorage $intentStorage,
        private readonly AuthorizationStateStorageInterface $stateStorage,
        private readonly CustomerProvisioner $customerProvisioner,
        private readonly GroupAssigner $groupAssigner,
        private readonly CustomerSessionCreator $customerSessionCreator,
        private readonly IdentityLinker $identityLinker,
        private readonly Session $customerSession,
        private readonly RedirectFactory $resultRedirectFactory,
        private readonly ManagerInterface $messageManager,
        private readonly Escaper $escaper,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Process the IdP callback and route the browser accordingly.
     *
     * On success the shopper lands on the intent's return URL, else the account area;
     * any failure returns with a message to the login page, or to My Account → Single
     * Sign-On for a pending link intent.
     *
     * @return Redirect
     */
    public function execute(): Redirect
    {
        $state = (string)$this->request->getParam('state');
        $error = (string)$this->request->getParam('error');
        if ($error !== '') {
            $this->messageManager->addErrorMessage($this->describeIdpError());
            return $this->toFailure($state);
        }

        $code = (string)$this->request->getParam('code');
        if ($code === '' || $state === '') {
            $this->messageManager->addErrorMessage(
                __('The SSO sign-in response was incomplete. Please try again.')
            );
            return $this->toFailure($state);
        }

        try {
            $identity = $this->callbackHandler->handle($code, $state, $this->issParam());
            $intent = $this->intentStorage->consume($state) ?? new SsoIntent(SsoIntent::MODE_LOGIN);
            if ($intent->isLink()) {
                return $this->linkIdentity($intent, $identity);
            }
            $customer = $this->customerProvisioner->provision($identity);
            $customer = $this->groupAssigner->assign($customer, $identity);
            $this->customerSessionCreator->create($customer);
        } catch (LocalizedException $e) {
            // Storefront session messages render as raw HTML. This module's own
            // exceptions carry safe static text, but repository/validation exceptions
            // (e.g. from customerRepository->save on a JIT create) can embed the
            // IdP-supplied email/name — escape to prevent reflected XSS.
            $this->messageManager->addErrorMessage($this->escaper->escapeHtml($e->getMessage()));
            return $this->toFailure($state);
        } catch (\Throwable $e) {
            $this->logger->critical($e);
            $this->messageManager->addErrorMessage(
                __('Could not complete SSO sign-in. Please try again or contact the store owner.')
            );
            return $this->toFailure($state);
        }

        $returnUrl = $intent->getReturnUrl();
        if ($returnUrl !== null) {
            return $this->resultRedirectFactory->create()->setUrl($returnUrl);
        }

        return $this->redirectTo('customer/account');
    }

    /**
     * Bind the identity to the signed-in customer the link intent was started for.
     *
     * @param SsoIntent $intent link intent
     * @param IdentityInterface $identity
     * @return Redirect to My Account → Single Sign-On
     */
    private function linkIdentity(SsoIntent $intent, IdentityInterface $identity): Redirect
    {
        $sessionCustomerId = (int)$this->customerSession->getCustomerId();
        if (!$this->customerSession->isLoggedIn() || $sessionCustomerId !== $intent->getCustomerId()) {
            $this->messageManager->addErrorMessage(
                __('Your session changed. Please sign in and try linking again.')
            );
            return $this->redirectTo(self::ACCOUNT_SSO_PATH);
        }

        try {
            $this->identityLinker->link($sessionCustomerId, $identity);
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($this->escaper->escapeHtml($e->getMessage()));
            return $this->redirectTo(self::ACCOUNT_SSO_PATH);
        } catch (\Throwable $e) {
            $this->logger->critical($e);
            $this->messageManager->addErrorMessage(
                __('Could not link single sign-on. Please try again or contact the store owner.')
            );
            return $this->redirectTo(self::ACCOUNT_SSO_PATH);
        }

        $this->messageManager->addSuccessMessage(__('Single sign-on has been linked to your account.'));

        return $this->redirectTo(self::ACCOUNT_SSO_PATH);
    }

    /**
     * Drop the pending intent and authorization state, then redirect to where the flow started.
     *
     * The auth state is dropped too so a later callback for the same `state` (e.g. the
     * shopper retries the IdP page) can't succeed without its intent and fall back to login.
     *
     * @param string $state OIDC `state` from the callback, possibly empty
     * @return Redirect to My Account → Single Sign-On for a link intent, else the login page
     */
    private function toFailure(string $state): Redirect
    {
        $intent = null;
        if ($state !== '') {
            $intent = $this->intentStorage->consume($state);
            $this->stateStorage->consume($state);
        }

        return $this->redirectTo(
            $intent !== null && $intent->isLink() ? self::ACCOUNT_SSO_PATH : 'customer/account/login'
        );
    }

    /**
     * Build a redirect result to the given storefront path.
     *
     * @param string $path
     * @return Redirect
     */
    private function redirectTo(string $path): Redirect
    {
        $result = $this->resultRedirectFactory->create();
        $result->setPath($path);

        return $result;
    }

    /**
     * RFC 9207 `iss` response param, or null when the IdP did not send one.
     */
    private function issParam(): ?string
    {
        $iss = $this->request->getParam('iss');

        return is_string($iss) ? $iss : null;
    }

    /**
     * Build a user-facing message from the IdP's `error`/`error_description` params.
     */
    private function describeIdpError(): Phrase
    {
        $description = trim((string)$this->request->getParam('error_description'));
        $detail = $description !== '' ? $description : (string)$this->request->getParam('error');

        // Storefront session messages are rendered as raw HTML, so escape the
        // attacker-controllable IdP params here to prevent reflected XSS.
        return __('The identity provider rejected the sign-in: %1', $this->escaper->escapeHtml($detail));
    }
}
