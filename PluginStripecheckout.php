<?php
require_once('plugins/gateways/stripecheckout/stripe-php-3.4.0/init.php');
require_once 'modules/admin/models/GatewayPlugin.php';
require_once 'modules/billing/models/class.gateway.plugin.php';

/**
* @package Plugins
*/
class PluginStripecheckout extends GatewayPlugin
{
    function getVariables()
    {
        $variables = array (
            lang("Plugin Name") => array (
                                "type"          =>"hidden",
                                "description"   =>lang("How CE sees this plugin ( not to be confused with the Signup Name )"),
                                "value"         =>"Stripe Checkout"
                                ),
            lang('Stripe Checkout Gateway Secret Key') => array (
                                'type'          =>'password',
                                'description'   =>lang('Please enter your Stripe Checkout Gateway Secret Key here.'),
                                'value'         =>''
                               ),
            lang('Stripe Checkout Gateway Publishable Key') => array (
                                'type'          =>'password',
                                'description'   =>lang('Please enter your Stripe Checkout Gateway Publishable Key here.'),
                                'value'         =>''
                               ),
            lang("Invoice After Signup") => array (
                                "type"          =>"yesno",
                                "description"   =>lang("Select YES if you want an invoice sent to the customer after signup is complete."),
                                "value"         =>"1"
                                ),
            lang("Signup Name") => array (
                                "type"          =>"text",
                                "description"   =>lang("Select the name to display in the signup process for this payment type. Example: eCheck or Credit Card."),
                                "value"         =>"Stripe Checkout"
                                ),
            lang("Dummy Plugin") => array (
                                "type"          =>"hidden",
                                "description"   =>lang("1 = Only used to specify a billing type for a customer. 0 = full fledged plugin requiring complete functions"),
                                "value"         =>"0"
                                ),
            lang('Auto Payment') => array (
                'type'          => 'hidden',
                'description'   => lang('No description'),
                'value'         => '1'
            )
        );
        return $variables;
    }

    function credit($params)
    {
        $params['refund'] = true;
        return $this->singlePayment($params);
    }

    function singlepayment($params)
    {
        return $this->autopayment($params);
    }

    function autopayment($params)
    {
        $cPlugin = new Plugin($params['invoiceNumber'], "stripecheckout", $this->user);
        $cPlugin->setAmount($params['invoiceTotal']);

        if (isset($params['refund']) && $params['refund']) {
            $isRefund = true;
            $cPlugin->setAction('refund');
        }else{
            $isRefund = false;
            $cPlugin->setAction('charge');
        }

        try {
            // Use Stripe's bindings...
            \Stripe\Stripe::setApiKey($this->settings->get('plugin_stripecheckout_Stripe Checkout Gateway Secret Key'));

            $profile_id == '';
            if(isset($params['stripeTokenId'])){
                $customer = \Stripe\Customer::create(array(
                    'email' => $params['userEmail'],
                    'card'  => $params['stripeTokenId']
                ));
                $profile_id = $customer->id;
                $user = new User($params['CustomerID']);
                $user->updateCustomTag('Billing-Profile-ID', serialize(array('stripecheckout' => $profile_id)));
                $user->save();
            }else{
                $Billing_Profile_ID = '';
                $user = new User($params['CustomerID']);
                if($user->getCustomFieldsValue('Billing-Profile-ID', $Billing_Profile_ID) && $Billing_Profile_ID != ''){
                    $profile_id_array = unserialize($Billing_Profile_ID);
                    if(is_array($profile_id_array) && isset($profile_id_array['stripecheckout'])){
                        $profile_id = $profile_id_array['stripecheckout'];
                    }
                }
            }

            if ($isRefund){
                $charge = \Stripe\Refund::create(array(
                    'charge'   => $params['invoiceRefundTransactionId'],
                    'metadata' => array(
                        'order_id' => $params['invoiceNumber']
                    )
                ));
            }else{
                //Needs to be in cents
                $totalAmount = sprintf("%01.2f", round($params["invoiceTotal"], 2)) * 100;

                $charge = \Stripe\Charge::create(array(
                    'customer'    => $profile_id,
                    'amount'      => $totalAmount,
                    'currency'    => $params['userCurrency'],
                    'description' => 'Invoice #'.$params['invoiceNumber'],
                    'metadata'    => array(
                        'order_id' => $params['invoiceNumber']
                    )
                ));
            }

            $charge = $charge->__toArray(true);

            if($charge['failure_message'] == ''){
                if($charge['object'] == 'charge'){
                    $cPlugin->setTransactionID($charge['id']);
                        if($charge['paid'] == true && $charge['status'] == 'succeeded'){
                            $chargeAmount = sprintf("%01.2f", round(($charge['amount'] / 100), 2));
                            $cPlugin->PaymentAccepted($chargeAmount, "Stripe Checkout payment of {$chargeAmount} was accepted.", $charge['id']);
                            return '';
                        }else{
                            $cPlugin->PaymentRejected($this->user->lang("There was an error performing this operation."));
                            return $this->user->lang("There was an error performing this operation.");
                        }
                }elseif($charge['object'] == 'refund'){
                    $chargeAmount = sprintf("%01.2f", round(($charge['amount'] / 100), 2));
                    $cPlugin->PaymentAccepted($chargeAmount, "Stripe Checkout refund of {$chargeAmount} was successfully processed.", $charge['id']);
                    return array('AMOUNT' => $chargeAmount);
                }else{
                    $cPlugin->PaymentRejected($this->user->lang("There was an error performing this operation."));
                    return $this->user->lang("There was an error performing this operation.");
                }
            }else{
                $cPlugin->PaymentRejected($this->user->lang("There was an error performing this operation.")." ".$charge['failure_message']);
                return $this->user->lang("There was an error performing this operation.")." ".$charge['failure_message'];
            }
        } catch(\Stripe\Error\Card $e) {
            $body = $e->getJsonBody();
            $err  = $body['error'];

            //A human-readable message giving more details about the error.
            $cPlugin->PaymentRejected($this->user->lang("There was an error performing this operation.")." ".$err['message']);
            return $this->user->lang("There was an error performing this operation.")." ".$err['message'];
        } catch (\Stripe\Error\RateLimit $e) {
            // Too many requests made to the API too quickly
            $body = $e->getJsonBody();
            $err  = $body['error'];

            //A human-readable message giving more details about the error.
            $cPlugin->PaymentRejected($this->user->lang("There was an error performing this operation.")." ".$this->user->lang("Too many requests made to the API too quickly.")." ".$err['message']);
            return $this->user->lang("There was an error performing this operation.")." ".$this->user->lang("Too many requests made to the API too quickly.")." ".$err['message'];
        } catch (\Stripe\Error\InvalidRequest $e) {
            // Invalid parameters were supplied to Stripe's API.
            $body = $e->getJsonBody();
            $err  = $body['error'];

            //A human-readable message giving more details about the error.
            $cPlugin->PaymentRejected($this->user->lang("There was an error performing this operation.")." ".$this->user->lang("Invalid parameters were supplied to Stripe's API.")." ".$err['message']);
            return $this->user->lang("There was an error performing this operation.")." ".$this->user->lang("Invalid parameters were supplied to Stripe's API.")." ".$err['message'];
        } catch (\Stripe\Error\Authentication $e) {
            // Authentication with Stripe's API failed. Maybe you changed API keys recently.
            $body = $e->getJsonBody();
            $err  = $body['error'];

            //A human-readable message giving more details about the error.
            $cPlugin->PaymentRejected($this->user->lang("There was an error performing this operation.")." ".$this->user->lang("Authentication with Stripe's API failed. Maybe you changed API keys recently.")." ".$err['message']);
            return $this->user->lang("There was an error performing this operation.")." ".$this->user->lang("Authentication with Stripe's API failed. Maybe you changed API keys recently.")." ".$err['message'];
        } catch (\Stripe\Error\ApiConnection $e) {
            // Network communication with Stripe failed.
            $body = $e->getJsonBody();
            $err  = $body['error'];

            //A human-readable message giving more details about the error.
            $cPlugin->PaymentRejected($this->user->lang("There was an error performing this operation.")." ".$this->user->lang("Network communication with Stripe failed")." ".$err['message']);
            return $this->user->lang("There was an error performing this operation.")." ".$this->user->lang("Network communication with Stripe failed")." ".$err['message'];
        } catch (\Stripe\Error\Base $e) {
            // Display a very generic error to the user, and maybe send yourself an email.
            $body = $e->getJsonBody();
            $err  = $body['error'];

            //A human-readable message giving more details about the error.
            $cPlugin->PaymentRejected($this->user->lang("There was an error performing this operation.")." ".$err['message']);
            return $this->user->lang("There was an error performing this operation.")." ".$err['message'];
        } catch (Exception $e) {
            // Something else happened, completely unrelated to Stripe
            $cPlugin->PaymentRejected($this->user->lang("There was an error performing this operation.")." ".$e->getMessage());
            return $this->user->lang("There was an error performing this operation.")." ".$e->getMessage();
        }
    }
}