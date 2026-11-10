<?php
require_once 'modules/admin/models/PluginCallback.php';
require_once 'modules/admin/models/StatusAliasGateway.php';
require_once 'modules/billing/models/class.gateway.plugin.php';
require_once 'modules/billing/models/Invoice_EventLog.php';
require_once 'modules/admin/models/Error_EventLog.php';
require_once 'modules/clients/models/Client_EventLog.php';

class PluginStripecheckoutCallback extends PluginCallback
{
    function processCallback()
    {
        CE_Lib::log(4, 'Stripecheckout callback invoked');

        // Execute PluginCallback::processCallback() first
        if (!parent::processCallback()) {
            return;
        }

        if (!isset($GLOBALS['testing'])) {
            $testing = false;
        } else {
            $testing = $GLOBALS['testing'];
        }

        if ($this->settings->get('plugin_stripecheckout_Test Mode?') == '1') {
            $key = $this->settings->get('plugin_stripecheckout_Stripe Checkout Test Secret Key');
        } else {
            $key = $this->settings->get('plugin_stripecheckout_Stripe Checkout Gateway Secret Key');
        }

        if (strpos((string)$key, 'sk_') !== 0 && strpos((string)$key, 'rk_') !== 0) {
            CE_Lib::log(
                1,
                'Stripe Checkout: the secret key configured for this gateway is missing or invalid '
                . '(it must start with sk_, or rk_ if it is a restricted API key with limited permissions). Check the Stripe Checkout plugin settings '
                . '(Test Mode is ' . ($this->settings->get('plugin_stripecheckout_Test Mode?') == '1' ? 'on' : 'off') . ').'
            );
        }

        // Use Stripe's bindings...
        \Stripe\Stripe::setApiKey($key);
        \Stripe\Stripe::setAppInfo(
            'Clientexec',
            CE_Lib::getAppVersion(),
            'https://www.clientexec.com',
            STRIPE_PARTNER_ID
        );
        \Stripe\Stripe::setApiVersion(STRIPE_API_VERSION);
        $stripe = new \Stripe\StripeClient($key);

        // Returning from a setup-mode Checkout Session started by updatePaymentMethod(). This has to be
        // handled before the payment flow below, since setup sessions have no line items.
        if (isset($_GET['isPaymentMethod']) && $_GET['isPaymentMethod']) {
            $this->processPaymentMethodCallback($stripe);
            exit;
        }

        $session = false;
        try {
            $session = $stripe->checkout->sessions->retrieve(
                $_GET['session_id'],
                []
            );
        } catch (Exception $e) {
            CE_Lib::log(4, "Invalid Checkout Session: " . $e->getMessage());
            $this->redirect();
        }

        $lineItems = $stripe->checkout->sessions->allLineItems($_GET['session_id'], ['limit' => 5]);
        $invoiceId = substr($lineItems->data[0]->description, 9);

        if ($session !== false) {
            $payment_intent_id = $session->payment_intent;
            // A session the client cancelled or never paid has no PaymentIntent, and
            // PaymentIntent::retrieve() throws on a null ID
            if ($payment_intent_id === null) {
                CE_Lib::log(4, 'Stripe Checkout callback: session ' . $session->id . ' has no payment intent (payment status: ' . $session->payment_status . ')');
                $this->redirect($invoiceId);
            }
            $payment_intent = \Stripe\PaymentIntent::retrieve($payment_intent_id);

            if (!$_GET['isSignup'] && $payment_intent->status == "requires_capture") {
                //Fraud Check
                $params = [
                    'actionType' => 'singlepayment',
                    'plugin' => 'stripecheckout',
                    'invoiceId' => $invoiceId
                ];

                include_once 'modules/billing/models/BillingGateway.php';
                $billingGateway = new BillingGateway($this->user);
                $fraudCheck = $billingGateway->fraudCheckFromInvoice($params);

                if (!$fraudCheck['isOrderAccepted']) {
                    //If fraud
                    header('Location: '.CE_Lib::getSoftwareURL() . "/index.php?fuse=billing&cancel=1&controller=invoice&view=invoice&id=" . $params['invoiceId']);
                    exit;
                }
                //Fraud Check
            }

            if ($payment_intent->status == "requires_capture") {
                $payment_intent = $payment_intent->capture();
            }

            if ($payment_intent->latest_charge === null) {
                $this->redirect($invoiceId);
            }

            $transactionId = \Stripe\Charge::retrieve($payment_intent->latest_charge)->balance_transaction;

            if ($transactionId === null) {
                sleep(3); // 3 seconds
                $payment_intent = \Stripe\PaymentIntent::retrieve($payment_intent_id);
                $transactionId = \Stripe\Charge::retrieve($payment_intent->latest_charge)->balance_transaction;
            }

            $amount = sprintf("%01.2f", round(($payment_intent->amount / 100), 2));

            // Create Plugin class object to interact with CE.
            $cPlugin = new Plugin($invoiceId, basename(dirname(__FILE__)), $this->user);
            $cPlugin->m_TransactionID = $transactionId;
            $cPlugin->setAmount($amount);
            $cPlugin->setAction('charge');
            $cPlugin->m_Last4 = "NA";

            $clientExecURL = CE_Lib::getSoftwareURL();
            $invoiceviewURLSuccess = $clientExecURL."/index.php?fuse=billing&paid=1&controller=invoice&view=invoice&id=".$invoiceId;
            $invoiceviewURLCancel = $clientExecURL."/index.php?fuse=billing&cancel=1&controller=invoice&view=invoice&id=".$invoiceId;

            //Need to check to see if user is coming from signup
            if ($_GET['isSignup']) {
                // Actually handle the signup URL setting
                if ($this->settings->get('Signup Completion URL') != '') {
                    $return_url = $this->settings->get('Signup Completion URL').'?success=1';
                    $cancel_url = $this->settings->get('Signup Completion URL');
                } else {
                    $return_url = $clientExecURL."/order.php?step=complete&pass=1";
                    $cancel_url = $clientExecURL."/order.php?step=3";
                }
            } else {
                $return_url = $invoiceviewURLSuccess;
                $cancel_url = $invoiceviewURLCancel;
            }

            switch ($payment_intent->status) {
                case 'processing':
                    if (isset($transactionId)) {
                        $cPlugin->PaymentPending("Stripe Checkout payment of {$amount} is being processed. Confirmation may take several business days. (Transaction ID: {$transactionId})", $transactionId);
                    }

                    header('Location: '.$return_url);

                    break;
                case 'succeeded':
                    //save profile id
                    $profile_id = $payment_intent->customer;
                    $payment_method = $payment_intent->payment_method;
                    $Billing_Profile_ID = '';
                    $profile_id_array = array();
                    $customerid = $cPlugin->m_Invoice->getUserID();
                    $user = new User($customerid);

                    if ($user->getCustomFieldsValue('Billing-Profile-ID', $Billing_Profile_ID) && $Billing_Profile_ID != '') {
                        $profile_id_array = unserialize($Billing_Profile_ID);
                    }

                    if (!is_array($profile_id_array)) {
                        $profile_id_array = array();
                    }

                    $profile_id_array[basename(dirname(__FILE__))] = $profile_id.'|'.$payment_method;
                    $user->updateCustomTag('Billing-Profile-ID', serialize($profile_id_array));
                    $user->save();
                    //save profile id

                    // "Make this my default payment method" on the invoice page. Stripe Checkout redirects straight
                    // here, so the invoice form never posts; sca.phtml passes the checkbox in a short-lived cookie.
                    if (empty($_GET['isSignup']) && !empty($_COOKIE['stripecheckout_makeDefault'])) {
                        $this->makeDefaultPaymentMethod($user);
                    }
                    setcookie('stripecheckout_makeDefault', '', ['expires' => time() - 3600, 'path' => '/', 'samesite' => 'Lax']);

                    $cPlugin->PaymentAccepted($amount, "Stripe Checkout payment of {$amount} was accepted. (Transaction ID: {$transactionId})", $transactionId);
                    header('Location: '.$return_url);

                    break;
                default: //'failed'
                    if (isset($transactionId)) {
                        $cPlugin->PaymentRejected("Stripe Checkout payment of {$amount} was rejected. (Transaction ID: {$transactionId})");
                    }

                    header('Location: '.$cancel_url);

                    break;
            }
        } else {
            $this->redirect();
        }
    }

    /**
     * Saves the card collected by a setup-mode Checkout Session as the client's Stripe Checkout
     * billing profile, switches the client to this gateway if needed, and returns them to the
     * payment method page.
     */
    private function processPaymentMethodCallback($stripe): void
    {
        //Get client Id from hash
        $encryptedCustomerID = strtr(urldecode($_GET['clientHash']), '-_', '+/');

        require_once 'library/encrypted/Clientexec.php';

        $clientExecURL = CE_Lib::getSoftwareURL();
        $paymentMethodURL = $clientExecURL . "/index.php?fuse=clients&controller=userprofile&view=paymentmethod";

        $customerid = Clientexec::decryptString($encryptedCustomerID);
        if (is_a($customerid, 'CE_Error')) {
            CE_Lib::log(4, 'Stripe Checkout payment method callback: invalid client hash');
            CE_Lib::addErrorMessage($this->user->lang('Your payment method was not updated'));
            CE_Lib::redirectPage($paymentMethodURL);
            return;
        }
        //Get client Id from hash

        $user = new User($customerid);

        $session = false;

        try {
            $session = $stripe->checkout->sessions->retrieve(
                $_GET['session_id'],
                []
            );
        } catch (Exception $e) {
            CE_Lib::log(4, "Invalid Stripe Checkout setup session: " . $e->getMessage());
        }

        // A cancelled setup session has no SetupIntent that completed
        if ($session === false || $session->setup_intent === null) {
            CE_Lib::addErrorMessage($user->lang('Your payment method was not updated'));
            CE_Lib::redirectPage($paymentMethodURL);
            return;
        }

        try {
            $setupIntent = $stripe->setupIntents->retrieve(
                $session->setup_intent,
                []
            );

            $profile_id = $setupIntent->customer;
            $payment_method = $setupIntent->payment_method;

            if ($setupIntent->status == 'succeeded' && $profile_id != '' && $payment_method != '') {
                //save profile id
                $Billing_Profile_ID = '';
                $profile_id_array = array();

                if ($user->getCustomFieldsValue('Billing-Profile-ID', $Billing_Profile_ID) && $Billing_Profile_ID != '') {
                    $profile_id_array = unserialize($Billing_Profile_ID);
                }

                if (!is_array($profile_id_array)) {
                    $profile_id_array = array();
                }

                $profile_id_array[basename(dirname(__FILE__))] = $profile_id . '|' . $payment_method;
                $user->updateCustomTag('Billing-Profile-ID', serialize($profile_id_array));
                $user->save();
                //save profile id

                $eventLog = Client_EventLog::newInstance(false, $user->getId(), $user->getId());
                $eventLog->setSubject($user->getId());

                $oldGateway = $user->getPaymentType();
                $newGateway = basename(dirname(__FILE__));

                if ($oldGateway != $newGateway) {
                    $eventLog->setAction(CLIENT_EVENTLOG_CHANGEDPAYMENTTYPE);
                    $eventLog->setParams($newGateway);
                    $eventLog->save();

                    $userInformation = array();
                    $userInformation['User ID'] = $user->getId();
                    $userInformation['Status'] = $user->getStatus();

                    //GET CUSTOMER VIEWABLE CUSTOM FIELDS//
                    $query = "SELECT id, type, name FROM customuserfields WHERE showCustomer = 1 AND isadminonly = 0 ORDER BY myOrder";
                    $result = $this->db->query($query);

                    while (list($tID, $tType, $tName) = $result->fetch()) {
                        if ($tType == typeDATE) {
                            if (!$user->getCustomFieldsValue($tID, $userInformation[$tName])) {
                                $userInformation[$tName] = '';
                            }

                            // We store the date as yyyy-mm-dd so convert to the proper date format
                            $userInformation[$tName] = CE_Lib::db_to_form($userInformation[$tName], $this->settings->get('Date Format'), "/");
                        } else {
                            $user->getCustomFieldsValue($tID, $userInformation[$tName]);
                        }
                    }

                    include_once 'library/CE/NE_PluginCollection.php';
                    $pluginCollection = new NE_PluginCollection('gateways', $this->user);

                    if ($this->settings->get('plugin_' . $oldGateway . '_Update Gateway')) {
                        $userInformation['Gateway'] = $oldGateway;
                        $userInformation['Action'] = 'delete';
                        $pluginCollection->callFunction($oldGateway, 'UpdateGateway', $userInformation);
                    }

                    $userInformation['Gateway'] = $newGateway;
                    $userInformation['Action'] = 'update';
                    $pluginCollection->callFunction($newGateway, 'UpdateGateway', $userInformation);

                    $user->setPaymentType($newGateway);
                }

                $user->SetAutoPayment(1);
                $user->clearCreditCardInfo();
                $user->save();

                CE_Lib::addSuccessMessage($user->lang('Your payment method was updated successfully'));
            } else {
                CE_Lib::log(4, 'Stripe Checkout payment method callback: setup intent ' . $setupIntent->id . ' did not succeed (status: ' . $setupIntent->status . ')');
                CE_Lib::addErrorMessage($user->lang('Your payment method was not updated'));
            }
        } catch (Exception $e) {
            CE_Lib::log(4, 'Stripe Checkout payment method callback: ' . $e->getMessage());
            CE_Lib::addErrorMessage($user->lang('Your payment method was not updated'));
        }

        CE_Lib::redirectPage($paymentMethodURL);
    }

    /**
     * Applies "Make this my default payment method" from the invoice page. Stripe Checkout redirects straight
     * to this callback, so the payinvoice action that normally handles the checkbox never runs; this makes the
     * same UserGateway::updateGatewayInformation() call it would have made.
     */
    private function makeDefaultPaymentMethod(User $user): void
    {
        // The flag arrives in a cookie, so only honor it for the logged in client who owns the invoice
        if ($this->user->getId() == 0 || $this->user->getId() != $user->getId()) {
            CE_Lib::log(4, 'Stripe Checkout callback: ignoring makeDefault for client ' . $user->getId() . ', it does not match the logged in user');
            return;
        }

        require_once 'modules/clients/models/UserGateway.php';
        $userGateway = new UserGateway($this->user);
        $plugincustomfields = [];
        $userGateway->updateGatewayInformation($this->user, basename(dirname(__FILE__)), $plugincustomfields, '', '', '');
    }

    /**
     * Sends the client back after a cancelled or failed payment: to the invoice when it is known,
     * otherwise to the invoice list (or the signup page when coming from signup).
     */
    private function redirect($invoiceId = null): void
    {
        $clientExecURL = CE_Lib::getSoftwareURL();

        if ($invoiceId !== null && $invoiceId !== '') {
            $invoiceviewURLCancel = $clientExecURL . "/index.php?fuse=billing&cancel=1&controller=invoice&view=invoice&id=" . urlencode((string) $invoiceId);
        } else {
            $invoiceviewURLCancel = $clientExecURL . "/index.php?fuse=billing&cancel=1&controller=invoice&view=allinvoices";
        }

        //Need to check to see if user is coming from signup
        if (!empty($_GET['isSignup'])) {
            // Actually handle the signup URL setting
            if ($this->settings->get('Signup Completion URL') != '') {
                $cancel_url = $this->settings->get('Signup Completion URL');
            } else {
                $cancel_url = $clientExecURL . "/order.php?step=3";
            }
        } else {
            $cancel_url = $invoiceviewURLCancel;
        }
        header('Location: ' . $cancel_url);
        exit;
    }
}
