<?php

require_once 'modules/admin/models/GatewayPlugin.php';
require_once 'modules/billing/models/class.gateway.plugin.php';

/**
* @package Plugins
*/
class PluginStripecheckout extends GatewayPlugin
{
    function getVariables()
    {
        $variables = array(
            lang('Plugin Name') => array(
                'type'        => 'hidden',
                'description' => lang('How CE sees this plugin ( not to be confused with the Signup Name )'),
                'value'       => 'Stripe Checkout'
            ),
            lang('Stripe Checkout Gateway Publishable Key') => array(
                'type'        => 'password',
                'description' => lang('Please enter your Stripe Checkout Gateway Publishable Key here.'),
                'value'       => ''
            ),
            lang('Stripe Checkout Gateway Secret Key') => array(
                'type'        => 'password',
                'description' => lang('Please enter your Stripe Checkout Gateway Secret Key here.'),
                'value'       => ''
            ),
            lang('Delete Client From Gateway') => array(
                'type'        => 'yesno',
                'description' => lang('Select YES if you want to delete the client from the gateway when the client changes the payment method or is deleted.'),
                'value'       => '0'
            ),
            lang('Stripe Checkout Logo Image URL') => array(
                'type'        => 'text',
                'description' => lang('A relative or absolute URL pointing to a square image of your brand or product.</br>The recommended minimum size is 128x128px.</br>The recommended image types are .gif, .jpeg, and .png.</br>Leave this field empty to use the default image.'),
                'value'       => ''
            ),
            lang('Invoice After Signup') => array(
                'type'        => 'yesno',
                'description' => lang('Select YES if you want an invoice sent to the client after signup is complete.'),
                'value'       => '1'
            ),
            lang('Signup Name') => array(
                'type'        => 'text',
                'description' => lang('Select the name to display in the signup process for this payment type. Example: eCheck or Credit Card.'),
                'value'       => 'Stripe Checkout'
            ),
            lang('Dummy Plugin') => array(
                'type'        => 'hidden',
                'description' => lang('1 = Only used to specify a billing type for a client. 0 = full fledged plugin requiring complete functions'),
                'value'       => '0'
            ),
            lang('Auto Payment') => array(
                'type'        => 'hidden',
                'description' => lang('No description'),
                'value'       => '1'
            ),
            lang('CC Stored Outside') => array(
                'type'        => 'hidden',
                'description' => lang('If this plugin is Auto Payment, is Credit Card stored outside of Clientexec? 1 = YES, 0 = NO'),
                'value'       => '1'
            ),
            lang('Billing Profile ID') => array(
                'type'        => 'hidden',
                'description' => lang('Is this plugin storing a Billing-Profile-ID? 1 = YES, 0 = NO'),
                'value'       => '1'
            ),
            lang('Form') => array(
                'type'        => 'hidden',
                'description' => lang('Has a form to be loaded?  1 = YES, 0 = NO'),
                'value'       => '1'
            ),
            lang('openHandler') => array(
                'type'        => 'hidden',
                'description' => lang('Call openHandler() in "Edit Your Payment Method" section if missing Billing-Profile-ID?  1 = YES, 0 = NO'),
                'value'       => '1'
            ),
            lang('Call on updateGatewayInformation') => array(
                'type'        => 'hidden',
                'description' => lang('Function name to be called in this plugin when given conditions are meet while updateGatewayInformation is invoked'),
                'value'       => serialize(
                    array(
                        'function'                      => 'createFullCustomerProfile',
                        'plugincustomfields conditions' => array( //All conditions must match.
                            array(
                                'field name' => 'stripeTokenId', //Supported values are the field names used in form.phtml of the plugin, with name="plugincustomfields[field_name]"
                                'operator'   => '!=',            //Supported operators are: ==, !=, <, <=, >, >=
                                'value'      => ''               //The value with which to compare
                            )
                        )
                    )
                )
            ),
            lang('Update Gateway') => array(
                'type'        => 'hidden',
                'description' => lang('1 = Create, update or remove Gateway client information through the function UpdateGateway when client choose to use this gateway, client profile is updated, client is deleted or client status is changed. 0 = Do nothing.'),
                'value'       => '1'
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
        } else {
            $isRefund = false;
            $cPlugin->setAction('charge');
        }

        try {
            \Stripe\Stripe::setApiKey($this->settings->get('plugin_stripecheckout_Stripe Checkout Gateway Secret Key'));
            \Stripe\Stripe::setAppInfo(
                'Clientexec',
                CE_Lib::getAppVersion(),
                'https://www.clientexec.com',
                STRIPE_PARTNER_ID
            );
            \Stripe\Stripe::setApiVersion(STRIPE_API_VERSION);

            $profile_id = '';
            $payment_method = '';
            $user = new User($params['CustomerID']);

            $Billing_Profile_ID = '';

            if ($user->getCustomFieldsValue('Billing-Profile-ID', $Billing_Profile_ID) && $Billing_Profile_ID != '') {
                $profile_id_array = unserialize($Billing_Profile_ID);

                if (is_array($profile_id_array) && isset($profile_id_array[basename(dirname(__FILE__))])) {
                    $profile_id = $profile_id_array[basename(dirname(__FILE__))];
                }
            }

            if ($isRefund) {
                try {
                    $transaction = \Stripe\BalanceTransaction::retrieve($params['invoiceRefundTransactionId']);
                } catch (Exception $e) {
                    if (strpos($e->getMessage(), 'No such balance transaction') !== false) {
                        try {
                            $charge = \Stripe\Charge::retrieve($params['invoiceRefundTransactionId']);
                            $params['invoiceRefundTransactionId'] = $charge->balance_transaction;
                            $transaction = \Stripe\BalanceTransaction::retrieve($params['invoiceRefundTransactionId']);
                        } catch (Exception $e) {
                            $cPlugin->PaymentRejected($this->user->lang("There was an error performing this operation."));
                            return $this->user->lang("There was an error performing this operation.");
                        }
                    }
                }

                $refund = \Stripe\Refund::create(
                    array(
                        "charge" => $transaction->source
                    )
                );

                if ($refund->status == 'succeeded') {
                    $chargeAmount = sprintf("%01.2f", round(($refund->amount / 100), 2));
                    $cPlugin->PaymentAccepted($chargeAmount, "Stripe Checkout refund of {$chargeAmount} was successfully processed.", $refund->id);
                    return array('AMOUNT' => $chargeAmount);
                } else {
                    $cPlugin->PaymentRejected($this->user->lang("There was an error performing this operation."));
                    return $this->user->lang("There was an error performing this operation.");
                }
            } else {
                $profile_id_values_array = explode('|', $profile_id);
                $profile_id = $profile_id_values_array[0];

                if (isset($profile_id_values_array[1])) {
                    $payment_method = $profile_id_values_array[1];
                } else {
                    if ($profile_id != '') {
                        try {
                            $customer = \Stripe\Customer::retrieve($profile_id);
                            $customer->name = $params["userFirstName"].' '.$params["userLastName"];
                            $customer->phone = $params['userPhone'];
                            $customer->address = array(
                                'line1'       => $params["userAddress"],
                                'postal_code' => $params["userZipcode"],
                                'city'        => $params["userCity"],
                                'state'       => $params["userState"],
                                'country'     => $params["userCountry"]
                            );
                            $customer->save();
                            $payment_method = $customer->default_source;
                        } catch (Exception $e) {
                            $profile_id = '';
                        }
                    }
                }

                $params['profile_id'] = $profile_id;
                $params['payment_method'] = $payment_method;

                if ($params['isSignup']) {
                    //This line makes the plugin works, but leaves ActiveOrderGateway.php too early.
                    // It is leaving on line 4506 instead of 4536, missing some logs and the call of send_account_creation_email
                    $form = $this->useForm($params);

                    //This line is the one we should use and signup is getting the respective value, but it seesm to not be using the code properly, giving an error
                    //$form = $this->ShowURL($params);

                    return array(
                        'error' => false,
                        'FORM'  => $form
                    );
                } else {
                    if ($params['profile_id'] == '' || $params['payment_method'] == '') {
                        $cPlugin->PaymentRejected($this->user->lang("There was an error performing this operation."));
                        return $this->user->lang("There was an error performing this operation.");
                    }

                    $totalAmount = sprintf("%01.2f", round($params['invoiceTotal'], 2));
                    $totalAmountCents = $totalAmount * 100;

                    $payment_intent = \Stripe\PaymentIntent::create(
                        array(
                            'amount'               => $totalAmountCents,
                            'currency'             => $params['userCurrency'],
                            'payment_method_types' => array(
                                'card'
                            ),
                            'customer'             => $params['profile_id'],
                            'payment_method'       => $params['payment_method'],
                            'description'          => 'Invoice #'.$params['invoiceNumber'],
                            'off_session'          => true,
                            'confirm'              => true
                        )
                    );

                    if ($payment_intent->status == 'succeeded') {
                        $transactionId = $payment_intent->charges->data[0]->balance_transaction;
                        $amount = sprintf("%01.2f", round(($payment_intent->charges->data[0]->amount / 100), 2));
                        $cPlugin->setTransactionID($transactionId);
                        $cPlugin->PaymentAccepted($amount, "Stripe Checkout payment of {$amount} was accepted. (Transaction ID: {$transactionId})", $transactionId);

                        try {
                            $payment_method_obj = \Stripe\PaymentMethod::retrieve($payment_intent->payment_method);
                            $payment_method_obj->attach(
                                array(
                                    'customer' => $payment_intent->customer
                                )
                            );
                        } catch (Exception $e) {
                        }

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

                        return '';
                    } else {
                        $cPlugin->PaymentRejected($this->user->lang("There was an error performing this operation."));
                        return $this->user->lang("There was an error performing this operation.");
                    }
                }
            }
        } catch (\Stripe\Error\Card $e) {
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

        if ($params['validate'] === false) {
            $validate = false;
        }

        try {
            // Use Stripe's bindings...
            \Stripe\Stripe::setApiKey($this->settings->get('plugin_stripecheckout_Stripe Checkout Gateway Secret Key'));
            \Stripe\Stripe::setAppInfo(
                'Clientexec',
                CE_Lib::getAppVersion(),
                'https://www.clientexec.com',
                STRIPE_PARTNER_ID
            );
            \Stripe\Stripe::setApiVersion(STRIPE_API_VERSION);

            if (isset($params['plugincustomfields']['stripeTokenId']) && $params['plugincustomfields']['stripeTokenId'] != "") {
                $profile_id = '';
                $Billing_Profile_ID = '';
                $profile_id_array = array();
                $user = new User($params['CustomerID']);

                if ($user->getCustomFieldsValue('Billing-Profile-ID', $Billing_Profile_ID) && $Billing_Profile_ID != '') {
                    $profile_id_array = unserialize($Billing_Profile_ID);

                    if (is_array($profile_id_array) && isset($profile_id_array[basename(dirname(__FILE__))])) {
                        $profile_id = $profile_id_array[basename(dirname(__FILE__))];
                        $profile_id_values_array = explode('|', $profile_id);
                        $profile_id = $profile_id_values_array[0];
                    }
                }

                if ($profile_id != '') {
                    $customer = \Stripe\Customer::retrieve($profile_id);
                    $customer->name = $params["userFirstName"].' '.$params["userLastName"];
                    $customer->phone = $params['userPhone'];
                    $customer->address = array(
                        'line1'       => $params["userAddress"],
                        'postal_code' => $params["userZipcode"],
                        'city'        => $params["userCity"],
                        'state'       => $params["userState"],
                        'country'     => $params["userCountry"]
                    );
                    $customer->source = $params['plugincustomfields']['stripeTokenId'];
                    $customer->save();
                } else {
                    $customer = \Stripe\Customer::create(
                        array(
                            'name'    => $params["userFirstName"].' '.$params["userLastName"],
                            'address' => array(
                                'line1'       => $params["userAddress"],
                                'postal_code' => $params["userZipcode"],
                                'city'        => $params["userCity"],
                                'state'       => $params["userState"],
                                'country'     => $params["userCountry"]
                            ),
                            'email'   => $params['userEmail'],
                            'phone'   => $params['userPhone'],
                            'card'    => $params['plugincustomfields']['stripeTokenId']
                        )
                    );
                }
            } else {
                $customer = \Stripe\Customer::create(
                    array(
                        'name'    => $params["userFirstName"].' '.$params["userLastName"],
                        'address' => array(
                            'line1'       => $params["userAddress"],
                            'postal_code' => $params["userZipcode"],
                            'city'        => $params["userCity"],
                            'state'       => $params["userState"],
                            'country'     => $params["userCountry"]
                        ),
                        'email'   => $params['userEmail'],
                        'phone'   => $params['userPhone'],
                        'card'    => array(
                            'number'          => $params['userCCNumber'],
                            'exp_month'       => $params['cc_exp_month'],
                            'exp_year'        => $params['cc_exp_year'],
                            'address_line1'   => $params["userAddress"],
                            'address_city'    => $params["userCity"],
                            'address_zip'     => $params["userZipcode"],
                            'address_state'   => $params["userState"],
                            'address_country' => $params["userCountry"]
                        ),
                       'validate' => $validate
                    )
                );
            }

            $profile_id = $customer->id;
            $Billing_Profile_ID = '';
            $profile_id_array = array();
            $user = new User($params['CustomerID']);

            if ($user->getCustomFieldsValue('Billing-Profile-ID', $Billing_Profile_ID) && $Billing_Profile_ID != '') {
                $profile_id_array = unserialize($Billing_Profile_ID);
            }

            if (!is_array($profile_id_array)) {
                $profile_id_array = array();
            }

            $profile_id_array[basename(dirname(__FILE__))] = $profile_id;
            $user->updateCustomTag('Billing-Profile-ID', serialize($profile_id_array));
            $user->save();

            return array(
                'error'               => false,
                'profile_id'          => $profile_id
            );
        } catch (\Stripe\Error\Card $e) {
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

    function UpdateGateway($params)
    {
        switch ($params['Action']) {
            case 'update':  // When updating customer profile or changing to use this gateway
                $statusAliasGateway = StatusAliasGateway::getInstance($this->user);

                if (in_array($params['Status'], $statusAliasGateway->getUserStatusIdsFor(array(USER_STATUS_INACTIVE, USER_STATUS_CANCELLED, USER_STATUS_FRAUD)))) {
                    $this->CustomerRemove($params);
                }

                break;
            case 'delete':  // When deleting the customer, changing to use another gateway, or updating the Credit Card
                $this->CustomerRemove($params);
                break;
        }
    }

    function CustomerRemove($params)
    {
        try {
            require_once 'modules/clients/models/Client_EventLog.php';

            // Use Stripe's bindings...
            \Stripe\Stripe::setApiKey($this->settings->get('plugin_stripecheckout_Stripe Checkout Gateway Secret Key'));
            \Stripe\Stripe::setAppInfo(
                'Clientexec',
                CE_Lib::getAppVersion(),
                'https://www.clientexec.com',
                STRIPE_PARTNER_ID
            );
            \Stripe\Stripe::setApiVersion(STRIPE_API_VERSION);

            $profile_id = '';
            $Billing_Profile_ID = '';
            $profile_id_array = array();
            $user = new User($params['User ID']);

            if ($user->getCustomFieldsValue('Billing-Profile-ID', $Billing_Profile_ID) && $Billing_Profile_ID != '') {
                $profile_id_array = unserialize($Billing_Profile_ID);

                if (is_array($profile_id_array)) {
                    if (isset($profile_id_array[basename(dirname(__FILE__))])) {
                        $profile_id = $profile_id_array[basename(dirname(__FILE__))];
                        $profile_id_values_array = explode('|', $profile_id);
                        $profile_id = $profile_id_values_array[0];
                    }
                }
            }

            if ($profile_id != '') {
                if ($this->settings->get('plugin_stripecheckout_Delete Client From Gateway')) {
                    try {
                        $customer = \Stripe\Customer::retrieve($profile_id);
                    } catch (Exception $e) {
                        if (strpos($e->getMessage(), 'No such customer') !== false) {
                            if (is_array($profile_id_array)) {
                                unset($profile_id_array[basename(dirname(__FILE__))]);
                            } else {
                                $profile_id_array = array();
                            }

                            $user->updateCustomTag('Billing-Profile-ID', serialize($profile_id_array));
                            $user->save();

                            $eventLog = Client_EventLog::newInstance(false, $user->getId(), $user->getId());
                            $eventLog->setSubject($this->user->getId());
                            $eventLog->setAction(CLIENT_EVENTLOG_DELETEDBILLINGPROFILEID);
                            $params = array(
                                'paymenttype' => $this->settings->get("plugin_" . basename(dirname(__FILE__)) . "_Plugin Name"),
                                'profile_id' => $profile_id
                            );
                            $eventLog->setParams(serialize($params));
                            $eventLog->save();

                            return array(
                                'error'      => false,
                                'profile_id' => $profile_id
                            );
                        }
                    }

                    if ($customer === null) {
                        return array(
                          'error'  => true,
                            'detail' => $this->user->lang("There was an error performing this operation.") . " " . $this->user->lang("User does not exist.")
                        );
                    }

                    try {
                        $customer = $customer->delete();
                    } catch (Exception $e) {
                        if (strpos($e->getMessage(), 'No such customer') !== false) {
                            if (is_array($profile_id_array)) {
                                unset($profile_id_array[basename(dirname(__FILE__))]);
                            } else {
                                $profile_id_array = array();
                            }

                            $user->updateCustomTag('Billing-Profile-ID', serialize($profile_id_array));
                            $user->save();

                            $eventLog = Client_EventLog::newInstance(false, $user->getId(), $user->getId());
                            $eventLog->setSubject($this->user->getId());
                            $eventLog->setAction(CLIENT_EVENTLOG_DELETEDBILLINGPROFILEID);
                            $params = array(
                                'paymenttype' => $this->settings->get("plugin_" . basename(dirname(__FILE__)) . "_Plugin Name"),
                                'profile_id' => $profile_id
                            );
                            $eventLog->setParams(serialize($params));
                            $eventLog->save();

                            return array(
                                'error'      => false,
                                'profile_id' => $profile_id
                            );
                        }
                    }

                    if ($customer->id == $profile_id && $customer->deleted == true) {
                        if (is_array($profile_id_array)) {
                            unset($profile_id_array[basename(dirname(__FILE__))]);
                        } else {
                            $profile_id_array = array();
                        }

                        $user->updateCustomTag('Billing-Profile-ID', serialize($profile_id_array));
                        $user->save();

                        $eventLog = Client_EventLog::newInstance(false, $user->getId(), $user->getId());
                        $eventLog->setSubject($this->user->getId());
                        $eventLog->setAction(CLIENT_EVENTLOG_DELETEDBILLINGPROFILEID);
                        $params = array(
                            'paymenttype' => $this->settings->get("plugin_" . basename(dirname(__FILE__)) . "_Plugin Name"),
                            'profile_id' => $profile_id
                        );
                        $eventLog->setParams(serialize($params));
                        $eventLog->save();

                        return array(
                            'error'      => false,
                            'profile_id' => $profile_id
                        );
                    } else {
                        return array(
                            'error'  => true,
                            'detail' => $this->user->lang("There was an error performing this operation.")
                        );
                    }
                } else {
                    if (is_array($profile_id_array)) {
                        unset($profile_id_array[basename(dirname(__FILE__))]);
                    } else {
                        $profile_id_array = array();
                    }

                    $user->updateCustomTag('Billing-Profile-ID', serialize($profile_id_array));
                    $user->save();

                    $eventLog = Client_EventLog::newInstance(false, $user->getId(), $user->getId());
                    $eventLog->setSubject($this->user->getId());
                    $eventLog->setAction(CLIENT_EVENTLOG_DELETEDBILLINGPROFILEID);
                    $params = array(
                        'paymenttype' => $this->settings->get("plugin_" . basename(dirname(__FILE__)) . "_Plugin Name"),
                        'profile_id' => $profile_id
                    );
                    $eventLog->setParams(serialize($params));
                    $eventLog->save();

                    return array(
                        'error'      => false,
                        'profile_id' => $profile_id
                    );
                }
            } else {
                if (is_array($profile_id_array)) {
                    unset($profile_id_array[basename(dirname(__FILE__))]);
                } else {
                    $profile_id_array = array();
                }

                $user->updateCustomTag('Billing-Profile-ID', serialize($profile_id_array));
                $user->save();

                $eventLog = Client_EventLog::newInstance(false, $user->getId(), $user->getId());
                $eventLog->setSubject($this->user->getId());
                $eventLog->setAction(CLIENT_EVENTLOG_DELETEDBILLINGPROFILEID);
                $params = array(
                    'paymenttype' => $this->settings->get("plugin_" . basename(dirname(__FILE__)) . "_Plugin Name"),
                    'profile_id' => $profile_id
                );
                $eventLog->setParams(serialize($params));
                $eventLog->save();

                return array(
                    'error'      => false,
                    'profile_id' => $profile_id
                );
            }
        } catch (\Stripe\Error\Card $e) {
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

    function useForm($params)
    {
        echo $this->ShowURL($params);
        exit;
    }

    function ShowURL($params)
    {
        $totalAmount = sprintf("%01.2f", round($params['invoiceTotal'], 2));
        $totalAmountCents = $totalAmount * 100;
        $isSignup = 0;

        //Need to check to see if user is coming from signup
        if ($params['isSignup']) {
            $isSignup = 1;
        }

        //Pass this variable to your gateway to let it know where to send a callback.
        $urlFix = mb_substr(CE_Lib::getSoftwareURL(), -1, 1) == "//" ? '' : '/';
        $callbackUrl = CE_Lib::getSoftwareURL().$urlFix.'plugins/gateways/'.basename(dirname(__FILE__)).'/callback.php?isSignup='.$isSignup.'&session_id={CHECKOUT_SESSION_ID}';

        // Use Stripe's bindings...
        \Stripe\Stripe::setApiKey($this->settings->get('plugin_stripecheckout_Stripe Checkout Gateway Secret Key'));
        \Stripe\Stripe::setAppInfo(
            'Clientexec',
            CE_Lib::getAppVersion(),
            'https://www.clientexec.com',
            STRIPE_PARTNER_ID
        );
        \Stripe\Stripe::setApiVersion(STRIPE_API_VERSION);

        $sessionParams = array(
            'payment_method_types' => array(
                'card'
            ),
            'line_items'           => array(
                array(
                    'name'        => 'Invoice #'.$params['invoiceNumber'],
                    'description' => 'Invoice #'.$params['invoiceNumber'],
                    'amount'      => $totalAmountCents,
                    'currency'    => $params['userCurrency'],
                    'quantity'    => 1,
                )
            ),
            //Set payment_intent off_session
            'payment_intent_data'  => array(
                'setup_future_usage' => 'off_session',
            ),
            'success_url'          => $callbackUrl,
            'cancel_url'           => $callbackUrl
        );

        $profile_id = $params['profile_id'];

        if ($profile_id != '') {
            $sessionParams['customer'] = $profile_id;
        } else {
            $sessionParams['customer_email'] = $this->user->getEmail();
        }

        $session = \Stripe\Checkout\Session::create($sessionParams);

        $publishableKey = $this->getVariable('Stripe Checkout Gateway Publishable Key');
        $sessionId = $session->id;

        $strRet = '<script data-cfasync="false" src="https://js.stripe.com/v3/"></script>'
            .'<script data-cfasync="false" type="text/javascript">'
            .'    var stripe = Stripe("'.$publishableKey.'");'
            // Open Checkout with further options
            .'    stripecheckoutOpenHandler();'
            .'    function stripecheckoutOpenHandler() {'
            .'        stripe.redirectToCheckout({'
            .'            sessionId: "'.$sessionId.'"'
            .'        }).then(function (result) {'
            // If `redirectToCheckout` fails due to a browser or network error, display the localized error message to your customer using `result.error.message`
            .'            console.log(result);'
            .'            console.log(result.error.message);'
            .'            alert(result.error.message);'
            .'        });'
            .'    }'
            .'</script>';

        return $strRet;
    }

    public function getForm($params)
    {
        if ($this->getVariable('Stripe Checkout Gateway Publishable Key') == '') {
            return '';
        }

        $this->view->from = $params['from'];

        switch ($params['from']) {
            case 'paymentmethod':
                $this->view->hasBillingProfile = false;
                $this->view->publishableKey = $this->getVariable('Stripe Checkout Gateway Publishable Key');
                $this->view->logoImage = $this->getVariable('Stripe Checkout Logo Image URL');
                $this->view->companyName = $this->settings->get("Company Name");
                $this->view->invoiceId = $params['invoiceId'];
                $this->view->currency = $params['currency'];
                $this->view->invoiceBalanceDue = $params['invoiceBalanceDue'];
                $this->view->panelLabel = $params['panellabel'];
                $this->view->termsConditions = $params['termsConditions'];

                $Billing_Profile_ID = '';

                if ($this->user->getCustomFieldsValue('Billing-Profile-ID', $Billing_Profile_ID) && $Billing_Profile_ID != '') {
                    $profile_id_array = unserialize($Billing_Profile_ID);

                    if (is_array($profile_id_array) && isset($profile_id_array[basename(dirname(__FILE__))])) {
                        $this->view->hasBillingProfile = true;
                    }
                }


                if ($this->view->logoImage == '') {
                    $SoftwareURL = mb_substr(CE_Lib::getSoftwareURL(), -1, 1) == "//" ? CE_Lib::getSoftwareURL() : CE_Lib::getSoftwareURL()."/";
                    $this->view->logoImage = $SoftwareURL.'plugins/gateways/stripecheckout/logo.png';
                }

                return $this->view->render('form.phtml');
                break;
            case 'signup':
                $fakeForm = '<a style="margin-left:0px;cursor:pointer;" class="app-btns primary customButton center-on-mobile" onclick="cart.submit_form('.$params['loggedIn'].');"  id="submitButton"></a>';

                return $fakeForm;
                break;
            default:
                $totalAmount = sprintf("%01.2f", round($params['invoiceBalanceDue'], 2));
                $totalAmountCents = $totalAmount * 100;
                $isSignup = 0;

                //Need to check to see if user is coming from signup
                if ($params['from'] == 'signup') {
                    $isSignup = 1;
                }

                //Pass this variable to your gateway to let it know where to send a callback.
                $urlFix = mb_substr(CE_Lib::getSoftwareURL(), -1, 1) == "//" ? '' : '/';
                $callbackUrl = CE_Lib::getSoftwareURL().$urlFix.'plugins/gateways/'.basename(dirname(__FILE__)).'/callback.php?isSignup='.$isSignup.'&session_id={CHECKOUT_SESSION_ID}';

                try {
                    // Use Stripe's bindings...
                    \Stripe\Stripe::setApiKey($this->settings->get('plugin_stripecheckout_Stripe Checkout Gateway Secret Key'));
                    \Stripe\Stripe::setAppInfo(
                        'Clientexec',
                        CE_Lib::getAppVersion(),
                        'https://www.clientexec.com',
                        STRIPE_PARTNER_ID
                    );
                    \Stripe\Stripe::setApiVersion(STRIPE_API_VERSION);

                    $sessionParams = array(
                        'payment_method_types' => array(
                            'card'
                        ),
                        'line_items'           => array(
                            array(
                                'name'        => 'Invoice #'.$params['invoiceId'],
                                'description' => 'Invoice #'.$params['invoiceId'],
                                'amount'      => $totalAmountCents,
                                'currency'    => $params['currency'],
                                'quantity'    => 1,
                            )
                        ),
                        //Set payment_intent off_session
                        'payment_intent_data'  => array(
                            'setup_future_usage' => 'off_session',
                        ),
                        'success_url'          => $callbackUrl,
                        'cancel_url'           => $callbackUrl
                    );

                    $profile_id = '';
                    $Billing_Profile_ID = '';

                    if ($this->user->getCustomFieldsValue('Billing-Profile-ID', $Billing_Profile_ID) && $Billing_Profile_ID != '') {
                        $profile_id_array = unserialize($Billing_Profile_ID);

                        if (is_array($profile_id_array) && isset($profile_id_array[basename(dirname(__FILE__))])) {
                            $profile_id = $profile_id_array[basename(dirname(__FILE__))];
                        }
                    }

                    $profile_id_values_array = explode('|', $profile_id);
                    $profile_id = $profile_id_values_array[0];

                    if ($profile_id != '') {
                        $sessionParams['customer'] = $profile_id;
                    } else {
                        $sessionParams['customer_email'] = $this->user->getEmail();
                    }

                    $session = \Stripe\Checkout\Session::create($sessionParams);

                    $this->view->publishableKey = $this->getVariable('Stripe Checkout Gateway Publishable Key');
                    $this->view->sessionId = $session->id;

                    return $this->view->render('sca.phtml');
                    break;
                } catch (Exception $e) {
                    return '';
                }
        }
    }
}
