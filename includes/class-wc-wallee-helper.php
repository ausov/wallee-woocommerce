<?php
if (!defined('ABSPATH')) {
	exit();
}
/**
 * wallee WooCommerce
 *
 * This WooCommerce plugin enables to process payments with wallee (https://www.wallee.com).
 *
 * @author customweb GmbH (http://www.customweb.com/)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache Software License (ASL 2.0)
 */
/**
 * WC_Wallee_Helper Class.
 */
class WC_Wallee_Helper {
	private static $instance;
	private $api_client;

	private function __construct(){}

	/**
	 * 
	 * @return WC_Wallee_Helper
	 */
	public static function instance(){
		if (self::$instance === null) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * 
	 * @throws Exception
	 * @return \Wallee\Sdk\ApiClient
	 */
	public function get_api_client(){
		if ($this->api_client === null) {
		    $user_id = get_option(WooCommerce_Wallee::CK_APP_USER_ID);
		    $user_key = get_option(WooCommerce_Wallee::CK_APP_USER_KEY);
			if (!empty($user_id) && !empty($user_key)) {
			    $this->api_client = new \Wallee\Sdk\ApiClient($user_id, $user_key);
				$this->api_client->setBasePath(rtrim($this->get_base_gateway_url(), '/') . '/api');
			}
			else {
				throw new Exception(__('The API access data is incomplete.', 'woo-wallee'));
			}
		}
		return $this->api_client;
	}
	
	
	public function reset_api_client(){
		$this->api_client = null;
	}

	/**
	 * Returns the base URL to the gateway.
	 *
	 * @return string
	 */
	public function get_base_gateway_url(){
		return get_option('wc_wallee_base_gateway_url', 'https://app-wallee.com/');
	}

	/**
	 * Returns the translation in the given language.
	 *
	 * @param array($language => $transaltion) $translated_string
	 * @param string $language
	 * @return string
	 */
	public function translate($translated_string, $language = null){
		if ($language == null) {
			$language = $this->get_cleaned_locale();
		}
		if (isset($translated_string[$language])) {
			return $translated_string[$language];
		}
		
		try {
			/* @var WC_Wallee_Provider_Language $language_provider */
		    $language_provider = WC_Wallee_Provider_Language::instance();
			$primary_language = $language_provider->find_primary($language);
			if ($primary_language && isset($translated_string[$primary_language->getIetfCode()])) {
				return $translated_string[$primary_language->getIetfCode()];
			}
		}
		catch (Exception $e) {
		}
		if (isset($translated_string['en-US'])) {
			return $translated_string['en-US'];
		}
		
		return null;
	}

	/**
	 * Returns the URL to a resource on Wallee in the given context (space, space view, language).
	 *
	 * @param string $path
	 * @param string $language
	 * @param int $spaceId
	 * @param int $spaceViewId
	 * @return string
	 */
	public function get_resource_url($base, $path, $language = null, $space_id = null, $space_view_id = null){
	    
	    if(empty($base)){
	        $url = $this->get_base_gateway_url();
	    }
	    else{
	        $url = $base;
	    }
	    $url = rtrim($url, '/');
	    
		if (!empty($language)) {
			$url .= '/' . str_replace('_', '-', $language);
		}
		
		if (!empty($space_id)) {
			$url .= '/s/' . $space_id;
		}
		
		if (!empty($space_view_id)) {
			$url .= '/' . $space_view_id;
		}
		
		$url .= '/resource/' . $path;
		return $url;
	}

	/**
	 * Returns the fraction digits of the given currency.
	 *
	 * @param string $currency_code
	 * @return number
	 */
	public function get_currency_fraction_digits($currency_code){
		/* @var WC_Wallee_Provider_Currency $currency_provider */
	    $currency_provider = WC_Wallee_Provider_Currency::instance();
		$currency = $currency_provider->find($currency_code);
		if ($currency) {
			return $currency->getFractionDigits();
		}
		else {
			return 2;
		}
	}

	/**
	 * Returns the total amount including tax of the given line items.
	 *
	 * @param \Wallee\Sdk\Model\LineItem[] $line_items
	 * @return float
	 */
	public function get_total_amount_including_tax(array $line_items){
		$sum = 0;
		foreach ($line_items as $line_item) {
			$sum += $line_item->getAmountIncludingTax();
		}
		return $sum;
	}

	/**
	 * Cleans the given line items by ensuring uniqueness and introducing adjustment line items if necessary.
	 *
	 * @param \Wallee\Sdk\Model\LineItemCreate[] $line_items
	 * @param float $expected_sum
	 * @param string $currency
	 * @return \Wallee\Sdk\Model\LineItemCreate[]
	 */
	public function cleanup_line_items(array $line_items, $expected_sum, $currency){
		$effective_sum = $this->round_amount($this->get_total_amount_including_tax($line_items), $currency);
		$diff = $this->round_amount($expected_sum, $currency) - $effective_sum;
		if ($diff != 0) {
		    $line_item = new \Wallee\Sdk\Model\LineItemCreate();
			$line_item->setAmountIncludingTax($this->round_amount($diff, $currency));
			$line_item->setName(__('Rounding Adjustment', 'woo-wallee'));
			$line_item->setQuantity(1);
			$line_item->setSku('rounding-adjustment');
			$line_item->setType($diff < 0 ? \Wallee\Sdk\Model\LineItemType::DISCOUNT : \Wallee\Sdk\Model\LineItemType::FEE);
			$line_item->setUniqueId('rounding-adjustment');
			$line_items[] = $line_item;
		}		
		return $this->ensure_unique_ids($line_items);
	}

	/**
	 * Ensures uniqueness of the line items.
	 *
	 * @param \Wallee\Sdk\Model\LineItemCreate[] $line_items
	 * @return \Wallee\Sdk\Model\LineItemCreate[]
	 */
	public function ensure_unique_ids(array $line_items){
		$unique_ids = array();
		foreach ($line_items as $line_item) {
			$unique_id = $line_item->getUniqueId();
			if (empty($unique_id)) {
				$unique_id = preg_replace("/[^a-z0-9]/", '', strtolower($line_item->getSku()));
			}
			if (empty($unique_id)) {
				throw new Exception("There is an invoice item without unique id.");
			}
			if (isset($unique_ids[$unique_id])) {
				$backup = $unique_id;
				$unique_id = $unique_id . '_' . $unique_ids[$unique_id];
				$unique_ids[$backup]++;
			}
			else {
				$unique_ids[$unique_id] = 1;
			}
			
			$line_item->setUniqueId($unique_id);
		}
		
		return $line_items;
	}

	/**
	 * Returns the amount of the line item's reductions.
	 *
	 * @param \Wallee\Sdk\Model\LineItem[] $lineItems
	 * @param \Wallee\Sdk\Model\LineItemReduction[] $reductions
	 * @return float
	 */
	public function get_reduction_amount(array $line_items, array $reductions){
		$line_item_map = array();
		foreach ($line_items as $line_item) {
			$line_item_map[$line_item->getUniqueId()] = $line_item;
		}
		
		$amount = 0;
		foreach ($reductions as $reduction) {
			$line_item = $line_item_map[$reduction->getLineItemUniqueId()];
			$amount += $line_item->getUnitPriceIncludingTax() * $reduction->getQuantityReduction();
			$amount += $reduction->getUnitPriceReduction() * ($line_item->getQuantity() - $reduction->getQuantityReduction());
		}
		
		return $amount;
	}

	private function round_amount($amount, $currency_code){
		return round($amount, $this->get_currency_fraction_digits($currency_code));
	}
	
	public function get_transaction_id_map_for_order(WC_Order $order){
	    $meta_data = $order->get_meta('_wallee_linked_ids', false);
	    if(empty($meta_data)){
	        //Old system
	        $space_id = $order->get_meta('_wallee_linked_space_id', true);
	        $transaction_id = $order->get_meta('_wallee_transaction_id', true);
	        return array('space_id' => $space_id, 'transaction_id' => $transaction_id);
	    }
	    
	    foreach($meta_data as $data){
	        $values = $data->value;
	        if(isset($values['sapce_id'])){
	            $values['space_id'] = $values['sapce_id']; 
	        }	        
	        $info = WC_Wallee_Entity_Transaction_Info::load_by_transaction($values['space_id'], $values['transaction_id']);
	        if($info->get_id() !== null && $info->get_state() != \Wallee\Sdk\Model\TransactionState::FAILED){
	            return $values;
	        }
	    }
	    return array();
	}

	public function get_current_cart_id(){
		$session_handler = WC()->session;
		$current_cart_id = $session_handler->get('wallee_current_cart_id', null);
		if ($current_cart_id === null) {
		    $current_cart_id = WC_Wallee_Unique_Id::get_uuid();
			$session_handler->set('wallee_current_cart_id', $current_cart_id);
		}
		return $current_cart_id;
	}

	public function destroy_current_cart_id(){
		$session_handler = WC()->session;
		$session_handler->set('wallee_current_cart_id', null);
	}

	/**
	 * Create a lock to prevent concurrency.
	 *
	 * @param int $lockType
	 */
	public function lock_by_transaction_id($space_id, $transaction_id){
		global $wpdb;
		
		$data_array = array(
			'locked_at' => date("Y-m-d H:i:s") 
		);
		$type_array = array(
			'%s' 
		);
		$wpdb->query(
				$wpdb->prepare(
						"SELECT * FROM " . $wpdb->prefix .
								 "woocommerce_wallee_transaction_info WHERE transaction_id = %d and space_id = %d FOR UPDATE", $transaction_id, 
								$space_id));
		
		$wpdb->update($wpdb->prefix . 'woocommerce_wallee_transaction_info', $data_array, 
				array(
					'transaction_id' => $transaction_id,
					'space_id' => $space_id 
				), $type_array, array(
					"%d",
					"%d" 
				));
	}
	
	public function get_cleaned_locale($useDefault = true){
		$languageString = get_locale();
		$languageString = str_replace('_','-', $languageString);
		$language = false;
		if(strlen($languageString) >= 5){
			//We assume it was a long ietf code, check if it exists
		    $language = WC_Wallee_Provider_Language::instance()->find($languageString);
			//Get first part of IETF and try to resolve as ISO
			if(strpos($languageString, '-') !== false){
				$languageString = substr($languageString, 0, strpos($languageString, '-'));
			}
		}
		if(!$language){
		    $language = WC_Wallee_Provider_Language::instance()->find_by_iso_code(strtolower($languageString));
		}
		//We did not find anything, so fall back
		if(!$language){
			if($useDefault){
				return 'en-US';
			}
			return null;
		}
		return $language->getIetfCode();
	}
	
	/**
	 * Try to parse the given date string. Returns a newly created DateTime object, or false if the string could not been parsed
	 * 
	 * @param String $date_string 
	 * @return DateTime | boolean
	 */
	public function try_to_parse_date($date_string){
	    $date_of_birth = false;
	    $custom_date_of_birth_format = apply_filters('wc_wallee_custom_date_of_birth_format', '');
	    if(!empty($custom_date_of_birth_format)){
	        $date_of_birth =  DateTime::createFromFormat($custom_date_of_birth_format, $date_string);
	    }
	    else{
	        $date_of_birth = DateTime::createFromFormat('d.m.Y', $date_string);
	        if(!$date_of_birth){
	            $date_of_birth = DateTime::createFromFormat('d-m-Y', $date_string);
	        }
	        if(!$date_of_birth){
	            $date_of_birth = DateTime::createFromFormat('m/d/Y', $date_string);
	        }
	        if(!$date_of_birth){
	            $date_of_birth = DateTime::createFromFormat('Y-m-d', $date_string);
	        }
	        if(!$date_of_birth){
	            $date_of_birth = DateTime::createFromFormat('Y/m/d', $date_string);
	        }
	    }
	    return $date_of_birth;
	}
	
	public function start_database_transaction(){
	    global $wpdb;
	    $wpdb->query("SET TRANSACTION ISOLATION LEVEL READ COMMITTED");
	    wc_transaction_query("start");
	    
	}
	
	public function commit_database_transaction(){
	    wc_transaction_query("commit");
	   
	}
	
	public function rollback_database_transaction(){
	    wc_transaction_query("rollback");
	}

}