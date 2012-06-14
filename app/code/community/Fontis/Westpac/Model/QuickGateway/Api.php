<?php
/**
 * Fontis Westpac QuickGateway Extension
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@magentocommerce.com so they can send you a copy immediately.
 *
 * @category   Fontis
 * @package    Fontis_Westpac
 * @author     Chris Norton
 * @author     Tom Greenaway
 * @author     Peter Spiller
 * @copyright  Copyright (c) 2009 Fontis Pty. Ltd. (http://www.fontis.com.au)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

// Include Qvalent's PHP class for interacting with the API.
include "Api/Qvalent_CardsAPI.php";

class Fontis_Westpac_Model_QuickGateway_Api extends Mage_Payment_Model_Method_Cc
{
	protected $_code  = 'quickgateway_api';

	const STATUS_APPROVED = 'Approved';
	const TRANS_TYPE_CAPTURE = 1;
	const TRANS_TYPE_REFUND = 2;
	protected $_canRefund               = true;
	protected $_canCapture              = true;

	const PAYMENT_ACTION_AUTH_CAPTURE = 'authorize_capture';
	const PAYMENT_ACTION_AUTH = 'authorize';

    const URL = 'https://ccapi.client.qvalent.com/post/CreditCardAPIReceiver';

	public function getCertificate()
	{
		return Mage::getBaseDir() . Mage::getStoreConfig('payment/' . $this->_code . '/certificate');
	}
	
	public function getCaFile()
	{
		return dirname(__FILE__) . '/Api/cacerts.crt';
	}

	public function getMerchantID()
	{
		return Mage::getStoreConfig('payment/' . $this->_code . '/merchant_id');
	}

	public function getUsername()
	{
		return Mage::getStoreConfig('payment/' . $this->_code . '/username');
	}
	
	public function getPassword()
	{
		return Mage::getStoreConfig('payment/' . $this->_code . '/password');
	}
	
	public function getUrl()
	{
		return Mage::getStoreConfig('payment/' . $this->_code . '/url');
	}

	public function getDebug()
	{
		return Mage::getStoreConfig('payment/' . $this->_code . '/debug');
	}
	
	protected function _getLogDir()
	{
		return Mage::getBaseDir() . '/var/log/';
	}
	
	protected function _getLogPath()
	{
		return $this->getLogDir() . $this->_code . '.log';
	}
	
	protected function _log($text, $level = null)
	{
	    Mage::log($text, $level, $this->_code . '.log');
	}

	public function validate()
	{
		parent::validate();
		$paymentInfo = $this->getInfoInstance();
		if ($paymentInfo instanceof Mage_Sales_Model_Order_Payment) {
		    $currency_code = $paymentInfo->getOrder()->getBaseCurrencyCode();
		} else {
		    $currency_code = $paymentInfo->getQuote()->getBaseCurrencyCode();
		}
		return $this;
	}

	public function capture(Varien_Object $payment, $amount)
	{
		$payment->setCcTransId($payment->getOrder()->getIncrementId() . date("His"));

		$this->setAmount($amount)->setPayment($payment);
		
		$result = $this->_call(self::TRANS_TYPE_CAPTURE, $payment);
		
		if($result === false)
		{
			$e = $this->getError();
			if (isset($e['message'])) {
				$message = Mage::helper('westpac')->__('There has been an error processing your payment.') . ' ' . $e['message'];
			} else {
				$message = Mage::helper('westpac')->__('There has been an error processing your payment. Please try later or contact us for help.');
			}
			Mage::throwException($message);
		}
		else
		{
			if ($result['response.summaryCode'] === '0') {
				$payment->setStatus(self::STATUS_APPROVED)
					->setLastTransId($this->getTransactionId());
			}
			else
			{
                $this->_log($result);
				Mage::throwException("Error " . $result["response.responseCode"] . ": " . $result["response.text"]);
			}
		}
	}

	public function refund(Varien_Object $payment, $amount)
    {
        $this->setAmount($amount)->setPayment($payment);

        $result = $this->_call(self::TRANS_TYPE_REFUND, $payment);

        if($result === false)
        {
            $e = $this->getError();
            if (isset($e['message'])) {
                $message = Mage::helper('westpac')->__('There has been an error processing your payment.') . ' ' . $e['message'];
            } else {
                $message = Mage::helper('westpac')->__('There has been an error processing your payment. Please try later or contact us for help.');
            }
            Mage::throwException($message);
        }
        else
        {
            if ($result['response.summaryCode'] === '0') {
                $payment->setStatus(self::STATUS_APPROVED)
                        ->setLastTransId($this->getTransactionId());
            }
            else
            {
                Mage::throwException("Error " . $result["response.responseCode"] . ": " . $result["response.text"]);
            }
        }
    }


	protected function _call($type, Varien_Object $payment)
	{
		$cardsAPI = $this->_initialiseAPI();

		$params = array();
		$params["customer.username"] = $this->getUsername();
		$params["customer.password"] = $this->getPassword();
		$params["customer.merchant"] = $this->getMerchantID();
		$params["card.expiryYear"] = substr($payment->getCcExpYear(), 2, 2);
		$params["card.expiryMonth"] = str_pad($payment->getCcExpMonth(), 2, '0', STR_PAD_LEFT);
		$params["card.currency"] = $payment->getOrder()->getBaseCurrencyCode();
		$params["order.amount"] = $this->getAmount() * 100;
		$params["order.ECI"] = "SSL";

        if($type == self::TRANS_TYPE_CAPTURE) {
		    $params["order.type"] = "capture";
		    $params["customer.orderNumber"] = $payment->getCcTransId();
		    $params["card.PAN"] = $payment->getCcNumber();
		    $params["card.CVN"] = $payment->getCcCid();
		}
		elseif($type == self::TRANS_TYPE_REFUND) {
            $params["order.type"] = "refund";
            $params["customer.orderNumber"] = $payment->getCcTransId() . "R";
            $params["customer.originalOrderNumber"] = $payment->getCcTransId();
		}
		
		try
        {
            $result = $cardsAPI->processCreditCard($params);
            $orderNumber = $params['customer.orderNumber'];
            $this->_recordResults($payment, $result, $orderNumber);

		    return $result;
        }
	    catch(Exception $e)
        {
	        $this->_log($e->getMessage());
	    }
	}
	
	protected function _initialiseAPI()
	{
	    $cardsAPI = new Qvalent_CardsAPI();

		$this->_log("Qvalent_CardsAPI created");

		$init = array(
		            'certificateFile' => $this->getCertificate(),
			        'caFile' => $this->getCaFile(),
	  		        'logDirectory' => $this->_getLogDir(),
	  		        'url' => $this->getUrl(),	  		        
	  		    );

		$this->_log($init);

		$cardsAPI->initialise($init);

		$this->_log("Qvalent_CardsAPI initialised");
		
		return $cardsAPI;
	}
	
	protected function _recordResults(Varien_Object $payment, $result, $orderNumber)
	{
	    try {
	        $db = Mage::getModel('Core/Mysql4_Config')->getReadConnection();

            $values = array();
            
            $values['order_id'] = $payment->getOrder()->getId();
            $values['increment_id'] = $payment->getOrder()->getIncrementId();
            $values['orderNumber'] = $orderNumber;
            
            if(isset($result['response.receiptNo'])) {
                $values['receiptNo'] = $result['response.receiptNo'];
            } elseif(isset($result['response.referenceNo'])) {
                $values['receiptNo'] = $result['response.referenceNo'];
            } else {
                $values['receiptNo'] = 'NULL';
            }
            
            if(isset($result['response.summaryCode'])) {
                $values['summaryCode'] = $result['response.summaryCode'];
            } else {
                $values['summaryCode'] = 'NULL';
            }
            
            if(isset($result['response.responseCode'])) {
                $values['responseCode'] = $result['response.responseCode'];
            } else {
                $values['responseCode'] = 'NULL';
            }
            
            if(isset($result['response.text'])) {
                $values['text'] = $result['response.text'];
            } else {
                $values['text'] = 'NULL';
            }
            
            if(isset($result['response.settlementDate'])) {
                $values['settlementDate'] = $result['response.settlementDate'];
            } else {
                $values['settlementDate'] = 'NULL';
            }
            
            if(isset($result['response.cardSchemeName'])) {
                $values['cardSchemeName'] = $result['response.cardSchemeName'];
            } else {
                $values['cardSchemeName'] = 'NULL';
            }
            
            if(isset($result['response.creditGroup'])) {
                $values['creditGroup'] = $result['response.creditGroup'];
            } else {
                $values['creditGroup'] = 'NULL';
            }
            
            if(isset($result['response.cvnResponse'])) {
                $values['cvnResponse'] = $result['response.cvnResponse'];
            } else {
                $values['cvnResponse'] = 'NULL';
            }
            
            if(isset($result['response.transactionDate'])) {
                $values['transactionDate'] = $result['response.transactionDate'];
            } else {
                $values['transactionDate'] = '';
            }
            
            if(isset($result['response.authId'])) {
                $values['authId'] = $result['response.authId'];
            } else {
                $values['authId'] = 'NULL';
            }

	        $db->query("INSERT INTO `westpac_quickgateway` 
	            (`order_id`, `increment_id`, `orderNumber`, `receiptNo`, `summaryCode`, `responseCode`, `text`, `settlementDate`, `cardSchemeName`, `creditGroup`, `cvnResponse`, `transactionDate`, `authId`)
	            VALUES ('" .
	            $values['order_id'] . "', '".
	            $values['increment_id'] . "', '".
	            $values['orderNumber'] . "', '".
	            $values['receiptNo'] . "', '".
	            $values['summaryCode'] . "', '".
	            $values['responseCode'] . "', '".
	            $values['text'] . "', '".
	            $values['settlementDate'] . "', '".
	            $values['cardSchemeName'] . "', '".
	            $values['creditGroup'] . "', '".
	            $values['cvnResponse'] . "', '".
	            $values['transactionDate'] . "', '".
	            $values['authId'] . "');");
	        
	     } catch(Exception $e) {
	        $this->_log($e->getMessage());
	     }
	}
}
