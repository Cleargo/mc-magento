<?php
/**
 * mc-magento Magento Component
 *
 * @category Ebizmarts
 * @package mc-magento
 * @author Ebizmarts Team <info@ebizmarts.com>
 * @copyright Ebizmarts (http://ebizmarts.com)
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 * @date: 8/4/16 8:28 PM
 * @file: List.php
 */
class Ebizmarts_MailChimp_Model_System_Config_Backend_Ecommerce extends Mage_Core_Model_Config_Data
{

    protected function _afterSave()
    {
        $groups = $this->getData('groups');
        $active = (isset($groups['general']['fields']['active']['value'])) ? $groups['general']['fields']['active']['value'] : null;
        if ($active === null) {
            $active = Mage::getModel('mailchimp/config')->getMailChimpEnabled($this->getScope(), $this->getScopeId());
        }
        $storeId = Mage::getModel('mailchimp/config')->getMCStoreId($this->getScope(), $this->getScopeId());

        if ($this->isValueChanged() && $active && !$storeId && $this->getValue()) {
            Mage::helper('mailchimp')->createStore($this->getValue());
        }
    }
}