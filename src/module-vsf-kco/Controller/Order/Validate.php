<?php
namespace Kodbruket\VsfKco\Controller\Order;

use Klarna\Core\Api\OrderRepositoryInterface;
use Klarna\Core\Model\OrderFactory;
use Kodbruket\VsfKco\Model\ExtensionConstants;
use Kodbruket\VsfKco\Model\Klarna\DataTransform\Request\Address;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\CustomerFactory;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\DataObject;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\QuoteIdMask;
use Magento\Quote\Model\QuoteIdMaskFactory;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Class Validate
 * @package Kodbruket\VsfKco\Controller\Order
 */
class Validate extends Action implements CsrfAwareActionInterface
{

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var CartRepositoryInterface
     */
    private $cartRepository;

    /**
     * @var DataObject
     */
    private $klarnaRequestData;

    /**
     * @var Address
     */
    private $addressDataTransform;

    /**
     * @var OrderFactory
     */
    private $klarnaOrderFactory;

    /**
     * @var OrderRepositoryInterface
     */
    private $klarnaOrderRepository;

    /**
     * @var CustomerFactory
     */
    private $customerFactory;

    /**
     * @var CustomerRepositoryInterface
     */
    private $customerRepository;

    /**
     * @var QuoteIdMaskFactory
     */
    private $quoteIdMaskFactory;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * Validate constructor.
     * @param Context $context
     * @param LoggerInterface $logger
     * @param CartRepositoryInterface $cartRepository
     * @param Address $addressDataTransform
     * @param OrderFactory $klarnaOrderFactory
     * @param CustomerFactory $customerFactory
     * @param OrderRepositoryInterface $klarnaOrderRepository
     * @param CustomerRepositoryInterface $customerRepository
     * @param QuoteIdMaskFactory $quoteIdMaskFactory
     * @param StoreManagerInterface $storeManager
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        Context $context,
        LoggerInterface $logger,
        CartRepositoryInterface $cartRepository,
        Address $addressDataTransform,
        OrderFactory $klarnaOrderFactory,
        CustomerFactory $customerFactory,
        OrderRepositoryInterface $klarnaOrderRepository,
        CustomerRepositoryInterface $customerRepository,
        QuoteIdMaskFactory $quoteIdMaskFactory,
        StoreManagerInterface $storeManager,
        ScopeConfigInterface $scopeConfig
    ) {
        parent::__construct(
            $context
        );
        $this->logger = $logger;
        $this->cartRepository = $cartRepository;
        $this->addressDataTransform = $addressDataTransform;
        $this->klarnaOrderFactory = $klarnaOrderFactory;
        $this->klarnaOrderRepository = $klarnaOrderRepository;
        $this->customerFactory = $customerFactory;
        $this->customerRepository = $customerRepository;
        $this->quoteIdMaskFactory = $quoteIdMaskFactory;
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
    }

    /**
     * Execute action based on request and return result
     *
     * Note: Request will be added as operation argument in future
     *
     * @return \Magento\Framework\Controller\ResultInterface|ResponseInterface
     * @throws \Magento\Framework\Exception\NotFoundException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function execute()
    {
        $this->logger->info('Validate: start');
        if (!$this->getRequest()->isPost()) {
            $this->logger->info('Validate: No post request');
            return $this->setValidateFailedResponse();
        }

        $klarnaOderId = $this->getKlarnaOrderId();

        $quote = $this->cartRepository->get($this->getQuoteId());
        if (!$quote->getId() || !$quote->hasItems() || $quote->getHasError()) {
            $this->logger->info('Validate: invalid magento quote');
            return $this->setValidateFailedResponse();
        }

        try {
            $checkoutData = $this->getKlarnaRequestData();
            $this->logger->info('Input request :' . print_r($checkoutData->toArray(), true));
            if (!$quote->isVirtual()) {
                $this->logger->info('updating order addresses');
                $this->updateOrderAddresses($checkoutData, $quote);
                $shippingMethodCode = null;
                if ($shippingMethod = $checkoutData->getData('selected_shipping_option')) {
                    $shippingMethodString = json_encode($shippingMethod, JSON_UNESCAPED_UNICODE);
                    $quote->setExtShippingInfo($shippingMethodString);
                    $shippingMethodCode = $this->getShippingFromKSSCarrierClass($shippingMethod['delivery_details']['carrier'].'_'.$shippingMethod['delivery_details']['class']);
                    if (empty($shippingMethod)) $shippingMethodCode = $shippingMethod['id'];
                } else {
                    if ($shippingMethod = $this->getShippingMedthodFromOrderLines($checkoutData)) {
                        $shippingMethodCode = $shippingMethod['reference'];
                    }
                }
                if (isset($shippingMethodCode)) {
                    $this->logger->info('Shipping method code:' . $this->convertShippingMethodCode($shippingMethodCode));
                    $quote->getShippingAddress()
                        ->setShippingMethod($this->convertShippingMethodCode($shippingMethodCode))
                        ->setCollectShippingRates(true)
                        ->collectShippingRates()
                    ;
                }
            }
            $quote->setData(ExtensionConstants::FORCE_ORDER_PLACE, true);
            $quote->getShippingAddress()->setPaymentMethod(\Klarna\Kp\Model\Payment\Kp::METHOD_CODE);

            $payment = $quote->getPayment();
            $payment->importData(['method' => \Klarna\Kp\Model\Payment\Kp::METHOD_CODE]);
            $payment->setAdditionalInformation(ExtensionConstants::FORCE_ORDER_PLACE, true);
            $payment->setAdditionalInformation(ExtensionConstants::KLARNA_ORDER_ID, $klarnaOderId);

            $quote->reserveOrderId();
            $this->cartRepository->save($quote);

            /** @var \Klarna\Core\Model\Order $klarnaOrder */
            $klarnaOrder = $this->klarnaOrderFactory->create();
            $klarnaOrder->setData([
                'klarna_order_id' => $klarnaOderId,
                'reservation_id' => $klarnaOderId,
            ]);
            $this->klarnaOrderRepository->save($klarnaOrder);

            return $this->resultFactory->create(ResultFactory::TYPE_RAW)->setHttpResponseCode(200);
        } catch (\Exception $exception) {
            $this->logger->critical('validation save kco Order' . $exception->getMessage());
            $this->logger->critical('validation save kco Order' . $exception->getTraceAsString());
            return $this->setValidateFailedResponse();
        }
    }

    /**
     * @param string $message
     * @return \Magento\Framework\Controller\Result\Redirect
     */
    private function setValidateFailedResponse($message = null)
    {
        $store = $this->storeManager->getStore();
        $failedUrl = $this->scopeConfig->getValue('klarna/vsf/failed_link', ScopeInterface::SCOPE_STORES, $store);
        return $this->resultRedirectFactory->create()
            ->setHttpResponseCode(303)
            ->setStatusHeader(303, null, $message)
            ->setUrl($failedUrl);
    }

    /**
     * @return int
     */
    private function getKlarnaOrderId()
    {
        return $this->getKlarnaRequestData()->getData(
            'order_id'
        );
    }

    /**
     * @return DataObject
     */
    private function getKlarnaRequestData()
    {
        if (null === $this->klarnaRequestData) {
            /** @var \Magento\Framework\App\Request\Http $request */
            $request = $this->getRequest();
            $this->klarnaRequestData = new DataObject(
                json_decode($request->getContent(), true)
            );
        }

        return $this->klarnaRequestData;
    }

    /**
     * @return int
     */
    private function getQuoteId()
    {
        $mask = $this->getKlarnaRequestData()->getData(
            'merchant_reference2'
        );

        /** @var $quoteIdMask QuoteIdMask */
        $quoteIdMask = $this->quoteIdMaskFactory->create()->load($mask, 'masked_id');
        return $quoteIdMask->getQuoteId();
    }

    /**
     * @param DataObject $checkoutData
     * @param \Magento\Quote\Model\Quote|CartInterface $quote
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    private function updateOrderAddresses(DataObject $checkoutData, CartInterface $quote)
    {
        if (!$checkoutData->hasBillingAddress() && !$checkoutData->hasShippingAddress()) {
            return;
        }

        $sameAsOther = $checkoutData->getShippingAddress() == $checkoutData->getBillingAddress();
        $billingAddress = new DataObject($checkoutData->getBillingAddress());
        $billingAddress->setSameAsOther($sameAsOther);
        $shippingAddress = new DataObject($checkoutData->getShippingAddress());
        $shippingAddress->setSameAsOther($sameAsOther);

        if (!$quote->getCustomerId()) {
            $websiteId = $quote->getStore()->getWebsiteId();
            $customer = $this->customerFactory->create();
            $customer->setWebsiteId($websiteId);
            $customer->loadByEmail($billingAddress->getEmail());
            if (!$customer->getEntityId()) {
                $customer->setWebsiteId($websiteId)
                    ->setStore($quote->getStore())
                    ->setFirstname($billingAddress->getGivenName())
                    ->setLastname($billingAddress->getFamilyName())
                    ->setEmail($billingAddress->getEmail())
                    ->setPassword($billingAddress->getEmail());
                $customer->save();
            }
            $customer = $this->customerRepository->getById($customer->getEntityId());
            $quote->assignCustomer($customer);
        }

        $quote->getBillingAddress()->addData(
            $this->addressDataTransform->prepareMagentoAddress($billingAddress)
        );

        /**
         * @todo  check use 'Billing as shiiping'
         */
        if ($checkoutData->hasShippingAddress()) {
            $quote->setTotalsCollectedFlag(false);
            $quote->getShippingAddress()->addData(
                $this->addressDataTransform->prepareMagentoAddress($shippingAddress)
            );
        }
    }

    /**
     * @param DataObject $checkoutData
     * @return bool|mixed
     */
    private function getShippingMedthodFromOrderLines(DataObject $checkoutData)
    {
        $orderLines = $checkoutData->getData('order_lines');

        if (is_array($orderLines)) {
            foreach ($orderLines as $line) {
                if (isset($line['type']) && $line['reference'] && $line['type'] === 'shipping_fee') {
                    return $line;
                }
            }
        }
        return false;
    }

    private function getShippingFromKSSCarrierClass($carrierClass) {
        $store = $this->storeManager->getStore();
        $mappings = $this->scopeConfig->getValue('klarna/vsf/carrier_mapping', ScopeInterface::SCOPE_STORES, $store);
        if ($mappings) {
            $mappings = json_decode($mappings, true);
            foreach($mappings as $item) {
                if($item['kss_carrier'] == $carrierClass) {
                    return $item['shipping_method'];
                }
            }
        }
        return '';

    }

    /**
     * @param $shippingCode
     * @return string
     */
    private function convertShippingMethodCode($shippingCode)
    {
        if (!strpos($shippingCode, '_')) return $shippingCode . '_' . $shippingCode;
        return $shippingCode;
    }

    /**
     * Create CSRF validation exception
     * 
     * @param RequestInterface $request
     *
     * @return InvalidRequestException|null
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * Validate for CSRF
     * 
     * @param RequestInterface $request
     *
     * @return bool|null
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
