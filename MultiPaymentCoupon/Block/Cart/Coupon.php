<?php
declare(strict_types=1);

namespace HungHoang\MultiPaymentCoupon\Block\Cart;

use Magento\Checkout\Block\Cart\AbstractCart;

/**
 * Class Coupon
 *
 * @package HungHoang\MultiPaymentCoupon\Block\Cart
 */
class Coupon extends AbstractCart
{
    /**
     * @return array
     */
    public function getCouponCodes(): array
    {
        if (!$this->getQuote()->getCouponCode()) {
            return [];
        }
        return explode(",", $this->getQuote()->getCouponCode());
    }
}
