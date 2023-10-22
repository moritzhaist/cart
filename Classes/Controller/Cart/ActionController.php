<?php

namespace Extcode\Cart\Controller\Cart;

/*
 * This file is part of the package extcode/cart.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

use Extcode\Cart\Domain\Model\Cart\Cart;
use Extcode\Cart\Service\SessionHandler;
use Extcode\Cart\Utility\CartUtility;
use Extcode\Cart\Utility\ParserUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManager;

class ActionController extends \TYPO3\CMS\Extbase\Mvc\Controller\ActionController
{
    /**
     * Session Handler
     *
     * @var SessionHandler
     */
    protected $sessionHandler;

    /**
     * Cart Utility
     *
     * @var CartUtility
     */
    protected $cartUtility;

    /**
     * Parser Utility
     *
     * @var ParserUtility
     */
    protected $parserUtility;

    /**
     * Plugin Settings
     *
     * @var array
     */
    protected $pluginSettings;

    /**
     * Cart
     *
     * @var Cart
     */
    protected $cart;

    /**
     * Payments
     *
     * @var array
     */
    protected $payments = [];

    /**
     * Shippings
     *
     * @var array
     */
    protected $shippings = [];

    /**
     * Specials
     *
     * @var array
     */
    protected $specials = [];

    /**
     * @param SessionHandler $sessionHandler
     */
    public function injectSessionHandler(
        SessionHandler $sessionHandler
    ) {
        $this->sessionHandler = $sessionHandler;
    }

    /**
     * @param CartUtility $cartUtility
     */
    public function injectCartUtility(
        CartUtility $cartUtility
    ) {
        $this->cartUtility = $cartUtility;
    }

    /**
     * @param ParserUtility $parserUtility
     */
    public function injectParserUtility(
        ParserUtility $parserUtility
    ) {
        $this->parserUtility = $parserUtility;
    }

    /**
     * Action initialize
     */
    public function initializeAction()
    {
        $this->pluginSettings = $this->configurationManager->getConfiguration(
            ConfigurationManager::CONFIGURATION_TYPE_FRAMEWORK
        );
    }

    /**
     * Parse Data
     */
    protected function parseData()
    {
        // parse all shippings
        $this->shippings = $this->parserUtility->parseServices('Shipping', $this->pluginSettings, $this->cart);

        // parse all payments
        $this->payments = $this->parserUtility->parseServices('Payment', $this->pluginSettings, $this->cart);

        // parse all specials
        $this->specials = $this->parserUtility->parseServices('Special', $this->pluginSettings, $this->cart);
    }

    /**
     *
     */
    protected function restoreSession()
    {
        // Check for the 'cart' key first, then for the nested 'pid' key, and provide a default value of 0 if not set.
        $pid = isset($this->settings['cart']) && isset($this->settings['cart']['pid']) ? $this->settings['cart']['pid'] : 0;

        $this->cart = $this->sessionHandler->restore($pid);

        if (!$this->cart instanceof Cart) {
            $this->cart = $this->cartUtility->getNewCart($this->pluginSettings);
            // Similarly, safely attempt to write to the session using the 'pid' key.
            $this->sessionHandler->write($this->cart, $pid);
        }
    }
}
