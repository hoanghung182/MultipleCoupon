<?php
declare(strict_types=1);

namespace HungHoang\MultiPaymentCoupon\Controller\Cart;

use Exception;
use Fwc\SalesExtended\Helper\Config as FwcHelper;
use Magento\Checkout\Helper\Cart;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Framework\Escaper;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\SalesRule\Model\CouponFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\SalesRule\Api\RuleRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Class CouponPost
 *
 * @package HungHoang\MultiPaymentCoupon\Controller\Cart
 */
class CouponPost extends \Magento\Checkout\Controller\Cart\CouponPost
{
    const IS_PAYMENT = 'is_payment';
    const TO_BE_CANCELED = 'to_be_canceled';

    /**
     * @var FwcHelper
     */
    protected $fwcHelper;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var Escaper
     */
    protected $escaper;

    /**
     * @var RuleRepositoryInterface
     */
    protected $ruleRespository;

    /**
     * @codeCoverageIgnore
     */
    public function __construct(
        Context $context,
        ScopeConfigInterface $scopeConfig,
        Session $checkoutSession,
        StoreManagerInterface $storeManager,
        Validator $formKeyValidator,
        \Magento\Checkout\Model\Cart $cart,
        CouponFactory $couponFactory,
        CartRepositoryInterface $quoteRepository,
        FwcHelper $fwcHelper,
        LoggerInterface $logger,
        Escaper $escaper,
        RuleRepositoryInterface $ruleRepository
    ) {
        $this->fwcHelper = $fwcHelper;
        $this->escaper = $escaper;
        $this->logger = $logger;
        $this->ruleRespository = $ruleRepository;
        parent::__construct(
            $context,
            $scopeConfig,
            $checkoutSession,
            $storeManager,
            $formKeyValidator,
            $cart,
            $couponFactory,
            $quoteRepository
        );
    }

    /**
     * Initialize coupon
     *
     * @return Redirect
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function execute(): Redirect
    {
        $isRemove = $this->getRequest()->getParam('remove') == 1;
        $couponCode = $this->getRequest()->getParam('coupon_code');
        $couponCode = $isRemove ? '' : trim($couponCode);
        $cartQuote = $this->cart->getQuote();
        $existingCouponCode = $cartQuote->getCouponCode();

        $codeLength = strlen($couponCode);
        if (!$codeLength && !strlen($existingCouponCode)) {
            return $this->_goBack();
        }

        try {
            $isCodeLengthValid = $codeLength && $codeLength <= Cart::COUPON_CODE_MAX_LENGTH;
            $itemsCount = $cartQuote->getItemsCount();
            $validateResult = $this->validateCouponCode($cartQuote, $couponCode);
            $couponCodeToApply = $couponCode;
            $isCanceled = self::TO_BE_CANCELED == $validateResult;
            if ($isCanceled) {
                $existingCouponCodeArray = explode(',', $existingCouponCode);
                $couponCodeToApply = implode(
                    ',',
                    array_diff(
                        $existingCouponCodeArray,
                        [$this->getRequest()->getParam('removeCouponValue')]
                    )
                );
            }
            $isPayment = self::IS_PAYMENT == $validateResult;
            if ($isPayment) {
                $couponCodeToApply = $existingCouponCode . ',' . $couponCode;
            }

            $coupon = $this->couponFactory->create();
            $coupon->load($couponCode, 'code');
            $ruleId = $coupon->getRuleId();
            $successMessage = __('You used coupon code "%1".', $this->escaper->escapeHtml($couponCode));
            if ($itemsCount) {
                $totalPaymentAmount = 0;
                $totalPaymentBaseAmount = 0;
                if ($isPayment) {
                    foreach ($cartQuote->getItems() as $item) {
                        $totalPaymentAmount += $item->getData('payment_coupon_amount');
                        $totalPaymentBaseAmount += $item->getData('payment_coupon_base_amount');
                    }
                    $couponCodeAmount = $this->ruleRespository->getById($ruleId);
                    $totalPaymentAmount += $couponCodeAmount->getDiscountAmount();
                    $totalPaymentBaseAmount += $couponCodeAmount->getDiscountAmount();
                }
                $cartQuote->getShippingAddress()->setCollectShippingRates(true);
                $cartQuote->setCouponCode($couponCodeToApply)
                    ->collectTotals();
                $this->quoteRepository->save($cartQuote);
            } else {
                if ($isCodeLengthValid && $coupon->getId()) {
                    $this->_checkoutSession->getQuote()->setCouponCode($existingCouponCode)->save();
                    $this->messageManager->addSuccessMessage($successMessage);
                } else {
                    $this->messageManager->addErrorMessage(
                        __('The coupon code "%1" is not valid.', $this->escaper->escapeHtml($couponCode))
                    );
                }
            }

            if ($isCanceled) {
                $this->messageManager->addSuccessMessage(__('You canceled the coupon code.'));
                return $this->_goBack();
            }
            if (in_array($couponCode, explode(',', $cartQuote->getCouponCode()))) {
                $this->messageManager->addSuccessMessage($successMessage);
            } else {
                $this->messageManager->addErrorMessage(__('We cannot apply the coupon code.'));
            }
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (Exception $e) {
            $this->messageManager->addErrorMessage(__('We cannot apply the coupon code.'));
            $this->logger->critical($e);
        }

        return $this->_goBack();
    }

    /**
     * @param        $quote
     * @param string $couponCode
     *
     * @return Redirect|string
     */
    private function validateCouponCode($quote, string $couponCode)
    {
        $couponToCancel = $this->getRequest()->getParam('removeCouponValue');
        if (strlen($couponToCancel)) {
            return self::TO_BE_CANCELED;
        }

        $codeLength = strlen($couponCode);
        $isCodeLengthValid = $codeLength && $codeLength <= Cart::COUPON_CODE_MAX_LENGTH;
        $existingCouponCode = $quote->getCouponCode();
        $existingCouponCodeArray = explode(',', $existingCouponCode);
        $coupon = $this->couponFactory->create();
        $coupon->load($couponCode, 'code');
        if (!($isCodeLengthValid && $coupon->getId())
            || in_array($couponCode, $existingCouponCodeArray)
        ) {
            $this->messageManager->addErrorMessage(
                __('The coupon code "%1" is not valid.', $this->escaper->escapeHtml($couponCode))
            );
            return $this->_goBack();
        }

        $hasExistingCoupon = strlen($existingCouponCode);
        if (!$hasExistingCoupon) {
            return $couponCode;
        }

        if ($this->fwcHelper->isPaymentVoucher($couponCode)) {
            return self::IS_PAYMENT;
        }

        $this->messageManager->addErrorMessage(__('Only one discount coupon is applied.'));
        return $this->_goBack();
    }
}
