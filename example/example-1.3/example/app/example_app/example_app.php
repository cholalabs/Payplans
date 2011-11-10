<?php
/**
* @copyright	Copyright (C) 2009 - 2009 Ready Bytes Software Labs Pvt. Ltd. All rights reserved.
* @license	GNU/GPL, see LICENSE.php
* @package	PayPlans
* @subpackage	Frontend
* @contact 	payplans@readybytes.in
*/
if(defined('_JEXEC')===false) die();

/*
  App should extend from 
	default : PayplansApp
	payment-app 	 : PayplansAppPayment
	registration-app : PayplansAppRegistration
	migration-app    : PayplansAppMigration
 */
class PayplansAppExample extends PayplansApp 
{
	//inherited properties
	// IMP : store txn_id in txn_id for payment
	// and store subscr_id for recurring master payment
	
	protected $_location	= __FILE__;
	
	function isApplicable($refObject = null, $eventName='')
	{
		// return true for event onPayplansControllerCreation
		if($eventName == 'onPayplansControllerCreation'){
			return true;
		}
		
		return parent::isApplicable($refObject, $eventName);
	}
	
	function onPayplansControllerCreation($view, $controller, $task, $format)
	{
		if($view != 'order' || $task != 'notify'){
			return true;
		}
		
		$token = JRequest::getVar('token', false);
		if(!$token){
			return true;
		}
		
		$respose = $this->_getResponse($token);
		if(!$respose){
			return true;
		}
		
		// we will get order and payment key from apc_1 and apc_2
		$orderKey 	= $respose['apc_1'];
		$paymentKey = $respose['apc_2'];
		
		if($orderKey == false || $paymentKey == false){
			return true;
		}
		// key is the combination of appId_orderKey
		$orderKey 	= explode('_', $orderKey);
		$paymentKey = explode('_', $paymentKey);
		
		if($orderKey[0] == $this->getId() &&  $paymentKey[0] == $this->getId()){
			JRequest::setVar('order_key', $orderKey[1], 'POST');
			JRequest::setVar('payment_key', $paymentKey[1], 'POST');
		}
		
		foreach($respose as $key => $value){
			JRequest::setVar($key, $value, 'POST');
		}
		
		return true;
	}
	
	public function onPayplansPaymentForm(PayplansPayment $payment, $data = null)
	{
		if(is_object($data)){
			$data = (array)$data;
		}

		$order = $payment->getOrder(PAYPLANS_INSTANCE_REQUIRE);
		
		$this->assign('post_url', "https://www.alertpay.com/PayProcess.aspx");
		if($this->getAppParam('sandbox', false)){
			$this->assign('ap_test', '1');
			$this->assign('post_url', "https://sandbox.alertpay.com/sandbox/payprocess.aspx"); 
		}

		$this->assign('ap_merchant', $this->getAppParam('merchant', ''));
		$this->assign('ap_itemname', $order->getKey());
		$this->assign('ap_currency', $this->getAppParam('currency', XiFactory::getConfig()->currency));
		
		$root = JURI::root();
    	if(XiFactory::getConfig()->https == true){
    		$root = JString::str_ireplace("http:", "https:", $root);
    	}
    	
		$this->assign('ap_returnurl', $root.'index.php?option=com_payplans&view=order&task=complete&action=success&order_key='.$order->getKey().'&payment_key='.$payment->getKey());
		$this->assign('ap_cancelurl', $root.'index.php?option=com_payplans&view=order&task=complete&action=cancel&order_key='.$order->getKey().'&payment_key='.$payment->getKey());
//		$this->assign('ap_alerturl',  $root.'index.php?option=com_payplans&view=order&task=notify&order_key='.$order->getKey().'&payment_key='.$payment->getKey());
		
		$plan = array_shift($payment->getPlans(PAYPLANS_INSTANCE_REQUIRE));
		$this->assign('ap_description', $plan->getTitle());	
	
		// custom data which store order key and payment key
		$this->assign('apc_1', $this->getId().'_'.$order->getKey());
		$this->assign('apc_2', $this->getId().'_'.$payment->getKey());
		//$this->assign('apc_3', $request->int_var['usage']);
		
		if($order->isRecurring()) {
			// recurring payment has been started
			$payment->set('status', XiStatus::PAYMENT_RECURRING_START)->save();
			
			$this->assign('ap_purchasetype', 'Subscription');
			$time = $this->getRecurrenceTime($plan->getExpiration());
			$first_price = $order->getFirstTotal();
			
			$this->assign('first_price', $first_price);
			
			$recurrence_count = $plan->getRecurrenceCount();
        	if(JString::trim($first_price) !== PAYPLANS_UNDEFINED){
        		// XITODO : could be different time
				$this->assign('ap_trialamount', $first_price);
				$this->assign('ap_trialtimeunit', $time['unit']);
				$this->assign('ap_trialperiodlength', $time['period']);
				
				$recurrence_count--;
			}

			$this->assign('ap_amount', 		 $order->getTotal());
			$this->assign('ap_timeunit', 	 $time['unit']);
			$this->assign('ap_periodlength', $time['period']);
			$this->assign('ap_periodcount',  $recurrence_count);
			return $this->_render('form_subscription');
		} 
		
		$this->assign('ap_purchasetype', 'Item');
		$this->assign('ap_amount', $order->getTotal());
		        
        return $this->_render('form_buynow');
	}
	
	public function getRecurrenceTime($expTime)
	{
		$expTime['year'] = isset($expTime['year']) ? intval($expTime['year']) : 0;
		$expTime['month'] = isset($expTime['month']) ? intval($expTime['month']) : 0;
		$expTime['day'] = isset($expTime['day']) ? intval($expTime['day']) : 0;;
		
		// years
		if(!empty($expTime['year'])){
			if($expTime['year'] >= 5){
				return array('period' => 5, 'unit' => 'Year', 'frequency' => XiText::_('COM_PAYPLANS_RECURRENCE_FREQUENCY_GREATER_THAN_ONE'));
			}
			
			if($expTime['year'] >= 2){
				return array('period' => $expTime['year'], 'unit' => 'Year', 'frequency' => XiText::_('COM_PAYPLANS_RECURRENCE_FREQUENCY_GREATER_THAN_ONE'));
			}
			
			// if months is not set then return years * 12 + months
			if(isset($expTime['month']) && $expTime['month']){
				return array('period' => $expTime['year'] * 12 + $expTime['month'], 'unit' => 'Month');
			}				
			
			return array('period' => $expTime['year'], 'unit' => 'Year', 'frequency' => XiText::_('COM_PAYPLANS_RECURRENCE_FREQUENCY_GREATER_THAN_ONE'));
		}
		
		// if months are set
		if(!empty($expTime['month'])){
			// if days are empty
			if(empty($expTime['day'])){
				return array('period' => $expTime['month'], 'unit' => 'Month', 'frequency' => XiText::_('COM_PAYPLANS_RECURRENCE_FREQUENCY_GREATER_THAN_ONE'));
			}
			
			// if total days are less or equlas to 90, then return days
			//  IMP : ASSUMPTION : 1 month = 30 days
			$days = $expTime['month'] * 30;
			if(($days + $expTime['day']) <= 90){
				return array('period' => $days + $expTime['day'], 'unit' => 'Day', 'frequency' => XiText::_('COM_PAYPLANS_RECURRENCE_FREQUENCY_GREATER_THAN_ONE'));
			}
			
			// other wise convert it into weeks
			return array('period' => intval(($days + $expTime['day'])/7, 10), 'unit' => 'W', 'frequency' => XiText::_('COM_PAYPLANS_RECURRENCE_FREQUENCY_GREATER_THAN_ONE'));
		}
		
		// if only days are set then return days as it is
		if(!empty($expTime['day'])){
			return array('period' => intval($expTime['day'], 10), 'unit' => 'Day', 'frequency' => XiText::_('COM_PAYPLANS_RECURRENCE_FREQUENCY_GREATER_THAN_ONE'));
		}
		
		// XITODO : what to do if not able to convert it
		return false;
	}

	public function onPayplansPaymentAfter(PayplansPayment $payment, $action, $data, $controller)
	{
		$record = array_pop(PayplansHelperLogger::getLog($payment, XiLogger::LEVEL_ERROR));			
		if($record && !empty($record)){
			$action = 'error';
		}
		
		return parent::onPayplansPaymentAfter($payment, $action, $data, $controller);
	}

	protected function _getResponse($token)
	{
		$response = '';
		if(empty($token)){
			return $response;
		}
		
		$url = "https://www.alertpay.com/ipn2.ashx";
		if($this->getAppParam('sandbox', false)){
			$url = "https://sandbox.alertpay.com/sandbox/ipn2.ashx"; 
		}
		// get the token from Alertpay
		$token = urlencode($token);
	
		//preappend the identifier string "token=" 
		$token = 'token='.$token;
		
		/**
		 * 
		 * Sends the URL encoded TOKEN string to the Alertpay's IPN handler
		 * using cURL and retrieves the response.
		 * 
		 * variable $response holds the response string from the Alertpay's IPN V2.
		 */
		
		$response = '';
		
		$ch = curl_init();
	
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $token);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HEADER, false);
		curl_setopt($ch, CURLOPT_TIMEOUT, 60);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	
		$response = curl_exec($ch);
	
		curl_close($ch);
		
		
		if(strlen($response) <= 0){
			// log the error
			$message = XiText::_('COM_PAYPLANS_APP_ALERTPAY_LOGGER_ERROR_NO_RESPONSE');
			$log_id = PayplansHelperLogger::log(XiLogger::LEVEL_ERROR, $message, $this, array($message));
			return false;
		}
		
		if(urldecode($response) == "INVALID TOKEN")
		{
			//the token is not valid
			$message = XiText::_('COM_PAYPLANS_APP_ALERTPAY_LOGGER_ERROR_INVALID_TOKEN');
			$log_id = PayplansHelperLogger::log(XiLogger::LEVEL_ERROR, $message, $this, array($message));
			return false;
		}
		
		//urldecode the received response from Alertpay's IPN V2
		$response = urldecode($response);
		
		//split the response string by the delimeter "&"
		$aps = explode("&", $response);
			
		//create a file to save the response information from Alertpay's IPN V2	
//		$myFile = "IPNRes.txt";
//		$fh = fopen($myFile,'a') or die("can't open the file");
		
		//define an array to put the IPN information
		$info = array();
		
		foreach ($aps as $ap)
		{
			//put the IPN information into an associative array $info
			$ele = explode("=", $ap);
			$info[$ele[0]] = $ele[1];
			
//			//write the information to the file IPNRes.txt
//			fwrite($fh, "$ele[0] \t");
//			fwrite($fh, "=\t");
//			fwrite($fh, "$ele[1]\r\n");
		}
		
//		fclose($fh);
		return $info;
	}
	
	protected function _processNotification($payment, $data)
	{
		$errors = array();
		if(JString::strtolower($this->getAppParam('merchant')) !== JString::strtolower($data['ap_merchant'])){
				$errors[] = XiText::_('COM_PAYPLANS_APP_ALERTPAY_INVALID_MERCHANT');
		}
			
		$stored_amount = number_format($payment->getAmount(),2);
        if ((float) $stored_amount !== (float) $data['ap_totalamount']) {
           	$errors[] = XiText::_('COM_PAYPLANS_APP_ALERTPAY_INVALID_PAYMENT_TOTAL');
        }
		$payment->set('amount', $data['ap_totalamount']);
		
		// check for recurring ang non-recurring
		if('success' !== JString::strtolower($data['ap_status']) && 'subscription-payment-success' !== JString::strtolower($data['ap_status'])){
			$errors[] = XiText::_('COM_PAYPLANS_APP_ALERTPAY_PAYMENT_FAIL');
		}
	
		$status   = XiStatus::PAYMENT_COMPLETE;
		if(!empty($errors)){
				$status = XiStatus::PAYMENT_HOLD;
				$message = XiText::_('COM_PAYPLANS_APP_ALERTPAY_LOGGER_ERROR_IN_PAYMENT');
				$log_id = PayplansHelperLogger::log(XiLogger::LEVEL_ERROR, $message, $payment, $errors);
		}
			
		$payment->set('txn_id', $data['ap_referencenumber'])
				->set('status', $status)
				->set('transaction',PayplansHelperParam::arrayToIni($data))
				->save();
					
		return true;
	}
	
	public function onPayplansPaymentNotify(PayplansPayment $payment, $data, $controller)
	{		
		if('item' === JString::transcode(JString::strtolower($data['ap_purchasetype']))){
			// its a normal payment			
			return $this->_processNotification(&$payment, $data);
			
		}
		
		if('subscription' === JString::transcode(JString::strtolower($data['ap_purchasetype']))){
			$order = $payment->getOrder(PAYPLANS_INSTANCE_REQUIRE);
			$txnId = $data['ap_referencenumber'];
															
			if ($data['ap_status'] == "Subscription-Payment-Success") 
			{
				if($data['ap_subscriptionpaymentnumber'] > 1)
				{
					// The payment is for a recurring subscription.
					// Check if TEST MODE is on/off and apply the proper logic. 
					// If Test Mode is ON then no transaction reference number will be returned.
					// A subscription reference number will be returned.
					// Process the order here by cross referencing the received data with your database. 														
					// Check that the total amount paid was the expected amount.
					// Check that the amount paid was for the correct service.
					// Check that the currency is correct.
					// ie: if ($totalAmountReceived == 50) ... etc ...
					// After verification, update your database accordingly.
					
					$newPayment = $this->_createPayment($order, $txnId, false);
					$newPayment->set('master', 0);
					return $this->_processNotification(&$newPayment, $data);					
				}
				
				$payment->set('txn_id', $data['ap_subscriptionreferencenumber'])
						->set('status', XiStatus::PAYMENT_RECURRING_SIGNUP)
						->save();
				
				// It is an initial payment for a subscription
				// Check if there was a trial period

				if ($data['ap_trialamount'] == 0){
					// It is a FREE trial and no transaction reference number is returned.
					// Check if TEST MODE is on/off and apply the proper logic.
					// A subscription reference number will be returned.
					// Process the order here by cross referencing the received data with your database.
					// After verification, update your database accordingly.
					$newPayment = $order->createPayment($this->getId(), true);
				}
				else{
					$newPayment = $this->_createPayment($order, $txnId, true);
				}
				
				$newPayment->set('master', 0);
				return $this->_processNotification(&$newPayment, $data);				
			}
			
			// payment is not succeeded 
			switch ($data['ap_status'])
			{
				case "Subscription-Expired":
					// Take appropriate when the subscription has reached its terms.
					$payment->set('status', XiStatus::PAYMENT_RECURRING_EOT);
					break;
				case "Subscription-Payment-Failed":
					// Take appropriate actions when a payment attempt has failed.
					$payment->set('status', XiStatus::PAYMENT_RECURRING_FAILED);
					break;
				case "Subscription-Payment-Rescheduled":
					// Take appropriate actions when a payment is rescheduled.
					$payment->set('status', XiStatus::PAYMENT_RECURRING_SIGNUP);
					break;
				case "Subscription-Canceled":
					// Take appropriate actions regarding a cancellation.
					$payment->set('status', XiStatus::PAYMENT_RECURRING_CANCEL);
					break;
				default:
					// Take a default action in the case that none of the above were handled.
					$payment->set('status', XiStatus::PAYMENT_RECURRING_FAILED);
					break;
			}

			$payment->save();
		}
	}
	
	
	function _createPayment($order, $txnId, $checkFirstPayment)
	{
		// if same transaction id is set in data
		// it mean multiple notification are sent from paypal for same payment
		$txnPayment = XiFactory::getInstance('payment', 'model')
									->loadRecords(array('txn_id' => $txnId));
									
		if(empty($txnPayment)){
			return $order->createPayment($this->getId(), $checkFirstPayment);
		}

		$tmpPayment = array_pop($txnPayment);
		return PayplansPayment::getInstance($tmpPayment->payment_id, null, $tmpPayment);		
	}
}
