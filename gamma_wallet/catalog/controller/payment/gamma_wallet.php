<?php
namespace Opencart\Catalog\Controller\Extension\GammaWallet\Payment;

require_once DIR_EXTENSION . 'gamma_wallet/system/library/gamma_wallet.php';

/**
 * Gamma Wallet for OpenCart — the shop's pages.
 *
 * index / confirm   the "Use Store Credits with Gamma" payment step
 * status / newcode  asked by the customer's page every 5 seconds (never Gamma itself), protected by a
 *                   key only this shop's server can make
 * event*            the reward step and the boxes on the success page, the order page and the order email
 */
class GammaWallet extends \Opencart\System\Engine\Controller {
	private function model() {
		$this->load->model('extension/gamma_wallet/payment/gamma_wallet');

		return $this->model_extension_gamma_wallet_payment_gamma_wallet;
	}

	// ---------------------------------------------------------------- the payment step

	public function index(): string {
		$this->load->language('extension/gamma_wallet/payment/gamma_wallet');
		$data['language'] = $this->config->get('config_language');
		$data['text_instruction'] = $this->language->get('text_instruction');
		$data['button_confirm'] = $this->language->get('button_confirm');

		return $this->load->view('extension/gamma_wallet/payment/gamma_wallet', $data);
	}

	/** The customer placed the order: it waits for the credits, and Gamma is asked for a QR code. */
	public function confirm(): void {
		$this->load->language('extension/gamma_wallet/payment/gamma_wallet');
		$json = [];
		if (!isset($this->session->data['order_id'])) {
			$json['error'] = $this->language->get('error_order');
		} elseif (!isset($this->session->data['payment_method']) || $this->session->data['payment_method']['code'] != 'gamma_wallet.gamma_wallet') {
			$json['error'] = $this->language->get('error_payment_method');
		}
		if (!$json) {
			$orderId = (int)$this->session->data['order_id'];
			$this->load->model('checkout/order');
			$this->model_checkout_order->addHistory($orderId, (int)$this->config->get('payment_gamma_wallet_order_status_id'));
			try {
				$this->model()->startRequest($orderId);
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
		if (\GammaWallet::methodCode($order) === 'gamma_wallet') {
			try {
				$status = $this->model()->creditStatus($orderId);
			} catch (\GammaWalletApiError $e) {
				$this->reply(['kind' => 'credit', 'status' => 'Unknown'], 503);

				return;
			}
			$this->reply(['kind' => 'credit'] + $status);

			return;
		}
		$this->reply(['kind' => 'reward', 'status' => $this->model()->refreshStatus($orderId)]);
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
		if ((int)$order['order_status_id'] !== (int)$this->config->get('payment_gamma_wallet_order_status_id')) {
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
			$started = $this->model()->startRequest($orderId);
		} catch (\GammaWalletApiError $e) {
			$this->log->write('Gamma Wallet: new store-credit code for order ' . $orderId . ' failed: ' . $e->getMessage());
			$this->reply(['error' => 'unavailable'], 503);

			return;
		}
		$this->reply([
			'status' => 'Waiting', 'secondsLeft' => (int)$started['secondsLeft'], 'link' => $started['link'],
			'qr' => 'data:image/png;base64,' . ($started['qrPngBase64'] ?? ''),
		]);
	}

	/**
	 * Called by the admin's "Send the reward QR code to the customer": creates the reward if needed and
	 * emails it. Only the admin can make the second key (it needs the extension's secret).
	 */
	public function adminResend(): void {
		$order = $this->orderFromRequest();
		$orderId = (int)($order['order_id'] ?? 0);
		$admin = (string)($this->request->get['admin'] ?? '');
		$secret = (string)$this->model()->gamma()->data('secret');
		if (!$order || $secret === '' || !hash_equals(hash_hmac('sha256', 'resend:' . $orderId, $secret), $admin)) {
			$this->reply(['error' => 'not_found'], 404);

			return;
		}
		$this->reply(['sent' => $this->model()->sendRewardEmail($orderId)]);
	}

	// ---------------------------------------------------------------- events

	/**
	 * catalog/model/checkout/order.addHistory/before, before the order email is built: an order
	 * entering a "paid" status gets its reward; a paid-later one also gets the reward email.
	 */
	public function eventAddHistory(string &$route, array &$args): void {
		$orderId = (int)($args[0] ?? 0);
		$statusId = (int)($args[1] ?? 0);
		if (!$orderId || !$statusId) {
			return;
		}
		try {
			$this->load->model('checkout/order');
			$order = $this->model_checkout_order->getOrder($orderId);
			if (!$order || \GammaWallet::methodCode($order) === 'gamma_wallet') {
				return;
			}
			$model = $this->model();
			if ($model->ensureBill($orderId, $statusId) && \GammaWallet::isPayLater(\GammaWallet::methodCode($order)) && !(int)$model->getRow($orderId)['emailed']) {
				$model->sendRewardEmail($orderId);
			}
		} catch (\Throwable $e) {
			// Never let a reward problem stop the order.
			$this->log->write('Gamma Wallet: reward step for order ' . $orderId . ' failed: ' . $e->getMessage());
		}
	}

	/** catalog/controller/checkout/success/before: remember the order before OpenCart forgets it. */
	public function eventSuccessBefore(string &$route, array &$args): void {
		if (isset($this->session->data['order_id'])) {
			$this->session->data['gamma_wallet_last_order_id'] = (int)$this->session->data['order_id'];
		}
	}

	/** catalog/view/common/success/after: the reward, or the store-credit QR code, on the success page. */
	public function eventSuccessAfter(string &$route, array &$args, string &$output): void {
		// View events get the template's data as $args (route, data, output).
		$orderId = (int)($this->session->data['gamma_wallet_last_order_id'] ?? 0);
		if (!$orderId) {
			return;
		}
		unset($this->session->data['gamma_wallet_last_order_id']);
		$output = $this->insertAfterHeading($output, $this->box($orderId));
	}

	/** catalog/view/account/order_info/after: the same box on the customer's order page. */
	public function eventOrderInfoAfter(string &$route, array &$args, string &$output): void {
		// View events get the template's data as $args (route, data, output).
		$orderId = (int)($args['order_id'] ?? 0);
		if ($orderId) {
			$output = $this->insertAfterHeading($output, $this->box($orderId));
		}
	}

	/** catalog/view/mail/order_add/after: the reward QR code in the order email of an order paid at checkout. */
	public function eventOrderMailAfter(string &$route, array &$args, string &$output): void {
		// View events get the template's data as $args (route, data, output).
		$orderId = (int)($args['order_id'] ?? 0);
		$gamma = $this->model()->gamma();
		if (!$orderId || !$gamma->rewardInEmail()) {
			return;
		}
		$this->load->model('checkout/order');
		$order = $this->model_checkout_order->getOrder($orderId);
		$row = $this->model()->getRow($orderId);
		if (!$order || !$row['bill_id'] || \GammaWallet::isPayLater(\GammaWallet::methodCode($order))) {
			return;
		}
		$this->load->language('extension/gamma_wallet/payment/gamma_wallet');
		$block = $this->load->view('extension/gamma_wallet/payment/gamma_wallet_mail_block', [
			'qr' => $row['qr_url'], 'link' => $row['link'],
			'text_title' => $this->language->get('text_reward_title'),
			'text_lead' => $this->language->get('text_mail_block_lead'),
			'text_open' => $this->language->get('text_open'),
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
		$this->load->language('extension/gamma_wallet/payment/gamma_wallet');
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
			]),
		];
		foreach (['text_reward_title', 'text_reward_lead', 'text_step_open', 'text_step_scan', 'text_step_added', 'text_open', 'text_claimed',
			'text_credit_title', 'text_step_scan_time', 'text_step_confirm', 'text_new_code', 'text_settled'] as $text) {
			$data[$text] = $this->language->get($text);
		}

		if (\GammaWallet::methodCode($order) === 'gamma_wallet') {
			if ($model->isSettled($orderId)) {
				$data['kind'] = 'note';
				$data['note'] = $this->language->get('text_settled');
			} else {
				$row = $model->getRow($orderId);
				$expires = $row['credit_expires_on'] ? strtotime($row['credit_expires_on']) : 0;
				$data['kind'] = 'credit';
				$data['seconds'] = $expires ? max(0, $expires - time()) : 0;
				$data['qr'] = $row['credit_qr'] ? 'data:image/png;base64,' . $row['credit_qr'] : '';
				$data['link'] = (string)$row['credit_link'];
				$data['text_credit_lead'] = sprintf($this->language->get('text_credit_lead'),
					$this->currency->format((float)$order['total'], $order['currency_code'], (float)$order['currency_value']));
			}
		} elseif ($model->ensureBill($orderId)) {
			$row = $model->getRow($orderId);
			$claimed = $row['bill_status'] === 'Claimed' || $model->refreshStatus($orderId) === 'Claimed';
			$data += ['kind' => 'reward', 'claimed' => $claimed, 'qr' => $row['qr_url'], 'link' => $row['link']];
		} elseif ($model->mayEarn($order) && !in_array((int)$order['order_status_id'], $model->gamma()->paidStatuses(), true)) {
			$data['kind'] = 'note';
			$data['note'] = $this->language->get(\GammaWallet::isPayLater(\GammaWallet::methodCode($order)) ? 'text_note_pay_later' : 'text_note_later');
		} else {
			return '';
		}

		return $this->load->view('extension/gamma_wallet/payment/gamma_wallet_box', $data);
	}
}
