<?php
namespace Kodbruket\VsfKco\Block\Adminhtml\Config;

class Carrier extends \Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray {

    /**
     * @var array
     */
    protected $_columns = [];

    /**
     * @var
     */
    protected $options;

    /**
     * @var bool
     */
    protected $_addAfter = false;

    /**
     * @var
     */
    protected $_addButtonLabel;

    /**
     *
     */
    protected function _construct() {
        parent::_construct();
        $this->_addButtonLabel = __('Add more');
    }

    /**
     * @return \Magento\Framework\View\Element\BlockInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function getOptionsToRender() {
        if ( !$this->options ) {
            $this->options = $this->getLayout()
                ->createBlock(
                    '\Kodbruket\VsfKco\Block\Adminhtml\Config\CarrierOptions',
                    '',
                    ['data' => ['is_render_to_js_template' => true]]
                );
        }
        return $this->options;
    }

    /**
     * @param \Magento\Framework\DataObject $row
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function _prepareArrayRow(\Magento\Framework\DataObject $row) {

        $options = [];

        $shippingMethod = $row->getData('shipping_method');

        if( $shippingMethod )
        {
            $options['option_' . $this->getOptionsToRender()->calcOptionHash($shippingMethod)]
                = 'selected="selected"';
        }

        $row->setData('option_extra_attrs', $options);
    }

    /**
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function _prepareToRender() {
        $this->addColumn(
            'shipping_method',
            [
                'label' => __('Shipping Method'),
                'renderer' => $this->getOptionsToRender(),
            ]
        );
        $this->addColumn(
            'kss_carrier',
            [
                'label' => __('KSS Carrier'),
            ]
        );
        $this->_addAfter = false;
        $this->_addButtonLabel = __('Add More');
    }
}
