<?php
// Heading
$_['heading_title']             = 'Monokulo (Monero)';

// Text
$_['text_extension']            = 'Extensions';
$_['text_success']              = 'Success: you have saved the Monokulo settings.';
$_['text_edit']                 = 'Monokulo settings';
$_['text_order_statuses']       = 'Order statuses';
$_['text_order_statuses_help']  = 'Where an order goes as its Monero payment progresses. Monokulo announces each change; OpenCart then does what it does on any status change (stock, emails, the customer\'s account page).';
$_['text_stores']               = 'Stores';
$_['text_stores_help']          = 'Each store connects to a store in your Monokulo, found by the store\'s web address. Stores that share an address share one connection.';
$_['text_connected_as']         = 'Connected as %s since %s';
$_['text_not_connected']        = 'Not connected';
$_['text_reconnect_needed']     = 'Connect again: this store was connected by an older version of this extension, and Monero is hidden at checkout until it is.';
$_['text_address_first']        = 'Save your Monokulo address first.';
$_['text_all_zones']            = 'All zones';
$_['text_connected']            = 'Your store is connected. Monero is now offered at checkout.';

// Button
$_['button_connect']            = 'Connect';
$_['button_reconnect']          = 'Connect again';

// Entry
$_['entry_address']             = 'Monokulo address';
$_['help_address']              = 'Your Monokulo\'s public address, as your browser opens it (https://pay.example.com).';
$_['entry_pending_status']      = 'Order placed';
$_['help_pending_status']       = 'When the customer is sent to pay. The payment link goes into the order history and confirmation email.';
$_['entry_waiting_status']      = 'Payment seen';
$_['help_waiting_status']       = 'A payment is on its way and waiting for confirmations.';
$_['entry_partial_status']      = 'Part paid';
$_['help_partial_status']       = 'Less than the amount due has arrived so far.';
$_['entry_paid_status']         = 'Paid';
$_['help_paid_status']          = 'Paid in full (or more). The customer is told.';
$_['entry_expired_status']      = 'Expired';
$_['help_expired_status']       = 'The payment window closed without full payment.';
$_['entry_geo_zone']            = 'Geo zone';
$_['entry_forward_errors']      = 'Send errors to Monokulo';
$_['help_forward_errors']       = 'Also sends this extension\'s warnings and errors to your Monokulo, when the store there accepts client logs. They are always written to storage/logs/monokulo.log.';
$_['entry_status']              = 'Status';
$_['entry_sort_order']          = 'Sort order';

// Error
$_['error_permission']          = 'Warning: you do not have permission to change the Monokulo settings.';
$_['error_address']             = 'Enter an http or https address, such as https://pay.example.com.';
$_['error_status']              = 'Choose an order status.';
$_['error_connect_address']     = 'Save your Monokulo address before connecting.';
$_['error_connect_permission']  = 'You do not have permission to connect stores.';
$_['error_connect_host']        = 'The connect flow came back to a different store than it started from. Please connect again from this page.';
$_['error_connect_finish']      = 'Monokulo did not hand over the store\'s keys. Please connect again; the log says why.';
$_['error_connect_unavailable'] = 'Your Monokulo cannot connect shops yet: its operator has not set its public address.';
$_['error_connect_unreachable'] = 'Your Monokulo could not be reached from this shop\'s server. Check the address, then connect again.';
