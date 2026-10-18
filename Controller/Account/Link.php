<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Controller\Account;

use DmLab\CustomerSso\Model\Oidc\AuthorizationStarter;
use DmLab\CustomerSso\Model\SsoIntent;
use Magento\Customer\Controller\AccountInterface;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\UrlInterface;
use Psr\Log\LoggerInterface;

/**
 * Starts linking an IdP identity to the signed-in customer: a `link` intent bound to the
 * session customer, completed by the SSO callback.
 */
class Link implements HttpPostActionInterface, AccountInterface
{
    /** My Account → Single Sign-On page. */
    private const ACCOUNT_SSO_PATH = 'customersso/account';

    /**
     * @param Session $customerSession
     * @param AuthorizationStarter $authorizationStarter
     * @param UrlInterface $url
     * @param RedirectFactory $resultRedirectFactory
     * @param ManagerInterface $messageManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Session $customerSession,
        private readonly AuthorizationStarter $authorizationStarter,
        private readonly UrlInterface $url,
        private readonly RedirectFactory $resultRedirectFactory,
        private readonly ManagerInterface $messageManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Redirect to the IdP, or back to the Single Sign-On page on failure.
     *
     * @return Redirect
     */
    public function execute(): Redirect
    {
        $result = $this->resultRedirectFactory->create();
        $customerId = (int)$this->customerSession->getCustomerId();
        if ($customerId <= 0) {
            $this->messageManager->addErrorMessage(__('Please sign in to link single sign-on.'));
            return $result->setPath('customer/account/login');
        }

        try {
            $intent = new SsoIntent(
                SsoIntent::MODE_LINK,
                $customerId,
                $this->url->getUrl(self::ACCOUNT_SSO_PATH)
            );

            return $result->setUrl($this->authorizationStarter->start($intent));
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->critical($e);
            $this->messageManager->addErrorMessage(
                __('Could not start linking single sign-on. Please try again or contact the store owner.')
            );
        }

        return $result->setPath(self::ACCOUNT_SSO_PATH);
    }
}
