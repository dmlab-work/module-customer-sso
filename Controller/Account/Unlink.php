<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Controller\Account;

use DmLab\CustomerSso\Model\SubjectLink;
use Magento\Customer\Controller\AccountInterface;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;

/**
 * Removes one of the signed-in customer's own SSO links (by `provider_code`).
 */
class Unlink implements HttpPostActionInterface, AccountInterface
{
    /** Request param naming the provider whose link is removed. */
    public const PARAM_PROVIDER_CODE = 'provider_code';

    /** My Account → Single Sign-On page. */
    private const ACCOUNT_SSO_PATH = 'customersso/account';

    /**
     * @param RequestInterface $request
     * @param Session $customerSession
     * @param SubjectLink $subjectLink
     * @param RedirectFactory $resultRedirectFactory
     * @param ManagerInterface $messageManager
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly Session $customerSession,
        private readonly SubjectLink $subjectLink,
        private readonly RedirectFactory $resultRedirectFactory,
        private readonly ManagerInterface $messageManager
    ) {
    }

    /**
     * Unlink and return to the Single Sign-On page.
     *
     * @return Redirect
     */
    public function execute(): Redirect
    {
        $result = $this->resultRedirectFactory->create();
        $customerId = (int)$this->customerSession->getCustomerId();
        if ($customerId <= 0) {
            $this->messageManager->addErrorMessage(__('Please sign in to manage single sign-on.'));
            return $result->setPath('customer/account/login');
        }

        $providerCode = trim((string)$this->request->getParam(self::PARAM_PROVIDER_CODE));
        if ($this->subjectLink->unlink($customerId, $providerCode)) {
            $this->messageManager->addSuccessMessage(__('Single sign-on has been unlinked from your account.'));
        } else {
            $this->messageManager->addErrorMessage(__('No single sign-on link was found for this provider.'));
        }

        return $result->setPath(self::ACCOUNT_SSO_PATH);
    }
}
