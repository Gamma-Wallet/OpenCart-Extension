<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace Opencart\Catalog\Model\Extension\GammaWallet\Payment;

require_once DIR_EXTENSION . 'gamma_wallet/system/library/gamma_wallet.php';

/**
 * Gamma Wallet for OpenCart — the shop side: "Use Store Credits with Gamma" as a payment method,
 * rewards for paid orders, and settling store-credit orders. The per-order data and the reward logic
 * shared with the admin live in system/library/gamma_wallet.php.
 *
 * Can be called from $this->load->model('extension/gamma_wallet/payment/gamma_wallet');
 */
class GammaWallet extends \Opencart\System\Engine\Model {
	public function gamma(): \GammaWallet {
		return new \GammaWallet($this->registry);
	}

	/** The extension's texts: English first, then the store's language over it (OpenCart has no fallback). */
	public function loadTexts(): void {
		$this->load->language('extension/gamma_wallet/payment/gamma_wallet', '', 'en-gb');
		$this->load->language('extension/gamma_wallet/payment/gamma_wallet');
	}

	private function order(int $orderId): array {
		$this->load->model('checkout/order');

		return $this->model_checkout_order->getOrder($orderId) ?: [];
	}

	// ---------------------------------------------------------------- the payment method

	/** "Use Store Credits with Gamma", offered only when it can work for this cart. */
	public function getMethods(array $address = []): array {
		$this->loadTexts();
		$gamma = $this->gamma();
		$total = (float)$this->cart->getTotal();
		$status = $this->config->get('payment_gamma_wallet_status')
			&& !$this->cart->hasSubscription()
			&& $total > 0
			&& $gamma->rewardServiceActive()
			&& $gamma->currencyMatches((string)$this->session->data['currency']);
		if (!$status) {
			return [];
		}

		return [
			'code'       => 'gamma_wallet',
			'name'       => $this->language->get('heading_title'),
			'option'     => ['gamma_wallet' => ['code' => 'gamma_wallet.gamma_wallet', 'name' => $this->language->get('text_option')]],
			'sort_order' => $this->config->get('payment_gamma_wallet_sort_order'),
		];
	}

	// ---------------------------------------------------------------- the per-order row

	public function getRow(int $orderId): array {
		return $this->gamma()->row($orderId);
	}

	// ---------------------------------------------------------------- rewards

	public function mayEarn(array $orderInfo): bool {
		return $this->gamma()->mayEarn($orderInfo);
	}

	/** Declares the order to Gamma once (see \GammaWallet::ensureBill). */
	public function ensureBill(int $orderId, ?int $statusId = null, int $timeout = 20): bool {
		$orderInfo = $this->order($orderId);
		$this->loadTexts();

		return $orderInfo && $this->gamma()->ensureBill($orderInfo, $statusId, $timeout, $this->language);
	}

	/** Asks Gamma whether the reward was collected. "Waiting" or "Claimed". */
	public function refreshStatus(int $orderId, int $timeout = 20): string {
		$gamma = $this->gamma();
		$row = $gamma->row($orderId);
		$api = $gamma->api();
		if ($row['bill_status'] === 'Claimed' || !$row['bill_id'] || !$api) {
			return (string)$row['bill_status'];
		}
		try {
			$bill = $api->getBill($row['bill_id'], $timeout);
		} catch (\GammaWalletApiError $e) {
			return (string)$row['bill_status'];
		}
		if ($bill['status'] !== $row['bill_status']) {
			$gamma->saveRow($orderId, ['bill_status' => $bill['status'], 'claimed_on' => $bill['claimedOn'] ?? null]);
		}

		return (string)$bill['status'];
	}

	/** The reward email of its own: for orders paid after the order email, and for sending it again. */
	public function sendRewardEmail(int $orderId): bool {
		$orderInfo = $this->order($orderId);
		if (!$orderInfo) {
			return false;
		}
		$this->loadTexts();
		$texts = [];
		foreach (['text_mail_subject', 'text_mail_title', 'text_mail_hello', 'text_mail_body', 'text_open', 'text_qr_alt'] as $key) {
			$texts[$key] = $this->language->get($key);
		}

		return $this->gamma()->sendRewardEmail($orderInfo, $texts, $this->language);
	}

	// ---------------------------------------------------------------- store credits

	/**
	 * Asks Gamma for a new store-credit request for the whole order, and keeps it. Returns null when
	 * another request is starting one or the current one is still live (never two codes per order).
	 * $firstOnly: only if the order never had a code (the checkout's confirm step).
	 */
	public function startRequest(int $orderId, bool $firstOnly = false): ?array {
		$gamma = $this->gamma();
		$api = $gamma->api();
		if (!$api) {
			throw new \GammaWalletApiError(401, '0392', 'IntegrationTokenMissing');
		}
		$orderInfo = $this->order($orderId);
		$previous = $gamma->claimCodeSlot($orderId, $firstOnly);
		if ($previous === null) {
			return null;
		}
		try {
			$request = $api->startCredit([
				'reference'    => $gamma->reference($orderId),
				'total'        => round($gamma->orderTotal($orderInfo), 2),
				'currencyCode' => $orderInfo['currency_code'],
			]);
		} catch (\GammaWalletApiError $e) {
			$gamma->releaseCodeSlot($orderId, $previous);
			throw $e;
		}
		$gamma->saveRow($orderId, [
			'credit_request' => $request['creditRequest'], 'credit_request_id' => $request['requestId'],
			'credit_expires_on' => $request['expiresOn'], 'credit_link' => $request['link'], 'credit_qr' => $request['qrPngBase64'] ?? '',
		]);

		return $request;
	}

	public function isSettled(int $orderId): bool {
		return (bool)$this->gamma()->row($orderId)['settled_request_id'];
	}

	/** Paid (settled now or before), Waiting with the seconds left, or Expired. */
	public function creditStatus(int $orderId, int $timeout = 20): array {
		if ($this->isSettled($orderId)) {
			return ['status' => 'Paid'];
		}
		$gamma = $this->gamma();
		$row = $gamma->row($orderId);
		$api = $gamma->api();
		if (!$row['credit_request'] || !$api) {
			return ['status' => 'Expired'];
		}
		$checked = $api->checkCredit($row['credit_request'], $timeout);
		if ($checked['status'] === 'Paid') {
			$this->markSettled($orderId, $checked);

			return ['status' => 'Paid'];
		}

		return ['status' => $checked['status'], 'secondsLeft' => (int)$checked['secondsLeft']];
	}

	/** Gamma's answer is about this order: same reference, total and currency. */
	private function matches(array $orderInfo, array $checked): bool {
		$gamma = $this->gamma();
		if (isset($checked['reference']) && (string)$checked['reference'] !== $gamma->reference((int)$orderInfo['order_id'])) {
			return false;
		}
		if (isset($checked['currencyCode']) && strcasecmp((string)$checked['currencyCode'], (string)$orderInfo['currency_code']) !== 0) {
			return false;
		}

		return !isset($checked['total']) || abs((float)$checked['total'] - round($gamma->orderTotal($orderInfo), 2)) < 0.005;
	}

	/**
	 * Records a settled request once: the order moves to the "settled" status and the QR code goes. When
	 * the order no longer waits for the credits (cancelled meanwhile, or changed by hand), its status is
	 * left as it is and a note in its history tells the shop: the customer has used their credits.
	 */
	public function markSettled(int $orderId, array $checked): void {
		$orderInfo = $this->order($orderId);
		if (!$orderInfo) {
			return;
		}
		if (!$this->matches($orderInfo, $checked)) {
			$this->log->write('Gamma Wallet: the settled request ' . ($checked['requestId'] ?? '?') . ' does not match order ' . $orderId
				. ' (reference, total or currency); the order was not changed.');

			return;
		}
		$requestId = (string)($checked['requestId'] ?? '') ?: 'settled';
		$gamma = $this->gamma();
		if (!$gamma->claimSettlement($orderId, $requestId)) {
			return;
		}
		$this->loadTexts();
		$this->load->model('checkout/order');
		if ((int)$orderInfo['order_status_id'] !== $gamma->awaitingStatus()) {
			$note = sprintf($this->language->get('text_settled_too_late'), $requestId);
			$this->log->write('Gamma Wallet: order ' . $orderId . ': ' . $note);
			$this->model_checkout_order->addHistory($orderId, (int)$orderInfo['order_status_id'], $note, false);

			return;
		}
		$this->model_checkout_order->addHistory($orderId, (int)($this->config->get('payment_gamma_wallet_settled_status_id') ?: 2),
			sprintf($this->language->get('text_settled_comment'), $requestId), true);
	}

	/**
	 * Store-credit orders whose customer may have confirmed in the app after leaving the page. Credit/
	 * Check answers for a request long after its code expired, so a settled order is found even then.
	 */
	public function reconcile(int $timeout = 5): void {
		foreach ($this->gamma()->awaitingSettlement() as $orderId) {
			$orderInfo = $this->order($orderId);
			if (!$orderInfo || \GammaWallet::methodCode($orderInfo) !== 'gamma_wallet') {
				continue;
			}
			try {
				$this->creditStatus($orderId, $timeout);
			} catch (\GammaWalletApiError $e) {
				$this->log->write('Gamma Wallet: checking the store credits of order ' . $orderId . ' failed: ' . $e->getMessage());
			}
		}
	}
}
