<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Controller\Account;

use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

/**
 * My Account → Single Sign-On: lists the customer's linked identities with link/unlink
 * actions. Guests are redirected to login.
 *
 * Not an AccountInterface: core's gate returns null for guests, which only an
 * {@see \Magento\Framework\App\Action\Action} subclass turns into a response.
 */
class Index implements HttpGetActionInterface
{
    /**
     * @param PageFactory $resultPageFactory
     * @param Session $customerSession
     * @param ResponseInterface $response
     */
    public function __construct(
        private readonly PageFactory $resultPageFactory,
        private readonly Session $customerSession,
        private readonly ResponseInterface $response
    ) {
    }

    /**
     * Render the Single Sign-On account page, or redirect a guest to login.
     *
     * @return Page|ResponseInterface
     */
    public function execute(): Page|ResponseInterface
    {
        // authenticate() stores the return URL and sets the login redirect on the shared response.
        if (!$this->customerSession->authenticate()) {
            return $this->response;
        }

        return $this->resultPageFactory->create();
    }
}
