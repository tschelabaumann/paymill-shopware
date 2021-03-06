<?php

/**
 * Shopware 4.0
 * Copyright © 2012 shopware AG
 *
 * According to our dual licensing model, this program can be used either
 * under the terms of the GNU Affero General Public License, version 3,
 * or under a proprietary license.
 *
 * The texts of the GNU Affero General Public License with an additional
 * permission and of our proprietary license can be found at and
 * in the LICENSE file you have received along with this program.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * "Shopware" is a registered trademark of shopware AG.
 * The licensing of the program under the AGPLv3 does not imply a
 * trademark license. Therefore any rights, title and interest in
 * our trademarks remain entirely with us.
 *
 * @category   Shopware
 * @package    Shopware_Plugins
 * @subpackage Paymill
 * @author     Paymill
 */
class Shopware_Plugins_Frontend_PaymPaymentCreditcard_Bootstrap extends Shopware_Components_Plugin_Bootstrap
{

    /**
     * @var Shopware_Plugins_Frontend_PaymPaymentCreditcard_Components_Util
     */
    private $util;

    /**
     * Indicates the caches to be cleared after install/enable/disable the plugin
     * @var type
     */
    private $clearCache = array(
        'config', 'backend', 'theme'
    );

    /**
     * initiates this class
     */
    public function init()
    {
        $this->util = new Shopware_Plugins_Frontend_PaymPaymentCreditcard_Components_Util();
    }

    /**
     * Returns the version
     *
     * @return string
     */
    public function getVersion()
    {
        return "2.0.2";
    }

    /**
     * Triggered on every request
     *
     * @param $args
     *
     * @return void
     */
    public function onPostDispatch(Enlight_Event_EventArgs $args)
    {
        $request = $args->getSubject()->Request();
        $response = $args->getSubject()->Response();
        $view = $args->getSubject()->View();

        if (!$request->isDispatched() || $response->isException() || $request->getModuleName() != 'frontend' || !$view->hasTemplate()) {
            return;
        }

        // if there is a token in the request save it for later use
        if ($request->get("paymillToken")) {
            Shopware()->Session()->paymillTransactionToken = $request->get("paymillToken");
        }
        if ($request->get("paymillName")) {
            Shopware()->Session()->paymillTransactionName = $request->get("paymillName");
        }

        $user = Shopware()->Session()->sOrderVariables['sUserData'];
        $userId = $user['billingaddress']['userID'];
        $paymentName = $user['additional']['payment']['name'];

        if (in_array($user['additional']['payment']['name'], array("paymillcc", "paymilldebit"))) {
            $view->sRegisterFinished = 'false';
            $modelHelper = new Shopware_Plugins_Frontend_PaymPaymentCreditcard_Components_ModelHelper();
            $paymentId = $modelHelper->getPaymillPaymentId($paymentName, $userId);
            if ($paymentId != null && empty(Shopware()->Session()->paymillTransactionToken)) {
                $view->sRegisterFinished = null;
                Shopware()->Session()->paymillTransactionToken = "NoTokenRequired";
            }
        }
    }

    /**
     * Extends the confirmation page with an error box, if there is an error.
     * Saves the Amount into the Session and passes it to the Template
     *
     * @param Enlight_Event_EventArgs $arguments
     *
     * @return null
     */
    public function onCheckoutConfirm(Enlight_Event_EventArgs $arguments)
    {
        $controller = $arguments->getSubject();
        $controller->View()->addTemplateDir($this->Path() . 'Views/common/');
        if (Shopware()->Shop()->getTemplate()->getVersion() >= 3) {
            $controller->View()->addTemplateDir($this->Path() . 'Views/responsive/');
        }else{
            $controller->View()->addTemplateDir($this->Path() . 'Views/emotion/');
        }

        if (!$this->isDispatchedEventValid(
            $arguments, array('checkout'), array('finish', 'confirm')
        )) {
            return null;
        }

        $view = $arguments->getSubject()->View();
        $user = Shopware()->System()->sMODULES['sAdmin']->sGetUserData();
        $params = $arguments->getRequest()->getParams();

        // Assign prefilled data to template
        $prefillDataService = new Shopware_Plugins_Frontend_PaymPaymentCreditcard_Components_Checkout_Form_PrefillData();
        $prefillData = $prefillDataService->prefill($user);
        $view->assign($prefillData);

        $swConfig = Shopware()->Plugins()->Frontend()->PaymPaymentCreditcard()->Config();
        $userId = $user['billingaddress']['userID'];
        $paymentName = $user['additional']['payment']['name'];
        if (in_array($paymentName, array("paymillcc", "paymilldebit"))) {
            $view->sRegisterFinished = 'false';
            if($prefillDataService->isDataAvailable($paymentName, $userId)){
                Shopware()->Session()->paymillTransactionToken = "NoTokenRequired";
            }
        }

        //Save amount into session to allow 3Ds
        $totalAmount = round((float) $view->getAssign('sAmount') * 100, 2);

        Shopware()->Session()->paymillTotalAmount = $totalAmount;
        $arguments->getSubject()->View()->Template()->assign("tokenAmount", $totalAmount);
        $arguments->getSubject()->View()->Template()->assign("publicKey", trim($swConfig->get("publicKey")));
        $arguments->getSubject()->View()->Template()->assign("sepaActive", $swConfig->get("paymillSepaActive"));
        $arguments->getSubject()->View()->Template()->assign("debug", $swConfig->get("paymillDebugging"));
        $arguments->getSubject()->View()->Template()->assign("CreditcardBrands", $this->getEnabledCreditcardbrands());
        $arguments->getSubject()->View()->Template()->assign("paymillPCI", $swConfig->get("paymillPCI"));

        if ($paymentName === "paymilldebit" && Shopware()->Session()->sOrderVariables['sOrderNumber']) {
            $sepaDate = $this->util->getSepaDate(Shopware()->Session()->sOrderVariables['sOrderNumber']);
            $arguments->getSubject()->View()->Template()->assign("sepaDate", date('d.m.Y', $sepaDate));
            if (Shopware()->Shop()->getTemplate()->getVersion() < 3) {
                $view->extendsBlock("frontend_checkout_finishs_transaction_number", "{include file='frontend/Paymillfinish.tpl'}", "append");
            }
        }
        if ($arguments->getRequest()->getActionName() !== 'confirm' && !isset($params["errorMessage"])) {
            return;
        }

        $isConexcoActiveSql = "SELECT active FROM s_core_plugins WHERE name='SwfResponsiveTemplate'";
        $isConexcoActive = (int) Shopware()->Db()->fetchOne($isConexcoActiveSql) === 1;
        if ($isConexcoActive) {
            $templateConfig = Shopware()->Plugins()->Frontend()->SwfResponsiveTemplate()->Config();
            $templateActive = $templateConfig->get('SwfResponsiveTemplateActive');
        } else {
            $templateActive = false;
        }

        $pigmbhErrorMessage = Shopware()->Session()->pigmbhErrorMessage;
        $class = $templateActive ? 'pm_error_replica' : '';
        unset(Shopware()->Session()->pigmbhErrorMessage);
        $content = '{if $pigmbhErrorMessage} ' .
            '<div class="grid_20 {$pigmbhErrorClass}">' .
            '<div class="error">' .
            '<div class="center">' .
            '<strong> {$pigmbhErrorMessage} </strong>' .
            '</div>' .
            '</div>' .
            '</div> ' .
            '{/if}';
        if (Shopware()->Shop()->getTemplate()->getVersion() < 3) {
            $view->extendsBlock("frontend_index_content_top", $content, "append");
        }
        $view->setScope(Enlight_Template_Manager::SCOPE_PARENT);
        $view->pigmbhErrorMessage = $pigmbhErrorMessage;
        $view->pigmbhErrorClass = $class;
        $view->pigmbhTemplateActive = $templateActive;
    }

    /**
     * Returns all enabled brands
     *
     * @return array
     */
    private function getEnabledCreditcardbrands()
    {
        $config = Shopware()->Plugins()->Frontend()->PaymPaymentCreditcard()->Config();
        $shouldBe = array("amex", "carta-si", "carte-bleue", "dankort", "diners-club", "discover", "jcb", "maestro", "mastercard", "china-unionpay", "visa");
        $result = array();
        $result[] = $config->get("paymillBrandIconAmex") ? 'amex' : '';
        $result[] = $config->get("paymillBrandIconCartaSi") ? 'carta-si' : '';
        $result[] = $config->get("paymillBrandIconCarteBleue") ? 'carte-bleue' : '';
        $result[] = $config->get("paymillBrandIconDankort") ? 'dankort' : '';
        $result[] = $config->get("paymillBrandIconDinersclub") ? 'diners-club' : '';
        $result[] = $config->get("paymillBrandIconDiscover") ? 'discover' : '';
        $result[] = $config->get("paymillBrandIconJcb") ? 'jcb' : '';
        $result[] = $config->get("paymillBrandIconMaestro") ? 'maestro' : '';
        $result[] = $config->get("paymillBrandIconMastercard") ? 'mastercard' : '';
        $result[] = $config->get("paymillBrandIconUnionpay") ? 'china-unionpay' : '';
        $result[] = $config->get("paymillBrandIconVisa") ? 'visa' : '';

        $arrayLength = count(array_diff($shouldBe, $result));
        return ($arrayLength === 0 || $arrayLength === 11) ? $shouldBe : $result;
    }

    /**
     * Get Info for the Pluginmanager
     *
     * @return array
     */
    public function getInfo()
    {
        return array('version' => $this->getVersion(),
            'author' => 'PAYMILL GmbH',
            'source' => $this->getSource(),
            'supplier' => 'PAYMILL GmbH',
            'support' => 'support@paymill.com',
            'link' => 'https://www.paymill.com',
            'copyright' => 'Copyright (c) 2015, Paymill GmbH',
            'label' => 'Paymill',
            'description' => '<h2>Payment plugin for Shopware Community Edition Version 4.0.0 - 4.3.6</h2>'
            . '<ul>'
            . '<li style="list-style: inherit;">PCI DSS compatibility</li>'
            . '<li style="list-style: inherit;">Payment means: Credit Card (Visa, Visa Electron, Mastercard, Maestro, Diners, Discover, JCB, AMEX, China Union Pay), Direct Debit (ELV)</li>'
            . '<li style="list-style: inherit;">Refunds can be created from an additional tab in the order detail view</li>'
            . '<li style="list-style: inherit;">Optional configuration for authorization and manual capture with credit card payments</li>'
            . '<li style="list-style: inherit;">Optional fast checkout configuration allowing your customers not to enter their payment detail over and over during checkout</li>'
            . '<li style="list-style: inherit;">Improved payment form with visual feedback for your customers</li>'
            . '<li style="list-style: inherit;">Supported Languages: German, English, Italian, French, Spanish, Portuguese</li>'
            . '<li style="list-style: inherit;">Backend Log with custom View accessible from your shop backend</li>'
            . '</ul>'
        );
    }

    /**
     * Eventhandler for the update of the client with new data on email change
     * @param $arguments
     */
    public function onUpdateCustomerEmail($arguments)
    {
        $user = Shopware()->System()->sMODULES['sAdmin']->sGetUserData();
        $userId = $user['billingaddress']['userID'];
        $modelHelper = new Shopware_Plugins_Frontend_PaymPaymentCreditcard_Components_ModelHelper();
        $clientId = $modelHelper->getPaymillClientId($userId);

        //If there is a client for the customer
        if ($clientId !== "") {
            $email = $arguments['email'];
            $description = $user['billingaddress']['customernumber'] . " " . Shopware()->Config()->get('shopname');
            $description = substr($description,0,128);
            //Update the client
            $swConfig = Shopware()->Plugins()->Frontend()->PaymPaymentCreditcard()->Config();
            $privateKey = trim($swConfig->get("privateKey"));
            $apiUrl = "https://api.paymill.com/v2/";
            require_once dirname(__FILE__) . '/lib/Services/Paymill/Clients.php';
            $client = new Services_Paymill_Clients($privateKey, $apiUrl);
            $client->update(array('id' => $clientId, 'email' => $email, 'description' => $description));
        }
    }

    /**
     * Eventhandler for the display of the paymill order operations tab in the order detail view
     *
     * @param $arguments
     */
    public function extendOrderDetailView($arguments)
    {
        $arguments->getSubject()->View()->addTemplateDir($this->Path() . 'Views/');

        if ($arguments->getRequest()->getActionName() === 'load') {
            $arguments->getSubject()->View()->extendsTemplate('backend/paymill_order_operations/view/main/window.js');
        }

        if ($arguments->getRequest()->getActionName() === 'index') {
            $arguments->getSubject()->View()->extendsTemplate('backend/paymill_order_operations/app.js');
        }
    }

    /**
     * Validates if an event has the right context.
     *
     * Example:
     * $this->isDispatchedEventValid(
     *     $arguments, array('checkout'), array('finish')
     * )
     *
     * See also: Shopware 4 Events und Hooks
     * @link http://wiki.shopware.com/Shopware-4-Events-und-Hooks_detail_981.html
     *
     * @param  Enlight_Event_EventArgs $arguments
     * @param  array                   $controller array of valid controller
     * @param  array                   $actions    array of valid actions
     * @return boolean
     */
    private function isDispatchedEventValid(
        Enlight_Event_EventArgs $arguments,
        array $controller,
        array $actions
    )
    {
        $isValid = false;

        $currentController = $arguments->getSubject();
        $request = $currentController->Request();
        $response = $currentController->Response();
        $currentAction = $request->getActionName();
        $view = $currentController->View();
        $currentControllerName = $request->getControllerName();

        if($request->isDispatched()
            && !$response->isException()
            && $request->getModuleName() == 'frontend'
            && in_array($currentControllerName, $controller)
            && in_array($currentAction, $actions)
            && $view->hasTemplate()
        ) {
            $isValid = true;
        }

        return $isValid;
    }

    /**
     * Fixes a known issue.
     *
     * @throws Exception
     */
    private function solveKnownIssue()
    {
        try {
            //Deleting translation for mainshop which causes in not be able to change it via backend
            Shopware()->Db()->delete('s_core_translations', Shopware()->Db()->quoteInto('objecttype = ?', "config_payment")
                . ' AND ' . Shopware()->Db()->quoteInto('objectkey = ?', 1)
                . ' AND ' . Shopware()->Db()->quoteInto('objectlanguage = ?', "1")
            );
        } catch (Exception $exception) {
            throw new Exception($exception->getMessage());
        }
    }

    /**
     * Performs the necessary installation steps
     *
     * @throws Exception
     * @return boolean
     */
    public function install()
    {
        try {
            Shopware_Plugins_Frontend_PaymPaymentCreditcard_Components_WebhookService::install();
            Shopware_Plugins_Frontend_PaymPaymentCreditcard_Components_LoggingManager::install();
            Shopware_Plugins_Frontend_PaymPaymentCreditcard_Components_ModelHelper::install($this);
            $this->createPaymentMeans();
            $this->_createForm();
            $this->_registerController();
            $this->_createEvents();
            $this->_updateOrderMail();
            $this->_applyBackendViewModifications();
            $this->_translatePaymentNames();
            $translationHelper = new Shopware_Plugins_Frontend_PaymPaymentCreditcard_Components_TranslationHelper($this->Form());
            $translationHelper->createPluginConfigTranslation();
            $this->solveKnownIssue();
            $this->Plugin()->setActive(true);
        } catch (Exception $exception) {
            $this->uninstall();
            throw new Exception($exception->getMessage());
        }

        return array('success' => parent::install(),'invalidateCache' => $this->clearCache);
    }

    /**
     * Adds Sepa-Information to Invoice
     *
     * @param Enlight_Hook_HookArgs $arguments
     */
    public function beforeCreatingDocument(Enlight_Hook_HookArgs $arguments)
    {
        $document = $arguments->getSubject();
        $view = $document->_view;
        if ($document->_order->payment['name'] != 'paymilldebit') {
            return;
        }

        $document->_template->addTemplateDir(dirname(__FILE__) . '/Views/');
        $containerData = $view->getTemplateVars('Containers');
        $containerData['Content_Info']['value'] .= 'PLACEHOLDER';
        $view->assign('Containers', $containerData);
    }

    /**
     * Adds Sepa-Information to Order confirmation mail
     *
     * @param Enlight_Event_EventArgs $arguments
     */
    public function beforeSendingMail(Enlight_Event_EventArgs $arguments)
    {
        $context = $arguments->get('context');
        if ($context['additional']['payment']['name'] != 'paymilldebit' || !isset($context['sOrderNumber'])) {
            return;
        }

    $paymillSepaDate = $this->util->getSepaDate($context['sOrderNumber']);
    if (!empty($paymillSepaDate)) {
            $context['paymillSepaDate'] = date("d.m.Y", $paymillSepaDate);
            $mail = Shopware()->TemplateMail()->createMail('sORDER', $context);
            $arguments->setReturn($mail);
            return $mail;
        }
    }

    /**
     * Performs the necessary uninstall steps
     *
     * @return boolean
     */
    public function uninstall()
    {
        $translationHelper = new Shopware_Plugins_Frontend_PaymPaymentCreditcard_Components_TranslationHelper(null);
        $translationHelper->dropSnippets();
        $this->removeSnippets();
        return parent::uninstall();
    }

    /**
     * Updates the Plugin and its components
     *
     * @param string $oldVersion
     *
     * @throws Exception
     * @return boolean
     */
    public function update($oldVersion)
    {
        try {
            switch ($oldVersion) {
                case "1.0.0":
                case "1.0.1":
                case "1.0.2":
                case "1.0.3":
                case "1.0.4":
                case "1.0.5":
                case "1.0.6":
                case "1.1.0":
                case "1.1.1":
                case "1.1.2":
                case "1.1.3":
                case "1.1.4":
                case "1.2.0":
                case "1.3.0":
                case "1.3.1":
                case "1.4.0":
                case "1.4.1":
                case "1.4.2":
                case "1.4.3":
                case "1.4.4":
                case "1.4.5":
                case "1.4.6":
                case "1.4.7":
                case "1.5.0":
                case "1.5.1":
                case "1.5.2":
                    $sql = "DELETE FROM s_core_config_element_translations
                        WHERE element_id IN (SELECT s_core_config_elements.id FROM s_core_config_elements
                        WHERE s_core_config_elements.form_id = (SELECT s_core_config_forms.id FROM s_core_config_forms
                        WHERE s_core_config_forms.plugin_id = ?));
                        DELETE FROM s_core_config_elements
                        WHERE form_id = (SELECT id FROM s_core_config_forms WHERE plugin_id = ?);";
                     Shopware()->Db()->query($sql, array($this->getId(), $this->getId()));
            }
            return true;
        } catch (Exception $exception) {
            throw new Exception($exception->getMessage());
        }
    }

    /**
     * Translates the payment names
     *
     * @throws Exception
     * @return void
     */
    private function _translatePaymentNames()
    {
        try {
            $ccPayment = $this->Payments()->findOneBy(array('name' => 'paymillcc'));
            $elvPayment = $this->Payments()->findOneBy(array('name' => 'paymilldebit'));

            $sortedSnippets = parse_ini_file(dirname(__FILE__).'/snippets/frontend/paym_payment_creditcard/checkout/payments.ini', true);
            $shops = Shopware()->Db()->select()
                ->from('s_core_shops', array('id', 'default'))
                ->joinInner('s_core_locales','`s_core_shops`.`locale_id`=`s_core_locales`.`id`','locale')
                ->query()
                ->fetchAll();

            foreach ($shops as $shop) {
                $shopId = $shop['id'];
                $locale = $shop['locale'];
                $this->updatePaymentTranslation($shopId, $ccPayment->getID(), $sortedSnippets[$locale]['creditcard'], $shop['default']);
                $this->updatePaymentTranslation($shopId, $elvPayment->getID(), $sortedSnippets[$locale]['directdebit'], $shop['default']);
            }

        } catch (Exception $exception) {
            throw new Exception("Can not create translation for payment names." . $exception->getMessage());
        }
    }

    /**
     * Update the translation of a payment
     *
     * @param integer $shopId
     * @param integer $paymentId
     * @param string $description
     * @param integer $default
     */
    private function updatePaymentTranslation($shopId, $paymentId, $description, $default)
    {
        if ($default) {
            Shopware()->Db()->update('s_core_paymentmeans', array(
                'description' => $description
                ), 'id=' . $paymentId
            );
        } else {
            $translationObject = new Shopware_Components_Translation();
            $translationObject->write(
                $shopId, "config_payment", $paymentId, array('description' => $description), true
            );
        }
    }

    /**
     * Disables the plugin
     *
     * @throws Exception
     * @return boolean
     */
    public function disable()
    {
        try {

            $payment[0] = 'paymillcc';
            $payment[1] = 'paymilldebit';

            foreach ($payment as $key) {
                $currentPayment = $this->Payments()->findOneBy(array('name' => $key));
                if ($currentPayment) {
                    $currentPayment->setActive(false);
                }
            }
        } catch (Exception $exception) {
            throw new Exception("Cannot disable payment: " . $exception->getMessage());
        }

        return array('success' => true, 'invalidateCache' => $this->clearCache);
    }

    public function enable()
    {
        return array('success' => true, 'invalidateCache' => $this->clearCache);
    }

    /**
     * Creates the payment method
     *
     * @throws Exception
     * @return void
     */
    protected function createPaymentMeans()
    {
        try {
            $this->createPayment(
                array(
                    'active' => 0,
                    'name' => 'paymillcc',
                    'action' => 'payment_paymill',
                    'template' => 'paymill.tpl',
                    'description' => 'Kreditkartenzahlung',
                    'additionalDescription' => ''
                )
            );

            $this->createPayment(
                array(
                    'active' => 0,
                    'name' => 'paymilldebit',
                    'action' => 'payment_paymill',
                    'template' => 'paymill.tpl',
                    'description' => 'ELV',
                    'additionalDescription' => ''
                )
            );
        } catch (Exception $exception) {
            throw new Exception("There was an error creating the payment means. " . $exception->getMessage());
        }
    }

    /**
     * Creates the configuration fields
     *
     * @throws Exception
     * @return void
     */
    private function _createForm()
    {
        try {
            $form = $this->Form();
            $form->setElement('text', 'publicKey', array('label' => 'Public Key', 'required' => true, 'position' => 0));
            $form->setElement('text', 'privateKey', array('label' => 'Private Key', 'required' => true, 'position' => 10));
            $form->setElement('select', 'paymillPCI', array('label' => 'Payment form', 'value' => 0, 'position' => 20, 'store' => array( array(0, 'PayFrame (min. PCI SAQ A)'),array(1, 'direct integration (min. PCI SAQ A-EP)'))));
            $form->setElement('number', 'paymillSepaDate', array('label' => 'Days until debit', 'required' => true, 'value' => 7, 'position' => 30, 'attributes' => array('minValue' => 0)));
            $form->setElement('checkbox', 'paymillPreAuth', array('label' => 'Authorize credit card transactions during checkout and capture manually', 'value' => false, 'position' => 40));
            $form->setElement('checkbox', 'paymillDebugging', array('label' => 'Activate debugging', 'value' => false, 'position' => 50));
            $form->setElement('checkbox', 'paymillFastCheckout', array('label' => 'Save data for FastCheckout', 'value' => false, 'position' => 60));
            $form->setElement('checkbox', 'paymillLogging', array('label' => 'Activate logging', 'value' => true, 'position' => 70));
            $form->setElement('checkbox', 'paymillBrandIconAmex', array('label' => 'American Express', 'value' => false, 'position' => 80));
            $form->setElement('checkbox', 'paymillBrandIconCartaSi', array('label' => 'Carta Si', 'value' => false, 'position' => 90));
            $form->setElement('checkbox', 'paymillBrandIconCarteBleue', array('label' => 'Carte Bleue', 'value' => false, 'position' => 100));
            $form->setElement('checkbox', 'paymillBrandIconDankort', array('label' => 'Dankort', 'value' => false, 'position' => 110));
            $form->setElement('checkbox', 'paymillBrandIconDinersclub', array('label' => 'Dinersclub', 'value' => false, 'position' => 120));
            $form->setElement('checkbox', 'paymillBrandIconDiscover', array('label' => 'Discover', 'value' => false, 'position' => 130));
            $form->setElement('checkbox', 'paymillBrandIconJcb', array('label' => 'JCB', 'value' => false, 'position' => 140));
            $form->setElement('checkbox', 'paymillBrandIconMaestro', array('label' => 'Maestro', 'value' => false, 'position' => 150));
            $form->setElement('checkbox', 'paymillBrandIconMastercard', array('label' => 'Mastercard', 'value' => false, 'position' => 160));
            $form->setElement('checkbox', 'paymillBrandIconUnionpay', array('label' => 'China Unionpay', 'value' => false, 'position' => 170));
            $form->setElement('checkbox', 'paymillBrandIconVisa', array('label' => 'Visa', 'value' => false, 'position' => 180));
        } catch (Exception $exception) {
            throw new Exception("There was an error creating the plugin configuration. " . $exception->getMessage());
        }
    }

    /**
     * Registers all Controllers
     */
    private function _registerController(){
        $this->registerController('Frontend', 'PaymentPaymill');
        $this->registerController('Backend', 'PaymillLogging');
        $this->registerController('Backend', 'PaymillOrderOperations');
    }


    /**
     * Creates all Events for the plugins
     *
     * @throws Exception
     * @return void
     */
    private function _createEvents()
    {
        try {
            $this->subscribeEvent('Enlight_Controller_Action_PostDispatch', 'onPostDispatch');
            $this->subscribeEvent('Enlight_Controller_Action_PostDispatch_Frontend_Checkout', 'onCheckoutConfirm');
            $this->subscribeEvent('Shopware_Modules_Admin_UpdateAccount_FilterEmailSql', 'onUpdateCustomerEmail');
            $this->subscribeEvent('Enlight_Controller_Action_PostDispatch_Backend_Order', 'extendOrderDetailView');
            $this->subscribeEvent('Shopware_Components_Document::assignValues::after', 'beforeCreatingDocument');
            $this->subscribeEvent('Shopware_Modules_Order_SendMail_Create', 'beforeSendingMail');
            $this->subscribeEvent('Shopware_Modules_Order_SaveOrderAttributes_FilterSQL', 'insertOrderAttribute');
            $this->subscribeEvent('Shopware_Controllers_Backend_Config::saveFormAction::before', 'beforeSavePluginConfig');
            $this->subscribeEvent('Theme_Compiler_Collect_Plugin_Javascript', 'addJsFiles');
            $this->subscribeEvent('Theme_Compiler_Collect_Plugin_Less','addLessFiles');
        } catch (Exception $exception) {
            throw new Exception("There was an error registering the plugins events. " . $exception->getMessage());
        }
    }

    /**
     * Registers the endpoint for the notifications
     *
     * @param Enlight_Hook_HookArgs $arguments
     * @return null
     */
    public function beforeSavePluginConfig($arguments)
    {
        $request = $arguments->getSubject()->Request();
        $parameter = $request->getParams();

        if ($parameter['name'] !== $this->getName() || $parameter['controller'] !== 'config') {
            return;
        }

        foreach ($parameter['elements'] as $element) {
            if (in_array($element['name'], array('privateKey')) && empty($element['values'][0]['value'])) {
                return;
            }
            if ($element['name'] === 'privateKey') {
                $privateKey = $element['values'][0]['value'];
            }
        }
        $webHookService = new Shopware_Plugins_Frontend_PaymPaymentCreditcard_Components_WebhookService();
        $webHookService->registerWebhookEndpoint($privateKey);
    }

    /**
     * Modifies the Backend menu by adding a PaymillLogging Label as a child element of the shopware logging
     *
     * @throws Exception
     * @return void
     */
    private function _applyBackendViewModifications()
    {
        try {
            $parent = $this->Menu()->findOneBy(['label' => 'logfile']);
            $this->createMenuItem(array('label' => 'Paymill', 'class' => 'sprite-cards-stack', 'active' => 1,
                'controller' => 'PaymillLogging', 'action' => 'index', 'parent' => $parent));
        } catch (Exception $exception) {
            throw new Exception("can not create menu entry." . $exception->getMessage());
        }
    }

    /**
     * Extends all orderMail-templates
     */
    private function _updateOrderMail()
    {
        $sql = Shopware()->Db()->select()
            ->from('s_core_config_mails', array('content', 'contentHTML'))
            ->where('`name`=?', array("sORDER"));
        $orderMail = Shopware()->Db()->fetchRow($sql);

        $snippets = Shopware()->Db()->select()
            ->from('s_core_snippets', array('shopID', 'value'))
            ->where('`name`=?', array('feedback_info_sepa_date'))
            ->query()
            ->fetchAll();
        foreach ($snippets as $snippet) {
            $additionalContent = '{$additional.payment.additionaldescription}' . "\n"
                . '{if $additional.payment.name == "paymilldebit"}%BR%'
                . $snippet['value'] . ': {$paymillSepaDate}' . "\n"
                . '{/if}' . "\n";
            $content = preg_replace('/%BR%/', "\n", $additionalContent);
            $contentHTML = preg_replace('/%BR%/', "<br/>\n", $additionalContent);

            if ($snippet['shopID'] === '1' && !preg_match('/\$paymillSepaDate/', $orderMail['content']) && !preg_match('/\$paymillSepaDate/', $orderMail['contentHTML'])) {
                $orderMail['content'] = preg_replace('/\\{\\$additional\\.payment\\.additionaldescription\\}/', $content, $orderMail['content']);
                $orderMail['contentHTML'] = preg_replace('/\\{\\$additional\\.payment\\.additionaldescription\\}/', $contentHTML, $orderMail['contentHTML']);
                Shopware()->Db()->update('s_core_config_mails', $orderMail, '`name` LIKE "sORDER"');
            }

            $translationObject = new Shopware_Components_Translation();
            $translation = $translationObject->read($snippet['shopID'], "config_mails", 2);
            if ((array_key_exists('content', $translation) || array_key_exists('content', $translation)) && $snippet['shopID'] !== '1' && !preg_match('/\$paymillSepaDate/', $translation['content']) && !preg_match('/\$paymillSepaDate/', $translation['contentHtml'])) {
                $translation['content'] = preg_replace('/\\{\\$additional\\.payment\\.additionaldescription\\}/', $content, $translation['content']);
                $translation['contentHtml'] = preg_replace('/\\{\\$additional\\.payment\\.additionaldescription\\}/', $contentHTML, $translation['contentHtml']);
                $translationObject->write(
                    $snippet['shopID'], "config_mails", 2, $translation
                );
            }
        }
    }

    /**
     * Adds an Attribte to s_order for sepa
     *
     * @param Enlight_Event_EventArgs $args
     * @return string
     */
    public function insertOrderAttribute(Enlight_Event_EventArgs $args)
    {
        $subject = $args->getSubject();
        if ($subject->sUserData['additional']['payment']['name'] === "paymilldebit") {
            $sepaDays = (int)$this->Config()->get('paymillSepaDate');
            if($sepaDays < 0) {
                $sepaDays = 0;
            }
            $timeStamp = strtotime("+" . $sepaDays . " DAYS");
            $attributeSql = preg_replace('/attribute6/', 'attribute6, paymill_sepa_date', $args->getReturn());
            $attributeSql = preg_replace('/\)$/', ",$timeStamp  )", $attributeSql);
            $args->setReturn($attributeSql);
        }
    }

    /**
    * Provide the file collection for js files
    *
    * @param Enlight_Event_EventArgs $args
    * @return \Doctrine\Common\Collections\ArrayCollection
    */
    public function addJsFiles(Enlight_Event_EventArgs $args)
    {
        $jsFiles = array(
            $this->Path() . 'Views/common/frontend/_public/src/js/BrandDetection.js',
            $this->Path() . 'Views/common/frontend/_public/src/js/Iban.js',
            $this->Path() . 'Views/common/frontend/_public/src/js/PaymillCheckout.js'
        );
        return new Doctrine\Common\Collections\ArrayCollection($jsFiles);
    }

    /**
    * Provide the file collection for Less
    */
   public function addLessFiles(Enlight_Event_EventArgs $args)
   {
       $less = new \Shopware\Components\Theme\LessDefinition(
           array(),
           array(
               __DIR__ . '/Views/common/frontend/_public/src/less/all.less'
           ),
           __DIR__
       );

       return new Doctrine\Common\Collections\ArrayCollection(array($less));
   }

}
