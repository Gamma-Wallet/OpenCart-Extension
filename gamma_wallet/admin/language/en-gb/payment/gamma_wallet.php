<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Gamma Wallet for OpenCart — the admin's texts (English).
$_['heading_title']             = 'Gamma Wallet';
$_['text_extension']            = 'Extensions';
$_['text_success']              = 'Settings saved, connection checked.';
$_['text_edit']                 = 'Gamma Wallet settings';
$_['text_gamma_wallet']         = '<img src="../extension/gamma_wallet/catalog/view/image/gamma-mark-20.png" alt="Gamma Wallet" title="Gamma Wallet" style="vertical-align:-4px"> Gamma Wallet';
$_['text_intro']                = 'Your customers earn a reward for every paid order and can settle an order with the store credits they hold at your shop, by scanning a QR code with the Gamma Wallet app.';

$_['text_connection']           = 'Connection';
$_['entry_token']               = 'Integration token';
$_['help_token']                = 'Create it in Gamma Business → Integrations, as the business owner. It starts with GWINT_ and is shown only once.';
$_['text_token_saved']          = 'A token is saved. Leave the field empty to keep it.';
$_['entry_remove_token']        = 'Remove the saved token (disconnects the shop)';
$_['entry_status_connection']   = 'Status';
$_['text_not_connected']        = 'Not connected. Paste your integration token above and save.';
$_['text_not_checked']          = 'Not checked yet.';
$_['text_connected']            = 'Connected';
$_['text_business']             = 'Business';
$_['text_currency']             = 'Currency';
$_['text_token']                = 'Token';
$_['text_days_left']            = '%d day(s) left';
$_['text_checked']              = 'Checked';
$_['button_check']              = 'Check again';
$_['error_no_reward_service']   = 'Gamma Wallet for OpenCart works only with a Reward service. Your business has no Reward service active in Gamma, so customers get no reward QR code and store credits are not offered at checkout. Activate a Reward service in Gamma Business.';
$_['error_currency']            = 'Your shop sells in %s but your Gamma business uses %s. Orders cannot be sent to Gamma until they match.';

$_['text_rewards']              = 'Rewards for paid orders';
$_['entry_rewards']             = 'Give customers a QR code to collect their reward for each paid order';
$_['entry_methods']             = 'Payment methods that earn a reward';
$_['text_paid_at_checkout']     = 'paid at checkout: the reward is given when the payment is confirmed';
$_['text_paid_later']           = 'paid later: the reward is emailed when you mark the order paid';
$_['help_methods']              = 'Paid at checkout (card and the like): the reward is given the moment the payment is confirmed, and its QR code is on the order success page and in the order email. Paid later (cash on delivery, bank transfer, cheque): nothing is paid when the order is placed, so the reward is given only when you move the order to a paid status. The customer then receives an email of its own with the QR code. Orders settled with store credits never earn a reward.';
$_['entry_paid_statuses']       = 'Order statuses that mean the money is in';
$_['help_paid_statuses']        = 'An order entering one of these statuses earns its reward.';
$_['entry_reward_email']        = 'Put the reward QR code in the order confirmation email';

$_['text_credits']              = 'Store credits at checkout';
$_['entry_credits']             = 'Offer "Use Store Credits with Gamma" at checkout';
$_['help_credits']              = 'At checkout, the customer scans a QR code with Gamma Wallet and the whole order is settled from their store credits. The code is valid for 60 seconds. An order settled this way earns no reward.';
$_['entry_order_status']        = 'Status while waiting for the credits';
$_['entry_settled_status']      = 'Status once settled';
$_['entry_sort_order']          = 'Sort order';

$_['text_box_settled']          = 'Settled with store credits through Gamma Wallet.';
$_['text_box_waiting_credits']  = 'Waiting for the customer to settle it with store credits.';
$_['text_box_request']          = 'Request';
$_['text_box_no_reward_credits']= 'No reward is given for an order settled with store credits.';
$_['text_box_collected']        = 'Reward collected';
$_['text_box_waiting_reward']   = 'Waiting for the customer to collect the reward';
$_['text_box_bill']             = 'Bill';
$_['text_box_no_service']       = 'No reward: your business has no Reward service active in Gamma.';
$_['text_box_no_reward_method'] = 'This order earns no reward (its payment method does not earn one).';
$_['text_box_pay_later']        = 'Paid later: when you move the order to a paid status, the reward QR code is created and the customer receives it in an email of its own.';
$_['text_box_not_yet']          = 'Not sent to Gamma Wallet yet. It is sent when the order reaches a paid status.';
$_['text_box_resend']           = 'Send the reward QR code to the customer';
$_['text_box_sent']             = 'The reward QR code was emailed to the customer.';
$_['text_box_failed']           = 'The reward QR code could not be sent: the order is not eligible for a reward yet, or Gamma could not be reached.';

$_['error_permission']          = 'Warning: You do not have permission to modify Gamma Wallet!';
$_['error_statuses']            = 'Choose the status while waiting for the credits and the status once settled.';
$_['text_awaiting_status']      = 'Awaiting Gamma store credits';
$_['text_card_title']           = 'Gamma Wallet';
$_['help_data_sent']            = 'What is sent to Gamma: every request carries your integration token and the extension version. For each order that earns a reward or uses store credits: an order reference (your order number with a short tag for your shop), the total, the currency, the order date and the platform name (OpenCart). About once an hour the connection is checked. No names, addresses, email addresses or products. The reward QR code image on the order pages and in the emails is loaded from integration.gamma-wallet.com, so the customer\'s browser or email app contacts that server when it shows it.';

$_['text_mail_subject']      = 'Your reward from %s (order %s)';
$_['text_mail_title']        = 'Your reward is ready';
$_['text_mail_hello']        = 'Hello %s,';
$_['text_mail_body']         = 'Thank you, we have received your payment. Your order %s earned a reward: scan the code below with the Gamma Wallet app to add it to your wallet.';
$_['text_open']              = 'On your phone? Open in Gamma Wallet';
$_['text_qr_alt']            = 'Reward QR code';

$_['error_api_0388']         = 'Gamma does not recognise this token. Copy it again from Gamma Business → Integrations.';
$_['error_api_0389']         = 'This token was disabled or replaced. Create a new one in Gamma Business → Integrations.';
$_['error_api_0390']         = 'This token has expired. Create a new one in Gamma Business → Integrations.';
$_['error_api_0393']         = 'The business this token belongs to is not available in Gamma.';
$_['error_api_unreachable']  = 'Gamma could not be reached. Check that this server can make outgoing HTTPS connections.';
$_['error_api_busy']         = 'Too many requests to Gamma. Try again in a minute.';
