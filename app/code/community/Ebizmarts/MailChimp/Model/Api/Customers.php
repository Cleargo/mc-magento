<?php

/**
 * mailchimp-lib Magento Component
 *
 * @category Ebizmarts
 * @package mailchimp-lib
 * @author Ebizmarts Team <info@ebizmarts.com>
 * @copyright Ebizmarts (http://ebizmarts.com)
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

class Ebizmarts_MailChimp_Model_Api_Customers
{

    const BATCH_LIMIT = 100;

    public function createBatchJson($scope, $scopeId, $mailchimpStoreId)
    {
        $customerArray = array();
        
        //get customers
        $customerTable = Mage::getSingleton('core/resource')->getTableName('customer_entity');
        $collection = Mage::getModel('mailchimp/customersyncdata')->getCollection()
          ->addFieldToFilter(
                array(
                    'mailchimp_sync_delta',
                    'mailchimp_sync_modified',
                ),
                array(
                    array('lt' => Mage::getModel('mailchimp/config')->getMCMinSyncDateFlag($scope, $scopeId)),
                    array('eq' => 1)
                )
            )
        ->addFieldToFilter('scope', array('eq' => $scope.'_'.$scopeId));

        $joinCondition = 'c.entity_id = item_id';

        $collection->getSelect()
            ->join(array('c' => $customerTable), $joinCondition, array('c.entity_id'))
            ->limit(self::BATCH_LIMIT);
        $batchId = Ebizmarts_MailChimp_Model_Config::IS_CUSTOMER . '_' . Mage::helper('mailchimp')->getDateMicrotime();
        $counter = 0;
        foreach ($collection as $item) {
            $customer = Mage::getModel('customer/customer')->load($item->getItemId());
            $data = $this->_buildCustomerData($customer, $scope, $scopeId);
            $customerJson = "";

            //enconde to JSON
            try {
                $customerJson = json_encode($data);
            } catch (Exception $e) {
                //json encode failed
                Mage::helper('mailchimp')->logError("Customer " . $customer->getId() . " json encode failed");
            }

            if (!empty($customerJson)) {
                $customerArray[$counter]['method'] = "PUT";
                $customerArray[$counter]['path'] = "/ecommerce/stores/" . $mailchimpStoreId . "/customers/" . $customer->getId();
                $customerArray[$counter]['operation_id'] = $batchId . '_' . $customer->getId();
                $customerArray[$counter]['body'] = $customerJson;

                //update customers delta
                $syncData = Mage::getModel('mailchimp/customersyncdata')->load($item->getId());
                $syncData->setData("mailchimp_sync_delta", Varien_Date::now())
                    ->setData("mailchimp_sync_error", "")
                    ->setData("mailchimp_sync_modified", 0)
                    ->save();
            }
            $counter++;
        }
        return $customerArray;
    }

    protected function _buildCustomerData($customer, $scope, $scopeId)
    {
        $data = array();
        $data["id"] = $customer->getId();
        $data["email_address"] = $customer->getEmail();
        $data["first_name"] = $customer->getFirstname();
        $data["last_name"] = $customer->getLastname();
        $data["opt_in_status"] = $this->getOptin($scope, $scopeId);

        //customer orders data
        $orderCollection = Mage::getModel('sales/order')->getCollection()
            ->addFieldToFilter('state', 'complete')
            ->addAttributeToFilter('customer_id', array('eq' => $customer->getId()));
        $totalOrders = 0;
        $totalAmountSpent = 0;
        foreach ($orderCollection as $order) {
            $totalOrders++;
            $totalAmountSpent += (int)$order->getGrandTotal();
        }
        $data["orders_count"] = $totalOrders;
        $data["total_spent"] = $totalAmountSpent;

        //addresses data
        foreach ($customer->getAddresses() as $address) {
            //send only first address
            if (!array_key_exists("address", $data)) {
                $street = $address->getStreet();
                $data["address"] = array(
                    "address1" => $street[0] ? $street[0] : "",
                    "address2" => count($street)>1 ? $street[1] : "",
                    "city" => $address->getCity() ? $address->getCity() : "",
                    "province" => $address->getRegion() ? $address->getRegion() : "",
                    "province_code" => $address->getRegionCode() ? $address->getRegionCode() : "",
                    "postal_code" => $address->getPostcode(),
                    "country" => $address->getCountry() ? Mage::getModel('directory/country')->loadByCode($address->getCountry())->getName(): "",
                    "country_code" => $address->getCountry() ? $address->getCountry() : ""
                );

                //company
                if ($address->getCompany()) {
                    $data["company"] = $address->getCompany();
                }
                break;
            }
        }
        $mergeFields = $this->getMergeVars($customer);
        if (is_array($mergeFields)) {
            $data = array_merge($mergeFields, $data);
        }

        return $data;
    }
    public function update($customerId)
    {
        $collection = Mage::getModel('mailchimp/customersyncdata')->getCollection()
            ->addFieldToFilter('item_id', array($customerId));
        foreach ($collection as $item) {
            $item->setData("mailchimp_sync_delta", Varien_Date::now())
                ->setData("mailchimp_sync_error", "")
                ->setData("mailchimp_sync_modified", 1)
                ->save();
        }
    }

    /**
     * Get Array with Merge Values for MailChimp.
     *
     * @param $object
     * @return array|null
     */
    public function getMergeVars($object)
    {
        //@Todo handle multi store
        $storeId = $object->getStoreId();
        $maps = unserialize(Mage::getModel('mailchimp/config')->getMapFields('stores', $storeId));
        $websiteId = Mage::getModel('core/store')->load($storeId)->getWebsiteId();
        $attrSetId = Mage::getResourceModel('eav/entity_attribute_collection')
            ->setEntityTypeFilter(1)
            ->addSetInfo()
            ->getData();
        $mergeVars = array();
        if($object instanceof Mage_Newsletter_Model_Subscriber) {
            $customer = Mage::getModel('customer/customer')->setWebsiteId($websiteId)->loadByEmail($object->getSubscriberEmail());
        } else {
            $customer = $object;
        }
        foreach ($maps as $map) {
            $customAtt = $map['magento'];
            $chimpTag = $map['mailchimp'];
            if ($chimpTag && $customAtt) {
                $key = strtoupper($chimpTag);
                foreach ($attrSetId as $attribute) {
                    if ($attribute['attribute_id'] == $customAtt) {
                        $attributeCode = $attribute['attribute_code'];
                        if ($customer->getId()) {
                            switch ($attributeCode) {
                                case 'email':
                                    break;
                                case 'default_billing':
                                case 'default_shipping':
                                    $addr = explode('_', $attributeCode);
                                    $address = $customer->{'getPrimary' . ucfirst($addr[1]) . 'Address'}();
                                    if (!$address) {
                                        if ($customer->{'getDefault' . ucfirst($addr[1])}()) {
                                            $address = Mage::getModel('customer/address')->load($customer->{'getDefault' . ucfirst($addr[1])}());
                                        }
                                    }
                                    if ($address) {
                                        $street = $address->getStreet();
                                        $mergeVars[$key] = array(
                                            "addr1" => $street[0] ? $street[0] : "",
                                            "addr2" => count($street)>1 ? $street[1] : "",
                                            "city" => $address->getCity() ? $address->getCity() : "",
                                            "state" => $address->getRegion() ? $address->getRegion() : "",
                                            "zip" => $address->getPostcode() ? $address->getPostcode() : "",
                                            "country" => $address->getCountry() ? Mage::getModel('directory/country')->loadByCode($address->getCountry())->getName() : ""
                                        );
                                    }
                                    break;
                                case 'gender':
                                    if ($customer->getData($attributeCode)) {
                                        $genderValue = $customer->getData($attributeCode);
                                        if ($genderValue == 1) {
                                            $mergeVars[$key] = 'Male';
                                        } elseif ($genderValue == 2) {
                                            $mergeVars[$key] = 'Female';
                                        }
                                    }
                                    break;
                                case 'group_id':
                                    if ($customer->getData($attributeCode)) {
                                        $group_id = (int)$customer->getData($attributeCode);
                                        $customerGroup = Mage::helper('customer')->getGroups()->toOptionHash();
                                        $mergeVars[$key] = $customerGroup[$group_id];
                                    }
                                    break;
                                default:
                                    if($customer->getData($attributeCode)) {
                                        $mergeVars[$key] = $customer->getData($attributeCode);
                                    }
                                    break;
                            }
                        } else {
                            switch ($attributeCode) {
                                case 'group_id':
                                    $mergeVars[$key] = 'NOT LOGGED IN';
                                    break;
                                case 'store_id':
                                    $mergeVars[$key] = $storeId;
                                    break;
                                case 'website_id':
                                    $websiteId = Mage::getModel('core/store')->load($storeId)->getWebsiteId();
                                    $mergeVars[$key] = $websiteId;
                                    break;
                                case 'created_in':
                                    $storeCode = Mage::getModel('core/store')->load($storeId)->getCode();
                                    $mergeVars[$key] = $storeCode;
                                    break;
                                case 'firstname':
                                    if($object instanceof Mage_Newsletter_Model_Subscriber) {
                                        $firstName = $object->getSubscriberFirstname();
                                    } else {
                                        $firstName = $customer->getFirstname();
                                    }
                                    if ($firstName) {
                                        $mergeVars[$key] = $firstName;
                                    }
                                    break;
                                case 'lastname':
                                    if($object instanceof Mage_Newsletter_Model_Subscriber) {
                                        $lastName = $object->getSubscriberLastname();
                                    } else {
                                        $lastName = $customer->getLastname();
                                    }
                                    if ($lastName) {
                                        $mergeVars[$key] = $lastName;
                                    }
                            }
                        }
                    }
                }
                if ($customer->getId()) {
                    switch ($customAtt) {
                        case 'billing_company':
                        case 'shipping_company':
                            $addr = explode('_', $customAtt);
                            $address = $customer->{'getPrimary' . ucfirst($addr[0]) . 'Address'}();
                            if (!$address) {
                                if ($customer->{'getDefault' . ucfirst($addr[0])}()) {
                                    $address = Mage::getModel('customer/address')->load($customer->{'getDefault' . ucfirst($addr[0])}());
                                }
                            }
                            if ($address) {
                                $company = $address->getCompany();
                                if ($company) {
                                    $mergeVars[$key] = $company;
                                }
                            }
                            break;
                        case 'billing_telephone':
                        case 'shipping_telephone':
                            $addr = explode('_', $customAtt);
                            $address = $customer->{'getPrimary' . ucfirst($addr[0]) . 'Address'}();
                            if (!$address) {
                                if ($customer->{'getDefault' . ucfirst($addr[0])}()) {
                                    $address = Mage::getModel('customer/address')->load($customer->{'getDefault' . ucfirst($addr[0])}());
                                }
                            }
                            if ($address) {
                                $telephone = $address->getTelephone();
                                if ($telephone) {
                                    $mergeVars[$key] = $telephone;
                                }
                            }
                            break;
                        case 'billing_country':
                        case 'shipping_country':
                            $addr = explode('_', $customAtt);
                            $address = $customer->{'getPrimary' . ucfirst($addr[0]) . 'Address'}();
                            if (!$address) {
                                if ($customer->{'getDefault' . ucfirst($addr[0])}()) {
                                    $address = Mage::getModel('customer/address')->load($customer->{'getDefault' . ucfirst($addr[0])}());
                                }
                            }
                            if ($address) {
                                $countryCode = $address->getCountry();
                                if ($countryCode) {
                                    $countryName = Mage::getModel('directory/country')->loadByCode($countryCode)->getName();
                                    $mergeVars[$key] = $countryName;
                                }
                            }
                            break;
                        case 'billing_zipcode':
                        case 'shipping_zipcode':
                            $addr = explode('_', $customAtt);
                            $address = $customer->{'getPrimary' . ucfirst($addr[0]) . 'Address'}();
                            if (!$address) {
                                if ($customer->{'getDefault' . ucfirst($addr[0])}()) {
                                    $address = Mage::getModel('customer/address')->load($customer->{'getDefault' . ucfirst($addr[0])}());
                                }
                            }
                            if ($address) {
                                $zipCode = $address->getPostcode();
                                if ($zipCode) {
                                    $mergeVars[$key] = $zipCode;
                                }
                            }
                            break;
                    }
                }
            }
        }
        return (!empty($mergeVars)) ? $mergeVars : null;
    }

    public function setMergeVars($customer, $mergeVarsArray)
    {
        $storeId = $customer->getStoreId();
        $maps = unserialize(Mage::getModel('mailchimp/config')->getMapFields('stores', $storeId));
        $attrSetId = Mage::getResourceModel('eav/entity_attribute_collection')
        ->setEntityTypeFilter(1)
        ->addSetInfo()
        ->getData();
        try {
            foreach ($maps as $map) {
                $customAtt = $map['magento'];
                $chimpTag = $map['mailchimp'];
                if ($chimpTag && $customAtt) {
                    $key = strtoupper($chimpTag);
                    foreach ($attrSetId as $attribute) {
                        if ($attribute['attribute_id'] == $customAtt) {
                            $attributeCode = $attribute['attribute_code'];
                            switch ($attributeCode) {
                                case 'email':
                                    break;
                                case 'default_billing':
                                case 'default_shipping':
                                    if (array_key_exists($key, $mergeVarsArray)) {
                                        //@Todo handle address.
                                    }
                                    break;
                                case 'gender':
                                    if (array_key_exists($key, $mergeVarsArray)) {
                                        if (strtolower($mergeVarsArray[$key]) == 'male') {
                                            $customer->setData($attributeCode, 1);
                                        } elseif (strtolower($mergeVarsArray[$key]) == 'female') {
                                            $customer->setData($attributeCode, 2);
                                        }
                                    }
                                    break;
                                case 'group_id':
                                    if (array_key_exists($key, $mergeVarsArray)) {
                                        $customerGroups = Mage::helper('customer')->getGroups()->toOptionHash();
                                        $key = array_search($mergeVarsArray[$key], $customerGroups);
                                        if ($key !== false) {
                                            $customer->setData($attributeCode, $key);
                                        }
                                    }
                                    break;
                                default:
                                    if (array_key_exists($key, $mergeVarsArray)) {
                                        $customer->setData($attributeCode, $mergeVarsArray[$key]);
                                    }
                                    break;
                            }
                        }
                    }
                }
            }
        $customer->save();
        } catch (Exception $e) {
            Mage::logException($e);
            Mage::helper('mailchimp')->logError('SetMergeVars error for ' . $attributeCode . ': ' . $e->getMessage());
        }
    }

//    public function createGuestCustomer($guestId, $order) {
//        $guestCustomer = Mage::getModel('customer/customer')->setId($guestId);
//        foreach ($order->getData() as $key => $value) {
//            $keyArray = explode('_', $key);
//            if ($value && isset($keyArray[0]) && $keyArray[0] == 'customer') {
//                $guestCustomer->{'set' . ucfirst($keyArray[1])}($value);
//            }
//        }
//        return $guestCustomer;
//    }

    public function getOptin($scope, $scopeId) {
        if (Mage::getModel('mailchimp/config')->getCustomerOptIn($scope,  $scopeId)) {
            $optin = true;
        } else {
            $optin = false;
        }
        return $optin;
    }
}