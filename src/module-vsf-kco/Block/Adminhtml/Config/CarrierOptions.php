<?php
namespace Kodbruket\VsfKco\Block\Adminhtml\Config;

/**
 * Class CarrierOptions
 * @package Kodbruket\VsfKco\Block\Adminhtml\Config
 */
class CarrierOptions extends \Magento\Framework\View\Element\Html\Select
{

    /**
     * CarrierOptions constructor.
     * @param \Magento\Framework\View\Element\Context $context
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\View\Element\Context $context,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return string
     */
    public function _toHtml()
    {
        // Fetch object manager
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();

        // Fetch all active shipping methods
        $activeShipping = $objectManager->create('Magento\Shipping\Model\Config')->getAllCarriers();

        if (!$this->getOptions()) {
            foreach ($activeShipping as $code => $method) {
                foreach ($method->getAllowedMethods() as $suffixCode => $name) {
                    $generatedCode = $code . '_' . $suffixCode;
                    $this->addOption($generatedCode, $name . ' (' . $generatedCode . ')');
                }
            }
        }
        return parent::_toHtml();
    }

    /**
     * @param $value
     * @return mixed
     */
    public function setInputName($value)
    {
        return $this->setName($value);
    }
}
