<?php

namespace Extcode\Cart\Controller\Cart;

/*
 * This file is part of the package extcode/cart.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */
use Psr\Http\Message\ResponseInterface;
use Extcode\Cart\Domain\Model\Order\BillingAddress;
use Extcode\Cart\Domain\Model\Order\Item;
use Extcode\Cart\Domain\Model\Order\ShippingAddress;
use Extcode\Cart\Event\CheckProductAvailabilityEvent;
use Extcode\Cart\View\CartTemplateView;
use http\Exception\InvalidArgumentException;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\View\ViewInterface;

class CartController extends ActionController
{
    protected $defaultViewObjectName = CartTemplateView::class;

    protected function initializeView(ViewInterface $view): void
    {
        if ($this->request->getControllerActionName() !== 'show') {
            return;
        }

        $steps = (int)($this->settings['cart']['steps'] ?? 0);
        if ($steps > 1) {
            if ($this->request->hasArgument('step')) {
                $currentStep = (int)$this->request->getArgument('step') ?: 1;
            } else {
                $currentStep = 1;
            }

            if ($currentStep > $steps) {
                throw new InvalidArgumentException();
            }
            $view->setStep($currentStep);

            if ($currentStep < $steps) {
                $view->assign('nextStep', $currentStep+1);
            }
            if ($currentStep > 1) {
                $view->assign('previousStep', $currentStep-1);
            }
        }
    }

    /**
     * @param Item $orderItem
     * @param BillingAddress $billingAddress
     * @param ShippingAddress $shippingAddress
     */
    public function showAction(
        Item $orderItem = null,
        BillingAddress $billingAddress = null,
        ShippingAddress $shippingAddress = null
    ): ResponseInterface {
        $this->restoreSession();
        if (is_null($billingAddress)) {
            if (isset($this->settings['cart']['pid'])) {
                $sessionData = $GLOBALS['TSFE']->fe_user->getKey('ses', 'cart_billing_address_' . $this->settings['cart']['pid']);
            } else {
                // Handle the case where the key doesn't exist or provide a default value
                $sessionData = null; // Or some other default value or behavior
            }
            if ($sessionData !== null && is_string($sessionData)) {
                $billingAddress = unserialize($sessionData);
            } else {
                // Handle the scenario where $sessionData is null or not a string
                $billingAddress = null; // or provide some other default value or behavior
            }
        } else {
            $sessionData = serialize($billingAddress);
            $GLOBALS['TSFE']->fe_user->setKey('ses', 'cart_billing_address_' . $this->settings['cart']['pid'], $sessionData);
            $GLOBALS['TSFE']->fe_user->storeSessionData();
        }

        if (is_null($shippingAddress)) {
            $pid = isset($this->settings['cart']['pid']) ? $this->settings['cart']['pid'] : null;

            if ($pid !== null) {
                $sessionData = $GLOBALS['TSFE']->fe_user->getKey('ses', 'cart_shipping_address_' . $pid);
            } else {
                // Handle the case where the key doesn't exist, e.g., provide a default value or log an error.
                $sessionData = null;
            }
            if ($sessionData !== null && is_string($sessionData)) {
               $shippingAddress = unserialize($sessionData);
            } else {
               // Handle the scenario where $sessionData is null or not a string
               $billingAddress = null; // or provide some other default value or behavior
            }
        } else {
            $sessionData = serialize($shippingAddress);
            $GLOBALS['TSFE']->fe_user->setKey('ses', 'cart_shipping_address_' . $this->settings['cart']['pid'], $sessionData);
            $GLOBALS['TSFE']->fe_user->storeSessionData();
        }

        if ($orderItem === null) {
            $orderItem = GeneralUtility::makeInstance(
                Item::class
            );

            if ($this->request->getOriginalRequest() &&
                $this->request->getOriginalRequest()->hasArgument('orderItem')
            ) {
                $originalRequestOrderItem = $this->request->getOriginalRequest()->getArgument('orderItem');

                if (isset($originalRequestOrderItem['shippingSameAsBilling'])) {
                    $this->cart->setShippingSameAsBilling($originalRequestOrderItem['shippingSameAsBilling']);
                    $this->sessionHandler->write($this->cart, $this->settings['cart']['pid']);
                }
            }
        } else {
            $this->cart->setShippingSameAsBilling($orderItem->isShippingSameAsBilling());
            $this->sessionHandler->write($this->cart, $this->settings['cart']['pid']);
        }
        if ($billingAddress === null) {
            $billingAddress = GeneralUtility::makeInstance(
                BillingAddress::class
            );
        }
        if ($shippingAddress === null) {
            $shippingAddress = GeneralUtility::makeInstance(
                ShippingAddress::class
            );
        }

        if (
            isset($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['cart']['showCartActionAfterCartWasLoaded']) &&
            !empty($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['cart']['showCartActionAfterCartWasLoaded'])
        ) {
            foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['cart']['showCartActionAfterCartWasLoaded'] as $funcRef) {
                if ($funcRef) {
                    $params = [
                        'request' => $this->request,
                        'settings' => $this->settings,
                        'cart' => &$this->cart,
                        'orderItem' => &$orderItem,
                        'billingAddress' => &$billingAddress,
                        'shippingAddress' => &$shippingAddress,
                    ];

                    GeneralUtility::callUserFunction($funcRef, $params, $this);
                }
            }
        }

        $this->view->assign('cart', $this->cart);

        $this->parseData();

        $assignArguments = [
            'shippings' => $this->shippings,
            'payments' => $this->payments,
            'specials' => $this->specials
        ];
        $this->view->assignMultiple($assignArguments);

        $assignArguments = [
            'orderItem' => $orderItem,
            'billingAddress' => $billingAddress,
            'shippingAddress' => $shippingAddress
        ];
        $this->view->assignMultiple($assignArguments);
        return $this->htmlResponse();
    }

    public function clearAction(): void
    {
        $this->cart = $this->cartUtility->getNewCart($this->pluginSettings);

        $this->sessionHandler->write($this->cart, $this->settings['cart']['pid']);

        $this->redirect('show');
    }

    public function updateAction(): void
    {
        if (!$this->request->hasArgument('quantities')) {
            $this->redirect('show');
        }

        $updateQuantities = $this->request->getArgument('quantities');

        if (!is_array($updateQuantities)) {
            $this->redirect('show');
        }

        $this->cart = $this->sessionHandler->restore($this->settings['cart']['pid']);

        foreach ($updateQuantities as $productId => $quantity) {
            $cartProduct = $this->cart->getProductById($productId);
            if ($cartProduct) {
                $checkAvailabilityEvent = new CheckProductAvailabilityEvent($this->cart, $cartProduct, $quantity);
                $this->eventDispatcher->dispatch($checkAvailabilityEvent);
                if ($checkAvailabilityEvent->isAvailable()) {
                    if (is_array($quantity)) {
                        $cartProduct->changeQuantities($quantity);
                    } else {
                        $cartProduct->changeQuantity($quantity);
                    }
                } else {
                    foreach ($checkAvailabilityEvent->getMessages() as $message) {
                        $this->addFlashMessage(
                            $message->getMessage(),
                            $message->getTitle(),
                            $message->getSeverity(),
                            true
                        );
                    }
                }
            }
        }
        $this->cart->reCalc();

        $this->cartUtility->updateService($this->cart, $this->pluginSettings);

        $this->sessionHandler->write($this->cart, $this->settings['cart']['pid']);

        $this->redirect('show');
    }
}
