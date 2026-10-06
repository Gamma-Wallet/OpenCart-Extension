<?php
namespace Opencart\Catalog\Model\Extension\GammaWallet\Payment;

require_once DIR_EXTENSION . 'gamma_wallet/system/library/gamma_wallet.php';

/**
 * Gamma Wallet for OpenCart — the shop side: "Use Store Credits with Gamma" as a payment method,
 * rewards for paid orders, and what Gamma said about each order (table PREFIX_gamma_wallet_order).
 *
 * Can be called from $this->load->model('extension/gamma_wallet/payment/gamma_wallet');
 */
class GammaWallet extends \Opencart\System\Engine\Model {
	/** No more automatic attempts after this many failures; the shop can still send the reward by hand. */
	public const MAX_ATTEMPTS = 6;

	private const COLUMNS = ['bill_id', 'code', 'link', 'qr_url', 'bill_status', 'claimed_on', 'error', 'attempts', 'emailed',
		'credit_request', 'credit_request_id', 'credit_expires_on', 'credit_link', 'credit_qr', 'settled_request_id'];

	public function gamma(): \GammaWallet {
		return new \GammaWallet($this->registry);
	}

	// ---------------------------------------------------------------- the payment method

	/** "Use Store Credits with Gamma", offered only when it can work for this cart. */
	public function getMethods(array $address = []): array {
		$this->load->language('extension/gamma_wallet/payment/gamma_wallet');
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
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "gamma_wallet_order` WHERE `order_id` = '" . $orderId . "'");

		return array_merge(array_fill_keys(self::COLUMNS, null), $query->row ?: []);
	}

	public function saveRow(int $orderId, array $values): void {
		$set = ["`date_modified` = NOW()"];
		foreach ($values as $column => $value) {
			if (in_array($column, self::COLUMNS, true)) {
				$set[] = "`" . $column . "` = " . ($value === null ? 'NULL' : "'" . $this->db->escape((string)$value) . "'");
			}
		}
		$this->db->query("INSERT INTO `" . DB_PREFIX . "gamma_wallet_order` SET `order_id` = '" . $orderId . "', " . implode(', ', $set)
			. " ON DUPLICATE KEY UPDATE " . implode(', ', $set));
	}

	/** The order total in the order's own currency, as the customer paid it. */
	public function orderTotal(array $orderInfo): float {
		return (float)$this->currency->format((float)$orderInfo['total'], $orderInfo['currency_code'], (float)$orderInfo['currency_value'], false);
	}

	// ---------------------------------------------------------------- rewards

	/** True when this order can ever earn a reward, now or once its payment is confirmed. */
	public function mayEarn(array $orderInfo): bool {
		$gamma = $this->gamma();

		return $gamma->rewardsEnabled() && $gamma->rewardServiceActive() && $gamma->methodEarnsReward(\GammaWallet::methodCode($orderInfo));
	}

	/**
	 * True when this order should have a reward QR code with this status: rewards are on, the business
	 * has a Reward service, the payment method earns one, the currencies match, and the status means
	 * the money is in. Orders settled with store credits never earn one.
	 */
	public function qualifies(array $orderInfo, int $statusId): bool {
		return in_array($statusId, $this->gamma()->paidStatuses(), true)
			&& $this->orderTotal($orderInfo) > 0
			&& $this->mayEarn($orderInfo)
			&& $this->gamma()->currencyMatches((string)$orderInfo['currency_code']);
	}

	/**
	 * Declares the order to Gamma once. Returns true when the order has a bill afterwards. Safe to call
	 * any number of times: Gamma returns the same bill for the same reference.
	 * $statusId: the status the order is entering (the order row may not have it yet).
	 */
	public function ensureBill(int $orderId, ?int $statusId = null): bool {
		$row = $this->getRow($orderId);
		if ($row['bill_id']) {
			return true;
		}
		$this->load->model('checkout/order');
		$orderInfo = $this->model_checkout_order->getOrder($orderId);
		if (!$orderInfo || !$this->qualifies($orderInfo, $statusId ?? (int)$orderInfo['order_status_id']) || (int)$row['attempts'] >= self::MAX_ATTEMPTS) {
			return false;
		}
		$api = $this->gamma()->api();
		if (!$api) {
			return false;
		}
		try {
			$bill = $api->createBill([
				'reference'     => $this->gamma()->reference($orderId),
				'total'         => round($this->orderTotal($orderInfo), 2),
				'currencyCode'  => $orderInfo['currency_code'],
				'issuedOn'      => date(DATE_ATOM, strtotime($orderInfo['date_added'])),
				'platform'      => 'opencart',
				'pluginVersion' => \GammaWalletApi::VERSION,
			]);
		} catch (\GammaWalletApiError $e) {
			$this->saveRow($orderId, ['error' => substr(\GammaWallet::explain($e), 0, 250), 'attempts' => (int)$row['attempts'] + 1]);
			$this->log->write('Gamma Wallet: bill for order ' . $orderId . ' failed: ' . $e->getMessage());

			return false;
		}
		$this->saveRow($orderId, [
			'bill_id' => $bill['billId'], 'code' => $bill['code'], 'link' => $bill['link'], 'qr_url' => $bill['qrImageUrl'],
			'bill_status' => $bill['status'], 'claimed_on' => $bill['claimedOn'] ?? null, 'error' => null,
		]);

		return true;
	}

	/** Asks Gamma whether the reward was collected. "Waiting" or "Claimed". */
	public function refreshStatus(int $orderId): string {
		$row = $this->getRow($orderId);
		$api = $this->gamma()->api();
		if ($row['bill_status'] === 'Claimed' || !$row['bill_id'] || !$api) {
			return (string)$row['bill_status'];
		}
		try {
			$bill = $api->getBill($row['bill_id']);
		} catch (\GammaWalletApiError $e) {
			return (string)$row['bill_status'];
		}
		if ($bill['status'] !== $row['bill_status']) {
			$this->saveRow($orderId, ['bill_status' => $bill['status'], 'claimed_on' => $bill['claimedOn'] ?? null]);
		}

		return (string)$bill['status'];
	}

	/** The reward email of its own: for paid-later orders, and for sending the QR code again by hand. */
	public function sendRewardEmail(int $orderId): bool {
		if (!$this->ensureBill($orderId) || !$this->config->get('config_mail_engine')) {
			return false;
		}
		$this->load->model('checkout/order');
		$orderInfo = $this->model_checkout_order->getOrder($orderId);
		$row = $this->getRow($orderId);
		$this->load->language('extension/gamma_wallet/payment/gamma_wallet');
		$storeName = html_entity_decode((string)$orderInfo['store_name'], ENT_QUOTES, 'UTF-8');
		$data = [
			'store_name' => $storeName,
			'firstname'  => $orderInfo['firstname'],
			'order_id'   => $orderId,
			'qr'         => $row['qr_url'],
			'link'       => $row['link'],
			'text_mail_title' => $this->language->get('text_mail_title'),
			'text_mail_hello' => sprintf($this->language->get('text_mail_hello'), $orderInfo['firstname']),
			'text_mail_body'  => sprintf($this->language->get('text_mail_body'), $orderId),
			'text_open'       => $this->language->get('text_open'),
		];
		$mail = new \Opencart\System\Library\Mail($this->config->get('config_mail_engine'), [
			'parameter'     => $this->config->get('config_mail_parameter'),
			'smtp_hostname' => $this->config->get('config_mail_smtp_hostname'),
			'smtp_username' => $this->config->get('config_mail_smtp_username'),
			'smtp_password' => html_entity_decode((string)$this->config->get('config_mail_smtp_password'), ENT_QUOTES, 'UTF-8'),
			'smtp_port'     => $this->config->get('config_mail_smtp_port'),
			'smtp_timeout'  => $this->config->get('config_mail_smtp_timeout'),
		]);
		$mail->setTo($orderInfo['email']);
		$mail->setFrom($this->config->get('config_email'));
		$mail->setSender($storeName);
		$mail->setSubject(sprintf($this->language->get('text_mail_subject'), $storeName, $orderId));
		$mail->setHtml($this->load->view('extension/gamma_wallet/payment/gamma_wallet_mail', $data));
		$mail->send();
		$this->saveRow($orderId, ['emailed' => time()]);

		return true;
	}

	// ---------------------------------------------------------------- store credits

	/** Asks Gamma for a new store-credit request for the whole order, and keeps it. */
	public function startRequest(int $orderId): array {
		$api = $this->gamma()->api();
		if (!$api) {
			throw new \GammaWalletApiError(401, '0392', 'IntegrationTokenMissing');
		}
		$this->load->model('checkout/order');
		$orderInfo = $this->model_checkout_order->getOrder($orderId);
		$request = $api->startCredit([
			'reference'    => $this->gamma()->reference($orderId),
			'total'        => round($this->orderTotal($orderInfo), 2),
			'currencyCode' => $orderInfo['currency_code'],
		]);
		$this->saveRow($orderId, [
			'credit_request' => $request['creditRequest'], 'credit_request_id' => $request['requestId'],
			'credit_expires_on' => $request['expiresOn'], 'credit_link' => $request['link'], 'credit_qr' => $request['qrPngBase64'] ?? '',
		]);

		return $request;
	}

	public function isSettled(int $orderId): bool {
		return (bool)$this->getRow($orderId)['settled_request_id'];
	}

	/** Paid (settled now or before), Waiting with the seconds left, or Expired. */
	public function creditStatus(int $orderId): array {
		if ($this->isSettled($orderId)) {
			return ['status' => 'Paid'];
		}
		$row = $this->getRow($orderId);
		$api = $this->gamma()->api();
		if (!$row['credit_request'] || !$api) {
			return ['status' => 'Expired'];
		}
		$checked = $api->checkCredit($row['credit_request']);
		if ($checked['status'] === 'Paid') {
			$this->markSettled($orderId, $checked);

			return ['status' => 'Paid'];
		}

		return ['status' => $checked['status'], 'secondsLeft' => (int)$checked['secondsLeft']];
	}

	/** Records a settled request once: the order moves to the "settled" status and the QR code goes. */
	public function markSettled(int $orderId, array $checked): void {
		if ($this->isSettled($orderId)) {
			return;
		}
		$requestId = (string)($checked['requestId'] ?? '');
		$this->saveRow($orderId, ['credit_qr' => '', 'settled_request_id' => $requestId ?: 'settled']);
		$this->load->language('extension/gamma_wallet/payment/gamma_wallet');
		$this->load->model('checkout/order');
		$this->model_checkout_order->addHistory($orderId, (int)($this->config->get('payment_gamma_wallet_settled_status_id') ?: 2),
			sprintf($this->language->get('text_settled_comment'), $requestId), true);
	}
}
