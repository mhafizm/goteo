<?php
/*
 * This file is part of the Goteo Package.
 *
 * (c) Platoniq y Fundación Goteo <fundacion@goteo.org>
 *
 * For the full copyright and license information, please view the README.md
 * and LICENSE files that was distributed with this source code.
 */

namespace Goteo\Payment\Method;

use Goteo\Application\Config;
use Goteo\Application\Currency;

/**
 * Creates a Payment Method that uses Paypal provider
 */
class ToyyibpayPaymentMethod extends AbstractPaymentMethod {

    // Uses omnipay manual method, always successful
    public function getGatewayName() {
        return 'ToyyibPay';
    }

    public function getName() {
        return 'ToyyibPay';
    }

    public function getDesc() {
        return 'ToyyibPay Payment Gateway';
    }

    public function purchase()
    {
        $gateway = $this->getGateway();
        $gateway->setTestMode(1);

	$parameters = [
    		'userSecretKey' => 'ew9t175g-18sn-zmiy-skbt-crkg8952e482',
    		'categoryCode' => 'as9ili1e',
    		'billName' => 'Product Name',
    		'billDescription' => 'testing only',
    		'billPriceSetting'=> 1,
    		'billPayorInfo'=> 1,
    		'billAmount'=> (float) $this->getTotalAmount(),
    		'billReturnUrl'=>$this->getCompleteUrl(),
    		'billCallbackUrl'=>$this->getCompleteUrl(),
    		'billExternalReferenceNo' => 'ORDER123',
    		'billTo'=>'Customer Name',
    		'billEmail'=>'customer@sampleemail.test',
    		'billPhone'=>'0123456789',
    		'billPaymentChannel'=> '2',
    		'billDisplayMerchant'=> 1,
    		'billContentEmail' => 'Sample email content',
    		'billChargeToCustomer' => 2
	];

        $payment = $gateway->purchase($parameters);
        return $payment->send();
    }


    public function completePurchase() {
        // Let's obtain the gateway and the
        $gateway = $this->getGateway();
	$gateway->setTestMode(1);

	$request = $this->getRequest();
        $invest = $this->getInvest();

        $payment = $gateway->completePurchase([
                'billCode' => 'dcb9eqb5'

        ]);
        echo  $request->request->get('billCode');
        return $payment->send();


    }

    static public function isInternal() {
        return true;
    }

}
