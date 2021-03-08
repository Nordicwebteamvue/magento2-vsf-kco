<?php
namespace Kodbruket\VsfKco\Controller\Order;

use Klarna\Core\Api\OrderRepositoryInterface;
use Klarna\Core\Model\OrderFactory;
use Kodbruket\VsfKco\Model\ExtensionConstants;
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
     * @var OrderFactory
     */
    private $klarnaOrderFactory;

    /**
     * @var OrderRepositoryInterface
     */
    private $klarnaOrderRepository;

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
     * @param OrderFactory $klarnaOrderFactory
     * @param OrderRepositoryInterface $klarnaOrderRepository
     * @param QuoteIdMaskFactory $quoteIdMaskFactory
     * @param StoreManagerInterface $storeManager
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        Context $context,
        LoggerInterface $logger,
        CartRepositoryInterface $cartRepository,
        OrderFactory $klarnaOrderFactory,
        OrderRepositoryInterface $klarnaOrderRepository,
        QuoteIdMaskFactory $quoteIdMaskFactory,
        StoreManagerInterface $storeManager,
        ScopeConfigInterface $scopeConfig
    ) {
        parent::__construct(
            $context
        );
        $this->logger = $logger;
        $this->cartRepository = $cartRepository;
        $this->klarnaOrderFactory = $klarnaOrderFactory;
        $this->klarnaOrderRepository = $klarnaOrderRepository;
        $this->quoteIdMaskFactory = $quoteIdMaskFactory;
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
    }

    /**
     * @return ResponseInterface|\Magento\Framework\Controller\Result\Redirect|\Magento\Framework\Controller\ResultInterface
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function execute()
    {
        $this->logger->info('Validate: start');
        if (!$this->getRequest()->isPost()) {
            $this->logger->info('Validate: No post request');
            return $this->setValidateFailedResponse();
        }

        $shippingMethodCode = null;
        $shippingDescription = "Shipping";

        $klarnaOderId = $this->getKlarnaOrderId();

        $quote = $this->cartRepository->get($this->getQuoteId());
        if (!$quote->getId() || !$quote->hasItems() || $quote->getHasError()) {
            $this->logger->info('Validate: invalid magento quote');
            return $this->setValidateFailedResponse();
        }

        try {
            $checkoutData = $this->getKlarnaRequestData();

            $this->logger->info('Input request :' . print_r($checkoutData->toArray(), true));

            /**
             *  Load shipping method from Klarna request
             */
            if ($shippingMethod = $checkoutData->getData('selected_shipping_option')) {

                $shippingMethodString = json_encode($shippingMethod, JSON_UNESCAPED_UNICODE);

                $quote->setExtShippingInfo($shippingMethodString);

                if (empty($shippingMethod) || !(array_key_exists('carrier', $shippingMethod['delivery_details']) && array_key_exists('class', $shippingMethod['delivery_details']))) {
                    $shippingMethodCode = $shippingMethod['id'];
                } else {
                    $shippingMethodCode = $this->getShippingFromKSSCarrierClass($shippingMethod['delivery_details']['carrier'] . '_' . $shippingMethod['delivery_details']['class']);
                }

                $shippingDescription = $shippingMethod['name'];
            } else {
                if ($shippingMethod = $this->getShippingMedthodFromOrderLines($checkoutData)) {
                    $shippingMethodCode = $shippingMethod['reference'];
                }
            }

            $store = $this->storeManager->getStore();
            $paymentMethodCode = $this->scopeConfig->getValue('klarna/vsf/payment_method_code', ScopeInterface::SCOPE_STORES, $store);


            $quote->setData(ExtensionConstants::FORCE_ORDER_PLACE, true);
            $quote->getShippingAddress()->setPaymentMethod($paymentMethodCode);
            $payment = $quote->getPayment();
            $payment->importData(['method' => $paymentMethodCode]);
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

            /**
             *  set selectedShippingMethod from Klarna to Magento2
             */
            if (isset($shippingMethodCode)) {
                $shippingMethodCode = $this->convertShippingMethodCode($shippingMethodCode);
                $quote->getShippingAddress()
                    ->setShippingMethod($shippingMethodCode)
                    ->setShippingDescription($shippingDescription)
                    ->setCollectShippingRates(true)
                    ->collectShippingRates();
            }

            $quote->setTotalsCollectedFlag(false)
                ->collectTotals()
                ->save();

            return $this->resultFactory->create(ResultFactory::TYPE_RAW)->setHttpResponseCode(200);
        } catch (\Exception $exception) {
            $this->logger->critical('validation save kco Order' . $exception->getMessage());
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
        $quoteId = $quoteIdMask->getQuoteId();
        if( (int)$quoteId==0 && ctype_digit(strval($mask))){
            $quoteId = (int)$mask;
        }
        return $quoteId;
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

    /**
     * @param $carrierClass
     * @return string
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
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

    /**
     * @param $shippingCode
     * @return string
     */
    private function convertShippingMethodCode($shippingCode)
    {
        if(!strpos(shippingCode, '_')) {
            return $shippingCode === 'bestway' ? 'tablerate_bestway' : $shippingCode .'_'.$shippingCode;
        }
        return $shippingCode;
    }
}
