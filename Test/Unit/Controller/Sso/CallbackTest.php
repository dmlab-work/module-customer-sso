<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\CustomerSso\Test\Unit\Controller\Sso;

use DmLab\CustomerSso\Controller\Sso\Callback;
use DmLab\CustomerSso\Model\CustomerProvisioner;
use DmLab\CustomerSso\Model\CustomerSessionCreator;
use DmLab\CustomerSso\Model\GroupAssigner;
use DmLab\CustomerSso\Model\IdentityLinker;
use DmLab\CustomerSso\Model\Oidc\CallbackHandler;
use DmLab\CustomerSso\Model\Oidc\CallbackResult;
use DmLab\CustomerSso\Model\Session\SsoIntentStorage;
use DmLab\CustomerSso\Model\SsoIntent;
use DmLab\SsoCore\Api\AuthorizationStateStorageInterface;
use DmLab\SsoCore\Model\Data\Identity;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\Session;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Escaper;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CallbackTest extends TestCase
{
    /** @var Redirect&MockObject */
    private $redirect;

    /** @var RequestInterface&Stub */
    private $request;

    /** @var ManagerInterface&MockObject */
    private $messageManager;

    /** @var LoggerInterface&MockObject */
    private $logger;

    /** @var CallbackHandler&MockObject */
    private $handler;

    /** @var CustomerProvisioner&MockObject */
    private $provisioner;

    /** @var GroupAssigner&MockObject */
    private $groupAssigner;

    /** @var CustomerSessionCreator&MockObject */
    private $sessionCreator;

    /** @var IdentityLinker&MockObject */
    private $identityLinker;

    /** @var Session&Stub */
    private $customerSession;

    /** @var AuthorizationStateStorageInterface&Stub */
    private $stateStorage;

    /** @var RedirectFactory&Stub */
    private $redirectFactory;

    /** @var Callback */
    private Callback $controller;

    protected function setUp(): void
    {
        $this->redirect = $this->createMock(Redirect::class);

        $this->redirectFactory = $this->createStub(RedirectFactory::class);
        $this->redirectFactory->method('create')->willReturn($this->redirect);

        $this->request = $this->createStub(RequestInterface::class);
        $this->messageManager = $this->createMock(ManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->handler = $this->createMock(CallbackHandler::class);
        $this->provisioner = $this->createMock(CustomerProvisioner::class);
        $this->groupAssigner = $this->createMock(GroupAssigner::class);
        $this->sessionCreator = $this->createMock(CustomerSessionCreator::class);
        $this->identityLinker = $this->createMock(IdentityLinker::class);
        $this->customerSession = $this->createStub(Session::class);
        $this->stateStorage = $this->createStub(AuthorizationStateStorageInterface::class);

        $this->controller = $this->buildController(
            $this->createStub(SsoIntentStorage::class),
            $this->passThroughEscaper()
        );
    }

    private function buildController(SsoIntentStorage $intentStorage, Escaper $escaper): Callback
    {
        return new Callback(
            $this->request,
            $this->handler,
            $intentStorage,
            $this->stateStorage,
            $this->provisioner,
            $this->groupAssigner,
            $this->sessionCreator,
            $this->identityLinker,
            $this->customerSession,
            $this->redirectFactory,
            $this->messageManager,
            $escaper,
            $this->logger
        );
    }

    private function passThroughEscaper(): Escaper
    {
        $escaper = $this->createStub(Escaper::class);
        $escaper->method('escapeHtml')->willReturnArgument(0);

        return $escaper;
    }

    /**
     * Expect a successful login of a provisioned customer (the outcome under test is the landing URL).
     */
    private function expectSuccessfulLogin(): void
    {
        $identity = new Identity('user-1', 'user@example.com', 'User One', []);
        $customer = $this->createStub(CustomerInterface::class);

        $this->handler->expects(self::once())->method('complete')->with('the-code', 'the-state')
            ->willReturn(new CallbackResult($identity, 'the-id-token', 'okta'));
        $this->provisioner->expects(self::once())->method('provision')->willReturn($customer);
        $this->groupAssigner->expects(self::once())->method('assign')->willReturn($customer);
        // The raw ID token reaches the session creator for IdP logout.
        $this->sessionCreator->expects(self::once())->method('create')->with($customer, 'the-id-token', 'okta');
        $this->identityLinker->expects(self::never())->method('link');
        $this->messageManager->expects(self::never())->method('addErrorMessage');
        $this->logger->expects(self::never())->method('critical');
    }

    public function testRedirectsToIntentReturnUrlOnSuccess(): void
    {
        $this->withParams(['code' => 'the-code', 'state' => 'the-state']);
        $this->expectSuccessfulLogin();

        $intentStorage = $this->createMock(SsoIntentStorage::class);
        $intentStorage->expects(self::once())
            ->method('consume')
            ->with('the-state')
            ->willReturn(new SsoIntent(SsoIntent::MODE_LOGIN, null, 'https://magento.loc/checkout/'));

        $this->redirect->expects(self::once())
            ->method('setUrl')
            ->with('https://magento.loc/checkout/')
            ->willReturnSelf();
        $this->redirect->expects(self::never())->method('setPath');

        $controller = $this->buildController($intentStorage, $this->passThroughEscaper());
        self::assertSame($this->redirect, $controller->execute());
    }

    public function testMissingIntentFallsBackToLoginIntoAccount(): void
    {
        $this->withParams(['code' => 'the-code', 'state' => 'the-state']);
        $this->expectSuccessfulLogin();

        $intentStorage = $this->createMock(SsoIntentStorage::class);
        $intentStorage->expects(self::once())->method('consume')->with('the-state')->willReturn(null);

        $this->redirect->expects(self::never())->method('setUrl');
        $this->redirect->expects(self::once())->method('setPath')->with('customer/account');

        $controller = $this->buildController($intentStorage, $this->passThroughEscaper());
        self::assertSame($this->redirect, $controller->execute());
    }

    public function testRefusesLoginWhenAlreadySignedIn(): void
    {
        $this->withParams(['code' => 'the-code', 'state' => 'the-state']);
        $this->handler->expects(self::once())->method('complete')
            ->willReturn(new CallbackResult(new Identity('user-1')));
        $this->customerSession->method('isLoggedIn')->willReturn(true);
        $this->expectNoProvisioning();
        $this->identityLinker->expects(self::never())->method('link');
        $this->messageManager->expects(self::once())->method('addErrorMessage')
            ->with('You are already signed in. Sign out first to sign in with a different account.');
        $this->logger->expects(self::never())->method('critical');
        $this->redirect->expects(self::once())->method('setPath')->with('customer/account');

        self::assertSame($this->redirect, $this->controller->execute());
    }

    public function testForwardsIssResponseParam(): void
    {
        $this->withParams(['code' => 'the-code', 'state' => 'the-state', 'iss' => 'https://idp.example']);
        $customer = $this->createStub(CustomerInterface::class);
        $this->handler->expects(self::once())
            ->method('complete')
            ->with('the-code', 'the-state', 'https://idp.example')
            ->willReturn(new CallbackResult(new Identity('user-1')));
        $this->provisioner->expects(self::once())->method('provision')->willReturn($customer);
        $this->groupAssigner->expects(self::once())->method('assign')->willReturn($customer);
        $this->sessionCreator->expects(self::once())->method('create')->with($customer);
        $this->identityLinker->expects(self::never())->method('link');
        $this->redirect->expects(self::once())->method('setPath')->with('customer/account');
        $this->messageManager->expects(self::never())->method('addErrorMessage');
        $this->logger->expects(self::never())->method('critical');

        $this->controller->execute();
    }

    /**
     * Run the callback with a link intent bound to customer 42, the session as given.
     */
    private function runLinkCallback(
        Identity $identity,
        bool $loggedIn,
        ?int $sessionCustomerId,
        ?Escaper $escaper = null
    ): Redirect {
        $this->withParams(['code' => 'the-code', 'state' => 'the-state']);
        $this->handler->expects(self::once())->method('complete')->with('the-code', 'the-state')
            ->willReturn(new CallbackResult($identity));
        $this->customerSession->method('isLoggedIn')->willReturn($loggedIn);
        $this->customerSession->method('getCustomerId')->willReturn($sessionCustomerId);

        $intentStorage = $this->createStub(SsoIntentStorage::class);
        $intentStorage->method('consume')->willReturn(new SsoIntent(SsoIntent::MODE_LINK, 42));

        // Link mode never provisions, reassigns the group, or creates a session.
        $this->expectNoProvisioning();

        return $this->buildController($intentStorage, $escaper ?? $this->passThroughEscaper())->execute();
    }

    private function expectAccountSsoRedirect(): void
    {
        $this->redirect->expects(self::once())->method('setPath')->with('customersso/account');
        $this->redirect->expects(self::never())->method('setUrl');
    }

    public function testLinkIntentLinksIdentityToSessionCustomer(): void
    {
        $identity = new Identity('user-1', 'user@example.com', 'User One', []);
        $this->identityLinker->expects(self::once())->method('link')->with(42, $identity);
        $this->messageManager->expects(self::once())
            ->method('addSuccessMessage')
            ->with('Single sign-on has been linked to your account.');
        $this->messageManager->expects(self::never())->method('addErrorMessage');
        $this->logger->expects(self::never())->method('critical');
        $this->expectAccountSsoRedirect();

        self::assertSame($this->redirect, $this->runLinkCallback($identity, true, 42));
    }

    public function testLinkIntentRefusedForAnonymousSession(): void
    {
        $this->identityLinker->expects(self::never())->method('link');
        $this->messageManager->expects(self::once())
            ->method('addErrorMessage')
            ->with('Your session changed. Please sign in and try linking again.');
        $this->logger->expects(self::never())->method('critical');
        $this->expectAccountSsoRedirect();

        self::assertSame($this->redirect, $this->runLinkCallback(new Identity('user-1'), false, null));
    }

    public function testLinkIntentRefusedWhenSessionCustomerDiffers(): void
    {
        $this->identityLinker->expects(self::never())->method('link');
        $this->messageManager->expects(self::never())->method('addSuccessMessage');
        $this->messageManager->expects(self::once())
            ->method('addErrorMessage')
            ->with('Your session changed. Please sign in and try linking again.');
        $this->logger->expects(self::never())->method('critical');
        $this->expectAccountSsoRedirect();

        self::assertSame($this->redirect, $this->runLinkCallback(new Identity('user-1'), true, 7));
    }

    public function testLinkerRefusalIsEscapedAndReturnsToAccountSso(): void
    {
        $payload = '<b>already linked</b>';
        $escaped = '&lt;b&gt;already linked&lt;/b&gt;';
        $escaper = $this->createMock(Escaper::class);
        $escaper->expects(self::once())->method('escapeHtml')->with($payload)->willReturn($escaped);

        $this->identityLinker->expects(self::once())
            ->method('link')
            ->willThrowException(new LocalizedException(__($payload)));
        $this->messageManager->expects(self::never())->method('addSuccessMessage');
        $this->messageManager->expects(self::once())->method('addErrorMessage')->with($escaped);
        $this->logger->expects(self::never())->method('critical');
        $this->expectAccountSsoRedirect();

        self::assertSame($this->redirect, $this->runLinkCallback(new Identity('user-1'), true, 42, $escaper));
    }

    public function testUnexpectedLinkerErrorIsLoggedAndReturnsToAccountSso(): void
    {
        $error = new \RuntimeException('db down');
        $this->identityLinker->expects(self::once())->method('link')->willThrowException($error);
        $this->logger->expects(self::once())->method('critical')->with($error);
        $this->messageManager->expects(self::once())
            ->method('addErrorMessage')
            ->with('Could not link single sign-on. Please try again or contact the store owner.');
        $this->expectAccountSsoRedirect();

        self::assertSame($this->redirect, $this->runLinkCallback(new Identity('user-1'), true, 42));
    }

    public function testUnknownStateOnFailureFallsBackToLogin(): void
    {
        $this->withParams(['code' => 'the-code', 'state' => 'forged']);
        $this->expectLoginRedirect();
        $this->expectNoProvisioning();

        $this->handler->expects(self::once())->method('complete')->willThrowException(
            new LocalizedException(__('The SSO sign-in session is invalid or has expired. Please try again.'))
        );
        $intentStorage = $this->createMock(SsoIntentStorage::class);
        $intentStorage->expects(self::once())->method('consume')->with('forged')->willReturn(null);
        $this->messageManager->expects(self::once())->method('addErrorMessage');
        $this->logger->expects(self::never())->method('critical');

        $controller = $this->buildController($intentStorage, $this->passThroughEscaper());
        self::assertSame($this->redirect, $controller->execute());
    }

    public function testIdpErrorDuringLinkingDropsIntentAndReturnsToAccountSso(): void
    {
        $this->withParams(['error' => 'access_denied', 'state' => 'the-state']);
        $this->expectNoProvisioning();
        $this->handler->expects(self::never())->method('complete');
        $this->identityLinker->expects(self::never())->method('link');
        $this->messageManager->expects(self::once())
            ->method('addErrorMessage')
            ->with('The identity provider rejected the sign-in: access_denied');
        $this->logger->expects(self::never())->method('critical');
        $this->expectAccountSsoRedirect();

        $intentStorage = $this->createMock(SsoIntentStorage::class);
        $intentStorage->expects(self::once())
            ->method('consume')
            ->with('the-state')
            ->willReturn(new SsoIntent(SsoIntent::MODE_LINK, 42));
        $this->stateStorage = $this->createMock(AuthorizationStateStorageInterface::class);
        $this->stateStorage->expects(self::once())->method('consume')->with('the-state');

        $controller = $this->buildController($intentStorage, $this->passThroughEscaper());
        self::assertSame($this->redirect, $controller->execute());
    }

    public function testHandlerFailureDuringLinkingDropsIntentAndReturnsToAccountSso(): void
    {
        $this->withParams(['code' => 'the-code', 'state' => 'the-state']);
        $this->expectNoProvisioning();
        $error = new \RuntimeException('token endpoint down');
        $this->handler->expects(self::once())->method('complete')->willThrowException($error);
        $this->identityLinker->expects(self::never())->method('link');
        $this->logger->expects(self::once())->method('critical')->with($error);
        $this->messageManager->expects(self::once())->method('addErrorMessage');
        $this->expectAccountSsoRedirect();

        $intentStorage = $this->createMock(SsoIntentStorage::class);
        $intentStorage->expects(self::once())
            ->method('consume')
            ->with('the-state')
            ->willReturn(new SsoIntent(SsoIntent::MODE_LINK, 42));

        $controller = $this->buildController($intentStorage, $this->passThroughEscaper());
        self::assertSame($this->redirect, $controller->execute());
    }

    public function testIncompleteResponseWithoutStateSkipsIntentLookup(): void
    {
        $this->withParams(['code' => 'the-code']);
        $this->expectLoginRedirect();
        $this->expectNoProvisioning();

        $intentStorage = $this->createMock(SsoIntentStorage::class);
        $intentStorage->expects(self::never())->method('consume');
        $this->stateStorage = $this->createMock(AuthorizationStateStorageInterface::class);
        $this->stateStorage->expects(self::never())->method('consume');
        $this->handler->expects(self::never())->method('complete');
        $this->logger->expects(self::never())->method('critical');
        $this->messageManager->expects(self::once())
            ->method('addErrorMessage')
            ->with('The SSO sign-in response was incomplete. Please try again.');

        $controller = $this->buildController($intentStorage, $this->passThroughEscaper());
        self::assertSame($this->redirect, $controller->execute());
    }

    /**
     * @param array<string,string> $params
     */
    private function withParams(array $params): void
    {
        $this->request->method('getParam')->willReturnCallback(
            static fn(string $name) => $params[$name] ?? null
        );
    }

    /**
     * A failure path ends on the customer login page without completing the exchange.
     */
    private function expectLoginRedirect(): void
    {
        $this->redirect->expects(self::once())->method('setPath')->with('customer/account/login');
        $this->identityLinker->expects(self::never())->method('link');
    }

    /**
     * A failure before a verified identity must neither provision nor log the shopper in.
     */
    private function expectNoProvisioning(): void
    {
        $this->provisioner->expects(self::never())->method('provision');
        $this->groupAssigner->expects(self::never())->method('assign');
        $this->sessionCreator->expects(self::never())->method('create');
    }

    public function testProvisionsCustomerEstablishesSessionAndRedirectsToAccountOnSuccess(): void
    {
        $this->withParams(['code' => 'the-code', 'state' => 'the-state']);

        $identity = new Identity('user-1', 'user@example.com', 'User One', ['shoppers']);
        $customer = $this->createStub(CustomerInterface::class);
        $groupedCustomer = $this->createStub(CustomerInterface::class);

        $this->handler->expects(self::once())
            ->method('complete')
            ->with('the-code', 'the-state')
            ->willReturn(new CallbackResult($identity));
        $this->provisioner->expects(self::once())
            ->method('provision')
            ->with($identity)
            ->willReturn($customer);
        // The group assigner runs on the provisioned customer; the session is
        // established for whatever customer it returns (re-saved when the group changed).
        $this->groupAssigner->expects(self::once())
            ->method('assign')
            ->with($customer, $identity)
            ->willReturn($groupedCustomer);
        $this->sessionCreator->expects(self::once())->method('create')->with($groupedCustomer);
        $this->identityLinker->expects(self::never())->method('link');

        $this->redirect->expects(self::once())->method('setPath')->with('customer/account');
        $this->messageManager->expects(self::never())->method('addErrorMessage');
        $this->logger->expects(self::never())->method('critical');

        self::assertSame($this->redirect, $this->controller->execute());
    }

    public function testShowsLocalizedErrorWhenProvisioningFails(): void
    {
        $this->withParams(['code' => 'the-code', 'state' => 'the-state']);
        $this->expectLoginRedirect();

        $this->handler->expects(self::once())
            ->method('complete')
            ->willReturn(new CallbackResult(new Identity('user-1', null, null, [])));
        $this->provisioner->expects(self::once())
            ->method('provision')
            ->willThrowException(new LocalizedException(
                __('The identity provider did not release an email address required to create a customer account.')
            ));
        // A failed provision must never assign a group or establish a session.
        $this->groupAssigner->expects(self::never())->method('assign');
        $this->sessionCreator->expects(self::never())->method('create');

        $this->logger->expects(self::never())->method('critical');
        $this->messageManager->expects(self::once())
            ->method('addErrorMessage')
            ->with('The identity provider did not release an email address required to create a customer account.');

        self::assertSame($this->redirect, $this->controller->execute());
    }

    public function testEscapesLocalizedProvisioningErrorBeforeShowing(): void
    {
        // A repository/validation exception can embed IdP-supplied email/name; since
        // storefront messages render as raw HTML it must pass through escapeHtml.
        $payload = '<img src=x onerror=alert(1)>';
        $escaped = '&lt;img src=x onerror=alert(1)&gt;';

        $escaper = $this->createMock(Escaper::class);
        $escaper->expects(self::once())
            ->method('escapeHtml')
            ->with($payload)
            ->willReturn($escaped);

        $controller = $this->buildController($this->createStub(SsoIntentStorage::class), $escaper);

        $this->withParams(['code' => 'the-code', 'state' => 'the-state']);
        $this->expectLoginRedirect();

        $this->handler->expects(self::once())
            ->method('complete')
            ->willReturn(new CallbackResult(new Identity('user-1', null, null, [])));
        $this->provisioner->expects(self::once())
            ->method('provision')
            ->willThrowException(new LocalizedException(__($payload)));
        // A failed provision must never assign a group or establish a session.
        $this->groupAssigner->expects(self::never())->method('assign');
        $this->sessionCreator->expects(self::never())->method('create');

        $this->logger->expects(self::never())->method('critical');
        $this->messageManager->expects(self::once())
            ->method('addErrorMessage')
            ->with($escaped);

        self::assertSame($this->redirect, $controller->execute());
    }

    public function testRedirectsToLoginOnIdpError(): void
    {
        $this->withParams(['error' => 'access_denied', 'error_description' => 'User denied access']);
        $this->expectLoginRedirect();
        $this->expectNoProvisioning();

        $this->handler->expects(self::never())->method('complete');
        $this->logger->expects(self::never())->method('critical');
        $this->messageManager->expects(self::once())
            ->method('addErrorMessage')
            ->with('The identity provider rejected the sign-in: User denied access');

        self::assertSame($this->redirect, $this->controller->execute());
    }

    public function testEscapesAttackerControlledIdpErrorDescription(): void
    {
        // Storefront messages render as raw HTML: the IdP-supplied error_description
        // must pass through escapeHtml before it reaches the message manager.
        $payload = '<script>alert(1)</script>';
        $escaped = '&lt;script&gt;alert(1)&lt;/script&gt;';

        $escaper = $this->createMock(Escaper::class);
        $escaper->expects(self::once())
            ->method('escapeHtml')
            ->with($payload)
            ->willReturn($escaped);

        $controller = $this->buildController($this->createStub(SsoIntentStorage::class), $escaper);

        $this->withParams(['error' => 'invalid_request', 'error_description' => $payload]);
        $this->expectLoginRedirect();
        $this->expectNoProvisioning();

        $this->handler->expects(self::never())->method('complete');
        $this->logger->expects(self::never())->method('critical');
        $this->messageManager->expects(self::once())
            ->method('addErrorMessage')
            ->with('The identity provider rejected the sign-in: ' . $escaped);

        self::assertSame($this->redirect, $controller->execute());
    }

    public function testRedirectsToLoginOnIdpErrorWithoutDescription(): void
    {
        $this->withParams(['error' => 'server_error']);
        $this->expectLoginRedirect();
        $this->expectNoProvisioning();

        $this->handler->expects(self::never())->method('complete');
        $this->logger->expects(self::never())->method('critical');
        $this->messageManager->expects(self::once())
            ->method('addErrorMessage')
            ->with('The identity provider rejected the sign-in: server_error');

        self::assertSame($this->redirect, $this->controller->execute());
    }

    public function testRedirectsToLoginOnIncompleteResponse(): void
    {
        $this->withParams(['code' => '', 'state' => 'the-state']);
        $this->expectLoginRedirect();
        $this->expectNoProvisioning();

        $this->handler->expects(self::never())->method('complete');
        $this->logger->expects(self::never())->method('critical');
        $this->messageManager->expects(self::once())
            ->method('addErrorMessage')
            ->with('The SSO sign-in response was incomplete. Please try again.');

        self::assertSame($this->redirect, $this->controller->execute());
    }

    public function testShowsLocalizedErrorFromHandler(): void
    {
        $this->withParams(['code' => 'the-code', 'state' => 'the-state']);
        $this->expectLoginRedirect();
        $this->expectNoProvisioning();

        $this->handler->expects(self::once())
            ->method('complete')
            ->willThrowException(new LocalizedException(
                __('The SSO sign-in session is invalid or has expired. Please try again.')
            ));

        $this->logger->expects(self::never())->method('critical');
        $this->messageManager->expects(self::once())
            ->method('addErrorMessage')
            ->with('The SSO sign-in session is invalid or has expired. Please try again.');

        self::assertSame($this->redirect, $this->controller->execute());
    }

    public function testLogsAndShowsGenericOnUnexpectedError(): void
    {
        $this->withParams(['code' => 'the-code', 'state' => 'the-state']);
        $this->expectLoginRedirect();
        $this->expectNoProvisioning();

        $error = new \RuntimeException('token endpoint down');
        $this->handler->expects(self::once())->method('complete')->willThrowException($error);

        $this->logger->expects(self::once())->method('critical')->with($error);
        $this->messageManager->expects(self::once())
            ->method('addErrorMessage')
            ->with('Could not complete SSO sign-in. Please try again or contact the store owner.');

        self::assertSame($this->redirect, $this->controller->execute());
    }
}
