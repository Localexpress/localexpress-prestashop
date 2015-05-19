<?php
if (!defined('_PS_VERSION_'))
   exit;

class Localexpress extends CarrierModule
{
   const PREFIX = 'localexpress_';
   public $id_carrier;
   
   protected $_hooks = array(
//     'header',
     'actionCarrierUpdate',
//     'displayOrderConfirmation',
//     'displayAdminOrder',
//     'displayBeforeCarrier',
   );
   
   protected $_carriers = array(
     'Localexpress' => 'loc',
   );
	public function __construct()
	{
		$this->name = 'localexpress';
		$this->tab = 'shipping_logistics';
		$this->version = '1.0.0';
		$this->author = 'Boxture';
		$this->need_instance = 0;

		$this->bootstrap = true;
		parent::__construct();

		$this->displayName = $this->l('Localexpress as your carrier');
		$this->description = $this->l('You can quote and check availablity for localexpress');
		$this->ps_versions_compliancy = array('min' => '1.6', 'max' => _PS_VERSION_);

		$this->confirmUninstall = $this->l('Are you sure you want to uninstall?');

	}

   public function install()
   {
      if (parent::install()) {
         foreach ($this->_hooks as $hook) {
            if (!$this->registerHook($hook)) {
               return false;
            }
         }

//         if (!$this->installDB()) {
//            return false;
//         }

         if (!$this->createCarriers()) {
            return false;
         }

         return true;
      }

      return false;
   }

   protected function deleteCarriers()
   {
      foreach ($this->_carriers as $value) {
         $tmp_carrier_id = Configuration::get(self::PREFIX . $value);
         $carrier = new Carrier($tmp_carrier_id);
         $carrier->delete();
      }
      return true;
    }

   public function uninstall()
   {
      if (parent::uninstall()) {
         foreach ($this->_hooks as $hook) {
            if (!$this->unregisterHook($hook)) {
               return false;
            }
         }

         /*if (!$this->uninstallDB()) {
             return false;
         }*/

         if (!$this->deleteCarriers()) {
             return false;
         }

         return true;
      }

      return false;
   }

   protected function createCarriers()
   {
      foreach ($this->_carriers as $key => $value) {
         $carrier = new Carrier();         
         $carrier->name = $key;
         $carrier->active = true;
         $carrier->deleted = 0;
         $carrier->shipping_handling = false;
         $carrier->range_behavior = 0;
         $carrier->delay[Configuration::get('PS_LANG_DEFAULT')] = 'Sameday';
         $carrier->shipping_external = true;
         $carrier->is_module = true;
         $carrier->external_module_name = $this->name;
         $carrier->need_range = true;

         if ($carrier->add()) {
            $groups = Group::getGroups(true);
            foreach ($groups as $group) {
               Db::getInstance()->autoExecute(_DB_PREFIX_ . 'carrier_group', array(
                  'id_carrier' => (int) $carrier->id,
                  'id_group' => (int) $group['id_group']
               ), 'INSERT');
            }

            $rangePrice = new RangePrice();
            $rangePrice->id_carrier = $carrier->id;
            $rangePrice->delimiter1 = '0';
            $rangePrice->delimiter2 = '1000000';
            $rangePrice->add();

            $rangeWeight = new RangeWeight();
            $rangeWeight->id_carrier = $carrier->id;
            $rangeWeight->delimiter1 = '0';
            $rangeWeight->delimiter2 = '23';
            $rangeWeight->add();

            $zones = Zone::getZones(true);
            foreach ($zones as $z) {
               Db::getInstance()->autoExecute(_DB_PREFIX_ . 'carrier_zone',
                  array('id_carrier' => (int) $carrier->id, 'id_zone' => (int) $z['id_zone']), 'INSERT');
               Db::getInstance()->autoExecuteWithNullValues(_DB_PREFIX_ . 'delivery',
                  array('id_carrier' => $carrier->id, 'id_range_price' => (int) $rangePrice->id, 'id_range_weight' => NULL, 'id_zone' => (int) $z['id_zone'], 'price' => '25'), 'INSERT');
               Db::getInstance()->autoExecuteWithNullValues(_DB_PREFIX_ . 'delivery',
                  array('id_carrier' => $carrier->id, 'id_range_price' => NULL, 'id_range_weight' => (int) $rangeWeight->id, 'id_zone' => (int) $z['id_zone'], 'price' => '25'), 'INSERT');
            }

            copy(dirname(__FILE__) . '/views/img/carrier.jpg', _PS_SHIP_IMG_DIR_ . '/' . (int) $carrier->id . '.jpg');

            Configuration::updateValue(self::PREFIX . $value, $carrier->id);
            Configuration::updateValue(self::PREFIX . $value . '_reference', $carrier->id);
         }
      }

      return true;
   }

   public function hookActionCarrierUpdate($params)
   {
      if ($params['carrier']->id_reference == Configuration::get(self::PREFIX . 'swipbox_reference')) {
         Configuration::updateValue(self::PREFIX . 'swipbox', $params['carrier']->id);
      }
   }
   public function hookUpdateCarrier($params)
   {
     $id_carrier_old = (int)($params['id_carrier']);
     $id_carrier_new = (int)($params['carrier']->id);
     if ($id_carrier_old == (int)(Configuration::get(self::PREFIX . 'CARRIER_ID')))
       Configuration::updateValue(self::PREFIX . 'CARRIER_ID', $id_carrier_new);
   }

   private function logToFile($var){
      if(is_array($var))
         $var = json_encode($var);
      elseif(is_object($var)){
         ob_start();
         var_dump($var);
         $var=ob_get_contents();
         ob_end_clean();  
      }
         
      $fp = fopen("/tmp/presta.txt","a");
      fwrite($fp, $var);
      fclose($fp);
   }

   public function getOrderShippingCost($params, $shipping_cost)
   {  
      $QA   = Configuration::get('environment');
      
      $address = new Address(intval($params->id_address_delivery));
      $country = new Country(intval($address->id_country));
      $products = $params->getProducts(true);

      if(($country->iso_code == 'NL' || $country->iso_code == 'NLD') && $_GET['step']!='1')
      {
         if( $_SESSION[self::PREFIX .'id_country'] != $address->id_country || $_SESSION[self::PREFIX .'address1'] != $address->address1 || $_SESSION[self::PREFIX .'postcode'] != $address->postcode || $_SESSION[self::PREFIX .'city'] != $address->city  )
         {
            $_SESSION[self::PREFIX .'id_country'] = $address->id_country;
            $_SESSION[self::PREFIX .'address1']   = $address->address1;
            $_SESSION[self::PREFIX .'postcode']   = $address->postcode;
            $_SESSION[self::PREFIX .'city']       = $address->city;

            $api_boxture   = $this->sentJSON("https://api.boxture.com/convert_address.php",json_encode(array("postal_code" => $address->postcode,"address"=> $address->address1,"iso_country_code"=> "NL")));
            $json_boxture  = json_decode($api_boxture['result'],true);

            if(!empty($json_boxture['lat']))
            {
               $return        = $this->sentBOXJSON("https://api".($QA ? "-qa" : "-new").".boxture.com/available_features?latitude=".$json_boxture['lat']."&purpose=pickup&longitude=".$json_boxture['lon'],Configuration::get('APIkey'));
               $json_boxture2 = json_decode($return['result'],true);
               $longitude     = $json_boxture['lon'];
               $latitude      = $json_boxture['lat']; 
               $return        = (($return['info']['http_code']=='404' || $return['info']['http_code']=='422' || $return['info']['http_code']=='401') ? false : true);
            }   
            if($return)
            {
               $value   = 0;
               $weight  = 1;
               foreach($products as $product)
               {
                  if($product['available_for_order'] == $product['quantity'] && $product['is_virtual'] == 0){
                     $value   += $product['price'];
                     $weight  += $product['weight'];
                  }
               }
               $width   = (float)Configuration::get('width');
               $height  = (float)Configuration::get('height');
               $length  = (float)Configuration::get('length');
               $country = $this->country();
               $json = array(
                  "service_type" => "",
                  "human_id" => null,
                  "state" => null,
                  "weight" => (float)$weight,
                  "value" => $value,
                  "quantity" => 1,
                  "insurance" => false,
                  "dimensions" => array("width" => $width,"height" => $height,"length" => $length),
                  "comments" => "",
                  "customer_email" => "",
                  "origin" =>  array(
                     "country" => $this->_country['NL'],
                     "formatted_address" => Configuration::get('shopStreet')." ".Configuration::get('shopNr')."\n".Configuration::get('shopZip')." ".Configuration::get('shopCity')."\n".$this->_country['NL'],
//                     "administrative_area" => ucwords($jsonPost['origin']['province']),
                     "iso_country_code" => 'NL',
                     "locality" => Configuration::get('shopCity'),
                     "postal_code" => Configuration::get('shopZip'),
                     "sub_thoroughfare" => Configuration::get('shopNr'),
                     "thoroughfare" => Configuration::get('shopStreet'),
                     "contact" => "",
                     "email" => "noreply@boxture.com",
                     "mobile" => "",
                     "comments" => "",
                     "company" => Configuration::get('shopName'),
                  ),
                  "destination" => array(
                     "country" => $this->_country['NL'],
                     "formatted_address" => $address->address1 ."\n".$address->postcode." ".$address->city."\n".$this->_country['NL'],
                     "iso_country_code" => 'NL',
                     "locality" => $address->city,
                     "postal_code" => $address->postcode,
                     "sub_thoroughfare" => $json_boxture['subThoroughfare'],
                     "thoroughfare" => $json_boxture['thoroughfare'],
                     "contact" => $address->firstname." ".$address->lastname,
                     "email" => "noreply@boxture.com",
                     "mobile" => $address->phone,
                     "comments" => "",
                     "company" => $address->company
                  ),
                  "waybill_nr" => null,
                  "vehicle_type" => "bicycle"
               );
               $json = json_encode(array("shipment_quote" => $json));
               $api_local_express_q    = $this->sentBOXJSON("https://api".($QA ? "-qa" : "-new").".boxture.com/shipment_quotes",Configuration::get('APIkey'),$json);
               $json_local_express_q   = json_decode($api_local_express_q['result'],true);
               if(empty(Configuration::get('price'))){
                  $_SESSION[self::PREFIX .'price'] = $json_local_express_q['shipment_quote']['price'];
               } else {
                  $_SESSION[self::PREFIX .'price'] = Configuration::get('price');
               }
            }
         }
         if(!empty($_SESSION[self::PREFIX .'price']))
            return $_SESSION[self::PREFIX .'price'];
         else
            return false;
      } else
         return false;
   }

   public function sentJSON($url,$post=false,$debug=false){
      $ch         = curl_init($url);     
      curl_setopt($ch, CURLOPT_RETURNTRANSFER,true);
      curl_setopt($ch, CURLOPT_SSL_VERIFYPEER,false);
   
      if($post){
         curl_setopt($ch, CURLOPT_POST, 1);
         curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
      }
   
      $result = curl_exec($ch);
      $info = curl_getinfo($ch);
      curl_close($ch);
      return array("info" => $info,"result" => $result);
   }
   public function sentBOXJson($url,$key,$post=false){
      $ch         = curl_init($url);
      if($post){
         curl_setopt($ch, CURLOPT_POST, 1);
         curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
      }
      curl_setopt($ch, CURLOPT_RETURNTRANSFER,true);
      curl_setopt($ch, CURLOPT_SSL_VERIFYPEER,false);
      curl_setopt($ch, CURLOPT_HTTPHEADER,array(
             'Content-Type: application/json',
             'Accept-Language: en',
             'Connection: Keep-Alive',
             'Authorization: Boxture '.$key));
      $result = curl_exec($ch);
      $info = curl_getinfo($ch);
      curl_close($ch);
      return array("info" => $info,"result" => $result);
   }

   public function getOrderShippingCostExternal($params)
   {
      return $this->getOrderShippingCost($params, 0);
   }
	public function getContent()
	{
      $vars = array('APIkey','price','height','width','length','shopName','shopStreet','shopNr','shopZip','shopCountry','shopEmail','shopPhone','environment');
	   $output = null;
	   if (Tools::isSubmit('submit'.$this->name))
	   {
	      foreach($vars as $id => $var){
   	      ${$var} = strval(Tools::getValue($var));
   	      if ((!${$var}  || empty(${$var}) || !Validate::isGenericName($var)) && $var !='price' && $var !='environment')
   	         $output = $this->displayError( $this->l('Invalid Configuration value some might been saved') );
   	      else
   	      {
   	         Configuration::updateValue($var, ${$var});
   	         if($output == null)
   	            $output .= $this->displayConfirmation($this->l('Settings updated'));
   	      }
   	   }
	   }
	   return $output.$this->displayForm();
	}
   
	public function displayForm()
	{
	   // Get default Language
	   $default_lang = (int)Configuration::get('PS_LANG_DEFAULT');
	   // Init Fields form array
	   $fields_form[0]['form'] = array(
	      'legend' => array(
	         'title' => $this->l('Settings'),
	      ),
	      'input' => array(
	         array(
	            'type' => 'text',
	            'label' => $this->l('API key'),
	            'name' => 'APIkey',
	            'size' => 120,
	            'required' => true
	         ),
	         array(
	            'type' => 'text',
	            'label' => $this->l('Price'),
	            'name' => 'price',
	            'size' => 8
	         ),
	         array(
	            'type' => 'text',
	            'label' => $this->l('Height'),
	            'name' => 'height',
	            'size' => 8,
	            'required' => true
	         ),
	         array(
	            'type' => 'text',
	            'label' => $this->l('Width'),
	            'name' => 'width',
	            'size' => 8,
	            'required' => true
	         ),
	         array(
	            'type' => 'text',
	            'label' => $this->l('Length'),
	            'name' => 'length',
	            'size' => 8,
	            'required' => true
	         ),
            array(
	            'type' => 'text',
	            'label' => $this->l('Shop name'),
	            'name' => 'shopName',
	            'size' => 50,
	            'required' => true
	         ),
            array(
	            'type' => 'text',
	            'label' => $this->l('Shop street'),
	            'name' => 'shopStreet',
	            'size' => 50,
	            'required' => true
	         ),
            array(
	            'type' => 'text',
	            'label' => $this->l('Shop house number'),
	            'name' => 'shopNr',
	            'size' => 8,
	            'required' => true
	         ),
            array(
	            'type' => 'text',
	            'label' => $this->l('Shop zipcode'),
	            'name' => 'shopZip',
	            'size' => 8,
	            'required' => true
	         ),
            array(
	            'type' => 'text',
	            'label' => $this->l('Shop country (iso 3166-2)'),
	            'name' => 'shopCountry',
	            'size' => 2,
	            'required' => true
	         ),
            array(
	            'type' => 'text',
	            'label' => $this->l('Shop email address'),
	            'name' => 'shopEmail',
	            'size' => 80,
	            'required' => true
	         ),
            array(
	            'type' => 'text',
	            'label' => $this->l('Shop phone number'),
	            'name' => 'shopPhone',
	            'size' => 15,
	            'required' => true
	         ),
	         array(
	            'type' => 'radio',
	            'label' => $this->l('Environment'),
	            'name' => 'environment',
	            'values' => array(
	               array(          
	                  'id' => 'qa',
	                  'value' => 1,
	                  'label' => $this->l('QA')
	               ),
	               array(
	                  'id' => 'live',
	                  'value' => 0,
	                  'label' => $this->l('Live')
	               )
	            )
	         )
	      ),
	      'submit' => array(
	         'title' => $this->l('Save'),
	         'class' => 'button'
	      )
	   );
	   $helper = new HelperForm();   // Module, token and currentIndex
	   $helper->module = $this;
	   $helper->name_controller = $this->name;
	   $helper->token = Tools::getAdminTokenLite('AdminModules');
	   $helper->currentIndex = AdminController::$currentIndex.'&configure='.$this->name;
	   // Language
	   $helper->default_form_language = $default_lang;
	   $helper->allow_employee_form_lang = $default_lang;

	   // Title and toolbar
	   $helper->title = $this->displayName;
	   $helper->show_toolbar = true;        // false -> remove toolbar
	   $helper->toolbar_scroll = true;      // yes - > Toolbar is always visible on the top of the screen.
	   $helper->submit_action = 'submit'.$this->name;

	   $helper->toolbar_btn = array(
	      'save' =>   array(
	         'desc' => $this->l('Save'),
	         'href' => AdminController::$currentIndex.'&configure='.$this->name.'&save'.$this->name.
	         '&token='.Tools::getAdminTokenLite('AdminModules'),
	      ),
	      'back' => array(
	         'href' => AdminController::$currentIndex.'&token='.Tools::getAdminTokenLite('AdminModules'),
	         'desc' => $this->l('Back to list')
	      )
	   );
	   // Load current value
      $helper->fields_value['price']         = Configuration::get('price');
	   $helper->fields_value['APIkey']        = Configuration::get('APIkey');
      $helper->fields_value['height']        = Configuration::get('height');
      $helper->fields_value['width']         = Configuration::get('width');
      $helper->fields_value['length']        = Configuration::get('length');
      $helper->fields_value['shopName']      = Configuration::get('shopName');
      $helper->fields_value['shopStreet']    = Configuration::get('shopStreet');
      $helper->fields_value['shopNr']        = Configuration::get('shopNr');
      $helper->fields_value['shopZip']       = Configuration::get('shopZip');
      $helper->fields_value['shopCountry']   = Configuration::get('shopCountry');
      $helper->fields_value['shopEmail']     = Configuration::get('shopEmail');
      $helper->fields_value['shopPhone']     = Configuration::get('shopPhone');
      $helper->fields_value['environment']   = Configuration::get('environment');
	   return $helper->generateForm($fields_form);
	}
	public function country()
	{
	   $this->_country = array(
      	'AF' => 'Afghanistan',
      	'AL' => 'Albania',
      	'DZ' => 'Algeria',
      	'AD' => 'Andorra',
      	'AO' => 'Angola',
      	'AI' => 'Anguilla',
      	'AG' => 'Antigua and Barbuda',
      	'AR' => 'Argentina',
      	'AM' => 'Armenia',
      	'AW' => 'Aruba',
      	'AU' => 'Australia',
      	'AT' => 'Austria',
      	'AZ' => 'Azerbaijan',
      	'BS' => 'Bahamas',
      	'BH' => 'Bahrain',
      	'BD' => 'Bangladesh',
      	'BB' => 'Barbados',
      	'BY' => 'Belarus',
      	'BE' => 'Belgium',
      	'BZ' => 'Belize',
      	'BJ' => 'Benin',
      	'BM' => 'Bermuda',
      	'BT' => 'Bhutan',
      	'BO' => 'Bolivia',
      	'BA' => 'Bosnia-Herzegovina',
      	'BW' => 'Botswana',
      	'BR' => 'Brazil',
      	'VG' => 'British Virgin Islands',
      	'BN' => 'Brunei Darussalam',
      	'BG' => 'Bulgaria',
      	'BF' => 'Burkina Faso',
      	'MM' => 'Burma',
      	'BI' => 'Burundi',
      	'KH' => 'Cambodia',
      	'CM' => 'Cameroon',
      	'CA' => 'Canada',
      	'CV' => 'Cape Verde',
      	'KY' => 'Cayman Islands',
      	'CF' => 'Central African Republic',
      	'TD' => 'Chad',
      	'CL' => 'Chile',
      	'CN' => 'China',
      	'CX' => 'Christmas Island (Australia)',
      	'CC' => 'Cocos Island (Australia)',
      	'CO' => 'Colombia',
      	'KM' => 'Comoros',
      	'CG' => 'Congo (Brazzaville),Republic of the',
      	'ZR' => 'Congo, Democratic Republic of the',
      	'CK' => 'Cook Islands (New Zealand)',
      	'CR' => 'Costa Rica',
      	'CI' => 'Cote d\'Ivoire (Ivory Coast)',
      	'HR' => 'Croatia',
      	'CU' => 'Cuba',
      	'CY' => 'Cyprus',
      	'CZ' => 'Czech Republic',
      	'DK' => 'Denmark',
      	'DJ' => 'Djibouti',
      	'DM' => 'Dominica',
      	'DO' => 'Dominican Republic',
      	'TP' => 'East Timor (Indonesia)',
      	'EC' => 'Ecuador',
      	'EG' => 'Egypt',
      	'SV' => 'El Salvador',
      	'GQ' => 'Equatorial Guinea',
      	'ER' => 'Eritrea',
      	'EE' => 'Estonia',
      	'ET' => 'Ethiopia',
      	'FK' => 'Falkland Islands',
      	'FO' => 'Faroe Islands',
      	'FJ' => 'Fiji',
      	'FI' => 'Finland',
      	'FR' => 'France',
      	'GF' => 'French Guiana',
      	'PF' => 'French Polynesia',
      	'GA' => 'Gabon',
      	'GM' => 'Gambia',
      	'GE' => 'Georgia, Republic of',
      	'DE' => 'Germany',
      	'GH' => 'Ghana',
      	'GI' => 'Gibraltar',
      	'GB' => 'Great Britain and Northern Ireland',
      	'GR' => 'Greece',
      	'GL' => 'Greenland',
      	'GD' => 'Grenada',
      	'GP' => 'Guadeloupe',
      	'GT' => 'Guatemala',
      	'GN' => 'Guinea',
      	'GW' => 'Guinea-Bissau',
      	'GY' => 'Guyana',
      	'HT' => 'Haiti',
      	'HN' => 'Honduras',
      	'HK' => 'Hong Kong',
      	'HU' => 'Hungary',
      	'IS' => 'Iceland',
      	'IN' => 'India',
      	'ID' => 'Indonesia',
      	'IR' => 'Iran',
      	'IQ' => 'Iraq',
      	'IE' => 'Ireland',
      	'IL' => 'Israel',
      	'IT' => 'Italy',
      	'JM' => 'Jamaica',
      	'JP' => 'Japan',
      	'JO' => 'Jordan',
      	'KZ' => 'Kazakhstan',
      	'KE' => 'Kenya',
      	'KI' => 'Kiribati',
      	'KW' => 'Kuwait',
      	'KG' => 'Kyrgyzstan',
      	'LA' => 'Laos',
      	'LV' => 'Latvia',
      	'LB' => 'Lebanon',
      	'LS' => 'Lesotho',
      	'LR' => 'Liberia',
      	'LY' => 'Libya',
      	'LI' => 'Liechtenstein',
      	'LT' => 'Lithuania',
      	'LU' => 'Luxembourg',
      	'MO' => 'Macao',
      	'MK' => 'Macedonia, Republic of',
      	'MG' => 'Madagascar',
      	'MW' => 'Malawi',
      	'MY' => 'Malaysia',
      	'MV' => 'Maldives',
      	'ML' => 'Mali',
      	'MT' => 'Malta',
      	'MQ' => 'Martinique',
      	'MR' => 'Mauritania',
      	'MU' => 'Mauritius',
      	'YT' => 'Mayotte (France)',
      	'MX' => 'Mexico',
      	'MD' => 'Moldova',
      	'MC' => 'Monaco (France)',
      	'MN' => 'Mongolia',
      	'MS' => 'Montserrat',
      	'MA' => 'Morocco',
      	'MZ' => 'Mozambique',
      	'NA' => 'Namibia',
      	'NR' => 'Nauru',
      	'NP' => 'Nepal',
      	'NL' => 'Netherlands',
      	'AN' => 'Netherlands Antilles',
      	'NC' => 'New Caledonia',
      	'NZ' => 'New Zealand',
      	'NI' => 'Nicaragua',
      	'NE' => 'Niger',
      	'NG' => 'Nigeria',
      	'KP' => 'North Korea (Korea, Democratic People\'s Republic of)',
      	'NO' => 'Norway',
      	'OM' => 'Oman',
      	'PK' => 'Pakistan',
      	'PA' => 'Panama',
      	'PG' => 'Papua New Guinea',
      	'PY' => 'Paraguay',
      	'PE' => 'Peru',
      	'PH' => 'Philippines',
      	'PN' => 'Pitcairn Island',
      	'PL' => 'Poland',
      	'PT' => 'Portugal',
      	'QA' => 'Qatar',
      	'RE' => 'Reunion',
      	'RO' => 'Romania',
      	'RU' => 'Russia',
      	'RW' => 'Rwanda',
      	'SH' => 'Saint Helena',
      	'KN' => 'Saint Kitts (St. Christopher and Nevis)',
      	'LC' => 'Saint Lucia',
      	'PM' => 'Saint Pierre and Miquelon',
      	'VC' => 'Saint Vincent and the Grenadines',
      	'SM' => 'San Marino',
      	'ST' => 'Sao Tome and Principe',
      	'SA' => 'Saudi Arabia',
      	'SN' => 'Senegal',
      	'YU' => 'Serbia-Montenegro',
      	'SC' => 'Seychelles',
      	'SL' => 'Sierra Leone',
      	'SG' => 'Singapore',
      	'SK' => 'Slovak Republic',
      	'SI' => 'Slovenia',
      	'SB' => 'Solomon Islands',
      	'SO' => 'Somalia',
      	'ZA' => 'South Africa',
      	'GS' => 'South Georgia (Falkland Islands)',
      	'KR' => 'South Korea (Korea, Republic of)',
      	'ES' => 'Spain',
      	'LK' => 'Sri Lanka',
      	'SD' => 'Sudan',
      	'SR' => 'Suriname',
      	'SZ' => 'Swaziland',
      	'SE' => 'Sweden',
      	'CH' => 'Switzerland',
      	'SY' => 'Syrian Arab Republic',
      	'TW' => 'Taiwan',
      	'TJ' => 'Tajikistan',
      	'TZ' => 'Tanzania',
      	'TH' => 'Thailand',
      	'TG' => 'Togo',
      	'TK' => 'Tokelau (Union) Group (Western Samoa)',
      	'TO' => 'Tonga',
      	'TT' => 'Trinidad and Tobago',
      	'TN' => 'Tunisia',
      	'TR' => 'Turkey',
      	'TM' => 'Turkmenistan',
      	'TC' => 'Turks and Caicos Islands',
      	'TV' => 'Tuvalu',
      	'UG' => 'Uganda',
      	'UA' => 'Ukraine',
      	'AE' => 'United Arab Emirates',
      	'UY' => 'Uruguay',
      	'UZ' => 'Uzbekistan',
      	'VU' => 'Vanuatu',
      	'VA' => 'Vatican City',
      	'VE' => 'Venezuela',
      	'VN' => 'Vietnam',
      	'WF' => 'Wallis and Futuna Islands',
      	'WS' => 'Western Samoa',
      	'YE' => 'Yemen',
      	'ZM' => 'Zambia',
      	'ZW' => 'Zimbabwe'
      );
	}
}

