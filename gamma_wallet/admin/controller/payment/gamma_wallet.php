<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace Opencart\Admin\Controller\Extension\GammaWallet\Payment;

require_once DIR_EXTENSION . 'gamma_wallet/system/library/gamma_wallet.php';

/**
 * Gamma Wallet for OpenCart — the admin side: the settings page (Extensions → Payments → Gamma
 * Wallet), install / uninstall, and the Gamma Wallet box on the order page.
 */
class GammaWallet extends \Opencart\System\Engine\Controller {
	private const ROUTE = 'extension/gamma_wallet/payment/gamma_wallet';

	/** The events the extension listens to. Order-history first (sort 0), before OpenCart builds the order email (sort 1). */
	private const EVENTS = [
		['gamma_wallet_add_history', 'catalog/model/checkout/order.addHistory/before', 'eventAddHistory', 0],
		['gamma_wallet_add_history_after', 'catalog/model/checkout/order.addHistory/after', 'eventAddHistoryAfter', 0],
		['gamma_wallet_footer', 'catalog/controller/common/footer/before', 'eventFooter', 0],
		['gamma_wallet_success_before', 'catalog/controller/checkout/success/before', 'eventSuccessBefore', 0],
		['gamma_wallet_success_after', 'catalog/view/common/success/after', 'eventSuccessAfter', 0],
		['gamma_wallet_order_info', 'catalog/view/account/order_info/after', 'eventOrderInfoAfter', 0],
		['gamma_wallet_order_mail', 'catalog/view/mail/order_add/after', 'eventOrderMailAfter', 0],
		['gamma_wallet_admin_order', 'admin/view/sale/order_info/after', 'eventAdminOrderInfo', 0],
	];

	private const CRON = 'gamma_wallet_reconcile';

	private function gamma(): \GammaWallet {
		return new \GammaWallet($this->registry);
	}

	/** The extension's texts: English first, then the admin's language over it (OpenCart has no fallback). */
	private function loadTexts(): void {
		$this->load->language(self::ROUTE, '', 'en-gb');
		$this->load->language(self::ROUTE);
	}

	private function token(): string {
		return 'user_token=' . $this->session->data['user_token'];
	}

	// ---------------------------------------------------------------- install

	public function install(): void {
		if (!$this->user->hasPermission('modify', 'extension/payment')) {
			return;
		}
		$this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "gamma_wallet_order` (
			`order_id` INT NOT NULL, `bill_id` VARCHAR(64) NULL, `code` VARCHAR(64) NULL, `link` VARCHAR(255) NULL,
			`qr_url` VARCHAR(255) NULL, `bill_status` VARCHAR(16) NULL, `claimed_on` VARCHAR(40) NULL, `error` VARCHAR(255) NULL,
			`attempts` INT NOT NULL DEFAULT 0, `emailed` INT NOT NULL DEFAULT 0, `credit_request` TEXT NULL,
			`credit_request_id` VARCHAR(64) NULL, `credit_expires_on` VARCHAR(40) NULL, `credit_link` TEXT NULL,
			`credit_qr` MEDIUMTEXT NULL, `settled_request_id` VARCHAR(64) NULL, `date_modified` DATETIME NOT NULL,
			PRIMARY KEY (`order_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

		$this->load->model('setting/event');
		foreach (self::EVENTS as [$code, $trigger, $method, $sort]) {
			$this->model_setting_event->deleteEventByCode($code);
			$this->model_setting_event->addEvent([
				'code' => $code, 'description' => 'Gamma Wallet', 'trigger' => $trigger,
				'action' => self::ROUTE . '.' . $method, 'status' => true, 'sort_order' => $sort,
			]);
		}

		// OpenCart's cron task (System → Maintenance → Cron Jobs): settles store-credit orders paid after the page closed.
		$this->load->model('setting/cron');
		$this->model_setting_cron->deleteCronByCode(self::CRON);
		$this->model_setting_cron->addCron(self::CRON, 'Gamma Wallet: settle store-credit orders paid after the page closed', 'hour', self::ROUTE . '.cron', true);

		// The installation secret is kept when the extension is reinstalled, so order references stay the
		// same and Gamma never gives an order a second reward. The install date starts again: orders
		// placed while the extension was not installed earn nothing.
		$gamma = $this->gamma();
		$gamma->orderKey(0);
		$gamma->setData('installed_on', (string)time());

		$this->load->model('setting/setting');
		$this->model_setting_setting->editSetting('payment_gamma_wallet', [
			'payment_gamma_wallet_status' => 1,
			'payment_gamma_wallet_rewards' => 1,
			'payment_gamma_wallet_reward_email' => 1,
			'payment_gamma_wallet_no_reward_methods' => json_encode(\GammaWallet::PAY_LATER),
			'payment_gamma_wallet_paid_statuses' => [2, 3, 5, 15],
			'payment_gamma_wallet_order_status_id' => $this->awaitingStatus(),
			'payment_gamma_wallet_settled_status_id' => 2,
			'payment_gamma_wallet_sort_order' => 0,
		]);
	}

	/** "Awaiting Gamma store credits": an order placed with store credits until Gamma says it is settled. */
	private function awaitingStatus(): int {
		$this->loadTexts();
		$name = (string)$this->language->get('text_awaiting_status');
		$query = $this->db->query("SELECT `order_status_id` FROM `" . DB_PREFIX . "order_status` WHERE `name` = '" . $this->db->escape($name) . "' LIMIT 1");
		if ($query->num_rows) {
			return (int)$query->row['order_status_id'];
		}
		$this->load->model('localisation/order_status');
		$this->load->model('localisation/language');
		$names = [];
		foreach ($this->model_localisation_language->getLanguages() as $language) {
			// The name from the extension's language file (English is the one it ships with).
			$names[$language['language_id']] = ['name' => $name];
		}

		return (int)$this->model_localisation_order_status->addOrderStatus(['order_status' => $names]);
	}

	public function uninstall(): void {
		if (!$this->user->hasPermission('modify', 'extension/payment')) {
			return;
		}
		$this->load->model('setting/event');
		foreach (self::EVENTS as [$code]) {
			$this->model_setting_event->deleteEventByCode($code);
		}
		$this->load->model('setting/cron');
		$this->model_setting_cron->deleteCronByCode(self::CRON);

		$this->load->model('setting/setting');
		$this->model_setting_setting->deleteSetting('payment_gamma_wallet');
		// What the extension kept goes, except the installation secret: kept so a reinstall sends the
		// same order references and Gamma never rewards an order twice.
		$secret = (string)$this->gamma()->data('secret');
		$this->model_setting_setting->deleteSetting(\GammaWallet::DATA_CODE);
		if ($secret !== '') {
			$this->gamma()->setData('secret', $secret);
		}
		// The table goes with the extension (a reinstall keeps the same references, see above); the order
		// status stays, because past orders still point at it.
		$this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "gamma_wallet_order`");

		// The permissions OpenCart gave user groups for the settings page.
		$this->load->model('user/user_group');
		foreach ($this->model_user_user_group->getUserGroups() as $group) {
			$this->model_user_user_group->removePermission((int)$group['user_group_id'], 'access', self::ROUTE);
			$this->model_user_user_group->removePermission((int)$group['user_group_id'], 'modify', self::ROUTE);
		}
	}

	// ---------------------------------------------------------------- the settings page

	public function index(): void {
		$this->loadTexts();
		$this->document->setTitle($this->language->get('heading_title'));
		$gamma = $this->gamma();
		$gamma->refreshIfStale(10);

		$data['breadcrumbs'] = [
			['text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', $this->token())],
			['text' => $this->language->get('text_extension'), 'href' => $this->url->link('marketplace/extension', $this->token() . '&type=payment')],
			['text' => $this->language->get('heading_title'), 'href' => $this->url->link(self::ROUTE, $this->token())],
		];
		$data['save'] = $this->url->link(self::ROUTE . '.save', $this->token());
		$data['check'] = $this->url->link(self::ROUTE . '.check', $this->token());
		$data['back'] = $this->url->link('marketplace/extension', $this->token() . '&type=payment');

		$token = $gamma->token();
		$data['has_token'] = $token !== '';
		$data['masked'] = $token !== '' ? \GammaWallet::masked($token) : '';
		$data['connection'] = $gamma->connection();
		$data['checked_on'] = $data['connection'] && !empty($data['connection']['checkedOn']) ? date('Y-m-d H:i', (int)$data['connection']['checkedOn']) : '';
		$data['shop_currency'] = (string)$this->config->get('config_currency');
		$data['currency_ok'] = $gamma->currencyMatches($data['shop_currency']);

		foreach (['status', 'rewards', 'reward_email', 'sort_order', 'order_status_id', 'settled_status_id'] as $key) {
			$data['payment_gamma_wallet_' . $key] = $this->config->get('payment_gamma_wallet_' . $key);
		}
		$data['paid_statuses'] = $gamma->paidStatuses();
		$this->load->model('localisation/order_status');
		$data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

		// The shop's payment extensions, each with a tick box: does an order paid with it earn a reward?
		$this->load->model('setting/extension');
		$data['methods'] = [];
		foreach ($this->model_setting_extension->getExtensionsByType('payment') as $extension) {
			$code = $extension['code'];
			// Free Checkout only handles orders that cost nothing, which never earn a reward.
			if ($code === 'gamma_wallet' || $code === 'free_checkout') {
				continue;
			}
			// Each payment extension's own name; loading its texts replaces ours, so ours are loaded again below.
			$this->load->language('extension/' . $extension['extension'] . '/payment/' . $code);
			$title = (string)$this->language->get('heading_title');
			$data['methods'][] = ['code' => $code, 'title' => $title ?: $code, 'ticked' => $gamma->methodEarnsReward($code), 'pay_later' => \GammaWallet::isPayLater($code)];
		}
		$this->loadTexts();
		foreach ($this->language->all() as $key => $value) {
			$data[$key] = $value;
		}

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');
		$this->response->setOutput($this->load->view(self::ROUTE, $data));
	}

	public function save(): void {
		$this->loadTexts();
		$json = [];
		if (!$this->user->hasPermission('modify', self::ROUTE)) {
			$json['error']['warning'] = $this->language->get('error_permission');
		} elseif (!(int)($this->request->post['payment_gamma_wallet_order_status_id'] ?? 0) || !(int)($this->request->post['payment_gamma_wallet_settled_status_id'] ?? 0)) {
			$json['error']['warning'] = $this->language->get('error_statuses');
		}
		if (!$json) {
			$post = $this->request->post;
			$gamma = $this->gamma();
			$token = trim((string)($post['payment_gamma_wallet_token'] ?? ''));
			if (!empty($post['remove_token'])) {
				$token = '';
			} elseif ($token === '') {
				$token = $gamma->token(); // left empty: keep the saved one
			}
			// Every payment extension shown but left unticked earns no reward. Pay-later ones that are not
			// installed stay excluded, so they are off by default if installed later.
			$shown = array_column($this->model_setting_extension_list(), 'code');
			$ticked = (array)($post['reward_methods'] ?? []);
			$excluded = array_values(array_unique(array_merge(array_diff($shown, $ticked), array_diff(\GammaWallet::PAY_LATER, $shown))));

			$this->load->model('setting/setting');
			$this->model_setting_setting->editSetting('payment_gamma_wallet', [
				'payment_gamma_wallet_token' => $token,
				'payment_gamma_wallet_status' => !empty($post['payment_gamma_wallet_status']) ? 1 : 0,
				'payment_gamma_wallet_rewards' => !empty($post['payment_gamma_wallet_rewards']) ? 1 : 0,
				'payment_gamma_wallet_reward_email' => !empty($post['payment_gamma_wallet_reward_email']) ? 1 : 0,
				'payment_gamma_wallet_no_reward_methods' => json_encode($excluded),
				'payment_gamma_wallet_paid_statuses' => array_map('intval', (array)($post['payment_gamma_wallet_paid_statuses'] ?? [])),
				'payment_gamma_wallet_order_status_id' => (int)($post['payment_gamma_wallet_order_status_id'] ?? 0),
				'payment_gamma_wallet_settled_status_id' => (int)($post['payment_gamma_wallet_settled_status_id'] ?? 2),
				'payment_gamma_wallet_sort_order' => (int)($post['payment_gamma_wallet_sort_order'] ?? 0),
			]);
			$this->config->set('payment_gamma_wallet_token', $token);
			$gamma->checkConnection(20, $this->language);
			$json['success'] = $this->language->get('text_success');
			$json['redirect'] = str_replace('&amp;', '&', $this->url->link(self::ROUTE, $this->token()));
		}
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	private function model_setting_extension_list(): array {
		$this->load->model('setting/extension');

		return array_values(array_filter($this->model_setting_extension->getExtensionsByType('payment'), fn ($e) => !in_array($e['code'], ['gamma_wallet', 'free_checkout'], true)));
	}

	/** "Check again" on the settings page. */
	public function check(): void {
		if ($this->user->hasPermission('modify', self::ROUTE)) {
			$this->loadTexts();
			$this->gamma()->checkConnection(20, $this->language);
		}
		$this->response->redirect($this->url->link(self::ROUTE, $this->token()));
	}

	// ---------------------------------------------------------------- the order page

	/** admin/view/sale/order_info/after: the Gamma Wallet box, above the order's history. */
	public function eventAdminOrderInfo(string &$route, array &$args, string &$output): void {
		// View events get the template's data as $args (route, data, output).
		$orderId = (int)($args['order_id'] ?? 0);
		if (!$orderId) {
			return;
		}
		$this->loadTexts();
		$gamma = $this->gamma();
		$row = $gamma->row($orderId);
		$this->load->model('sale/order');
		$order = $this->model_sale_order->getOrder($orderId);
		$code = \GammaWallet::methodCode($order ?: []);
		$lines = [];
		$canResend = false;
		if ($code === 'gamma_wallet') {
			$lines[] = $this->language->get(!empty($row['settled_request_id']) ? 'text_box_settled' : 'text_box_waiting_credits');
			if (!empty($row['credit_request_id'])) {
				$lines[] = $this->language->get('text_box_request') . ': ' . $row['credit_request_id'];
			}
			$lines[] = $this->language->get('text_box_no_reward_credits');
		} elseif (!empty($row['bill_id'])) {
			$lines[] = $this->language->get(($row['bill_status'] ?? '') === 'Claimed' ? 'text_box_collected' : 'text_box_waiting_reward');
			$lines[] = $this->language->get('text_box_bill') . ': ' . $row['bill_id'];
			$canResend = ($row['bill_status'] ?? '') !== 'Claimed';
		} elseif (!empty($row['error'])) {
			$lines[] = $row['error'];
			$canResend = true;
		} elseif (!$gamma->rewardServiceActive()) {
			$lines[] = $this->language->get('text_box_no_service');
		} elseif (!$gamma->methodEarnsReward($code)) {
			$lines[] = $this->language->get('text_box_no_reward_method');
		} else {
			$lines[] = $this->language->get(\GammaWallet::isPayLater($code) ? 'text_box_pay_later' : 'text_box_not_yet');
		}
		$card = $this->load->view('extension/gamma_wallet/payment/gamma_wallet_order', [
			'lines' => $lines,
			'qr' => $code === 'gamma_wallet' ? '' : \GammaWallet::safeUrl($row['qr_url']),
			'resend' => $canResend ? $this->url->link(self::ROUTE . '.resend', $this->token() . '&order_id=' . $orderId) : '',
			'logo' => HTTP_CATALOG . 'extension/gamma_wallet/catalog/view/image/gamma-mark-20.png',
			'text_resend' => $this->language->get('text_box_resend'),
			'flash' => $this->session->data['gamma_wallet_flash'] ?? '',
			'text_flash_sent' => $this->language->get('text_box_sent'),
			'text_flash_failed' => $this->language->get('text_box_failed'),
			'text_card_title' => $this->language->get('text_card_title'),
		]);
		unset($this->session->data['gamma_wallet_flash']);

		$anchor = strpos($output, 'fa-solid fa-comment');
		$cardStart = $anchor === false ? false : strrpos(substr($output, 0, $anchor), '<div class="card mb-3">');
		$output = $cardStart === false ? $output . $card : substr_replace($output, $card, $cardStart, 0);
	}

	/**
	 * "Send the reward QR code to the customer": creates the reward if needed and emails it, here in the
	 * admin (the same code the shop uses, in system/library/gamma_wallet.php). A click is a deliberate new
	 * try, also after the automatic ones ran out.
	 */
	public function resend(): void {
		$orderId = (int)($this->request->get['order_id'] ?? 0);
		$sent = false;
		if ($orderId && $this->user->hasPermission('modify', 'sale/order')) {
			$this->loadTexts();
			$this->load->model('sale/order');
			$order = $this->model_sale_order->getOrder($orderId);
			$gamma = $this->gamma();
			if ($order && \GammaWallet::methodCode($order) !== 'gamma_wallet') {
				if (!$gamma->row($orderId)['bill_id']) {
					$gamma->saveRow($orderId, ['attempts' => 0]);
				}
				$texts = [];
				foreach (['text_mail_subject', 'text_mail_title', 'text_mail_hello', 'text_mail_body', 'text_open', 'text_qr_alt'] as $key) {
					$texts[$key] = $this->language->get($key);
				}
				$sent = $gamma->sendRewardEmail($order, $texts, $this->language);
			}
		}
		$this->session->data['gamma_wallet_flash'] = $sent ? 'sent' : 'failed';
		$this->response->redirect($this->url->link('sale/order.info', $this->token() . '&order_id=' . $orderId));
	}
}
