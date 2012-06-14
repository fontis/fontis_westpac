<?php
/**
 * Fontis Westpac PayWay Extension
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
 * @copyright  Copyright (c) 2008 Fontis Pty. Ltd. (http://www.fontis.com.au)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

// Include Qvalent's PHP class for interacting with the API.
include "Api/Qvalent_PayWayAPI.php";

class Fontis_Westpac_Model_PayWay_Api extends Mage_Payment_Model_Method_Cc
{
	protected $_code  = 'payway_api';

	const STATUS_APPROVED = 'Approved';
	protected $_canRefund               = true;
	protected $_canCapture              = true;

	const PAYMENT_ACTION_AUTH_CAPTURE = 'authorize_capture';
	const PAYMENT_ACTION_AUTH = 'authorize';

	const URL = 'https://ccapi.client.qvalent.com/payway/ccapi';
    
	public function getCertificate()
	{
		return Mage::getBaseDir() . '/' . Mage::getStoreConfig('payment/payway_api/certificate');
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

	public function getDebug()
	{
		return Mage::getStoreConfig('payment/' . $this->_code . '/debug');
	}
	
	public function getLogDir()
	{
		return Mage::getBaseDir() . '/var/log/';
	}
	
	public function getLogPath()
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
		
		$result = $this->_call($payment);
		
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
                Mage::log($result);
                if(isset($result["response.responseCode"]) && isset($result["response.text"])) {
    				Mage::throwException("Error " . $result["response.responseCode"] . ": " . $result["response.text"]);
    			} elseif(isset($result["response.text"])) {
    			    Mage::throwException("Error: " . $result["response.text"]);
    			} else {
    			    Mage::throwException("There has been an error processing your payment. Please try later or contact us for help.");
    			}
			}
		}
	}

	protected function _call(Varien_Object $payment)
	{

		if($this->getDebug())
		{
			$writer = new Zend_Log_Writer_Stream($this->getLogPath());
			$logger = new Zend_Log($writer);
			$logger->info("entering _call()");
		}
		
		$paywayAPI = new Qvalent_PayWayAPI();

		if($this->getDebug()) { $logger->info("Qvalent_PayWayAPI created"); }

		$init = "certificateFile=" . $this->getCertificate() . "&" .
		        "caFile=" . $this->getCaFile() . "&" .
		        "logDirectory=" . $this->getLogDir();

		if($this->getDebug()) { $logger->info($init); }

		$paywayAPI->initialise($init);

		if($this->getDebug()) { $logger->info("Qvalent_PayWayAPI initialised"); }

		$orderNumber = $payment->getOrder()->getStoreId() . str_pad($payment->getOrder()->getQuoteId(), 9, '0', STR_PAD_LEFT);

		if($this->getDebug()) { 
			$logger->info(print_r($payment->getOrder()->getData(), true));
		}

		$params = array();
		//$params["url"] = self::URL;
		$params["order.type"] = "capture";
		$params["customer.username"] = $this->getUsername();
		$params["customer.password"] = $this->getPassword();
		$params["customer.merchant"] = $this->getMerchantID();
		$params["customer.orderNumber"] = $payment->getCcTransId();
		$params["card.PAN"] = $payment->getCcNumber();
		$params["card.CVN"] = $payment->getCcCid();
		$params["card.expiryYear"] = substr($payment->getCcExpYear(), 2, 2);
		$params["card.expiryMonth"] = str_pad($payment->getCcExpMonth(), 2, '0', STR_PAD_LEFT);
		$params["card.currency"] = $payment->getOrder()->getBaseCurrencyCode();
		$params["order.amount"] = $this->getAmount() * 100;
		$params["order.ECI"] = "SSL";
		
		if($this->getDebug()) { $logger->info("Params: " . print_r($params, true)); }
		
		$requestText = $paywayAPI->formatRequestParameters($params);
		$responseText = $paywayAPI->processCreditCard($requestText);
		$result = $paywayAPI->parseResponseParameters($responseText);

		if($this->getDebug()) { $logger->info("Result: " . print_r($result, true)); }

		return $result;
	}

	public function refund(Varien_Object $payment, $amount)
    {

        if($this->getDebug())
        {
            $writer = new Zend_Log_Writer_Stream($this->getLogPath());
            $logger = new Zend_Log($writer);
            $logger->info("entering _refund()");
        }

        $this->setAmount($amount)->setPayment($payment);

        $result = $this->_refundcall($payment);

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
                if(isset($result["response.responseCode"]) && isset($result["response.text"])) {
    				Mage::throwException("Error " . $result["response.responseCode"] . ": " . $result["response.text"]);
    			} elseif(isset($result["response.text"])) {
    			    Mage::throwException("Error: " . $result["response.text"]);
    			} else {
    			    Mage::throwException("There has been an error processing your payment. Please try later or contact us for help.");
    			}
            }
        }
    }

    protected function _refundcall(Varien_Object $payment)
    {
        if($this->getDebug())
        {
            $writer = new Zend_Log_Writer_Stream($this->getLogPath());
            $logger = new Zend_Log($writer);
            $logger->info("entering _refundcall()");
        }

        $paywayAPI = new Qvalent_PayWayAPI();

        if($this->getDebug()) { $logger->info("Qvalent_PayWayAPI created"); }

        $init = "certificateFile=" . $this->getCertificate() . "&" .
                "caFile=" . $this->getCaFile() . "&" .
                "logDirectory=" . $this->getLogDir();

        if($this->getDebug()) { $logger->info($init); }

        $paywayAPI->initialise($init);

        if($this->getDebug()) { $logger->info("Qvalent_PayWayAPI initialised"); }

        $orderNumber = $payment->getOrder()->getStoreId() . str_pad($payment->getOrder()->getQuoteId(), 9, '0', STR_PAD_LEFT);

        if($this->getDebug()) {
            $logger->info(print_r($payment->getOrder()->getData(), true));
        }

        $params = array();
        $params["order.type"] = "refund";
        $params["customer.username"] = $this->getUsername();
        $params["customer.password"] = $this->getPassword();
        $params["customer.merchant"] = $this->getMerchantID();
        $params["card.expiryYear"] = substr($payment->getCcExpYear(), 2, 2);
        $params["card.expiryMonth"] = str_pad($payment->getCcExpMonth(), 2, '0', STR_PAD_LEFT);
        $params["card.currency"] = $payment->getOrder()->getBaseCurrencyCode();
        $params["order.amount"] = $this->getAmount() * 100;
        $params["order.ECI"] = "SSL";

        $params["customer.orderNumber"] = $payment->getCcTransId() . "R";
        $params["customer.originalOrderNumber"] = $payment->getCcTransId();

        if($this->getDebug()) { $logger->info("Params: " . print_r($params, true)); }

        $requestText = $paywayAPI->formatRequestParameters($params);
        $responseText = $paywayAPI->processCreditCard($requestText);
        $result = $paywayAPI->parseResponseParameters($responseText);

        if($this->getDebug()) { $logger->info("Result: " . print_r($result, true)); }

        return $result;
    }
}
