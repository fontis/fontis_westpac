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

class Fontis_Westpac_Model_QuickGateway_Config_PaymentAction
{
	public function toOptionArray()
	{
		return array(
			array(
				'value' => 'authorize_capture',
				'label' => Mage::helper('westpac')->__('Authorise and Capture')
			),
			array(
				'value' => 'authorize',
				'label' => Mage::helper('westpac')->__('Authorise')
			)
		);
	}
}
