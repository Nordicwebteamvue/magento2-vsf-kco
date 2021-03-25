<?php

namespace Kodbruket\VsfKco\Controller\Order;

use Klarna\Core\Api\OrderRepositoryInterface;
use Klarna\Core\Helper\ConfigHelper;
use Klarna\Core\Model\OrderFactory;
use Klarna\Ordermanagement\Api\ApiInterface;
use Klarna\Ordermanagement\Model\Api\Ordermanagement;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\QuoteIdMaskFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface as MageOrderRepositoryInterface;
use Psr\Log\LoggerInterface;
use Magento\Framework\DataObject;
use Magento\Quote\Api\Data\CartInterface;
use Kodbruket\VsfKco\Model\Klarna\DataTransform\Request\Address as AddressDataTransform;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\CustomerFactory;
use Kodbruket\VsfKco\Helper\Data as VsfKcoHelper;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;


/**
 * Class Push
 * @package Kodbruket\VsfKco\Controller\Order
 */
class Push extends Action implements CsrfAwareActionInterface
{
    const EVENT_NAME = 'Push';

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var OrderFactory
     */
    private $klarnaOrderFactory;

    /**
     * @var OrderRepositoryInterface
     */
    private $klarnaOrderRepository;

    /**
     * @var CartRepositoryInterface
     */
    private $cartRepository;

    /**
     * @var ApiInterface
     */
    private $orderManagement;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var QuoteIdMaskFactory
     */
    private $quoteIdMaskFactory;

    /**
     * @var MageOrderRepositoryInterface
     */
    private $mageOrderRepository;

    /**
     * @var AddressDataTransform
     */
    private $addressDataTransform;

    /**
     * @var CustomerRepositoryInterface
     */
    private $customerRepository;

    /**
     * @var CustomerFactory
     */
    private $customerFactory;

    /**
     * @var VsfKcoHelper
     */
    private $helper;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * Push constructor.
     * @param Context $context
     * @param LoggerInterface $logger
     * @param OrderFactory $klarnaOrderFactory
     * @param OrderRepositoryInterface $klarnaOrderRepository
     * @param CartRepositoryInterface $cartRepository
     * @param Ordermanagement $orderManagement
     * @param StoreManagerInterface $storeManager
     * @param QuoteIdMaskFactory $quoteIdMaskFactory
     * @param MageOrderRepositoryInterface $mageOrderRepository
     * @param AddressDataTransform $addressDataTransform
     * @param CustomerRepositoryInterface $customerRepository
     * @param CustomerFactory $customerFactory
     * @param EmailHelper $emailHelper
     * @param VsfKcoHelper $helper
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        Context $context,
        LoggerInterface $logger,
        OrderFactory $klarnaOrderFactory,
        OrderRepositoryInterface $klarnaOrderRepository,
        CartRepositoryInterface $cartRepository,
        Ordermanagement $orderManagement,
        StoreManagerInterface $storeManager,
        QuoteIdMaskFactory $quoteIdMaskFactory,
        MageOrderRepositoryInterface $mageOrderRepository,
        AddressDataTransform $addressDataTransform,
        CustomerRepositoryInterface $customerRepository,
        CustomerFactory $customerFactory,
        VsfKcoHelper $helper,
        ScopeConfigInterface $scopeConfig
    )
    {
        $this->logger = $logger;
        $this->klarnaOrderFactory = $klarnaOrderFactory;
        $this->klarnaOrderRepository = $klarnaOrderRepository;
        $this->cartRepository = $cartRepository;
        $this->orderManagement = $orderManagement;
        $this->storeManager = $storeManager;
        $this->quoteIdMaskFactory = $quoteIdMaskFactory;
        $this->mageOrderRepository = $mageOrderRepository;
        $this->addressDataTransform = $addressDataTransform;
        $this->customerRepository = $customerRepository;
        $this->customerFactory = $customerFactory;
        $this->helper = $helper;
        $this->scopeConfig = $scopeConfig;

        parent::__construct(
            $context
        );
    }

    /**
     * @return \Magento\Framework\App\ResponseInterface|\Magento\Framework\Controller\ResultInterface|void
     * @throws \Klarna\Core\Exception
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function execute()
    {
        $klarnaOrderId = $this->getRequest()->getParam('id');

        $this->logger->info('Pushing Klarna Order Id: ' . $klarnaOrderId);

        $store = $this->storeManager->getStore();

        if (!$klarnaOrderId) {
            echo 'Klarna Order ID is required';
            return;
        }
        $this->helper->trackEvent(self::EVENT_NAME, $klarnaOrderId, null, 'Pushing Klarna Order Id: ' . $klarnaOrderId);
        $klarnaOrder = $this->klarnaOrderRepository->getByKlarnaOrderId($klarnaOrderId);

        if ($klarnaOrder->getIsAcknowledged()) {
            $message = 'Error: Order ' . $klarnaOrderId . ' has been acknowledged';
            $this->helper->trackEvent(self::EVENT_NAME, $klarnaOrderId, null, $message);
            return;
        }

        $this->orderManagement->resetForStore($store, ConfigHelper::KCO_METHOD_CODE);

        $placedKlarnaOrder = $this->orderManagement->getPlacedKlarnaOrder($klarnaOrderId);

        $maskedId = $placedKlarnaOrder->getDataByKey('merchant_reference2');

        $quoteIdMask = $this->quoteIdMaskFactory->create()->load($maskedId, 'masked_id');

        $quoteId = $quoteIdMask->getQuoteId();

        if ((int)$quoteId == 0 && ctype_digit(strval($maskedId))) {
            $quoteId = (int)$maskedId;
        }

        $quote = $this->cartRepository->get($quoteId);

        if (!$quote->getId()) {
            $this->helper->trackEvent(self::EVENT_NAME, $klarnaOrderId, null, 'Quote is not existed in Magento');
        }

        /**
         *  Update shipping/billing address for quote.
         */
        $this->helper->trackEvent(self::EVENT_NAME, $klarnaOrderId, null, 'Start Updating Order Address From Pushing Klarna', 'Quote ID: ' . $quote->getId());
        $this->updateOrderAddresses($placedKlarnaOrder, $quote);
        $this->helper->trackEvent(self::EVENT_NAME, $klarnaOrderId, null, 'End Order Address Update From Pushing Klarna', 'Quote ID: ' . $quote->getId());

        /**
         * Create order and acknowledged
         */
        $this->helper->submitQuote($klarnaOrderId, $quote);
        exit;
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
     * @param DataObject $checkoutData
     * @param \Magento\Quote\Model\Quote|CartInterface $quote
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    private function updateOrderAddresses(DataObject $checkoutData, CartInterface $quote)
    {
        $this->logger->info('Start Updating Order Address From Pushing Klarna');

        if (!$checkoutData->hasBillingAddress() && !$checkoutData->hasShippingAddress()) {
            $this->logger->error(sprintf('Klarna order doesn\'t have billing and shipping address for quoteId %s', $quote->getId()));
            return;
        }
        $store = $this->storeManager->getStore();
        $shippingFromMerchantData = $this->scopeConfig->getValue('klarna/vsf/shipping_from_merchant_data', ScopeInterface::SCOPE_STORES, $store);
        $sameAsOther = $checkoutData->getShippingAddress()->getData() == $checkoutData->getBillingAddress();
        if ($shippingFromMerchantData){
            $sameAsOther = false;
            //TODO: проверка shipping pltcm
//            $sameAsOther = $quote->getShippingAddress() == $checkoutData->getBillingAddress();
        }

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


        $this->logger->info(sprintf('Updated Billing Address Data for QuoteId %s :', $quote->getId()) . print_r($quote->getBillingAddress()->getData(), true));

        /**
         * @todo  check use 'Billing as shiiping'
         */

        if ($shippingFromMerchantData) {
            $merchantData = $checkoutData->getMerchantData();
            if ($merchantData) {
                $decodesMerchantData = json_decode($merchantData, true);
                if ($decodesMerchantData && $decodesMerchantData['shipping']) {

                    /**
                     * $quote->setTotalsCollectedFlag(false);
                     **/

                    $quote->getShippingAddress()->addData(
                        $this->addressDataTransform->prepareMagentoAddress(new DataObject($decodesMerchantData['shipping']))
                    );

                    $this->logger->info(sprintf('Updated Shipping Address Data for QuoteId %s :', $quote->getId()) . print_r($quote->getShippingAddress()->getData(), true));


                    $this->logger->info('TEST: ' . print_r($this->addressDataTransform->prepareMagentoAddress(new DataObject($decodesMerchantData['shipping'])), 1));


                    $this->logger->info('VS: ' . print_r($this->addressDataTransform->prepareMagentoAddress($shippingAddress), 1));
                }
            }
        } elseif ($checkoutData->hasShippingAddress()) {

            $quote->setTotalsCollectedFlag(false);

            $quote->getShippingAddress()->addData(
                $this->addressDataTransform->prepareMagentoAddress($shippingAddress)
            );

            $this->logger->info(sprintf('Updated Shipping Address Data for QuoteId %s :', $quote->getId()) . print_r($quote->getShippingAddress()->getData(), true));
        }

        $this->logger->info('End Updating Order Address From Pushing Klarna');
    }
}
