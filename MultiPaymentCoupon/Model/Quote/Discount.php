<?php
declare(strict_types=1);

namespace HungHoang\MultiPaymentCoupon\Model\Quote;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\Data\ShippingAssignmentInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address\Total;
use Magento\Quote\Model\Quote\Item;
use Zend_Db_Select_Exception;
use Zend_Validate_Exception;

/**
 * Class Discount
 *
 * @package HungHoang\MultiPaymentCoupon\Model\Quote
 */
class Discount extends \Magento\SalesRule\Model\Quote\Discount
{
    /**
     * Collect address discount amount
     *
     * @param Quote $quote
     * @param ShippingAssignmentInterface $shippingAssignment
     * @param Total $total
     * @return $this
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function collect(
        Quote $quote,
        ShippingAssignmentInterface $shippingAssignment,
        Total $total
    ): Discount {
        try {
            $store = $this->storeManager->getStore($quote->getStoreId());
            $address = $shippingAssignment->getShipping()->getAddress();
            if ($quote->currentPaymentWasSet()) {
                $address->setPaymentMethod($quote->getPayment()->getMethod());
            }
            $this->calculator->reset($address);
            $items = $shippingAssignment->getItems();
            if (!count($items)) {
                return $this;
            }

            // Extract potential multiple coupons.
            $multipleCoupons = array_unique(explode(',', $quote->getCouponCode()));

            // Loop on each coupon and apply it. Code inside the loop is a copy-paste of the parent::collect().
            foreach ($multipleCoupons as $couponCodeValue) {
                $eventArgs = [
                    'website_id' => $store->getWebsiteId(),
                    'customer_group_id' => $quote->getCustomerGroupId(),
                    'coupon_code' => $couponCodeValue,
                ];
                $this->calculator->init($store->getWebsiteId(), $quote->getCustomerGroupId(), $couponCodeValue);
                $this->calculator->initTotals($items, $address);

                $address->setDiscountDescription('');
                $items = $this->calculator->sortItemsByPriority($items, $address);

                /** @var Item $item */
                foreach ($items as $item) {
                    if ($item->getNoDiscount() || !$this->calculator->canApplyDiscount($item)) {
                        $item->setDiscountAmount(0);
                        $item->setBaseDiscountAmount(0);

                        // ensure my children are zeroed out
                        if ($item->getHasChildren() && $item->isChildrenCalculated()) {
                            foreach ($item->getChildren() as $child) {
                                $child->setDiscountAmount(0);
                                $child->setBaseDiscountAmount(0);
                            }
                        }
                        continue;
                    }
                    // to determine the child item discount, we calculate the parent
                    if ($item->getParentItem()) {
                        continue;
                    }

                    $eventArgs['item'] = $item;
                    $this->eventManager->dispatch('sales_quote_address_discount_item', $eventArgs);

                    if ($item->getHasChildren() && $item->isChildrenCalculated()) {
                        $this->calculator->process($item);
                        foreach ($item->getChildren() as $child) {
                            $eventArgs['item'] = $child;
                            $this->eventManager->dispatch('sales_quote_address_discount_item', $eventArgs);
                            $this->aggregateItemDiscount($child, $total);
                        }
                    } else {
                        $this->calculator->process($item);
                        $this->aggregateItemDiscount($item, $total);
                    }
                }

                $this->calculator->prepareDescription($address);
                $total->setDiscountDescription($address->getDiscountDescription());
                $total->setSubtotalWithDiscount($total->getSubtotal() + $total->getDiscountAmount());
                $total->setBaseSubtotalWithDiscount($total->getBaseSubtotal() + $total->getBaseDiscountAmount());
                $address->setDiscountAmount($total->getDiscountAmount());
                $address->setBaseDiscountAmount($total->getBaseDiscountAmount());
            }
        } catch (NoSuchEntityException | Zend_Db_Select_Exception | Zend_Validate_Exception $e) {

        }
        return $this;
    }
}
