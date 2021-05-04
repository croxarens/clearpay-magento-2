<?php
/**
 * Magento 2 extensions for Clearpay Payment
 *
 * @author Clearpay
 * @copyright 2021 Clearpay https://www.clearpay.com
 */
namespace Clearpay\ClearpayEurope\Model\Adapter\V2;

use \Clearpay\ClearpayEurope\Model\Adapter\Clearpay\Call;
use \Clearpay\ClearpayEurope\Model\Config\Payovertime as PayovertimeConfig;
use \Magento\Framework\ObjectManagerInterface as ObjectManagerInterface;
use \Magento\Store\Model\StoreManagerInterface as StoreManagerInterface;
use \Magento\Catalog\Api\ProductRepositoryInterface as ProductRepositoryInterface;
use \Magento\Framework\Json\Helper\Data as JsonHelper;
use \Clearpay\ClearpayEurope\Helper\Data as Helper;

use \Magento\Directory\Model\CountryFactory as CountryFactory;
use \Magento\Framework\App\Config\ScopeConfigInterface as ScopeConfig;

/**
 * Class ClearpayOrderTokenV2
 * @package Clearpay\ClearpayEurope\Model\Adapter\V2
 */
class ClearpayOrderTokenV2
{
    /**
     * Constant data
     */
    const DECIMAL_PRECISION = 2;
    const PAYMENT_TYPE_CODE = 'PBI';

    /**
     * @var Call
     */
    protected $_clearpayApiCall;
    protected $_clearpayConfig;
    protected $_objectManagerInterface;
    protected $_storeManagerInterface;
    protected $_productRepositoryInterface;
    protected $_jsonHelper;
    protected $_helper;

    protected $_countryFactory;
    protected $_scopeConfig;
    protected $listStateRequired;

    /**
     * ClearpayOrderToken constructor.
     * @param Call $clearpayApiCall
     * @param PayovertimeConfig $clearpayConfig
     * @param ObjectManagerInterface $objectManagerInterface
     * @param StoreManagerInterface $storeManagerInterface
     * @param ProductRepositoryInterface $productRepositoryInterface
     * @param JsonHelper $jsonHelper
     * @param CountryFactory $countryFactory
     * @param ScopeConfig $scopeConfig
     * @param Helper $clearpayHelper
     */
    public function __construct(
        Call $clearpayApiCall,
        PayovertimeConfig $clearpayConfig,
        ObjectManagerInterface $objectManagerInterface,
        StoreManagerInterface $storeManagerInterface,
        ProductRepositoryInterface $productRepositoryInterface,
        JsonHelper $jsonHelper,
        CountryFactory $countryFactory,
        ScopeConfig $scopeConfig,
        Helper $clearpayHelper
    ) {
        $this->_clearpayApiCall = $clearpayApiCall;
        $this->_clearpayConfig = $clearpayConfig;
        $this->_objectManagerInterface = $objectManagerInterface;
        $this->_storeManagerInterface = $storeManagerInterface;
        $this->_productRepositoryInterface = $productRepositoryInterface;
        $this->_jsonHelper = $jsonHelper;
        $this->_helper = $clearpayHelper;
        $this->_countryFactory = $countryFactory;
        $this->_scopeConfig = $scopeConfig;
        $this->listStateRequired = $this->_getStateRequired();
    }

    /**
     * @param $object
     * @param $code
     * @param array $override
     * @return mixed|\Zend_Http_Response
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function generate($object,$override = [])
    {
        $requestData = $this->_buildOrderTokenRequest($object, $override);
        $targetUrl = $this->_clearpayConfig->getApiUrl('v1/orders', null, $override);
        $requestData = $this->constructPayload($requestData, $this->listStateRequired);
        $this->handleValidation($requestData);
        $response = $this->performTransaction($requestData, $targetUrl);
        return $response;
    }

    public function constructPayload($requestData, $listStateRequired)
    {
        //handle possibility of Postal Code not being mandatory
        //e.g. Gift Cards
        $requestData = $this->_handlePostcode($requestData);
        $requestData = $this->_handleState($requestData, $listStateRequired);
        return $requestData;
    }
    public function performTransaction($requestData, $targetUrl)
    {
        try {
            $response = $this->_clearpayApiCall->send(
                $targetUrl,
                $requestData,
                \Magento\Framework\HTTP\ZendClient::POST
            );
        } catch (\Exception $e) {
            $this->_helper->debug($e->getMessage());
            throw new \Magento\Framework\Exception\LocalizedException(__($e->getMessage()));
        }
        return $response;
    }

    private function _handlePostcode($requestData)
    {
        if (empty($requestData["shipping"])) {
            return $requestData;
        }
        $billing_postcode = $requestData['billing']['postcode'];
        $shipping_postcode = $requestData['shipping']['postcode'];
        if ((empty($billing_postcode) || strlen(trim($billing_postcode)) < 3)
            && !empty($shipping_postcode) && strlen(trim($shipping_postcode) >= 3) ) {
            $requestData['billing']['postcode'] = $shipping_postcode;
        }
        return $requestData;
    }

    private function _getStateRequired()
    {
        $destinations = (string)$this->_scopeConfig->getValue(
            'general/region/state_required'
        );

        $state_required = !empty($destinations) ? explode(',', $destinations) : [];

        return $state_required;
    }

    private function _handleState($requestData, $listStateRequired)
    {
        $billing_state = ( !empty($requestData['billing']['region']) ? $requestData['billing']['region'] : null);
        $shipping_state = ( !empty($requestData['shipping']['region']) ? $requestData['shipping']['region'] : null);
        //if the Billing or Shipping State is empty, enforce a transfer of values
        if (empty($billing_state) && !empty($shipping_state)) {
            $requestData['billing']['region'] = $shipping_state;
        }
        return $requestData;
    }

    public function handleValidation($requestData)
    {
        $errors = [];
       
	    if (empty($requestData['billing']['line1'])) {
            $errors[] = 'Address is required';
        } 
		if (empty($requestData['billing']['suburb'])) {
            $errors[] = 'Suburb/City is required';
        }
        if(!empty($requestData['billing']['state']) && strlen(trim($requestData['billing']['state'])) < 2){
            $errors[] = "Please enter a valid State name";
        }
		if (empty($requestData['billing']['postcode']) || strlen(trim($requestData['billing']['postcode'])) < 3) {
            $errors[] = 'Zip/Postal is required';
        }
		if (empty($requestData['billing']['countryCode'])) {
            $errors[] = 'Country is required';
        } 
		
        if (count($errors)) {
            throw new \Magento\Framework\Exception\LocalizedException(__(implode(' ; ', $errors)));
        } else {
            return true;
        }
    }


    /**
     * Build object for order token
     *
     * @param \Magento\Sales\Model\Order $object Order to get token for
     * @param $code
     * @param array $override
     * @return array
     */
    protected function _buildOrderTokenRequest($object, $override = [])
    {
        $precision = self::DECIMAL_PRECISION;
        $data = $object->getData();
        $billingAddress  = $object->getBillingAddress();
        $shippingAddress = $object->getShippingAddress();

        $email = $object->getCustomerEmail();

        $params['consumer'] = [
            'email'       => (string)$email,
            'givenNames'  => $object->getCustomerFirstname() ? (string)$object->getCustomerFirstname() : $billingAddress->getFirstname(),
            'surname'     => $object->getCustomerLastname() ? (string)$object->getCustomerLastname() : $billingAddress->getLastname(),
            'phoneNumber' => (string)$billingAddress->getTelephone()
        ];
        
        $params['merchantReference'] = array_key_exists('merchantOrderId', $override) ? $override['merchantOrderId'] : $object->getIncrementId();

        $params['merchant'] = [
            'redirectConfirmUrl'    => $this->_storeManagerInterface->getStore($object->getStore()->getId())->getBaseUrl() . 'clearpayeurope/payment/response',
            'redirectCancelUrl'     => $this->_storeManagerInterface->getStore($object->getStore()->getId())->getBaseUrl() . 'clearpayeurope/payment/response'
        ];

        foreach ($object->getAllVisibleItems() as $item) {
            if (!$item->getParentItem()) {
				
				$product = $this->_productRepositoryInterface->getById($item->getProductId());
				$category_ids = $product->getCategoryIds();
				$imageHelper =  $this->_objectManagerInterface->get('\Magento\Catalog\Helper\Image');
				
                $categories=[];
				if(count($category_ids) > 0){
					foreach($category_ids as $category){
						$cat = $this->_objectManagerInterface->create('Magento\Catalog\Model\Category')->load($category);
						array_push($categories,$cat->getName());
					}
				}
				$params['items'][] = [
                    'name'     => (string)$item->getName(),
                    'sku'      => (string)$item->getSku(),
                    'pageUrl'  =>  $product->getProductUrl(),
                    'imageUrl' =>  $imageHelper->init($product, 'product_page_image_small')->setImageFile($product->getFile())->getUrl(),
                    'quantity' => (int)$item->getQty(),
                    'price'    => [
                        'amount'   => round((float)$item->getPriceInclTax(), $precision),
                        'currency' => (string)$data['store_currency_code']
                    ],
					'categories' => [$categories]
                ];
            }
        }
        if ($object->getShippingInclTax()) {
            $params['shippingAmount'] = [
                'amount'   => round((float)$object->getShippingInclTax(), $precision), // with tax
                'currency' => (string)$data['store_currency_code']
            ];
        }
        if (isset($data['discount_amount'])) {
            $params['discounts']['displayName'] = 'Discount';
            $params['orderDetail']['amount']     = [
                'amount'   => round((float)$data['discount_amount'], $precision),
                'currency' => (string)$data['store_currency_code']
            ];
        }
        $taxAmount = array_key_exists('tax_amount', $data) ? $data['tax_amount'] : $shippingAddress->getTaxAmount();
        $params['taxAmount'] = [
            'amount'   => isset($taxAmount) ? round((float)$taxAmount, $precision) : 0,
            'currency' => (string)$data['store_currency_code']
        ];
	
		if(!empty($shippingAddress) && !empty($shippingAddress->getStreetLine(1)))
		{
			$params['shipping'] = [
				'name'          => (string)$shippingAddress->getFirstname() . ' ' . $shippingAddress->getLastname(),
				'line1'         => (string)$shippingAddress->getStreetLine(1),
				'line2'         => (string)$shippingAddress->getStreetLine(2),
				'suburb'        => (string)$shippingAddress->getCity(),
				'postcode'      => (string)$shippingAddress->getPostcode(),
				'state'         => (string)$shippingAddress->getRegion(),
				'countryCode'   => (string)$shippingAddress->getCountryId(),
				// 'countryCode'   => 'GB',
				'phoneNumber'   => (string)$shippingAddress->getTelephone(),
			];
		}
        $params['billing'] = [
            'name'          => (string)$billingAddress->getFirstname() . ' ' . $billingAddress->getLastname(),
            'line1'         => (string)$billingAddress->getStreetLine(1),
            'line2'         => (string)$billingAddress->getStreetLine(2),
            'suburb'        => (string)$billingAddress->getCity(),
            'postcode'      => (string)$billingAddress->getPostcode(),
            'state'         => (string)$billingAddress->getRegion(),
            'countryCode'   => (string)$billingAddress->getCountryId(),
            // 'countryCode'   => 'GB',
            'phoneNumber'   => (string)$billingAddress->getTelephone(),
        ];
        $params['totalAmount'] = [
            'amount'   => round((float)$object->getGrandTotal(), $precision),
            'currency' => (string)$data['store_currency_code'],
        ];

        $params['purchaseCountry'] =  (string)$billingAddress->getCountryId();

        return $params;
    }
}
