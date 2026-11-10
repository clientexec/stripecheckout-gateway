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
            lang('Test Mode?') => array(
                'type'        => 'yesno',
                'description' => lang('Enable test mode, and use the test keys instead of the live keys.'),
                'value'       => '0'
            ),
            lang('Stripe Checkout Test Publishable Key') => array(
                'type'        => 'password',
                'description' => lang('Please enter your Stripe Checkout Test Publishable Key here.'),
                'value'       => ''
            ),
            lang('Stripe Checkout Test Secret Key') => array(
                'type'        => 'password',
                'description' => lang('Please enter your Stripe Checkout Test Secret Key here.'),
                'value'       => ''
            ),
            lang('Delete Client From Gateway') => array(
                'type'        => 'yesno',
                'description' => lang('Select YES if you want to delete the client from the gateway when the client changes the payment method or is deleted.'),
                'value'       => '0'
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
            if ($this->getVariable('Test Mode?') == '1') {
                $key = $this->getVariable('Stripe Checkout Test Secret Key');
            } else {
                $key = $this->getVariable('Stripe Checkout Gateway Secret Key');
            }

            if (strpos((string)$key, 'sk_') !== 0 && strpos((string)$key, 'rk_') !== 0) {
                CE_Lib::log(
                    1,
                    'Stripe Checkout: the secret key configured for this gateway is missing or invalid '
                    . '(it must start with sk_, or rk_ if it is a restricted API key with limited permissions). Check the Stripe Checkout plugin settings '
                    . '(Test Mode is ' . ($this->getVariable('Test Mode?') == '1' ? 'on' : 'off') . ').'
                );
            }

            \Stripe\Stripe::setApiKey($key);
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

                if (is_array($profile_id_array)) {
                    if (isset($profile_id_array[basename(dirname(__FILE__))])) {
                        $profile_id = $profile_id_array[basename(dirname(__FILE__))];
                    } elseif (isset($profile_id_array['stripecheckout'])) {
                        $profile_id = $profile_id_array['stripecheckout'];
                    } elseif (isset($profile_id_array['stripe'])) {
                        $profile_id = $profile_id_array['stripe'];
                    }
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

                try {
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
                } catch (Exception $e) {
                    $cPlugin->PaymentRejected($this->user->lang("There was an error performing this operation.") . ' ' . $e->getMessage());
                    return $this->user->lang("There was an error performing this operation.") . ' ' . $e->getMessage();
                }
            } else {
                $profile_id_values_array = explode('|', $profile_id);
                $profile_id = $profile_id_values_array[0];

                if (isset($profile_id_values_array[1])) {
                    $payment_method = $profile_id_values_array[1];
                } else {
                    if ($profile_id != '') {
                        $customer = $this->updateStripeCustomer($profile_id, $this->getStripeCustomerFields($params), $user->getId());
                        if ($customer === null) {
                            $profile_id = '';
                        } else {
                            $payment_method = $customer->default_source;
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
                        'FORM' => $form
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
                            'amount' => $totalAmountCents,
                            'currency' => $params['userCurrency'],
                            'payment_method_types' => array(
                                'card'
                            ),
                            'customer' => $params['profile_id'],
                            'payment_method' => $params['payment_method'],
                            'description' => 'Invoice #' . $params['invoiceNumber'],
                            'off_session' => true,
                            'confirm' => true,
                            'capture_method' => 'manual'
                        )
                    );

                    if ($payment_intent->status == "requires_capture") {
                        $result = $payment_intent->capture();
                    }

                    if ($payment_intent->status == 'succeeded') {
                        $transactionId = \Stripe\Charge::retrieve($payment_intent->latest_charge)->balance_transaction;
                        $amount = sprintf("%01.2f", round(($payment_intent->amount / 100), 2));
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

                        $profile_id_array[basename(dirname(__FILE__))] = $profile_id . '|' . $payment_method;
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
        } catch (Exception $e) {
            $errorMessage = $this->getStripeErrorMessage($e);
            $cPlugin->PaymentRejected($errorMessage);
            return $errorMessage;
        }
    }

    /**
     * Sends the client to a setup-mode Stripe Checkout Session to save or replace their card.
     * The callback (isPaymentMethod=1) stores the resulting customer and payment method.
     */
    public function updatePaymentMethod($params): mixed
    {
        if ($this->getVariable('Test Mode?') == '1') {
            $key = $this->getVariable('Stripe Checkout Test Secret Key');
        } else {
            $key = $this->getVariable('Stripe Checkout Gateway Secret Key');
        }

        if (strpos((string)$key, 'sk_') !== 0 && strpos((string)$key, 'rk_') !== 0) {
            CE_Lib::log(
                1,
                'Stripe Checkout: the secret key configured for this gateway is missing or invalid '
                . '(it must start with sk_, or rk_ if it is a restricted API key with limited permissions). Check the Stripe Checkout plugin settings '
                . '(Test Mode is ' . ($this->getVariable('Test Mode?') == '1' ? 'on' : 'off') . ').'
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

        $params['CustomerID'] = $this->user->getId();
        $params['userEmail'] = $this->user->getEmail();
        $params['userFirstName'] = $this->user->getFirstName();
        $params['userLastName'] = $this->user->getLastName();
        $params['userAddress'] = $this->user->getAddress();
        $params['userCity'] = $this->user->getCity();
        $params['userState'] = $this->user->getState();
        $params['userZipcode'] = $this->user->getZipCode();
        $params['userCountry'] = $this->user->getCountry();
        $params['userPhone'] = $this->user->getPhone();

        //Create a client Id hash
        require_once 'library/encrypted/Clientexec.php';

        $encryptedCustomerID = Clientexec::encryptString($params['CustomerID']);
        if (is_a($encryptedCustomerID, 'CE_Error')) {
            return $encryptedCustomerID;
        }

        $encryptedCustomerID = urlencode(strtr($encryptedCustomerID, '+/', '-_'));
        //Create a client Id hash

        //Pass this variable to your gateway to let it know where to send a callback.
        $urlFix = mb_substr(CE_Lib::getSoftwareURL(), -1, 1) == "//" ? '' : '/';
        $callbackUrl = CE_Lib::getSoftwareURL() . $urlFix . 'plugins/gateways/' . basename(dirname(__FILE__)) . '/callback.php?isPaymentMethod=1&clientHash=' . $encryptedCustomerID . '&session_id={CHECKOUT_SESSION_ID}';

        $sessionParams = array(
            'payment_method_types' => array(
                'card'
            ),
            'mode' => 'setup',
            'success_url' => $callbackUrl,
            'cancel_url' => $callbackUrl,
        );

        $profile_id = '';
        $Billing_Profile_ID = '';

        if ($this->user->getCustomFieldsValue('Billing-Profile-ID', $Billing_Profile_ID) && $Billing_Profile_ID != '') {
            $profile_id_array = unserialize($Billing_Profile_ID);

            if (is_array($profile_id_array)) {
                if (isset($profile_id_array[basename(dirname(__FILE__))])) {
                    $profile_id = $profile_id_array[basename(dirname(__FILE__))];
                } elseif (isset($profile_id_array['stripecheckout'])) {
                    $profile_id = $profile_id_array['stripecheckout'];
                } elseif (isset($profile_id_array['stripe'])) {
                    $profile_id = $profile_id_array['stripe'];
                }
            }
        }

        $profile_id_values_array = explode('|', $profile_id);
        $profile_id = $profile_id_values_array[0];

        if ($profile_id != '') {
            $customer = $this->updateStripeCustomer($profile_id, $this->getStripeCustomerFields($params), $params['CustomerID']);
            if ($customer === null) {
                $profile_id = '';
            }
        }

        if ($profile_id == '') {
            $customerFields = $this->getStripeCustomerFields($params);
            $customerFields['email'] = $params['userEmail'];
            $customer = $this->createStripeCustomer($customerFields, $params['CustomerID']);
            $profile_id = ($customer === null) ? '' : $customer->id;
        }

        if ($profile_id == '') {
            CE_Lib::addErrorMessage($this->user->lang('Your payment method was not updated'));
            return '';
        }

        $sessionParams['customer'] = $profile_id;

        try {
            //https://stripe.com/docs/payments/save-and-reuse
            //Create a Checkout Session
            $session = \Stripe\Checkout\Session::create($sessionParams);

            // 303 redirect to $session->url
            CE_Lib::redirectPage($session->url);
            return '';
        } catch (Exception $e) {
            CE_Lib::log(4, $this->getStripeErrorMessage($e));
            CE_Lib::addErrorMessage($this->user->lang('Your payment method was not updated'));
            return '';
        }
    }

    /**
     * Builds the name, phone and address sent to Stripe when creating or updating a customer.
     */
    private function getStripeCustomerFields(array $params): array
    {
        $fields = [
            'name' => $params["userFirstName"] . ' ' . $params["userLastName"],
            'phone' => (string)$params['userPhone'],
            'address' => [
                'line1' => $params["userAddress"],
                'postal_code' => $params["userZipcode"],
                'city' => $params["userCity"],
                'state' => $params["userState"],
                'country' => $params["userCountry"]
            ]
        ];

        // Stripe rejects phone numbers longer than 20 characters, which fails the whole customer request
        if (mb_strlen($fields['phone']) > 20) {
            CE_Lib::log(4, 'Stripe Checkout: leaving out the phone number for client ' . $params['CustomerID'] . ', it is longer than the 20 characters Stripe allows');
            unset($fields['phone']);
        }

        return $fields;
    }

    /**
     * Creates a Stripe customer. If Stripe rejects one of the optional fields (phone, address or name),
     * that field is dropped and the request retried, so a bad value in the client profile never stops
     * the customer from being created. Returns null if the customer could not be created.
     */
    private function createStripeCustomer(array $fields, $clientId): ?\Stripe\Customer
    {
        for ($attempt = 0; $attempt <= 3; $attempt++) {
            try {
                return \Stripe\Customer::create($fields);
            } catch (\Stripe\Exception\InvalidRequestException $e) {
                $droppedField = $this->dropRejectedCustomerField($fields, $e);
                if ($droppedField === null) {
                    CE_Lib::log(1, 'Stripe Checkout: could not create a customer for client ' . $clientId . '. ' . $e->getMessage());
                    return null;
                }

                CE_Lib::log(4, 'Stripe Checkout: retrying customer creation for client ' . $clientId . ' without ' . $droppedField . '. ' . $e->getMessage());
            } catch (Exception $e) {
                CE_Lib::log(1, 'Stripe Checkout: could not create a customer for client ' . $clientId . '. ' . $e->getMessage());
                return null;
            }
        }

        CE_Lib::log(1, 'Stripe Checkout: could not create a customer for client ' . $clientId . ' after dropping the rejected fields');
        return null;
    }

    /**
     * Updates an existing Stripe customer, dropping and retrying any optional field Stripe rejects.
     * Returns null only when the customer no longer exists in Stripe, so callers create a new one.
     * If the update fails for any other reason, the existing customer is returned unchanged, so a bad
     * value in the client profile never leads to a duplicate customer.
     */
    private function updateStripeCustomer(string $profileId, array $fields, $clientId): ?\Stripe\Customer
    {
        for ($attempt = 0; $attempt <= 3; $attempt++) {
            try {
                return \Stripe\Customer::update($profileId, $fields);
            } catch (\Stripe\Exception\InvalidRequestException $e) {
                if ($e->getStripeCode() === 'resource_missing') {
                    CE_Lib::log(4, 'Stripe Checkout: customer ' . $profileId . ' for client ' . $clientId . ' no longer exists, a new one will be created');
                    return null;
                }

                $droppedField = $this->dropRejectedCustomerField($fields, $e);
                if ($droppedField === null) {
                    CE_Lib::log(1, 'Stripe Checkout: could not update customer ' . $profileId . ' for client ' . $clientId . ', keeping it unchanged. ' . $e->getMessage());
                    break;
                }

                CE_Lib::log(4, 'Stripe Checkout: retrying update of customer ' . $profileId . ' for client ' . $clientId . ' without ' . $droppedField . '. ' . $e->getMessage());
            } catch (Exception $e) {
                CE_Lib::log(1, 'Stripe Checkout: could not update customer ' . $profileId . ' for client ' . $clientId . ', keeping it unchanged. ' . $e->getMessage());
                break;
            }
        }

        try {
            return \Stripe\Customer::retrieve($profileId);
        } catch (Exception $e) {
            CE_Lib::log(1, 'Stripe Checkout: could not retrieve customer ' . $profileId . ' for client ' . $clientId . '. ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Removes the field Stripe reported as invalid, if it is one of the optional customer fields.
     * Returns the rejected parameter name, or null if there is nothing that can safely be dropped.
     */
    private function dropRejectedCustomerField(array &$fields, \Stripe\Exception\InvalidRequestException $e): ?string
    {
        $param = (string)$e->getStripeParam();
        $field = preg_replace('/\[.*$/', '', $param);

        if (!in_array($field, ['phone', 'address', 'name'], true) || !array_key_exists($field, $fields)) {
            return null;
        }

        unset($fields[$field]);

        return $param;
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

            if ($this->getVariable('Test Mode?') == '1') {
                $key = $this->getVariable('Stripe Checkout Test Secret Key');
            } else {
                $key = $this->getVariable('Stripe Checkout Gateway Secret Key');
            }

            if (strpos((string)$key, 'sk_') !== 0 && strpos((string)$key, 'rk_') !== 0) {
                CE_Lib::log(
                    1,
                    'Stripe Checkout: the secret key configured for this gateway is missing or invalid '
                    . '(it must start with sk_, or rk_ if it is a restricted API key with limited permissions). Check the Stripe Checkout plugin settings '
                    . '(Test Mode is ' . ($this->getVariable('Test Mode?') == '1' ? 'on' : 'off') . ').'
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

            $profile_id = '';
            $Billing_Profile_ID = '';
            $profile_id_array = array();
            $user = new User($params['User ID']);

            if ($user->getCustomFieldsValue('Billing-Profile-ID', $Billing_Profile_ID) && $Billing_Profile_ID != '') {
                $profile_id_array = unserialize($Billing_Profile_ID);

                if (is_array($profile_id_array)) {
                    if (isset($profile_id_array[basename(dirname(__FILE__))])) {
                        $profile_id = $profile_id_array[basename(dirname(__FILE__))];
                    } elseif (isset($profile_id_array['stripecheckout'])) {
                        $profile_id = $profile_id_array['stripecheckout'];
                    } elseif (isset($profile_id_array['stripe'])) {
                        $profile_id = $profile_id_array['stripe'];
                    }
                }
            }

            $profile_id_values_array = explode('|', $profile_id);
            $profile_id = $profile_id_values_array[0];

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
        } catch (Exception $e) {
            return array(
                'error'  => true,
                'detail' => $this->getStripeErrorMessage($e)
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
        $callbackUrl = CE_Lib::getSoftwareURL() . $urlFix . 'plugins/gateways/' . basename(dirname(__FILE__)) . '/callback.php?isSignup=' . $isSignup . '&session_id={CHECKOUT_SESSION_ID}';

        if ($this->getVariable('Test Mode?') == '1') {
            $key = $this->getVariable('Stripe Checkout Test Secret Key');
        } else {
            $key = $this->getVariable('Stripe Checkout Gateway Secret Key');
        }

        if (strpos((string)$key, 'sk_') !== 0 && strpos((string)$key, 'rk_') !== 0) {
            CE_Lib::log(
                1,
                'Stripe Checkout: the secret key configured for this gateway is missing or invalid '
                . '(it must start with sk_, or rk_ if it is a restricted API key with limited permissions). Check the Stripe Checkout plugin settings '
                . '(Test Mode is ' . ($this->getVariable('Test Mode?') == '1' ? 'on' : 'off') . ').'
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

        $sessionParams = array(
            'payment_method_types' => array(
                'card'
            ),
            'line_items' => [[
                'price_data' => [
                    'currency' => $params['userCurrency'],
                    'unit_amount' => $totalAmountCents,
                    'product_data' => [
                        'name' => 'Invoice #' . $params['invoiceNumber'],
                        'description' => 'Invoice #' . $params['invoiceNumber'],
                    ],
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            //Set payment_intent off_session
            'payment_intent_data'  => array(
                'setup_future_usage' => 'off_session',
                'capture_method'     => 'manual',
            ),
            'success_url'          => $callbackUrl,
            'cancel_url'           => $callbackUrl
        );

        $profile_id = $params['profile_id'];

        if ($profile_id != '') {
            $sessionParams['customer'] = $profile_id;
        } else {
            $sessionParams['customer_email'] = $this->user->getEmail();
            $sessionParams['customer_creation']  = 'always';
        }

        $session = \Stripe\Checkout\Session::create($sessionParams);

        // Stripe.js removed redirectToCheckout() starting with Clover, so redirect to the
        // hosted Checkout page URL directly
        $strRet = '<script data-cfasync="false" type="text/javascript">'
            . '    window.location.href = ' . json_encode($session->url) . ';'
            . '</script>';

        return $strRet;
    }

    public function getForm($params)
    {
        if (($this->getVariable('Test Mode?') == '0' && $this->getVariable('Stripe Checkout Gateway Publishable Key') == '') || ($this->getVariable('Test Mode?') == '1' && $this->getVariable('Stripe Checkout Test Publishable Key') == '')) {
            return '';
        }

        $this->view->from = $params['from'];

        switch ($params['from']) {
            case 'paymentmethod':
                // Card updates go through a setup-mode Checkout Session (see updatePaymentMethod())
                // The saved "Call on updateGatewayInformation" setting on existing installs calls
                // createFullCustomerProfile() when stripeTokenId is not empty, so the field keeps that name and
                // now just carries a flag. createFullCustomerProfile() hands off to updatePaymentMethod().
                // The payment method page calls stripecheckoutOpenHandler() when a client without a saved card
                // selects this gateway (see the "openHandler" variable). It is intentionally a no-op: the client
                // clicks "Update Credit Card", which submits the form.
                $strRet = '<input type="hidden" id="stripeTokenId" name="stripecheckout_plugincustomfields[stripeTokenId]" value="1">'
                    . '<button style="margin-left:0px;cursor:pointer;" class="btn btn-primary customButton stripecheckoutButton" id="customButton">' . $this->user->lang("Update Credit Card") . '</button>'
                    . '<script data-cfasync="false" type="text/javascript">'
                    . '    function stripecheckoutOpenHandler() {'
                    . '    }'
                    . '</script>';

                return $strRet;
                break;
            case 'signup':
                $fakeForm = '<a style="margin-left:0px;cursor:pointer;" class="app-btns primary customButton center-on-mobile ' . ((@$params['termsConditions']) ? 'disabled' : '') . '" onclick="cart.submit_form(' . $params['loggedIn'] . ');"  id="submitButton"></a>';

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
                $callbackUrl = CE_Lib::getSoftwareURL() . $urlFix . 'plugins/gateways/' . basename(dirname(__FILE__)) . '/callback.php?isSignup=' . $isSignup . '&session_id={CHECKOUT_SESSION_ID}';

                try {
                    if ($this->getVariable('Test Mode?') == '1') {
                        $key = $this->getVariable('Stripe Checkout Test Secret Key');
                    } else {
                        $key = $this->getVariable('Stripe Checkout Gateway Secret Key');
                    }

                    if (strpos((string)$key, 'sk_') !== 0 && strpos((string)$key, 'rk_') !== 0) {
                        CE_Lib::log(
                            1,
                            'Stripe Checkout: the secret key configured for this gateway is missing or invalid '
                            . '(it must start with sk_, or rk_ if it is a restricted API key with limited permissions). Check the Stripe Checkout plugin settings '
                            . '(Test Mode is ' . ($this->getVariable('Test Mode?') == '1' ? 'on' : 'off') . ').'
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

                    $sessionParams = array(
                        'payment_method_types' => array(
                            'card'
                        ),

                        'line_items' => [[
                            'price_data' => [
                                'currency' => $params['currency'],
                                'unit_amount' => $totalAmountCents,
                                'product_data' => [
                                    'name' => 'Invoice #' . $params['invoiceId'],
                                    'description' => 'Invoice #' . $params['invoiceId'],
                                ],
                            ],
                            'quantity' => 1,
                        ]],

                        'mode' => 'payment',
                        //Set payment_intent off_session
                        'payment_intent_data'  => array(
                            'setup_future_usage' => 'off_session',
                            'capture_method'     => 'manual',
                        ),
                        'success_url'          => $callbackUrl,
                        'cancel_url'           => $callbackUrl
                    );

                    $profile_id = '';
                    $Billing_Profile_ID = '';

                    if ($this->user->getCustomFieldsValue('Billing-Profile-ID', $Billing_Profile_ID) && $Billing_Profile_ID != '') {
                        $profile_id_array = unserialize($Billing_Profile_ID);

                        if (is_array($profile_id_array)) {
                            if (isset($profile_id_array[basename(dirname(__FILE__))])) {
                                $profile_id = $profile_id_array[basename(dirname(__FILE__))];
                            } elseif (isset($profile_id_array['stripecheckout'])) {
                                $profile_id = $profile_id_array['stripecheckout'];
                            } elseif (isset($profile_id_array['stripe'])) {
                                $profile_id = $profile_id_array['stripe'];
                            }
                        }
                    }

                    $profile_id_values_array = explode('|', $profile_id);
                    $profile_id = $profile_id_values_array[0];

                    if ($profile_id != '') {
                        $sessionParams['customer'] = $profile_id;
                    } else {
                        $sessionParams['customer_email'] = $this->user->getEmail();
                        $sessionParams['customer_creation']  = 'always';
                    }

                    $session = \Stripe\Checkout\Session::create($sessionParams);

                    $this->view->sessionUrl = $session->url;

                    return $this->view->render('sca.phtml');
                    break;
                } catch (Exception $e) {
                    CE_Lib::log(4, $e->getMessage());
                    return '';
                }
        }
    }

    /**
     * Entry point from "Call on updateGatewayInformation" when the client submits the payment method
     * page. The name is kept so the setting already saved on existing installs keeps working.
     */
    public function createFullCustomerProfile($params): mixed
    {
        return $this->updatePaymentMethod($params);
    }

    /**
     * Builds the error message returned to the caller for a failed Stripe operation, and logs
     * Stripe API errors with their request ID so they can be traced in the Stripe dashboard.
     */
    private function getStripeErrorMessage(Exception $e): string
    {
        $message = $this->user->lang("There was an error performing this operation.");

        if ($e instanceof \Stripe\Exception\ApiErrorException) {
            CE_Lib::log(4, 'Stripe API error (' . get_class($e) . '): ' . $e->getMessage() . ' Request ID: ' . $e->getRequestId());
        }

        // RateLimitException extends InvalidRequestException, so it must be checked first
        if ($e instanceof \Stripe\Exception\RateLimitException) {
            $message .= " " . $this->user->lang("Too many requests made to the API too quickly.");
        } elseif ($e instanceof \Stripe\Exception\InvalidRequestException) {
            $message .= " " . $this->user->lang("Invalid parameters were supplied to Stripe's API.");
        } elseif ($e instanceof \Stripe\Exception\AuthenticationException) {
            $message .= " " . $this->user->lang("Authentication with Stripe's API failed. Maybe you changed API keys recently.");
        } elseif ($e instanceof \Stripe\Exception\ApiConnectionException) {
            $message .= " " . $this->user->lang("Network communication with Stripe failed");
        }

        return $message . " " . $e->getMessage();
    }
}