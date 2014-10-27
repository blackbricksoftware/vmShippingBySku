<?php defined ('_JEXEC') or die('Direct access not allowed.');
//error_reporting(E_ALL);ini_set('display_errors',1);
 /**
 * @name vmShippingBySku
 * @description Allowing shipping rates to be base on an items SKU number. Based on weight_countries.php by Valerie Isaksen (VirtueMart Team)
 * @author:  David Hayes, david@blackbricksoftware.com
 * @company: Black Brick Software LLC, http://blackbricksoftware.com
 * @date: 8/13/12
 * @copyright	Copyright (C) 2012 Black Brick Software LLC. All rights reserved.
 * @license		GNU General Public License version 2 or later.
 * @package		Joomla.Plugin
 * @version		1.0.0
*/

if (!class_exists ('vmPSPlugin')) require(JPATH_VM_PLUGINS.DS.'vmpsplugin.php');

class plgVmshipmentVmShippingBySku extends vmPSPlugin {

	protected $shipping_cost = 0;

	// construct this!
	public function __construct(&$subject,$config) {

		parent::__construct($subject, $config);

		$this->_loggable = true;

		// load up the language
		JFactory::getLanguage()->load('plg_vmshipment_vmShippingBySku',JPATH_ADMINISTRATOR);

		// setup table fields
		$this->tableFields = array_keys($this->getTableSQLFields());

		// setup parameter fields
		$varsToPush = $this->getVarsToPush();
		//echo "<pre>"; print_r($varsToPush); echo "</pre>"; exit;
		$this->setConfigParameterable($this->_configTableFieldName,$varsToPush);

	}

	/*
	 * Database
	 */

	// UPDATE: Create DB table if necessary
	public function getVmPluginCreateTableSQL () {
		return $this->createTableSQL('VM Shipping By SKU');
	}

	// UDPATE: List of database fields
	function getTableSQLFields () {

		$SQLfields = array(
			'id'                           => 'INT(1) UNSIGNED NOT NULL AUTO_INCREMENT',
			'virtuemart_order_id'          => 'INT(11) UNSIGNED',
			'order_number'                 => 'CHAR(32)',
			'virtuemart_shipmentmethod_id' => 'MEDIUMINT(1) UNSIGNED',
			'shipment_name'                => 'VARCHAR(5000)',
			'order_weight'                 => 'DECIMAL(10,4)',
			'shipment_weight_unit'         => 'CHAR(3) DEFAULT \'LB\'',
			'shipment_cost'                => 'DECIMAL(10,2)',
			'tax_id'                       => 'SMALLINT(1)',
			'rules'						   => 'TEXT',
		);
		return $SQLfields;
	}

	/*
	 * Hooks
	 */

	 // UPDATE
	function plgVmConfirmedOrder (VirtueMartCart $cart, $order) {

		if (!($method = $this->getVmPluginMethod ($order['details']['BT']->virtuemart_shipmentmethod_id))) {
			return NULL; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement ($method->shipment_element)) {
			return FALSE;
		}
		$values['virtuemart_order_id'] = $order['details']['BT']->virtuemart_order_id;
		$values['order_number'] = $order['details']['BT']->order_number;
		$values['virtuemart_shipmentmethod_id'] = $order['details']['BT']->virtuemart_shipmentmethod_id;
		$values['shipment_name'] = $this->renderPluginName($method);
		$values['order_weight'] = $this->getOrderWeight($cart,$method->weight_unit);
		$values['shipment_weight_unit'] = $method->weight_unit;
		$values['shipment_cost'] = $this->shipping_cost;
		$values['tax_id'] = $method->tax_id;
		$values['rules'] = $method->sku_rules;
		$this->storePSPluginInternalData ($values);

		return TRUE;
	}

	// UDPATE
	public function plgVmOnShowOrderBEShipment ($virtuemart_order_id, $virtuemart_shipmentmethod_id) {

		if (!($this->selectedThisByMethodId ($virtuemart_shipmentmethod_id))) {
			return NULL;
		}
		$html = $this->getOrderShipmentHtml ($virtuemart_order_id);
		return $html;
	}

	// UDPATE
	function getOrderShipmentHtml ($virtuemart_order_id) {

		$db = JFactory::getDBO ();
		$q = 'SELECT * FROM `' . $this->_tablename . '` '
			. 'WHERE `virtuemart_order_id` = ' . $virtuemart_order_id;
		$db->setQuery ($q);
		if (!($shipinfo = $db->loadObject ())) {
			vmWarn (500, $q . " " . $db->getErrorMsg ());
			return '';
		}

		if (!class_exists ('CurrencyDisplay')) {
			require(JPATH_VM_ADMINISTRATOR . DS . 'helpers' . DS . 'currencydisplay.php');
		}

		$currency = CurrencyDisplay::getInstance ();
		$tax = ShopFunctions::getTaxByID ($shipinfo->tax_id);
		$taxDisplay = is_array ($tax) ? $tax['calc_value'] . ' ' . $tax['calc_value_mathop'] : $shipinfo->tax_id;
		$taxDisplay = ($taxDisplay == -1) ? JText::_ ('COM_VIRTUEMART_PRODUCT_TAX_NONE') : $taxDisplay;

		$html = '<table class="adminlist">' . "\n";
		$html .= $this->getHtmlHeaderBE ();
		$html .= $this->getHtmlRowBE ('VM_SHIPPING_BY_SKU_SHIPPING_NAME', $shipinfo->shipment_name);
		$html .= $this->getHtmlRowBE ('VM_SHIPPING_BY_SKU_WEIGHT', $shipinfo->order_weight . ' ' . ShopFunctions::renderWeightUnit ($shipinfo->shipment_weight_unit));
		$html .= $this->getHtmlRowBE ('VM_SHIPPING_BY_SKU_COST', $currency->priceDisplay ($shipinfo->shipment_cost));
		$html .= $this->getHtmlRowBE ('VM_SHIPPING_BY_SKU_TAX', $taxDisplay);
		$html .= $this->getHtmlRowBE ('VM_SHIPPING_BY_SKU_RULES_USED', $shipinfo->rules);
		$html .= '</table>' . "\n";

		return $html;
	}

	// UPDATE
	function getCosts(VirtueMartCart $cart, $method, $cart_prices) {

		if ($method->free_shipment && $cart_prices['salesPrice'] >= $method->free_shipment) {

			$this->shipping_cost = 0;

		} else {

			// parse rules, in csv format, into a multidimensional array
			$rules = $this->_parseCSV($method->sku_rules);
			// run through the rules and create an array that details each rules pricing structure
			$rule_pricing = $this->_priceRules($rules);
			// run through the rules and match them to products to calculate up how many fit that rule
			$rule_matches = $this->_matchRules($cart,$rules);
			// run through the rules again and this time calculate price
			$this->shipping_cost = $this->_calcPrice($rule_matches,$rule_pricing);

			/*
			echo 'rules';
			echo "<pre>"; print_r($rules); echo "</pre>";

			echo 'rule_pricing';
			echo "<pre>"; print_r($rule_pricing); echo "</pre>";

			echo 'rule_matches';
			echo "<pre>"; print_r($rule_matches); echo "</pre>";

			echo 'cart';
			echo "<pre>"; print_r($cart); echo "</pre>";

			echo 'cart_prices';
			echo "<pre>"; print_r($cart_prices); echo "</pre>";

			echo "price: $price";

			exit;
			*/

		}

		return $this->shipping_cost;

	}

	// UDPATE
	function plgVmOnStoreInstallShipmentPluginTable ($jplugin_id) {

		return $this->onStoreInstallPluginTable ($jplugin_id);
	}

	// UPDATE
	public function plgVmOnSelectCheckShipment (VirtueMartCart &$cart) {

		return $this->OnSelectCheck ($cart);
	}

	// UPDATE
	public function plgVmDisplayListFEShipment (VirtueMartCart $cart, $selected = 0, &$htmlIn) {

		return $this->displayListFE ($cart, $selected, $htmlIn);
	}

	// UPDATE
	public function plgVmonSelectedCalculatePriceShipment (VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name) {

		return $this->onSelectedCalculatePrice ($cart, $cart_prices, $cart_prices_name);
	}

	// UPDATE
	function plgVmOnCheckAutomaticSelectedShipment (VirtueMartCart $cart, array $cart_prices = array(), &$shipCounter) {

		if ($shipCounter > 1) {
			return 0;
		}
		return $this->onCheckAutomaticSelected ($cart, $cart_prices, $shipCounter);
	}

	//UPDATE
	function plgVmonShowOrderPrint ($order_number, $method_id) {

		return $this->onShowOrderPrint ($order_number, $method_id);
	}

	// UDPATE
	function plgVmDeclarePluginParamsShipment ($name, $id, &$data) {
		return $this->declarePluginParams ('shipment', $name, $id, $data);
	}

	// UPDATE
	function plgVmSetOnTablePluginParamsShipment ($name, $id, &$table) {

		return $this->setOnTablePluginParams ($name, $id, $table);
	}

	/*
	 * Other Functions
	 */

	// Convert parameter data into the correct format
	public function convert (&$method) {

		//$method->sku_rules = 			(array)_parseCSV($method->sku_rules);
		$method->zip_start = 			(int)$method->zip_start;
		$method->zip_stop = 			(int)$method->zip_stop;
		//$method->weight_start = 		(float) $method->weight_start;
		//$method->weight_stop = 		(float) $method->weight_stop;
		$method->nbproducts_start = 	(int)$method->nbproducts_start;
		$method->nbproducts_stop = 		(int)$method->nbproducts_stop;
		$method->orderamount_start = 	(float)$method->orderamount_start;
		$method->orderamount_stop = 	(float)$method->orderamount_stop;

	}

	// determine whether we can use these settings for this shipment
	public function checkConditions($cart, $method, $cart_prices) {

		$this->convert($method);

		$orderWeight = $this->getOrderWeight($cart,$method->weight_unit);
		$weight_cond = $this->_weightCond ($orderWeight, $method);
		$nbproducts_cond = $this->_nbproductsCond ($cart, $method);
		$orderamount_cond = $this->_orderamountCond ($cart_prices, $method);


		// probably did not gave his BT:ST address
		$address = (($cart->ST == 0) ? $cart->BT : $cart->ST);
		if (!is_array($address)) {
			$address = array();
			$address['zip'] = 0;
			$address['virtuemart_country_id'] = 0;
		}
		if (!isset($address['zip'])) {
			$address['zip'] = 0;
		}
		$zip_cond = $this->_zipCond ($address['zip'], $method);

		$countries = array();
		if (!empty($method->countries)) $countries = (array)$method->countries;
		$states = array();
		if (!empty($method->states)) $states = (array)$method->states;
		if (!isset($address['virtuemart_country_id'])) $address['virtuemart_country_id'] = 0;
		if (!isset($address['virtuemart_state_id'])) $address['virtuemart_state_id'] = 0;
		$country_state_cond = $this->_countryStateCond($countries,$states,$address['virtuemart_country_id'],$address['virtuemart_state_id']);

		return	(
					$country_state_cond &&
					$weight_cond &&
					$zip_cond &&
					$nbproducts_cond &&
					$orderamount_cond
				);

	}

	/*
	 * Functions to parse rules and calculate prices
	 */

	// convert csv into a multidimentional array
	private function _parseCSV($text) {
		$rows = str_getcsv($text,"\n");
		$csv = array();
		if (count($rows)>0) foreach ($rows as $row) $csv[] = str_getcsv($row);
		return $csv;
	}

	/*
		0	=> sku begins
		1	=> sku ends
		2	=> sku matches
		3	=> pricing in format {min qty}-{max qty}/{price} (multiple seperated by spaces, if no max, inf used)
	*/
	// determin each rule's pricing structure
	private function _priceRules($rules) {
		$pricing = array();
		//run throgh all the rules
		if (count($rules)>0) {
			foreach ($rules as $rule_num => $rule) {
				//continue if it is a blank rule or doesn't have the right number of columns
				if (count($rule)<4) continue;
				// split up the different prices
				$prices = array_filter(preg_split('/\s+/',$rule[3]));
				// go through the prices
				if (count($prices)>0) {
					// seperate prices by quantity
					foreach ($prices as $key => $price) {
						list($qtys,$pricing[$rule_num][$key]['price']) = explode('/',$price);
/*
						$qtys can been in the formats: 1-2 (1-2) (1-2)+
						that would correspond to qty 1-2 is charged each, (1-2) all qtys between 1 and 2 charged same; all qtys between 1 and two charge same, recurring
*/
						// do we have parens? if so, we have some sort of block shipping
						if ($pricing[$rule_num][$key]['block']=($qtys[0]=='(')) {
							// check if this is a recurring block
							$pricing[$rule_num][$key]['recurring'] = ($qtys[strlen($qtys)-1]=='+');
							// replace all the special chars so we just have a number-number
							$qtys = preg_replace('/[^\d\-]+/','',$qtys);
						}
						//
						list($pricing[$rule_num][$key]['min'],$pricing[$rule_num][$key]['max']) = explode('-',$qtys);
						// cast prices to correct format
						$pricing[$rule_num][$key]['price'] = (float)$pricing[$rule_num][$key]['price'];
						$pricing[$rule_num][$key]['min'] = (int)$pricing[$rule_num][$key]['min'];
						$pricing[$rule_num][$key]['max'] = ($pricing[$rule_num][$key]['max']=='inf') ? 'inf' : (int)$pricing[$rule_num][$key]['max'];
					}
				}
			}
		}
		return $pricing;
	}
	// match products and ruls together and count up how many products match each rule
	private function _matchRules (VirtueMartCart $cart, $rules) {
		$rule_quantities = array();
		// run through each of the products
		if (count($cart->products)>0) {
			foreach ($cart->products as $product) {
				// run through each of the rules and test it against the product
				if (count($rules)>0) {
					foreach ($rules as $rule_num => $rule) {
						//continue if it is a blank rule or doesn't have the right number of columns
						if (count($rule)<4) continue;
						// set our quantity to zero if it isnt set yet
						if (!isset($rule_quantities[$rule_num])) $rule_quantities[$rule_num] = 0;
						// does the product match our conditions?
						if (
								( (strlen($rule[0])<=0) || preg_match('/^'.preg_quote($rule[0],'/').'/',$product->product_sku) ) &&
								( (strlen($rule[1])<=0) || preg_match('/'.preg_quote($rule[1],'/').'$/',$product->product_sku) ) &&
								( (strlen($rule[2])<=0) || preg_match($rule[2],$product->product_sku) )
						) {
							// if it does add its quantity to that rules products
							$rule_quantities[$rule_num] += $product->quantity;
							// break out of the rules loop so that the products quantity doesn't get added to more than one rule
							break;
						}
					}
				}
			}
		}
		return $rule_quantities;
	}
	// calculate price from rules
	private function _calcPrice($rule_matches,$rule_pricing) {
		$price = 0;
		if (count($rule_matches)>0) {
			foreach ($rule_matches as $rule_num => $rule_qty) {
				if ($rule_qty<1) continue;
				for ($i=1;$i<=$rule_qty;$i++) {
					if (isset($rule_pricing[$rule_num])&&count($rule_pricing[$rule_num])>0) {
						// find the first and last key is the pricing array
						$first_price_num = reset(array_keys($rule_pricing[$rule_num]));
						$last_price_num = end(array_keys($rule_pricing[$rule_num]));
						//echo "<h1>i $i</h1>";
						foreach ($rule_pricing[$rule_num] as $price_num => $pricing) {
							// this catches when we are charing per item or have hit the beginning of a block charge
							if (
								// if we have block pricing, only tack on price when we read min qty
								($pricing['block'] && $i==$pricing['min']) ||
								// otherwise tack on price for every item
								(!$pricing['block'] && $i>=$pricing['min'] && ($i<=$pricing['max']||$pricing['max']=='inf'))
							) { //echo "<h2>regular or begin block</h2><pre>"; print_r($pricing); echo "</pre>";
								// tack on our price to the total
								$price += $pricing['price'];
								//since we found something; lets store the current pricing for future use and leave!
								$prev_pricing = $pricing;
								break;
							// this catches when we have hit a spot where we should charge again with a block charge where there are rules for quantities greater that the block charge
							} elseif (
								// if we have made it somewhere but we dont fit this pricing and the previous pricing is recurring
								($price_num!==$first_price_num && $prev_pricing['recurring'] && $i>$prev_pricing['max'] && $i<$pricing['min']) &&
								// check if we have met the condition to charge again
								(
									($i-$prev_pricing['max']) % ($prev_pricing['max']-$prev_pricing['min']+1) == 1
								)
							) {  //echo "<h2>middle recurring block</h2><pre>"; print_r($prev_pricing); echo "</pre>";
								$price += $prev_pricing['price'];
								break;
							// this catches when we have hit the last rule and have a quantity greater that it and it is recurring
							} elseif (
								// we hit the last block, and weren't caught any where else; luckily the last block is recurring
								($price_num===$last_price_num && $pricing['recurring'] && $i>$pricing['max']) &&
								// check if we have met the condition to charge again
								(
									($i-$pricing['max']) % ($pricing['max']-$pricing['min']+1) == 1
								)
							) {  //echo "<h2>end recurring block</h2><pre>"; print_r($pricing); echo "</pre>";
								$price += $pricing['price'];
								break;
							}
						}
					}
				}
			}
		}
		return $price;
	}

	/*
	 * Conditions for if this shipping method is applicable
	 */

	// check if order fits total weight condition
	private function _weightCond ($orderWeight, $method) {

		$weight_cond = (
						($orderWeight >= $method->weight_start && $orderWeight <= $method->weight_stop) ||
						($method->weight_start <= $orderWeight && $method->weight_stop === '')
					);

		return $weight_cond;
	}

	// check if order fits the number of items condition
	private function _nbproductsCond ($cart, $method) {

		$nbproducts = 0;
		foreach ($cart->products as $product) $nbproducts += $product->quantity;

		if (!isset($method->nbproducts_start) and !isset($method->nbproducts_stop)) return true;

		if ($nbproducts) {
			$nbproducts_cond = (
								($nbproducts >= $method->nbproducts_start && $nbproducts <= $method->nbproducts_stop) ||
								($method->nbproducts_start <= $nbproducts && ($method->nbproducts_stop == 0))
							);
		} else {
			$nbproducts_cond = true;
		}

		return $nbproducts_cond;
	}

	// check if order fits the order amount conditions
	private function _orderamountCond ($cart_prices, $method) {

		if (!isset($method->orderamount_start) && !isset($method->orderamount_stop)) return true;

		if ($cart_prices['salesPrice']) {
			$orderamount_cond = (
									($cart_prices['salesPrice'] >= $method->orderamount_start && $cart_prices['salesPrice'] <= $method->orderamount_stop) ||
									($method->orderamount_start <= $cart_prices['salesPrice'] && ($method->orderamount_stop == 0))
								);
		} else {
			$orderamount_cond = true;
		}

		return $orderamount_cond;
	}

	// check if order fits zip conditions
	private function _zipCond ($zip, $method) {

		$zip = (int)$zip;
		$zip_cond = true;
		if (!empty($zip) ) {
			if(!empty($method->zip_start) and !empty( $method->zip_stop)){
				$zip_cond = (($zip >= $method->zip_start AND $zip <= $method->zip_stop));
			} else if (!empty($method->zip_start)) {
				$zip_cond = ($zip >= $method->zip_start);
			} else if (!empty($method->zip_stop)) {
				$zip_cond = ($zip <= $method->zip_stop);
			}
		} else if(!empty($method->zip_start) or !empty( $method->zip_stop)){
			$zip_cond = false;
		}

		return $zip_cond;
	}

	// check if order fits country conditions
	private function _countryStateCond($countries,$states,$country,$state) {

		// handle when we have a state (maybe a country)
		if ($state) {
			if ($country) {
				// handle when state and country specified
				$country_state_cond =	(
											(
												// check if our country is is allowed countries
												in_array($country, $countries) ||
												// or if we havent specified any countries, just allow it
												count($countries)<=0
											) &&
											(
												// check if our country is is allowed states
												in_array($state, $states) ||
												// or if we havent specified any state, just allow it
												count($states)<=0
											)
										);
			// handle when just state specified
			} else {
				$country_state_cond = (
										// check if our country is is allowed states
										in_array($state, $states) ||
										// or if we havent specified any state, just allow it
										count($states)<=0
									);
			}
		// handle when we might just have a country specified
		} else {
			$country_state_cond = (
									// check if our country is is allowed countries
									in_array($country, $countries) ||
									// or if we havent specified any countries, just allow it
									count($countries)<=0
								);
		}
		return $country_state_cond;
	}
}




