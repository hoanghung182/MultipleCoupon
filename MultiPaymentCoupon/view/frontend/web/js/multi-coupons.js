define([
    'jquery',
    'jquery-ui-modules/widget'
], function ($) {
    'use strict';

    $.widget('mage.MultiPaymentCoupons', {
        options: {
            "couponCodeSelector": "#coupon_code",
            "removeCouponSelector": "#remove-coupon",
            "removeCouponValue": "#remove-coupon-value",
            "applyButton": "button.action.apply",
            "cancelButton": ".action.cancel"
        },

        _create: function () {
            var widget = this;
            var couponCodeElement = $(widget.options.couponCodeSelector);
            var removeCouponElement = $(widget.options.removeCouponSelector);
            var removeCouponValueElement = $(widget.options.removeCouponValue);
            var formElement = $('#discount-coupon-form');

            $(widget.options.applyButton).on('click', function (e) {
                e.preventDefault();
                couponCodeElement.attr('data-validate', '{required:true}');
                removeCouponElement.attr('value', '0').val(0);
                formElement.validation().submit();
            });

            $(widget.options.cancelButton).on('click', function (e) {
                e.preventDefault();
                couponCodeElement.removeAttr('data-validate');
                removeCouponElement.attr('value', '1').val(1);
                removeCouponValueElement.attr('value', $(this).data('value'));
                formElement.submit();
            });
        }
    });

    return $.mage.MultiPaymentCoupons;
});
