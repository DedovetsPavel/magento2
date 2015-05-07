<?php
/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Backend\App\Action\Plugin;

use Magento\Framework\Exception\AuthenticationException;

class Authentication
{
    /**
     * @var \Magento\Backend\Model\Auth
     */
    protected $_auth;

    /**
     * @var string[]
     */
    protected $_openActions = [
        'forgotpassword',
        'resetpassword',
        'resetpasswordpost',
        'logout',
        'refresh', // captcha refresh
    ];

    /**
     * @var \Magento\Backend\Model\UrlInterface
     */
    protected $_url;

    /**
     * @var \Magento\Framework\App\ResponseInterface
     */
    protected $_response;

    /**
     * @var \Magento\Framework\App\ActionFlag
     */
    protected $_actionFlag;

    /**
     * @var \Magento\Framework\Message\ManagerInterface
     */
    protected $messageManager;

    /**
     * @var \Magento\Backend\Model\UrlInterface
     */
    protected $backendUrl;

    /**
     * @var \Magento\Backend\App\BackendAppList
     */
    protected $backendAppList;

    /**
     * @var \Magento\Framework\Controller\Result\RedirectFactory
     */
    protected $resultRedirectFactory;

    /**
     * @param \Magento\Backend\Model\Auth $auth
     * @param \Magento\Backend\Model\UrlInterface $url
     * @param \Magento\Framework\App\ResponseInterface $response
     * @param \Magento\Framework\App\ActionFlag $actionFlag
     * @param \Magento\Framework\Message\ManagerInterface $messageManager
     * @param \Magento\Backend\Model\UrlInterface $backendUrl
     * @param \Magento\Framework\Controller\Result\RedirectFactory $resultRedirectFactory
     * @param \Magento\Backend\App\BackendAppList $backendAppList
     */
    public function __construct(
        \Magento\Backend\Model\Auth $auth,
        \Magento\Backend\Model\UrlInterface $url,
        \Magento\Framework\App\ResponseInterface $response,
        \Magento\Framework\App\ActionFlag $actionFlag,
        \Magento\Framework\Message\ManagerInterface $messageManager,
        \Magento\Backend\Model\UrlInterface $backendUrl,
        \Magento\Framework\Controller\Result\RedirectFactory $resultRedirectFactory,
        \Magento\Backend\App\BackendAppList $backendAppList
    ) {
        $this->_auth = $auth;
        $this->_url = $url;
        $this->_response = $response;
        $this->_actionFlag = $actionFlag;
        $this->messageManager = $messageManager;
        $this->backendUrl = $backendUrl;
        $this->resultRedirectFactory = $resultRedirectFactory;
        $this->backendAppList = $backendAppList;
    }

    /**
     * @param \Magento\Backend\App\AbstractAction $subject
     * @param callable $proceed
     * @param \Magento\Framework\App\RequestInterface $request
     *
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundDispatch(
        \Magento\Backend\App\AbstractAction $subject,
        \Closure $proceed,
        \Magento\Framework\App\RequestInterface $request
    ) {
        $requestedActionName = $request->getActionName();
        if (in_array($requestedActionName, $this->_openActions)) {
            $request->setDispatched(true);
        } else {
            if ($this->_auth->getUser()) {
                $this->_auth->getUser()->reload();
            }
            if (!$this->_auth->isLoggedIn()) {
                $this->_processNotLoggedInUser($request);
            } else {
                $this->_auth->getAuthStorage()->prolong();

                $backendApp = null;
                if ($request->getParam('app')) {
                    $backendApp = $this->backendAppList->getCurrentApp();
                }

                if ($backendApp) {
                    $resultRedirect = $this->resultRedirectFactory->create();
                    $url = $this->backendUrl->getBaseUrl() . $backendApp->getStartupPage();
                    return $resultRedirect->setUrl($url);
                }
            }
        }
        $this->_auth->getAuthStorage()->refreshAcl();
        return $proceed($request);
    }

    /**
     * Process not logged in user data
     *
     * @param \Magento\Framework\App\RequestInterface $request
     * @return void
     */
    protected function _processNotLoggedInUser(\Magento\Framework\App\RequestInterface $request)
    {
        $isRedirectNeeded = false;
        if ($request->getPost('login') && $this->_performLogin($request)) {
            $isRedirectNeeded = $this->_redirectIfNeededAfterLogin($request);
        }
        if (!$isRedirectNeeded && !$request->getParam('forwarded')) {
            if ($request->getParam('isIframe')) {
                $request->setParam(
                    'forwarded',
                    true
                )->setRouteName(
                    'adminhtml'
                )->setControllerName(
                    'auth'
                )->setActionName(
                    'deniedIframe'
                )->setDispatched(
                    false
                );
            } elseif ($request->getParam('isAjax')) {
                $request->setParam(
                    'forwarded',
                    true
                )->setRouteName(
                    'adminhtml'
                )->setControllerName(
                    'auth'
                )->setActionName(
                    'deniedJson'
                )->setDispatched(
                    false
                );
            } else {
                $request->setParam(
                    'forwarded',
                    true
                )->setRouteName(
                    'adminhtml'
                )->setControllerName(
                    'auth'
                )->setActionName(
                    'login'
                )->setDispatched(
                    false
                );
            }
        }
    }

    /**
     * Performs login, if user submitted login form
     *
     * @param \Magento\Framework\App\RequestInterface $request
     * @return bool
     */
    protected function _performLogin(\Magento\Framework\App\RequestInterface $request)
    {
        $outputValue = true;
        $postLogin = $request->getPost('login');
        $username = isset($postLogin['username']) ? $postLogin['username'] : '';
        $password = isset($postLogin['password']) ? $postLogin['password'] : '';
        $request->setPostValue('login', null);

        try {
            $this->_auth->login($username, $password);
        } catch (AuthenticationException $e) {
            if (!$request->getParam('messageSent')) {
                $this->messageManager->addError($e->getMessage());
                $request->setParam('messageSent', true);
                $outputValue = false;
            }
        }
        return $outputValue;
    }

    /**
     * Checks, whether Magento requires redirection after successful admin login, and redirects user, if needed
     *
     * @param \Magento\Framework\App\RequestInterface $request
     * @return bool
     */
    protected function _redirectIfNeededAfterLogin(\Magento\Framework\App\RequestInterface $request)
    {
        $requestUri = null;

        // Checks, whether secret key is required for admin access or request uri is explicitly set
        if ($this->_url->useSecretKey()) {
            $requestUri = $this->_url->getUrl('*/*/*', ['_current' => true]);
        } elseif ($request) {
            $requestUri = $request->getRequestUri();
        }

        if (!$requestUri) {
            return false;
        }

        $this->_response->setRedirect($requestUri);
        $this->_actionFlag->set('', \Magento\Framework\App\Action\Action::FLAG_NO_DISPATCH, true);
        return true;
    }
}
