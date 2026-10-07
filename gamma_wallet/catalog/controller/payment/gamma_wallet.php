<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace Opencart\Catalog\Controller\Extension\GammaWallet\Payment;

require_once DIR_EXTENSION . 'gamma_wallet/system/library/gamma_wallet.php';

/**
 * Gamma Wallet for OpenCart — the shop's pages.
 *
 * index / confirm   the "Use Store Credits with Gamma" payment step
 * status / newcode  asked by the customer's page every 5 seconds (never Gamma itself), protected by a
 *                   key only this shop's server can make
 * cron              OpenCart's cron task: settles store-credit orders paid after the page closed
 * event*            the reward step and the boxes on the success page, the order page and the order email
 */
class GammaWallet extends \Opencart\System\Engine\Controller {
	/** How often the storefront checks the waiting store-credit orders, at most. */
	private const RECONCILE_EVERY = 120;

	private function model() {
		$this->load->model('extension/gamma_wallet/payment/gamma_wallet');

		return $this->model_extension_gamma_wallet_payment_gamma_wallet;
	}

	// ---------------------------------------------------------------- the payment step

	public function index(): string {
		$this->model()->loadTexts();
		$data['language'] = $this->config->get('config_language');
		$data['text_instruction'] = $this->language->get('text_instruction');
		$data['button_confirm'] = $this->language->get('button_confirm');

		return $this->load->view('extension/gamma_wallet/payment/gamma_wallet', $data);
	}

	/** The customer placed the order: it waits for the credits, and Gamma is asked for a QR code. */
	public function confirm(): void {
		$this->model()->loadTexts();
		$json = [];
		if (!isset($this->session->data['order_id'])) {
			$json['error'] = $this->language->get('error_order');
		} elseif (!isset($this->session->data['payment_method']) || $this->session->data['payment_method']['code'] != 'gamma_wallet.gamma_wallet') {
			$json['error'] = $this->language->get('error_payment_method');
		}
		if (!$json) {
			$orderId = (int)$this->session->data['order_id'];
			$this->load->model('checkout/order');
			$order = $this->model_checkout_order->getOrder($orderId);
			// A repeated post (double click, a retry) neither adds a second history line nor a second code.
			if ($order && !(int)$order['order_status_id']) {
				$this->model_checkout_order->addHistory($orderId, (int)$this->config->get('payment_gamma_wallet_order_status_id'));
			}
			try {
				$this->model()->startRequest($orderId, true);
			} catch (\GammaWalletApiError $e) {
				// The page then offers a new code; the order stays waiting.
				$this->log->write('Gamma Wallet: store-credit request for order ' . $orderId . ' failed: ' . $e->getMessage());
			}
			$json['redirect'] = $this->url->link('checkout/success', 'language=' . $this->config->get('config_language'), true);
		}
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	// ---------------------------------------------------------------- what the customer's page asks

	private function reply(array $data, int $status = 200): void {
		if ($status !== 200) {
			$this->response->addHeader('HTTP/1.1 ' . $status . ' ' . ($status === 404 ? 'Not Found' : ($status === 409 ? 'Conflict' : 'Service Unavailable')));
		}
		$this->response->addHeader('Content-Type: application/json');
		$this->response->addHeader('Cache-Control: no-store');
		$this->response->setOutput(json_encode($data));
	}

	/** The order named in the request, or null when the key does not match. */
	private function orderFromRequest(): ?array {
		$orderId = (int)($this->request->get['order_id'] ?? 0);
		$key = (string)($this->request->get['key'] ?? '');
		if (!$orderId || $key === '' || !hash_equals($this->model()->gamma()->orderKey($orderId), $key)) {
			return null;
		}
		$this->load->model('checkout/order');
		$order = $this->model_checkout_order->getOrder($orderId);

		return $order ?: null;
	}

	public function status(): void {
		$order = $this->orderFromRequest();
		if (!$order) {
			$this->reply(['error' => 'not_found'], 404);

			return;
		}
		$orderId = (int)$order['order_id'];
		// Several open pages (or a busy client) ask Gamma at most once every few seconds per order.
		$cacheKey = 'gamma_wallet.status.' . $orderId;
		$cached = $this->cache->get($cacheKey);
		// OpenCart's cache answers an empty array for a missing key.
		if (is_array($cached) && isset($cached['kind'])) {
			$this->reply($cached);

			return;
		}
		if (\GammaWallet::methodCode($order) === 'gamma_wallet') {
			try {
				$answer = ['kind' => 'credit'] + $this->model()->creditStatus($orderId, 10);
			} catch (\GammaWalletApiError $e) {
				$this->reply(['kind' => 'credit', 'status' => 'Unknown'], 503);

				return;
			}
		} else {
			$answer = ['kind' => 'reward', 'status' => $this->model()->refreshStatus($orderId, 10)];
		}
		$this->cache->set($cacheKey, $answer, 4);
		$this->reply($answer);
	}

	/** A new store-credit code; asks Gamma about the current one first, which may have just been settled. */
	public function newcode(): void {
		$order = $this->orderFromRequest();
		if (!$order || \GammaWallet::methodCode($order) !== 'gamma_wallet' || $this->request->server['REQUEST_METHOD'] !== 'POST') {
			$this->reply(['error' => 'not_found'], 404);

			return;
		}
		$orderId = (int)$order['order_id'];
		try {
			$current = $this->model()->creditStatus($orderId);
		} catch (\GammaWalletApiError $e) {
			$this->reply(['error' => 'unavailable'], 503);

			return;
		}
		if ($current['status'] === 'Paid') {
			$this->reply(['status' => 'Paid']);

			return;
		}
		if ((int)$order['order_status_id'] !== $this->model()->gamma()->awaitingStatus()) {
			$this->reply(['error' => 'not_payable'], 409);

			return;
		}
		// Never two live codes for one order, or the customer could settle it twice. Gamma still accepts
		// a code a few seconds past its time (clock differences), so wait those out too.
		$row = $this->model()->getRow($orderId);
		$expires = $row['credit_expires_on'] ? strtotime($row['credit_expires_on']) : 0;
		if ($expires && time() < $expires + 15) {
			$this->reply(['error' => 'still_valid'], 409);

			return;
		}
		try {
			// Reserved in one step: a second click or tab arriving meanwhile gets "still valid".
			$started = $this->model()->startRequest($orderId);
		} catch (\GammaWalletApiError $e) {
			$this->log->write('Gamma Wallet: new store-credit code for order ' . $orderId . ' failed: ' . $e->getMessage());
			$this->reply(['error' => 'unavailable'], 503);

			return;
		}
		if ($started === null) {
			$this->reply(['error' => 'still_valid'], 409);

			return;
		}
		$this->reply([
			'status' => 'Waiting', 'secondsLeft' => (int)$started['secondsLeft'], 'link' => $started['link'],
			'qr' => 'data:image/png;base64,' . ($started['qrPngBase64'] ?? ''),
		]);
	}

	// ---------------------------------------------------------------- settling orders paid after the page closed

	/** Checks the waiting store-credit orders with Gamma, at most every RECONCILE_EVERY seconds. */
	private function reconcileIfDue(int $timeout): void {
		$gamma = $this->model()->gamma();
		if ($gamma->token() === '' || (int)$gamma->data('reconciled_at') > time() - self::RECONCILE_EVERY) {
			return;
		}
		$gamma->setData('reconciled_at', (string)time());
		$this->model()->reconcile($timeout);
	}

	/** OpenCart's cron task (System → Maintenance → Cron Jobs), every hour. */
	public function cron(int $cron_id = 0, string $code = '', string $cycle = '', string $date_added = '', string $date_modified = ''): void {
		try {
			$this->reconcileIfDue(10);
			$this->model()->gamma()->refreshIfStale(10);
		} catch (\Throwable $e) {
			$this->log->write('Gamma Wallet: cron task failed: ' . $e->getMessage());
		}
	}

	/**
	 * catalog/controller/common/footer/before: while customers browse the shop, the waiting store-credit
	 * orders are checked with Gamma (at most every 2 minutes), so a customer who confirmed in the app
	 * and closed the page still gets their order, even without a cron job.
	 */
	public function eventFooter(string &$route = '', array &$args = []): void {
		try {
			$this->reconcileIfDue(5);
		} catch (\Throwable $e) {
			$this->log->write('Gamma Wallet: checking waiting orders failed: ' . $e->getMessage());
		}
	}

	// ---------------------------------------------------------------- events

	/**
	 * True when OpenCart's anti-fraud check may still change the status this order is entering: the
	 * check runs inside addHistory, after this event, so the reward then waits for the saved status.
	 */
	private function fraudCheckPending(array $order, int $statusId, bool $override): bool {
		$checked = array_map('intval', array_merge((array)$this->config->get('config_processing_status'), (array)$this->config->get('config_complete_status')));
		if ($override || !in_array($statusId, $checked, true)) {
			return false;
		}
		$this->load->model('account/customer');
		$customer = $order['customer_id'] ? $this->model_account_customer->getCustomer((int)$order['customer_id']) : [];
		if ($customer && !empty($customer['safe'])) {
			return false;
		}
		$this->load->model('setting/extension');
		foreach ($this->model_setting_extension->getExtensionsByType('fraud') as $extension) {
			if ($this->config->get('fraud_' . $extension['code'] . '_status')) {
				return true;
			}
		}

		return false;
	}

	/**
	 * catalog/model/checkout/order.addHistory/before, before the order email is built: an order entering
	 * a "paid" status gets its reward. When the order email already went out (paid later, or confirmed
	 * later by the payment provider), the customer gets the reward in an email of its own, once.
	 */
	public function eventAddHistory(string &$route = '', array &$args = []): void {
		$orderId = (int)($args[0] ?? 0);
		$statusId = (int)($args[1] ?? 0);
		if (!$orderId || !$statusId) {
			return;
		}
		try {
			$this->load->model('checkout/order');
			$order = $this->model_checkout_order->getOrder($orderId);
			if (!$order || \GammaWallet::methodCode($order) === 'gamma_wallet' || $this->fraudCheckPending($order, $statusId, (bool)($args[4] ?? false))) {
				return;
			}
			$model = $this->model();
			// The order email is sent only when an order is confirmed for the first time (status 0 → …).
			$orderEmailComing = !(int)$order['order_status_id'] && !\GammaWallet::isPayLater(\GammaWallet::methodCode($order));
			if ($model->ensureBill($orderId, $statusId, 10) && !$orderEmailComing && !(int)$model->getRow($orderId)['emailed']) {
				$model->sendRewardEmail($orderId);
			}
		} catch (\Throwable $e) {
			// Never let a reward problem stop the order.
			$this->log->write('Gamma Wallet: reward step for order ' . $orderId . ' failed: ' . $e->getMessage());
		}
	}

	/**
	 * catalog/model/checkout/order.addHistory/after: for an order that went through the anti-fraud check,
	 * the reward follows the status the order really got, with an email of its own.
	 */
	public function eventAddHistoryAfter(string &$route = '', array &$args = [], mixed &$output = null): void {
		$orderId = (int)($args[0] ?? 0);
		if (!$orderId) {
			return;
		}
		try {
			$model = $this->model();
			if ($model->getRow($orderId)['bill_id']) {
				return;
			}
			$this->load->model('checkout/order');
			$order = $this->model_checkout_order->getOrder($orderId);
			if (!$order || \GammaWallet::methodCode($order) === 'gamma_wallet') {
				return;
			}
			if ($model->ensureBill($orderId, (int)$order['order_status_id'], 10) && !(int)$model->getRow($orderId)['emailed']) {
				$model->sendRewardEmail($orderId);
			}
		} catch (\Throwable $e) {
			$this->log->write('Gamma Wallet: reward step for order ' . $orderId . ' failed: ' . $e->getMessage());
		}
	}

	/** catalog/controller/checkout/success/before: remember the order before OpenCart forgets it. */
	public function eventSuccessBefore(string &$route = '', array &$args = []): void {
		if (isset($this->session->data['order_id'])) {
			$this->session->data['gamma_wallet_last_order_id'] = (int)$this->session->data['order_id'];
		}
	}

	/** catalog/view/common/success/after: the reward, or the store-credit QR code, on the success page. */
	public function eventSuccessAfter(string &$route = '', array &$args = [], mixed &$output = null): void {
		// View events get the template's data as $args (route, data, output).
		$orderId = (int)($this->session->data['gamma_wallet_last_order_id'] ?? 0);
		if (!$orderId || !is_string($output)) {
			return;
		}
		unset($this->session->data['gamma_wallet_last_order_id']);
		$output = $this->insertAfterHeading($output, $this->box($orderId));
	}

	/** catalog/view/account/order_info/after: the same box on the customer's order page. */
	public function eventOrderInfoAfter(string &$route = '', array &$args = [], mixed &$output = null): void {
		// View events get the template's data as $args (route, data, output). OpenCart shows this page
		// only to the customer who owns the order.
		$orderId = (int)($args['order_id'] ?? 0);
		if ($orderId && is_string($output)) {
			$output = $this->insertAfterHeading($output, $this->box($orderId));
		}
	}

	/** catalog/view/mail/order_add/after: the reward QR code in the order email of an order paid at checkout. */
	public function eventOrderMailAfter(string &$route = '', array &$args = [], mixed &$output = null): void {
		// View events get the template's data as $args (route, data, output).
		$orderId = (int)($args['order_id'] ?? 0);
		$gamma = $this->model()->gamma();
		if (!$orderId || !is_string($output) || !$gamma->rewardInEmail()) {
			return;
		}
		$this->load->model('checkout/order');
		$order = $this->model_checkout_order->getOrder($orderId);
		$row = $this->model()->getRow($orderId);
		$qr = \GammaWallet::safeUrl($row['qr_url']);
		$link = \GammaWallet::safeUrl($row['link']);
		if (!$order || !$row['bill_id'] || $qr === '' || $link === '' || \GammaWallet::isPayLater(\GammaWallet::methodCode($order))) {
			return;
		}
		$this->model()->loadTexts();
		$block = $this->load->view('extension/gamma_wallet/payment/gamma_wallet_mail_block', [
			'qr' => $qr, 'link' => $link,
			'text_title' => $this->language->get('text_reward_title'),
			'text_lead' => $this->language->get('text_mail_block_lead'),
			'text_open' => $this->language->get('text_open'),
			'text_qr_alt' => $this->language->get('text_qr_alt'),
		]);
		$output = stripos($output, '</body>') !== false ? preg_replace('~</body>~i', $block . '</body>', $output, 1) : $output . $block;
	}

	private function insertAfterHeading(string $output, string $html): string {
		if ($html === '') {
			return $output;
		}
		$position = stripos($output, '</h1>');

		return $position === false ? $output . $html : substr_replace($output, '</h1>' . $html, $position, 5);
	}

	/** The Gamma Wallet box for an order: the reward, the store-credit QR code, or a short note. */
	private function box(int $orderId): string {
		$model = $this->model();
		$this->load->model('checkout/order');
		$order = $this->model_checkout_order->getOrder($orderId);
		if (!$order) {
			return '';
		}
		$model->loadTexts();
		$base = $this->config->get('config_url') . 'extension/gamma_wallet/catalog/view/';
		$key = $model->gamma()->orderKey($orderId);
		$link = fn (string $method) => str_replace('&amp;', '&', $this->url->link('extension/gamma_wallet/payment/gamma_wallet.' . $method, 'order_id=' . $orderId . '&key=' . $key, true));
		$data = [
			'css' => $base . 'stylesheet/gamma_wallet.css?v=' . \GammaWalletApi::VERSION,
			'js' => $base . 'javascript/gamma_wallet.js?v=' . \GammaWalletApi::VERSION,
			'logo' => $base . 'image/gamma-logo.png',
			'status_url' => $link('status'),
			'new_code_url' => $link('newcode'),
			'texts' => json_encode([
				'secondsLeft' => $this->language->get('js_seconds_left'), 'timeLeft' => $this->language->get('js_time_left'),
				'expired' => $this->language->get('js_expired'), 'settled' => $this->language->get('js_settled'),
				'claimed' => $this->language->get('js_claimed'), 'unavailable' => $this->language->get('js_unavailable'),
			], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
		];
		foreach (['text_reward_title', 'text_reward_lead', 'text_step_open', 'text_step_scan', 'text_step_added', 'text_open', 'text_claimed',
			'text_credit_title', 'text_step_scan_time', 'text_step_confirm', 'text_new_code', 'text_settled', 'text_qr_alt'] as $text) {
			$data[$text] = $this->language->get($text);
		}

		if (\GammaWallet::methodCode($order) === 'gamma_wallet') {
			if ($model->isSettled($orderId)) {
				$data['kind'] = 'note';
				$data['note'] = $this->language->get('text_settled');
			} elseif ((int)$order['order_status_id'] !== $model->gamma()->awaitingStatus()) {
				$data['kind'] = 'note';
				$data['note'] = $this->language->get('text_no_longer_waiting');
			} else {
				$row = $model->getRow($orderId);
				$expires = $row['credit_expires_on'] ? strtotime($row['credit_expires_on']) : 0;
				$data['kind'] = 'credit';
				$data['seconds'] = $expires ? max(0, $expires - time()) : 0;
				$data['qr'] = $row['credit_qr'] && preg_match('~^[A-Za-z0-9+/=]+$~', (string)$row['credit_qr']) ? 'data:image/png;base64,' . $row['credit_qr'] : '';
				$data['link'] = \GammaWallet::safeUrl($row['credit_link']);
				$data['text_credit_lead'] = sprintf($this->language->get('text_credit_lead'),
					$this->currency->format((float)$order['total'], $order['currency_code'], (float)$order['currency_value']));
			}
		} elseif ($model->ensureBill($orderId, null, 5)) {
			// Short wait: the page is being built. Whether it was collected is asked by the page script.
			$row = $model->getRow($orderId);
			$claimed = $row['bill_status'] === 'Claimed';
			$data += ['kind' => 'reward', 'claimed' => $claimed, 'qr' => \GammaWallet::safeUrl($row['qr_url']), 'link' => \GammaWallet::safeUrl($row['link'])];
		} elseif ($model->mayEarn($order) && !in_array((int)$order['order_status_id'], $model->gamma()->paidStatuses(), true)) {
			$data['kind'] = 'note';
			$data['note'] = $this->language->get(\GammaWallet::isPayLater(\GammaWallet::methodCode($order)) ? 'text_note_pay_later' : 'text_note_later');
		} else {
			return '';
		}

		return $this->load->view('extension/gamma_wallet/payment/gamma_wallet_box', $data);
	}
}
