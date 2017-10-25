<?php

private function insertInvoice()
{
    $order_source_id = $this->order['source_id'];
    $items_by_category = [];
    $total_cost = 0;
    foreach ($this->order['order_items'] as $item) {
        if (isset ($item['metadata']['invoice_visible']) && $item['metadata']['invoice_visible'] == 1 && isset ($item['metadata']['vat_category'])) {
            $vat_category = $item['metadata']['vat_category'];
            $item_cost = $item['price'] * $item['quantity'];
            $items_by_category[$vat_category]['items'][] = $item;
            $items_by_category[$vat_category]['total'] = isset($items_by_category[$vat_category]['total']) ? $items_by_category[$vat_category]['total'] + $item_cost : $item_cost;
            $total_cost += $item_cost;
        }
    }
    foreach ($items_by_category as &$cat) {
        $price_part = $cat['total'] / $total_cost;
        $cat['discount']=[];
        foreach ($this->order['discount_list'] as &$discount) {
            $discount_amount = $discount['amount'] * $price_part;
            $discount['part_amount'] = $discount_amount;
            $cat['discount'][] = $discount;
        }
        $cat['shipping'] = $this->order['shipping_total'] * $price_part;
    }
    if (!isset($this->order['order_metadata']['currency'])) {
        return ('Currency is not set!');
    }
    $order_created_timestamp = strtotime($this->order['date_added']);
    $currency_code = $this->order['order_metadata']['currency'];
    $currency_data = KashFlowModel::getExchangeRate($currency_code, $order_created_timestamp);
    $exchange_rate = $currency_data->exchange_rate;
    $country_code = $this->order['shipping']['country'];
    $country_data = OrderDeskModel::getCountry($country_code);
    if (!$currency_data) {
        return ('Can\'t find exchange rate or currency data');
    }
    $currency_id = $currency_data->currency_id;
    $error_message = 'ok';
    $operation = "GetCustomerByEmail";
    try {
        $customer = $this->kf->makeAPICall($operation, ['CustomerEmail' => $this->order['email']]);
    } catch (\Exception $e) {
        $error_message = $e->getMessage();
        return ($error_message);
    }
    if ($customer->Status != 'OK') {
        Log::info('Customer not found. Creating.');
        $error_message = 'ok';
        $operation = "InsertCustomer";
        $data = ['Name' => $this->order['customer']['first_name'] . ' ' . $this->order['customer']['last_name'],
            'Contact' => $this->order['customer']['first_name'] . ' ' . $this->order['customer']['last_name'],
            'Telephone' => $this->order['customer']['phone'],
            'Email' => $this->order['email'],
            'CustomerID' => 0,
            'EC' => 0,
            'OutsideEC' => 0,
            'Source' => 53408,
            'Discount' => 0,
            'ShowDiscount' => false,
            'PaymentTerms' => 21,
            'CheckBox1' => 0,
            'CheckBox2' => 0,
            'CheckBox3' => 0,
            'CheckBox4' => 0,
            'CheckBox5' => 0,
            'CheckBox6' => 0,
            'CheckBox7' => 0,
            'CheckBox8' => 0,
            'CheckBox9' => 0,
            'CheckBox10' => 0,
            'CheckBox11' => 0,
            'CheckBox12' => 0,
            'CheckBox13' => 0,
            'CheckBox14' => 0,
            'CheckBox15' => 0,
            'CheckBox16' => 0,
            'CheckBox17' => 0,
            'CheckBox18' => 0,
            'CheckBox19' => 0,
            'CheckBox20' => 0,
            'Created' => date('Y-m-d\TH:i:s'),
            'Updated' => date('Y-m-d\TH:i:s'),
            'CustHasDeliveryAddress' => 1,
            'CurrencyID' => $currency_id,
            'DeliveryAddress1' => isset($this->order['shipping']['address1']) ? $this->order['shipping']['address1'] : '',
            'DeliveryAddress2' => isset($this->order['shipping']['address2']) ? $this->order['shipping']['address2'] : '',
            'DeliveryAddress3' => isset($this->order['shipping']['city']) ? $this->order['shipping']['city'] : '',
            'DeliveryAddress4' => isset($this->order['shipping']['state']) ? $this->order['shipping']['state'] : '',
            'DeliveryPostcode' => isset($this->order['shipping']['postal_code']) ? $this->order['shipping']['postal_code'] : '',

            'Address1' => isset($this->order['customer']['address1']) ? $this->order['customer']['address1'] : '',
            'Address2' => isset($this->order['customer']['address2']) ? $this->order['customer']['address2'] : '',
            'Address3' => isset($this->order['customer']['city']) ? $this->order['customer']['city'] : '',
            'Address4' => isset($this->order['customer']['state']) ? $this->order['customer']['state'] : '',
            'Postcode' => isset($this->order['customer']['postal_code']) ? $this->order['customer']['postal_code'] : '',

            'CountryCode' => $this->order['customer']['country'],
            'DeliveryCountryCode' => $this->order['shipping']['country']
        ];
        try {
            $new_customer = $this->kf->makeAPICall($operation, ['custr' => $data]);
        } catch (\Exception $e) {
            $error_message = $e->getMessage();
            return ($error_message);
        }
        if ($new_customer->Status != 'OK') {
            return ($new_customer->StatusDetail);
        }
        $customer_id = $new_customer->InsertCustomerResult;
    } else {
        $customer_id = $customer->GetCustomerByEmailResult->CustomerID;
    }
    Log::info('Customer id #' . $customer_id);
    foreach ($items_by_category as $vat_category => $category) {
        $error_message = 'ok';
        $vat_field = 'vat_' . $vat_category;
        $vat_rate = $country_data->$vat_field;
        Log::info('Creating invoice');
        $operation = "InsertInvoice";
        $data = ['CurrencyCode' => $currency_code,
            'ExchangeRate' => $exchange_rate,
            'Paid' => 1,
            'InvoiceDBID' => '',
            'InvoiceNumber' => '',
            'CustomerReference' => $order_source_id,
            'CustomerID' => $customer_id,
            'SuppressTotal' => 0,
            'ProjectID' => 0,
            'NetAmount' => 0,
            'VATAmount' => 0,
            'AmountPaid' => 0,
            'Permalink' => '',
            'UseCustomDeliveryAddress' => false,
            'InvoiceDate' => date('Y-m-d\TH:i:s', $order_created_timestamp),
            'DueDate' => date('Y-m-d\TH:i:s', $order_created_timestamp + 21 * 24 * 3600),
        ];
        try {
            $invoice = $this->kf->makeAPICall($operation, ['Inv' => $data]);
        } catch (\Exception $e) {
            $error_message = $e->getMessage();
            return ($error_message);
        }
        if ($invoice->Status != 'OK') {
            return ($invoice->StatusDetail);
        }
        $invoice_id = $invoice->InsertInvoiceResult;
        Log::info('Created invoice #' . $invoice_id);
        $this->invoices_list[] = $invoice_id;
        foreach ($category['items'] as $item) {
            Log::info('Inserting ' . $item['name']);
            $rate = round($item['price'] / (1 + $vat_rate * 0.01), 2);
            //$rate = $item['price'] / (1 + $vat_rate * 0.01);
            $vat_amount = $item['price'] * $item['quantity'] - $rate * $item['quantity'];
            $operation = 'InsertInvoiceLineWithInvoiceNumber';
            $line_data = ['Quantity' => $item['quantity'],
                'Description' => $item['name'],
                'Rate' => $rate,
                'ChargeType' => isset($item['metadata']['kf_charge_type']) ? $item['metadata']['kf_charge_type'] : 0,
                'VatRate' => $vat_rate,
                'VatAmount' => $vat_amount,
                'ProductID' => 0,
                'Sort' => '',
                'ProjID' => 0,
                'LineID' => 0,
                'ValuesInCurrency' => $currency_code == 'GBP' ? 0 : 1
            ];
            try {
                $invoice = $this->kf->makeAPICall($operation, ['InvoiceNumber' => $invoice_id, 'InvLine' => $line_data]);
            } catch (\Exception $e) {
                $error_message = $e->getMessage();
                return ($error_message);
            }
            if ($invoice->Status != 'OK') {
                return ($invoice->StatusDetail);
            }
        }
        foreach ($category['discount'] as $disc) {
            if ($disc['part_amount'] != 0) {
                Log::info('Inserting discount');
                $rate = round($disc['part_amount'] / (1 + $vat_rate * 0.01), 2);
                //$rate = $disc['part_amount'] / (1 + $vat_rate * 0.01);
                $vat_amount = $disc['part_amount'] - $rate;
                $operation = 'InsertInvoiceLineWithInvoiceNumber';
                $line_data = ['Quantity' => 1,
                    'Description' => $disc['name'],
                    'Rate' => 0 - $rate,
                    'ChargeType' => 17372263,
                    'VatRate' => $vat_rate,
                    'VatAmount' => 0 - $vat_amount,
                    'ProductID' => 0,
                    'Sort' => '',
                    'ProjID' => 0,
                    'LineID' => 0,
                    'ValuesInCurrency' => $currency_code == 'GBP' ? 0 : 1
                ];
                try {
                    $invoice = $this->kf->makeAPICall($operation, ['InvoiceNumber' => $invoice_id, 'InvLine' => $line_data]);
                } catch (\Exception $e) {
                    $error_message = $e->getMessage();
                    return ($error_message);
                }
                if ($invoice->Status != 'OK') {
                    return ($invoice->StatusDetail);
                }
            }
        }
        if ($category['shipping'] > 0) {
            $rate = round($category['shipping'] / (1 + $vat_rate * 0.01), 2);
            //$rate = $category['shipping'] / (1 + $vat_rate * 0.01);
            $vat_amount = $category['shipping'] - $rate;
            $operation = 'InsertInvoiceLineWithInvoiceNumber';
            Log::info('Inserting shipping');
            $line_data = ['Quantity' => 1,
                'Description' => 'Shipping',
                'Rate' => $rate,
                'ChargeType' => 1857368,
                'VatRate' => $vat_rate,
                'VatAmount' => $vat_amount,
                'ProductID' => 0,
                'Sort' => '',
                'ProjID' => 0,
                'LineID' => 0,
                'ValuesInCurrency' => $currency_code == 'GBP' ? 0 : 1
            ];
            try {
                $invoice = $this->kf->makeAPICall($operation, ['InvoiceNumber' => $invoice_id, 'InvLine' => $line_data]);
            } catch (\Exception $e) {
                $error_message = $e->getMessage();
                return ($error_message);
            }
            if ($invoice->Status != 'OK') {
                return ($invoice->StatusDetail);
            }
        }
        $operation = 'GetInvoice';
        try {
            $invoice = $this->kf->makeAPICall($operation, ['InvoiceNumber' => $invoice_id]);
        } catch (\Exception $e) {
            $error_message = $e->getMessage();
            return ($error_message);
        }
        if ($invoice->Status != 'OK') {
            return ($invoice->StatusDetail);
        }
        $amount=$invoice->GetInvoiceResult->NetAmount+$invoice->GetInvoiceResult->VATAmount;
        if ((float)$amount!=round($this->order['order_total']/$exchange_rate,2))
        {
            Log::info ('DIFFERENCE!!! '.$amount.' --- '.round($this->order['order_total']/$exchange_rate,2));
        }
        $payment_method = KashFlowModel::getPaymentMethod(mb_strtolower($this->order['payment_type']),mb_strtoupper($currency_code));
        $payment_method = $payment_method ? $payment_method : KashFlowModel::getPaymentMethod('default method',mb_strtoupper($currency_code));
        Log::info ((array)$payment_method);
        $transaction_id=false;
        $fee=false;
        $transaction_message='No fee found';

        if (isset ($this->order['order_metadata']['shopify_order_id'])) {

            try {
                $this->shopify = $this->setShopifyAPI($this->credentials[$this->order['order_metadata']['original_store_id']]);
            } catch (\Exception $e) {
                $error_message = $e->getMessage();
                return ($error_message);
            }
            try {
                $transactions = $this->shopify->call(['URL' => '/admin/orders/' . $this->order['order_metadata']['shopify_order_id'] . '/transactions.json', 'METHOD' => 'GET', 'DATA' => ['RETURNARRAY' => false]]);
            } catch (\Exception $e) {
                $error_message = $e->getMessage();
                return ($error_message);
            }
            foreach ($transactions->transactions as $transaction) {
                if (($transaction->kind == 'sale' && $transaction->status == 'success')) //todo refund - success
                {
                    if (isset($transaction->receipt->fee_amount)) {
                        $transaction_id = $transaction->authorization;
                        $fee = $transaction->receipt->fee_amount;
                    }
                    if (isset($transaction->gateway) && $transaction->gateway=='payflow_uk')
                    {
                        if (isset($transaction->receipt->pp_ref)) {
                            $transaction_id = $transaction->receipt->pp_ref;
                            $data = array(
                                'METHOD' => 'GetTransactionDetails',
                                'VERSION' => '51.0',
                                'TRANSACTIONID' => $transaction_id
                            );

                            $credentials["username"] = '***';
                            $credentials["password"] = '***';
                            $credentials["signature"] = '***';
                            $endpoint = 'https://api-3t.paypal.com/nvp';
                            $response = $this->makePayPalRequest($endpoint, $credentials, $data);
                            if (isset ($response['FEEAMT'])) {
                                $fee = $response['FEEAMT'];
                            }
                        }
                        else
                        {
                            $transaction_message='pp_ref not found';
                        }
                    }
                }
            }
        }
        Log::info('Inserting payment');
        $operation = 'InsertInvoicePayment';
        $payment_data = ['PayID' => '',
            'PayInvoice' => $invoice_id,
            'PayDate' => date('Y-m-d\TH:i:s', $order_created_timestamp),
            'PayNote' => $transaction_id ? $transaction_id : $transaction_message,
            'PayMethod' => $payment_method->method_id,
            'PayAccount' => $payment_method->method_account,
            'PayAmount' => $amount
        ];
        try {
            $invoice = $this->kf->makeAPICall($operation, ['InvoicePayment' => $payment_data]);
        } catch (\Exception $e) {
            $error_message = $e->getMessage();
            return ($error_message);
        }
        if ($invoice->Status != 'OK') {
            return ($invoice->StatusDetail);
        }

    }
    if ($fee && $transaction_id) {
        Log::info('Inserting fee');
        $operation = 'InsertBankTransaction';
        $transaction_data = [
            'ID'=>'',
            'CustomerId'=>0,
            'SupplierId'=>0,
            'accid'=>60719, //todo dynamical
            'TransactionDate' => date('Y-m-d\TH:i:s', $order_created_timestamp),
            'moneyin'=> 0,
            'moneyout'=> $fee/$exchange_rate,
            'Vatable'=> 0,
            'VatRate'=> 0,
            'VatAmount'=> 0,
            'TransactionType'=>3394165,//todo 'PayPal Commission'
            'Comment'=> 'PayPal Charge - INV## '.implode(',',$this->invoices_list),
            'ProjectID'=> 0
        ];
        try {
            $bank_transaction = $this->kf->makeAPICall($operation, ['bp' => $transaction_data]);
        } catch (\Exception $e) {
            $error_message = $e->getMessage();
            return ($error_message);
        }
        if ($bank_transaction->Status != 'OK') {
            return ($bank_transaction->StatusDetail);
        }
    }
    return ('ok');
}