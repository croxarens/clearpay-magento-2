/**
 * Magento 2 extensions for Clearpay Payment
 *
 * @author Clearpay
 * @copyright 2016-2021 Clearpay https://www.clearpay.com
 */
/*browser:true*/
/*global define*/
define(['jquery'],
 function($) {
    'use strict';
    return  {
        redirectToClearpay: function (data) {
          AfterPay.redirect({
				token: data.token
			});
        }
    }

});