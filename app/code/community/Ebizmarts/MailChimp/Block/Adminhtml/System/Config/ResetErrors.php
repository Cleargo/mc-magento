<?php
/**
 * mc-magento Magento Component
 *
 * @category Ebizmarts
 * @package mc-magento
 * @author Ebizmarts Team <info@ebizmarts.com>
 * @copyright Ebizmarts (http://ebizmarts.com)
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 * @date: 5/27/16 1:02 PM
 * @file: ResetProducts.php
 */
class Ebizmarts_MailChimp_Block_Adminhtml_System_Config_ResetErrors
    extends Mage_Adminhtml_Block_System_Config_Form_Field
{
    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('ebizmarts/mailchimp/system/config/reseterrors.phtml');
    }

    /**
     * @param Varien_Data_Form_Element_Abstract $element
     * @return string
     */
    protected function _getElementHtml(Varien_Data_Form_Element_Abstract $element)
    {
        return $this->_toHtml();
    }

    /**
     * @return mixed
     */
    public function getButtonHtml()
    {
        $button = $this->getLayout()->createBlock('adminhtml/widget_button')
            ->setData(
                array(
                'id' => 'reseterrors_button',
                'label' => $this->helper('mailchimp')->__('Reset Local Errors'),
                'onclick' => 'javascript:reseterrors(); return false;'
                )
            );

        return $button->toHtml();
    }

    /**
     * @return mixed
     */
    public function getAjaxCheckUrl()
    {
        $scopeString = Mage::helper('mailchimp')->getScopeString();
        return Mage::helper('adminhtml')->getUrl('adminhtml/ecommerce/resetLocalErrors', array('scope' => $scopeString));
    }

}