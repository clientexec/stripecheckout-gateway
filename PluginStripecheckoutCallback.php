<?php
require_once 'modules/admin/models/PluginCallback.php';
require_once 'modules/admin/models/StatusAliasGateway.php';
require_once 'modules/billing/models/class.gateway.plugin.php';
require_once 'modules/billing/models/Invoice_EventLog.php';
require_once 'modules/admin/models/Error_EventLog.php';

class PluginStripecheckoutCallback extends PluginCallback
{
    function processCallback()
    {
        CE_Lib::log(4, 'Stripecheckout callback invoked');

        if (!isset($GLOBALS['testing'])) {
            $testing = false;
        } else {
            $testing = $GLOBALS['testing'];
        }

        // Use Stripe's bindings...
        \Stripe\Stripe::setApiKey($this->settings->get('plugin_stripecheckout_Stripe Checkout Gateway Secret Key'));
        \Stripe\Stripe::setAppInfo(
            'Clientexec',
            CE_Lib::getAppVersion(),
            'https://www.clientexec.com',
            STRIPE_PARTNER_ID
        );
        \Stripe\Stripe::setApiVersion(STRIPE_API_VERSION);
        $stripe = new \Stripe\StripeClient($this->settings->get('plugin_stripecheckout_Stripe Checkout Gateway Secret Key'));

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
            $payment_intent = \Stripe\PaymentIntent::retrieve($session->payment_intent);
            $transactionId = $payment_intent->charges->data[0]->balance_transaction;
            $amount = sprintf("%01.2f", round(($payment_intent->charges->data[0]->amount / 100), 2));
            $success = ($payment_intent->status == 'succeeded');

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

            if ($success) {
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

                $cPlugin->PaymentAccepted($amount, "Stripe Checkout payment of {$amount} was accepted. (Transaction ID: {$transactionId})", $transactionId);
                header('Location: '.$return_url);
            } else {
                if (isset($transactionId)) {
                    $cPlugin->PaymentRejected("Stripe Checkout payment of {$amount} was rejected. (Transaction ID: {$transactionId})");
                }
                
                header('Location: '.$cancel_url);
            }
            exit;
        } else {
            $this->redirect();
        }
    }

    private function redirect()
    {
        $clientExecURL = CE_Lib::getSoftwareURL();
        $invoiceviewURLCancel = $clientExecURL."/index.php?fuse=billing&cancel=1&controller=invoice&view=allinvoices";

        //Need to check to see if user is coming from signup
        if ($_GET['isSignup']) {
            // Actually handle the signup URL setting
            if ($this->settings->get('Signup Completion URL') != '') {
                $cancel_url = $this->settings->get('Signup Completion URL');
            } else {
                $cancel_url = $clientExecURL."/order.php?step=3";
            }
        } else {
            $cancel_url = $invoiceviewURLCancel;
        }
        header('Location: '.$cancel_url);
        exit;
    }
}
