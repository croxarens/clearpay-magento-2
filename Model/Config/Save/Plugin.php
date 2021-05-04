<?php
/**
 * Magento 2 extensions for Clearpay Payment
 *
 * @author Clearpay
 * @copyright 2021 Clearpay https://www.clearpay.com
 */
namespace Clearpay\ClearpayEurope\Model\Config\Save;
use Magento\Store\Model\ScopeInterface;

/**
 * Class Plugin
 * @package Clearpay\ClearpayEurope\Model\Config\Save
 */
class Plugin
{
    /**
     * @var \Magento\Framework\Json\Helper\Data
     */
    protected $jsonHelper;
    protected $clearpayTotalLimit;
    protected $resourceConfig;
    protected $requested;
    protected $storeManager;
    protected $request;
    protected $messageManager;
    protected $_scopeConfig;

    /**
     * Plugin constructor.
     * @param \Magento\Framework\Json\Helper\Data $jsonHelper
     * @param \Clearpay\ClearpayEurope\Model\Adapter\ClearpayTotalLimit $clearpayTotalLimit
     * @param \Magento\Config\Model\ResourceModel\Config $resourceConfig
     */
    public function __construct(
        \Magento\Framework\Json\Helper\Data $jsonHelper,
        \Clearpay\ClearpayEurope\Model\Adapter\ClearpayTotalLimit $clearpayTotalLimit,
        \Magento\Config\Model\ResourceModel\Config $resourceConfig,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\App\Request\Http $request,
        \Magento\Framework\Message\ManagerInterface $messageManager,
        \Magento\Framework\App\Config\Storage\WriterInterface $configWriter,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
    ) {
        $this->jsonHelper = $jsonHelper;
        $this->clearpayTotalLimit = $clearpayTotalLimit;
        $this->resourceConfig = $resourceConfig;
        $this->storeManager = $storeManager;
        $this->request = $request;
        $this->messageManager = $messageManager;
        $this->configWriter = $configWriter;
        $this->_scopeConfig = $scopeConfig;
    }

    /**
     * @param \Magento\Config\Model\Config $subject
     * @param \Closure $proceed
     */
    public function aroundSave(
        \Magento\Config\Model\Config $subject,
        \Closure $proceed
    ) {
        
        //first saving run to eliminate possibilities of conflicting config results
        $returnValue=$proceed();

        if (class_exists('\Clearpay\ClearpayEurope\Model\Payovertime')) {

            try {
                $configRequest = $subject->getGroups();
    if(!empty($configRequest) && is_array($configRequest)){
                $this->requested = array_key_exists(\Clearpay\ClearpayEurope\Model\Payovertime::METHOD_CODE, $configRequest);
				
				if ($this->requested) {
					$config_array=$configRequest[\Clearpay\ClearpayEurope\Model\Payovertime::METHOD_CODE]['groups'][\Clearpay\ClearpayEurope\Model\Payovertime::METHOD_CODE . '_basic']['fields'][\Clearpay\ClearpayEurope\Model\Config\Payovertime::ACTIVE];
					
					if(array_key_exists('value',$config_array)){
						
						if($config_array['value'] == '1'){
							$response = $this->clearpayTotalLimit->getLimit();
							$response = $this->jsonHelper->jsonDecode($response->getBody());

							if (!array_key_exists('errorCode', $response)) {
								// default min and max if not provided
								$minTotal = "0";
								$maxTotal = "0";
								$allowedCountries = "N/A";

								$response = $response[0];
								// understand the response from the API
								$minTotal = array_key_exists('minimumAmount',$response) && isset($response['minimumAmount']['amount']) ? $response['minimumAmount']['amount'] : "0";
								$maxTotal = array_key_exists('maximumAmount',$response) && isset($response['maximumAmount']['amount']) ? $response['maximumAmount']['amount'] : "0";
                                $allowedCountries = array_key_exists('activeCountries', $response) && isset($response['activeCountries']) ? implode('|', $response['activeCountries']) : "N/A";

								//Change the minimum amd maximum to Not applicable if both limits are 0.
								if ($minTotal == "0" && $maxTotal=="0") {
									$minTotal="N/A";
									$maxTotal="N/A";
                                    $allowedCountries = "N/A";
								}

								// set on config request
								$configRequest[\Clearpay\ClearpayEurope\Model\Payovertime::METHOD_CODE]['groups'][\Clearpay\ClearpayEurope\Model\Payovertime::METHOD_CODE . '_advanced']['fields'][\Clearpay\ClearpayEurope\Model\Config\Payovertime::MIN_TOTAL_LIMIT]['value'] = $minTotal;
								$configRequest[\Clearpay\ClearpayEurope\Model\Payovertime::METHOD_CODE]['groups'][\Clearpay\ClearpayEurope\Model\Payovertime::METHOD_CODE . '_advanced']['fields'][\Clearpay\ClearpayEurope\Model\Config\Payovertime::MAX_TOTAL_LIMIT]['value'] = $maxTotal;
                                $configRequest[\Clearpay\ClearpayEurope\Model\Payovertime::METHOD_CODE]['groups'][\Clearpay\ClearpayEurope\Model\Payovertime::METHOD_CODE . '_advanced']['fields'][\Clearpay\ClearpayEurope\Model\Config\Payovertime::ALLOWED_COUNTRIES]['value'] = $allowedCountries;

								// Check for Cross Border Trade(CBT)
								//$enable_cbt = (array_key_exists('CBT',$response) && isset($response['CBT']['enabled']) && ($response['CBT']['enabled']===true)) ? "1" : "0";
								
								// Get current Store Id
								$storeId=(int) $this->request->getParam('store', 0);
								// Get current Website Id
								$websiteId = (int) $this->request->getParam('website', 0);
								
								// Set current scope
								$scope='default';
								$scopeId=0;
								if(!empty($websiteId)){
								    $scope=ScopeInterface::SCOPE_WEBSITES;
								    $scopeId=$websiteId;
								}elseif (!empty($storeId)){
								    $scope=ScopeInterface::SCOPE_STORE;
								    $scopeId=$storeId;
								}
								
								$countryName="";
								/*if($enable_cbt=="1"){
								    $countryName = $this->_scopeConfig->getValue('general/country/default', $scope,$scopeId);
								     if(isset($response['CBT']['countries']) && !empty($response['CBT']['countries'])){
								         if(is_array($response['CBT']['countries'])){
								             $countryName .=",".implode(",",$response['CBT']['countries']);
								         }
								     }								    
								}*/
								
								// Save Cross Border Trade(CBT) details on config request
								//$this->configWriter->save("payment/clearpayeupayovertime/".\Clearpay\ClearpayEurope\Model\Config\Payovertime::ENABLE_CBT, $enable_cbt, $scope, $scopeId);
								//$this->configWriter->save("payment/clearpayeupayovertime/".\Clearpay\ClearpayEurope\Model\Config\Payovertime::CBT_COUNTRY, $countryName, $scope, $scopeId);
							
								$subject->setGroups($configRequest);
								$returnValue=$proceed();
							} else {
								$this->messageManager->addWarningMessage('Clearpay Update Limits Failed. Please check Merchant ID and Key.');
								
							}
						}
					}
				}
               }
			}
            catch (\Exception $e) {
                $this->messageManager->addErrorMessage($e->getMessage());
            }
        }

        return $returnValue;
    }
}
