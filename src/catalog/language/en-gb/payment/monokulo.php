<?php
// Heading
$_['heading_title']              = 'Monero (via Monokulo)';

// Text
$_['text_description']           = 'Pay with Monero. You pay on a secure Monokulo payment page next; the payment link is also in your order confirmation.';
$_['text_order_description']     = 'Order #%s';
$_['text_more']                  = ' + %d more';
$_['text_see_in_monokulo']       = ' In Monokulo: %s';
$_['text_connect_failed']        = 'This connect link has already been used or has expired. Start connecting again from the Monokulo payment settings in your OpenCart admin.';

// Button
$_['button_confirm']             = 'Pay with Monero';

// Order history
$_['note_started']               = 'Pay for this order with Monero here: %s';
$_['note_waiting']               = 'Monokulo: a payment was seen and is waiting for confirmations.';
$_['note_partial']               = 'Monokulo: part of the amount due has arrived. The order is not paid in full yet.';
$_['note_paid_customer']         = 'Your Monero payment has arrived. Thank you!';
$_['note_paid']                  = 'Monokulo: paid in full.';
$_['note_overpaid']              = 'Monokulo: paid, with more than the amount due. The excess is not refunded automatically: refund it yourself if needed.';
$_['note_expired']               = 'Monokulo: the payment window closed without full payment. Any funds received stay at the payment address; refund them yourself if needed.';
$_['note_double_spend_detected'] = 'Monokulo: FRAUD ALERT. A payment to this order was double-spent and no longer counts (transaction %s). If this changed the order status, that is a separate entry. Check this order before sending anything.';
$_['note_double_spend_reversed'] = 'Monokulo: the earlier double-spend alert for this order (transaction %s) was wrong and is withdrawn. If this changed the order status, that is a separate entry.';
$_['note_earlier_request']       = 'This is about an earlier payment request for this order, not its newest: the order total may have changed since, so check the amount.';
$_['note_kept_paid']             = 'The order was already paid, so its status is left as it is: check it before doing anything else.';
$_['note_paid_again']            = 'The order was already paid, so this is more money than it needed: refund it from your wallet if needed.';
$_['note_not_current']           = 'The order\'s newest payment request decides its status, so it is left as it is.';
$_['note_unknown_transaction']   = 'unknown';

// Error
$_['error_order']                = 'Your order could not be found. Please start the checkout again.';
$_['error_payment_method']       = 'Monero is not the selected payment method.';
$_['error_not_connected']        = 'Monero payments are not set up for this store yet. Please contact the store owner.';
$_['error_busy']                 = 'Monero payments are busy right now. Please wait a moment and try again.';
$_['error_unavailable']          = 'Monero payments are not available for this store right now. Please contact the store owner.';
$_['error_generic']              = 'The Monero payment could not be started. Please try again, or contact the store.';
$_['error_currency']             = 'Monero payments can\'t be taken in this order\'s currency right now. Please choose another currency or payment method.';
$_['error_no_onion']             = 'Monero can\'t be paid over Tor at this shop right now. Please contact the store owner.';
$_['error_unreachable']          = 'The Monero payment service could not be reached. Please try again shortly.';
