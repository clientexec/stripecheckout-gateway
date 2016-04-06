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
            lang('Plugin Name') => array (
                'type'        => 'hidden',
                'description' => lang('How CE sees this plugin ( not to be confused with the Signup Name )'),
                'value'       => 'Stripe Checkout'
            ),
            lang('Stripe Checkout Gateway Secret Key') => array (
                'type'        => 'password',
                'description' => lang('Please enter your Stripe Checkout Gateway Secret Key here.'),
                'value'       => ''
            ),
            lang('Stripe Checkout Gateway Publishable Key') => array (
                'type'        => 'password',
                'description' => lang('Please enter your Stripe Checkout Gateway Publishable Key here.'),
                'value'       => ''
            ),
            lang('Stripe Checkout Accept Bitcoin Payments') => array (
                'type'        => 'yesno',
                'description' => lang('Select YES if you want Stripe Checkout to accept Bitcoin payments.</br>You currently need a US bank account to accept Bitcoin payments.</br>Stripe users in more than twenty countries can attach a US bank account to their Stripe account, but we know it is not ideal for non-US users, so we are working to expand Bitcoin acceptance more broadly.</br>To process live Bitcoin payments, you need to <a href="https://dashboard.stripe.com/account/bitcoin/enable" target="_blank">enable the live Bitcoin API on your account</a>'),
                'value'       => '0'
            ),
            lang('Bitcoin Address - User Custom Field') => array (
                'type'        => 'text',
                'description' => lang('Create a User Custom Field <a href="index.php?fuse=admin&controller=settings&view=usercustomfields" target="_blank">here</a> that will be used for your customers to enter a Bitcoin Address.</br>Enter in this field the exact same name you used to create the User Custom Field.</br>This will be used in case you need to refund them a Bitcoin Payment.'),
                'value'       => 'Bitcoin Address'
            ),
            lang('Stripe Checkout Logo Image URL') => array (
                'type'        => 'text',
                'description' => lang('A relative or absolute URL pointing to a square image of your brand or product.</br>The recommended minimum size is 128x128px.</br>The recommended image types are .gif, .jpeg, and .png.</br>Leave this field empty to use the default image.'),
                'value'       => ''
            ),
            lang('Invoice After Signup') => array (
                'type'        => 'yesno',
                'description' => lang('Select YES if you want an invoice sent to the customer after signup is complete.'),
                'value'       => '1'
            ),
            lang('Signup Name') => array (
                'type'        => 'text',
                'description' => lang('Select the name to display in the signup process for this payment type. Example: eCheck or Credit Card.'),
                'value'       => 'Stripe Checkout'
            ),
            lang('Dummy Plugin') => array (
                'type'        => 'hidden',
                'description' => lang('1 = Only used to specify a billing type for a customer. 0 = full fledged plugin requiring complete functions'),
                'value'       => '0'
            ),
            lang('Auto Payment') => array (
                'type'        => 'hidden',
                'description' => lang('No description'),
                'value'       => '1'
            ),
            lang('Update Gateway') => array (
                'type'        => 'hidden',
                'description' => lang('1 = Create, update or remove Gateway customer information through the function UpdateGateway when customer choose to use this gateway, customer profile is updated, customer is deleted or customer status is changed. 0 = Do nothing.'),
                'value'       => '1'
            ),
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

            $profile_id = '';
            $user = new User($params['CustomerID']);
            if(isset($params['stripeTokenId'])){
                $fullCustomerProfile = $this->createFullCustomerProfile($params);
                if($fullCustomerProfile['error']){
                    $cPlugin->PaymentRejected($this->user->lang("There was an error performing this operation.")." ".$fullCustomerProfile['detail']);
                    return $this->user->lang("There was an error performing this operation.")." ".$fullCustomerProfile['detail'];
                }
                $profile_id = $fullCustomerProfile['profile_id'];
            }else{
                $Billing_Profile_ID = '';
                if($user->getCustomFieldsValue('Billing-Profile-ID', $Billing_Profile_ID) && $Billing_Profile_ID != ''){
                    $profile_id_array = unserialize($Billing_Profile_ID);
                    if(is_array($profile_id_array) && isset($profile_id_array['stripecheckout'])){
                        $profile_id = $profile_id_array['stripecheckout'];
                    }
                }
            }

            if ($isRefund){
                $BitcoinOrCC = substr($params['invoiceRefundTransactionId'], 0, 3);
                if($BitcoinOrCC == 'py_'){// py_ Bitcoin
                    $customer_bitcoin_address = '';
                    $Bitcoin_Address_User_Custom_Field = trim($this->settings->get('plugin_stripecheckout_Bitcoin Address - User Custom Field'));
                    if($Bitcoin_Address_User_Custom_Field != ''){
                        $customer_bitcoin_address = trim($user->customFields->getCustomFieldByName($Bitcoin_Address_User_Custom_Field, true));
                    }
                    $charge = \Stripe\Refund::create(array(
                        'refund_address' => $customer_bitcoin_address,
                        'charge'         => $params['invoiceRefundTransactionId'],
                        'metadata'       => array(
                            'order_id' => $params['invoiceNumber']
                        )
                    ));
                }else{// ch_ Credit Card
                    $charge = \Stripe\Refund::create(array(
                        'charge'   => $params['invoiceRefundTransactionId'],
                        'metadata' => array(
                            'order_id' => $params['invoiceNumber']
                        )
                    ));
                }
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
                        if ( $charge['paid'] == true && in_array($charge['status'], array('succeeded', 'paid')) ) {
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

    // Create customer Stripe Checkout profile
    function createFullCustomerProfile($params)
    {
        $validate = true;
        if ( $params['validate'] === false ) {
          $validate = false;
        }
        
        try {
            // Use Stripe's bindings...
            \Stripe\Stripe::setApiKey($this->settings->get('plugin_stripecheckout_Stripe Checkout Gateway Secret Key'));

            if(isset($params['stripeTokenId'])){
                $customer = \Stripe\Customer::create(array(
                    'email' => $params['userEmail'],
                    'card'  => $params['stripeTokenId']
                ));
            }else{
                $customer = \Stripe\Customer::create(array(
                    'email' => $params['userEmail'],
                    'card'  => array(
                        'number' => $params['userCCNumber'],
                        'exp_month' => $params['cc_exp_month'],
                        'exp_year' => $params['cc_exp_year']
                    ),
                   'validate' => $validate
                ));
            }
            $profile_id = $customer->id;
            $Billing_Profile_ID = '';
            $profile_id_array = array();
            $user = new User($params['CustomerID']);
            if($user->getCustomFieldsValue('Billing-Profile-ID', $Billing_Profile_ID) && $Billing_Profile_ID != ''){
                $profile_id_array = unserialize($Billing_Profile_ID);
            }
            if(!is_array($profile_id_array)){
                $profile_id_array = array();
            }
            $profile_id_array['stripecheckout'] = $profile_id;
            $user->updateCustomTag('Billing-Profile-ID', serialize($profile_id_array));
            $user->save();

            return array(
                'error'               => false,
                'profile_id'          => $profile_id
            );
        } catch(\Stripe\Error\Card $e) {
            $body = $e->getJsonBody();
            $err  = $body['error'];

            //A human-readable message giving more details about the error.
            return array(
                'error'  => true,
                'detail' => $this->user->lang("There was an error performing this operation.")." ".$err['message']
            );
        } catch (\Stripe\Error\RateLimit $e) {
            // Too many requests made to the API too quickly
            $body = $e->getJsonBody();
            $err  = $body['error'];

            //A human-readable message giving more details about the error.
            return array(
                'error'  => true,
                'detail' => $this->user->lang("There was an error performing this operation.")." ".$this->user->lang("Too many requests made to the API too quickly.")." ".$err['message']
            );
        } catch (\Stripe\Error\InvalidRequest $e) {
            // Invalid parameters were supplied to Stripe's API.
            $body = $e->getJsonBody();
            $err  = $body['error'];

            //A human-readable message giving more details about the error.
            return array(
                'error'  => true,
                'detail' => $this->user->lang("There was an error performing this operation.")." ".$this->user->lang("Invalid parameters were supplied to Stripe's API.")." ".$err['message']
            );
        } catch (\Stripe\Error\Authentication $e) {
            // Authentication with Stripe's API failed. Maybe you changed API keys recently.
            $body = $e->getJsonBody();
            $err  = $body['error'];

            //A human-readable message giving more details about the error.
            return array(
                'error'  => true,
                'detail' => $this->user->lang("There was an error performing this operation.")." ".$this->user->lang("Authentication with Stripe's API failed. Maybe you changed API keys recently.")." ".$err['message']
            );
        } catch (\Stripe\Error\ApiConnection $e) {
            // Network communication with Stripe failed.
            $body = $e->getJsonBody();
            $err  = $body['error'];

            //A human-readable message giving more details about the error.
            return array(
                'error'  => true,
                'detail' => $this->user->lang("There was an error performing this operation.")." ".$this->user->lang("Network communication with Stripe failed")." ".$err['message']
            );
        } catch (\Stripe\Error\Base $e) {
            // Display a very generic error to the user, and maybe send yourself an email.
            $body = $e->getJsonBody();
            $err  = $body['error'];

            //A human-readable message giving more details about the error.
            return array(
                'error'  => true,
                'detail' => $this->user->lang("There was an error performing this operation.")." ".$err['message']
            );
        } catch (Exception $e) {
            // Something else happened, completely unrelated to Stripe
            return array(
                'error'  => true,
                'detail' => $this->user->lang("There was an error performing this operation.")." ".$e->getMessage()
            );
        }
    }

    function UpdateGateway($params){
        switch($params['Action']){
            case 'update':  // When updating customer profile or changing to use this gateway
                $statusAliasGateway = StatusAliasGateway::getInstance($this->user);
                if(in_array($params['Status'], $statusAliasGateway->getUserStatusIdsFor(array(USER_STATUS_INACTIVE, USER_STATUS_CANCELLED, USER_STATUS_FRAUD)))){
                  $this->CustomerRemove($params);
                }
                break;
            case 'delete':  // When deleting the customer or changing to use another gateway
                $this->CustomerRemove($params);
                break;
        }
    }

    function CustomerRemove($params){
        try {
            // Use Stripe's bindings...
            \Stripe\Stripe::setApiKey($this->settings->get('plugin_stripecheckout_Stripe Checkout Gateway Secret Key'));


            $profile_id = '';
            $Billing_Profile_ID = '';
            $profile_id_array = array();
            $user = new User($params['User ID']);
            if($user->getCustomFieldsValue('Billing-Profile-ID', $Billing_Profile_ID) && $Billing_Profile_ID != ''){
                $profile_id_array = unserialize($Billing_Profile_ID);
                if(is_array($profile_id_array) && isset($profile_id_array['stripecheckout'])){
                    $profile_id = $profile_id_array['stripecheckout'];
                }
            }

            if($profile_id != ''){
                $customer = \Stripe\Customer::retrieve($profile_id);
                $customer = $customer->delete();

                if($customer->id == $profile_id && $customer->deleted == true){

                    if(is_array($profile_id_array)){
                        unset($profile_id_array['stripecheckout']);
                    }else{
                        $profile_id_array = array();
                    }

                    $user->updateCustomTag('Billing-Profile-ID', serialize($profile_id_array));
                    $user->save();

                    return array(
                        'error'      => false,
                        'profile_id' => $profile_id
                    );
                }else{
                    return array(
                        'error'      => true,
                        'detail' => $this->user->lang("There was an error performing this operation.")
                    );
                }
            }else{
                return array(
                    'error'      => true,
                    'detail' => $this->user->lang("There was an error performing this operation.").' '.$this->user->lang("profile_id is empty.")
                );
            }

        } catch(\Stripe\Error\Card $e) {
            $body = $e->getJsonBody();
            $err  = $body['error'];

            //A human-readable message giving more details about the error.
            return array(
                'error'  => true,
                'detail' => $this->user->lang("There was an error performing this operation.")." ".$err['message']
            );
        } catch (\Stripe\Error\RateLimit $e) {
            // Too many requests made to the API too quickly
            $body = $e->getJsonBody();
            $err  = $body['error'];

            //A human-readable message giving more details about the error.
            return array(
                'error'  => true,
                'detail' => $this->user->lang("There was an error performing this operation.")." ".$this->user->lang("Too many requests made to the API too quickly.")." ".$err['message']
            );
        } catch (\Stripe\Error\InvalidRequest $e) {
            // Invalid parameters were supplied to Stripe's API.
            $body = $e->getJsonBody();
            $err  = $body['error'];

            //A human-readable message giving more details about the error.
            return array(
                'error'  => true,
                'detail' => $this->user->lang("There was an error performing this operation.")." ".$this->user->lang("Invalid parameters were supplied to Stripe's API.")." ".$err['message']
            );
        } catch (\Stripe\Error\Authentication $e) {
            // Authentication with Stripe's API failed. Maybe you changed API keys recently.
            $body = $e->getJsonBody();
            $err  = $body['error'];

            //A human-readable message giving more details about the error.
            return array(
                'error'  => true,
                'detail' => $this->user->lang("There was an error performing this operation.")." ".$this->user->lang("Authentication with Stripe's API failed. Maybe you changed API keys recently.")." ".$err['message']
            );
        } catch (\Stripe\Error\ApiConnection $e) {
            // Network communication with Stripe failed.
            $body = $e->getJsonBody();
            $err  = $body['error'];

            //A human-readable message giving more details about the error.
            return array(
                'error'  => true,
                'detail' => $this->user->lang("There was an error performing this operation.")." ".$this->user->lang("Network communication with Stripe failed")." ".$err['message']
            );
        } catch (\Stripe\Error\Base $e) {
            // Display a very generic error to the user, and maybe send yourself an email.
            $body = $e->getJsonBody();
            $err  = $body['error'];

            //A human-readable message giving more details about the error.
            return array(
                'error'  => true,
                'detail' => $this->user->lang("There was an error performing this operation.")." ".$err['message']
            );
        } catch (Exception $e) {
            // Something else happened, completely unrelated to Stripe
            return array(
                'error'  => true,
                'detail' => $this->user->lang("There was an error performing this operation.")." ".$e->getMessage()
            );
        }
    }

    public function getForm($args)
    {

      $this->view->publishableKey = $this->getVariable('Stripe Checkout Gateway Publishable Key');
      $this->view->logoImage = $this->getVariable('Stripe Checkout Logo Image URL');
      $this->view->acceptBitcoins = ($this->getVariable('Stripe Checkout Accept Bitcoin Payments')) ? 'true' : 'false';
      $this->view->companyName = $this->settings->get("Company Name");
      $this->view->invoiceId = $args['invoiceId'];
      $this->view->currency = $args['currency'];
      $this->view->invoiceBalanceDue = $args['invoiceBalanceDue'];
      $this->view->panelLabel = $args['panellabel'];
      $this->view->from = $args['from'];
      $this->view->termsConditions = $args['termsConditions'];

      if ( $args['from'] == 'paymentmethod' ) {
        $this->view->acceptBitcoins = 'false';
      }

      if($this->view->logoImage == ''){
          $SoftwareURL = mb_substr(CE_Lib::getSoftwareURL(),-1,1) == "//" ? CE_Lib::getSoftwareURL() : CE_Lib::getSoftwareURL()."/";
          $this->view->logoImage = $SoftwareURL.'plugins/gateways/stripecheckout/logo.png';
      }

      return $this->view->render('form.phtml');
    }
}
