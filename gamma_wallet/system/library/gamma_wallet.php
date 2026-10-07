<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * Gamma Wallet for OpenCart — the calls to the Gamma Integration API, the settings, and the per-order
 * data both the shop and the admin use. Loaded with require_once from the extension's controllers and
 * model.
 *
 * Every call carries the shop's integration token (GWINT_…) and runs on the shop's server, never in
 * the customer's browser. Gamma answers in an envelope {version, statusCode, message, result, error};
 * this file returns `result` or throws GammaWalletApiError.
 */

if (!class_exists('GammaWalletApiError')) {

class GammaWalletApiError extends \Exception {
	/** @var int HTTP status, 0 when Gamma could not be reached at all */
	public int $status;
	public ?string $identifier;
	public ?string $errorName;

	public function __construct(int $status, ?string $identifier, ?string $errorName) {
		$this->status = $status;
		$this->identifier = $identifier;
		$this->errorName = $errorName;
		parent::__construct(sprintf('Gamma answered HTTP %d: %s%s', $status, $errorName ?: 'no details', $identifier ? ' (' . $identifier . ')' : ''));
	}

	/** Worth trying again later: Gamma unreachable, busy or failing. */
	public function isRetryable(): bool {
		return $this->status === 0 || $this->status === 429 || $this->status >= 500;
	}
}

class GammaWalletApi {
	/** Can be overridden for testing: define('GAMMA_WALLET_API_URL', 'https://…'); in config.php. */
	public const DEFAULT_URL = 'https://integration.gamma-wallet.com';
	public const VERSION = '1.1.0';

	public function __construct(private string $token) {}

	public static function baseUrl(): string {
		return rtrim(defined('GAMMA_WALLET_API_URL') ? GAMMA_WALLET_API_URL : self::DEFAULT_URL, '/');
	}

	/** Who the token belongs to: business, currency, whether customers can claim, token expiry. */
	public function connection(int $timeout = 20): array {
		return $this->send('GET', '/api/Connection/Me', null, $timeout);
	}

	/** Declares a paid order. Safe to repeat with the same reference: Gamma returns the same bill. */
	public function createBill(array $bill, int $timeout = 20): array {
		return $this->send('POST', '/api/Bill/Create', $bill, $timeout);
	}

	public function getBill(string $billId, int $timeout = 20): array {
		return $this->send('GET', '/api/Bill/Get/' . rawurlencode($billId), null, $timeout);
	}

	/** A new store-credit request for the whole order. */
	public function startCredit(array $order): array {
		return $this->send('POST', '/api/Credit/Start', $order);
	}

	/** Waiting, Paid or Expired, with the seconds left. */
	public function checkCredit(string $creditRequest, int $timeout = 20): array {
		return $this->send('POST', '/api/Credit/Check', ['creditRequest' => $creditRequest], $timeout);
	}

	/**
	 * JSON with amounts written exactly: 0.8, never 0.80000000000000004. With serialize_precision = 17,
	 * which many servers still set, Gamma would sign a total the customer's app does not match.
	 */
	public static function json(array $body): string {
		$previous = ini_get('serialize_precision');
		ini_set('serialize_precision', '-1');
		$json = json_encode($body, JSON_PRESERVE_ZERO_FRACTION);
		ini_set('serialize_precision', $previous);

		return $json;
	}

	private function send(string $method, string $path, ?array $body = null, int $timeout = 20): array {
		$headers = [
			'Authorization: Bearer ' . $this->token,
			'Accept: application/json',
			'User-Agent: gamma-wallet-opencart/' . self::VERSION,
		];
		$curl = curl_init(self::baseUrl() . $path);
		curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_TIMEOUT, $timeout);
		curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, min(10, $timeout));
		if ($body !== null) {
			$headers[] = 'Content-Type: application/json';
			curl_setopt($curl, CURLOPT_POSTFIELDS, self::json($body));
		}
		curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

		$raw = curl_exec($curl);
		if ($raw === false) {
			$message = curl_error($curl);
			curl_close($curl);
			throw new GammaWalletApiError(0, null, 'Gamma could not be reached: ' . $message);
		}
		$status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
		curl_close($curl);

		$envelope = json_decode((string)$raw, true);
		if ($status >= 200 && $status < 300 && is_array($envelope) && isset($envelope['result']) && is_array($envelope['result'])) {
			return $envelope['result'];
		}
		$error = (is_array($envelope) && isset($envelope['error']) && is_array($envelope['error'])) ? $envelope['error'] : [];

		throw new GammaWalletApiError($status, $error['identifier'] ?? null, $error['message'] ?? null);
	}
}

/**
 * The extension's settings, the cached connection check and the per-order data (table
 * PREFIX_gamma_wallet_order). The settings the shop owner saves are "payment_gamma_wallet_*"; what the
 * extension keeps for itself (the connection check, the installation secret, the install date) is
 * "gamma_wallet_data_*", so saving the form never wipes it.
 */
class GammaWallet {
	/** Paid outside the shop, after the order is placed. */
	public const PAY_LATER = ['cod', 'bank_transfer', 'cheque'];
	public const STALE_SECONDS = 3600;
	public const DATA_CODE = 'gamma_wallet_data';
	/** No more automatic attempts after this many failures; the shop can still send the reward by hand. */
	public const MAX_ATTEMPTS = 6;
	/** How long after a code was shown the shop keeps asking Gamma whether it was settled. */
	public const RECONCILE_HOURS = 6;

	private const COLUMNS = ['bill_id', 'code', 'link', 'qr_url', 'bill_status', 'claimed_on', 'error', 'attempts', 'emailed',
		'credit_request', 'credit_request_id', 'credit_expires_on', 'credit_link', 'credit_qr', 'settled_request_id'];

	public function __construct(private $registry) {}

	private function config() {
		return $this->registry->get('config');
	}

	private function db() {
		return $this->registry->get('db');
	}

	public function get(string $key, $default = null) {
		$value = $this->config()->get('payment_gamma_wallet_' . $key);

		return $value === null ? $default : $value;
	}

	public function token(): string {
		return trim((string)$this->get('token', ''));
	}

	public function api(): ?GammaWalletApi {
		return $this->token() === '' ? null : new GammaWalletApi($this->token());
	}

	public function rewardsEnabled(): bool {
		return (bool)$this->get('rewards', 0);
	}

	public function rewardInEmail(): bool {
		return (bool)$this->get('reward_email', 0);
	}

	/** Statuses that mean the money is in: an order entering one of them earns its reward. */
	public function paidStatuses(): array {
		return array_map('intval', (array)$this->get('paid_statuses', [2, 3, 5, 15]));
	}

	/** The status a store-credit order waits in until it is settled. */
	public function awaitingStatus(): int {
		return (int)$this->get('order_status_id', 0);
	}

	/** "cod.cod" → "cod": the payment extension an order was paid with. */
	public static function methodCode(array $orderInfo): string {
		$method = $orderInfo['payment_method'] ?? '';
		$code = is_array($method) ? ($method['code'] ?? '') : (string)($orderInfo['payment_code'] ?? '');

		return (string)strtok($code, '.');
	}

	public static function isPayLater(string $code): bool {
		return in_array($code, self::PAY_LATER, true);
	}

	/** Payment extensions left unticked in the settings earn no reward. Pay-later ones are unticked by default. */
	public function methodEarnsReward(string $code): bool {
		if ($code === '' || $code === 'gamma_wallet') {
			return false;
		}
		$excluded = json_decode((string)$this->get('no_reward_methods', ''), true);
		if (!is_array($excluded)) {
			$excluded = self::PAY_LATER;
		}

		return !in_array($code, $excluded, true);
	}

	// ---------------------------------------------------------------- data the extension keeps

	public function data(string $key): ?string {
		$value = $this->config()->get(self::DATA_CODE . '_' . $key);

		return $value === null ? null : (string)$value;
	}

	public function setData(string $key, string $value): void {
		$db = $this->db();
		$full = self::DATA_CODE . '_' . $key;
		$db->query("DELETE FROM `" . DB_PREFIX . "setting` WHERE `store_id` = '0' AND `code` = '" . self::DATA_CODE . "' AND `key` = '" . $db->escape($full) . "'");
		$db->query("INSERT INTO `" . DB_PREFIX . "setting` SET `store_id` = '0', `code` = '" . self::DATA_CODE . "', `key` = '" . $db->escape($full) . "', `value` = '" . $db->escape($value) . "', `serialized` = '0'");
		$this->config()->set($full, $value);
	}

	/** The installation secret; made at install, and here only if an older install has none yet. */
	private function secret(): string {
		$secret = (string)$this->data('secret');
		if ($secret === '') {
			$secret = bin2hex(random_bytes(24));
			$this->setData('secret', $secret);
		}

		return $secret;
	}

	/** A key that only this shop's server can make, for the customer's order status links. */
	public function orderKey(int $orderId): string {
		return substr(hash_hmac('sha256', 'order:' . $orderId, $this->secret()), 0, 32);
	}

	/**
	 * The reference Gamma knows an order by: "OC-3f9a1c-42". Order numbers start again at 1 in every
	 * OpenCart installation, so a business with two shops would reuse them; the short tag, fixed for
	 * this installation (and kept when the extension is reinstalled), keeps them apart.
	 */
	public function reference(int $orderId): string {
		return 'OC-' . substr(hash('sha256', 'reference:' . $this->secret()), 0, 6) . '-' . $orderId;
	}

	/** When the extension was (last) installed: orders placed before it never earn a reward. */
	public function installedOn(): int {
		return (int)$this->data('installed_on');
	}

	// ---------------------------------------------------------------- the connection, cached

	public function connection(): ?array {
		$value = json_decode((string)$this->data('connection'), true);

		return is_array($value) ? $value : null;
	}

	public function checkConnection(int $timeout = 20, $language = null): ?array {
		$api = $this->api();
		if (!$api) {
			$this->setData('connection', '');

			return null;
		}
		try {
			$connection = $api->connection($timeout);
			$connection['checkedOn'] = time();
		} catch (GammaWalletApiError $e) {
			$connection = ['error' => self::explain($e, $language), 'checkedOn' => time()];
			// Gamma briefly out of reach: keep what it said last time, so the shop keeps working.
			$previous = $this->connection();
			if ($e->isRetryable() && $previous && isset($previous['canClaim'])) {
				$connection['canClaim'] = $previous['canClaim'];
				if (isset($previous['currencyCode'])) {
					$connection['currencyCode'] = $previous['currencyCode'];
				}
			}
		}
		$this->setData('connection', json_encode($connection));

		return $connection;
	}

	public function refreshIfStale(int $timeout = 5): void {
		if ($this->token() === '') {
			return;
		}
		$connection = $this->connection();
		if (!$connection || (int)($connection['checkedOn'] ?? 0) < time() - self::STALE_SECONDS) {
			$this->checkConnection($timeout);
		}
	}

	/** True while the business's active Gamma service is a Reward service. The extension does nothing for customers otherwise. */
	public function rewardServiceActive(): bool {
		if ($this->token() === '') {
			return false;
		}
		$this->refreshIfStale(3);

		return !empty($this->connection()['canClaim']);
	}

	/** True when the currency is the business's, or when that cannot be known yet. */
	public function currencyMatches(string $code): bool {
		$business = $this->connection()['currencyCode'] ?? null;

		return !$business || strtoupper($business) === strtoupper($code);
	}

	/** A sentence the shop owner can act on, from the extension's language file when one is loaded. */
	public static function explain(GammaWalletApiError $e, $language = null): string {
		$key = match (true) {
			in_array($e->identifier, ['0388', '0389', '0390', '0393'], true) => 'error_api_' . $e->identifier,
			$e->status === 0 => 'error_api_unreachable',
			$e->status === 429 => 'error_api_busy',
			default => '',
		};
		if ($key !== '' && $language) {
			$text = (string)$language->get($key);
			if ($text !== '' && $text !== $key) {
				return $text;
			}
		}

		return $e->getMessage();
	}

	/** "GWINT_Ab12Cd3…x9Yz": enough to recognise a token, never enough to use it. */
	public static function masked(string $token): string {
		return strlen($token) > 17 ? substr($token, 0, 13) . '…' . substr($token, -4) : '…';
	}

	/** An address Gamma gave, shown in pages and emails only when it is a plain https link. */
	public static function safeUrl(?string $url): string {
		$url = (string)$url;

		return preg_match('~^https://[^\s"<>]+$~i', $url) ? $url : '';
	}

	// ---------------------------------------------------------------- the per-order row

	public function row(int $orderId): array {
		$query = $this->db()->query("SELECT * FROM `" . DB_PREFIX . "gamma_wallet_order` WHERE `order_id` = '" . $orderId . "'");

		return array_merge(array_fill_keys(self::COLUMNS, null), $query->row ?: []);
	}

	public function saveRow(int $orderId, array $values): void {
		$db = $this->db();
		$set = ["`date_modified` = NOW()"];
		foreach ($values as $column => $value) {
			if (in_array($column, self::COLUMNS, true)) {
				$set[] = "`" . $column . "` = " . ($value === null ? 'NULL' : "'" . $db->escape((string)$value) . "'");
			}
		}
		$db->query("INSERT INTO `" . DB_PREFIX . "gamma_wallet_order` SET `order_id` = '" . $orderId . "', " . implode(', ', $set)
			. " ON DUPLICATE KEY UPDATE " . implode(', ', $set));
	}

	private function ensureRow(int $orderId): void {
		$this->db()->query("INSERT IGNORE INTO `" . DB_PREFIX . "gamma_wallet_order` SET `order_id` = '" . $orderId . "', `date_modified` = NOW()");
	}

	/**
	 * Marks the order settled, once: true only for the request that made the change, so two pages
	 * asking at the same moment can never settle the order twice.
	 */
	public function claimSettlement(int $orderId, string $requestId): bool {
		$this->ensureRow($orderId);
		$db = $this->db();
		$db->query("UPDATE `" . DB_PREFIX . "gamma_wallet_order` SET `settled_request_id` = '" . $db->escape($requestId)
			. "', `credit_qr` = '', `date_modified` = NOW() WHERE `order_id` = '" . $orderId . "' AND `settled_request_id` IS NULL");

		return $db->countAffected() === 1;
	}

	/**
	 * Reserves the right to ask Gamma for a new store-credit code, for one request only, so an order
	 * never has two live codes (two tabs, a double click, a repeated checkout post). $firstOnly: only
	 * when the order never had a code. Otherwise only once the last code expired over 15 seconds ago
	 * (Gamma still accepts a code a few seconds past its time). The reservation counts as a live code
	 * for 30 seconds. Returns the previous expiry (to give the slot back if Gamma cannot be reached),
	 * or null when another request holds it.
	 */
	public function claimCodeSlot(int $orderId, bool $firstOnly): ?string {
		$this->ensureRow($orderId);
		$previous = (string)$this->row($orderId)['credit_expires_on'];
		$db = $this->db();
		$where = "`order_id` = '" . $orderId . "' AND `settled_request_id` IS NULL";
		$where .= $firstOnly
			? " AND `credit_request` IS NULL AND `credit_expires_on` IS NULL"
			: " AND (`credit_expires_on` IS NULL OR `credit_expires_on` < '" . $db->escape(gmdate('Y-m-d\TH:i:s\Z', time() - 15)) . "')";
		$db->query("UPDATE `" . DB_PREFIX . "gamma_wallet_order` SET `credit_expires_on` = '" . $db->escape(gmdate('Y-m-d\TH:i:s\Z', time() + 30)) . "' WHERE " . $where);

		return $db->countAffected() === 1 ? $previous : null;
	}

	/** Gives a reserved slot back when Gamma could not start the code. */
	public function releaseCodeSlot(int $orderId, string $previous): void {
		$db = $this->db();
		$db->query("UPDATE `" . DB_PREFIX . "gamma_wallet_order` SET `credit_expires_on` = "
			. ($previous !== '' ? "'" . $db->escape($previous) . "'" : 'NULL') . " WHERE `order_id` = '" . $orderId . "'");
	}

	/** Store-credit orders not settled yet whose code was shown in the last hours. */
	public function awaitingSettlement(): array {
		$query = $this->db()->query("SELECT `order_id` FROM `" . DB_PREFIX . "gamma_wallet_order` WHERE `settled_request_id` IS NULL"
			. " AND `credit_request` IS NOT NULL AND `date_modified` > DATE_SUB(NOW(), INTERVAL " . self::RECONCILE_HOURS . " HOUR)");

		return array_map('intval', array_column($query->rows, 'order_id'));
	}

	// ---------------------------------------------------------------- rewards (shared by the shop and the admin)

	/** The order total in the order's own currency, as the customer paid it. */
	public function orderTotal(array $orderInfo): float {
		return (float)$this->registry->get('currency')->format((float)$orderInfo['total'], $orderInfo['currency_code'], (float)$orderInfo['currency_value'], false);
	}

	/** True when this order can ever earn a reward, now or once its payment is confirmed. */
	public function mayEarn(array $orderInfo): bool {
		return $this->rewardsEnabled() && $this->rewardServiceActive() && $this->methodEarnsReward(self::methodCode($orderInfo));
	}

	/** Placed since the extension was installed: older orders never earn a reward. */
	public function placedSinceInstall(array $orderInfo): bool {
		$installedOn = $this->installedOn();

		return $installedOn > 0 && strtotime((string)$orderInfo['date_added']) >= $installedOn;
	}

	/**
	 * True when this order should have a reward QR code with this status: rewards are on, the business
	 * has a Reward service, the payment method earns one, the currencies match, the order was placed
	 * since the install, and the status means the money is in. Orders settled with store credits never
	 * earn one.
	 */
	public function qualifies(array $orderInfo, int $statusId): bool {
		return in_array($statusId, $this->paidStatuses(), true)
			&& $this->orderTotal($orderInfo) > 0
			&& $this->placedSinceInstall($orderInfo)
			&& $this->mayEarn($orderInfo)
			&& $this->currencyMatches((string)$orderInfo['currency_code']);
	}

	/**
	 * Declares the order to Gamma once. Returns true when the order has a bill afterwards. Safe to call
	 * any number of times: Gamma returns the same bill for the same reference.
	 * $statusId: the status the order is entering (the order row may not have it yet).
	 */
	public function ensureBill(array $orderInfo, ?int $statusId = null, int $timeout = 20, $language = null): bool {
		$orderId = (int)$orderInfo['order_id'];
		$row = $this->row($orderId);
		if ($row['bill_id']) {
			return true;
		}
		if (!$this->qualifies($orderInfo, $statusId ?? (int)$orderInfo['order_status_id']) || (int)$row['attempts'] >= self::MAX_ATTEMPTS) {
			return false;
		}
		$api = $this->api();
		if (!$api) {
			return false;
		}
		try {
			$bill = $api->createBill([
				'reference'     => $this->reference($orderId),
				'total'         => round($this->orderTotal($orderInfo), 2),
				'currencyCode'  => $orderInfo['currency_code'],
				'issuedOn'      => date(DATE_ATOM, strtotime($orderInfo['date_added'])),
				'platform'      => 'opencart',
				'pluginVersion' => GammaWalletApi::VERSION,
			], $timeout);
		} catch (GammaWalletApiError $e) {
			$this->saveRow($orderId, ['error' => substr(self::explain($e, $language), 0, 250), 'attempts' => (int)$row['attempts'] + 1]);
			$this->registry->get('log')->write('Gamma Wallet: bill for order ' . $orderId . ' failed: ' . $e->getMessage());

			return false;
		}
		$this->saveRow($orderId, [
			'bill_id' => $bill['billId'], 'code' => $bill['code'], 'link' => $bill['link'], 'qr_url' => $bill['qrImageUrl'],
			'bill_status' => $bill['status'], 'claimed_on' => $bill['claimedOn'] ?? null, 'error' => null,
		]);

		return true;
	}

	/**
	 * The reward email of its own: for orders paid after the order email went out, and for sending the
	 * QR code again by hand. $texts: text_mail_subject, text_mail_title, text_mail_hello, text_mail_body,
	 * text_open, text_qr_alt, from the language file of whoever sends it (the shop or the admin).
	 */
	public function sendRewardEmail(array $orderInfo, array $texts, $language = null): bool {
		$config = $this->config();
		$orderId = (int)$orderInfo['order_id'];
		if (!$this->ensureBill($orderInfo, null, 20, $language) || !$config->get('config_mail_engine')) {
			return false;
		}
		$row = $this->row($orderId);
		$qr = self::safeUrl($row['qr_url']);
		$link = self::safeUrl($row['link']);
		if ($qr === '' || $link === '') {
			return false;
		}
		$e = fn ($text) => htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
		$storeName = html_entity_decode((string)$orderInfo['store_name'], ENT_QUOTES, 'UTF-8');
		$html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . $e($storeName) . '</title></head>'
			. '<body style="margin:0;padding:24px;background:#f3f6f8;font-family:Arial,sans-serif;color:#1d2a33">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:12px">'
			. '<tr><td style="padding:28px 28px 8px">'
			. '<p style="margin:0 0 4px;font-size:14px;color:#5b6b76">' . $e($storeName) . '</p>'
			. '<p style="margin:0 0 16px;font-size:20px;font-weight:bold">' . $e($texts['text_mail_title']) . '</p>'
			. '<p style="margin:0 0 12px;font-size:15px;line-height:1.5">' . $e(sprintf($texts['text_mail_hello'], $orderInfo['firstname'])) . '</p>'
			. '<p style="margin:0 0 12px;font-size:15px;line-height:1.5">' . $e(sprintf($texts['text_mail_body'], $orderId)) . '</p>'
			. '</td></tr><tr><td style="padding:8px 28px 28px;text-align:center">'
			. '<img src="' . $e($qr) . '" width="200" height="200" alt="' . $e($texts['text_qr_alt']) . '" style="display:block;margin:0 auto 14px">'
			. '<a href="' . $e($link) . '" style="color:#2e9bcc;font-weight:bold;font-size:15px">' . $e($texts['text_open']) . '</a>'
			. '</td></tr></table></body></html>';
		try {
			$mail = new \Opencart\System\Library\Mail($config->get('config_mail_engine'), [
				'parameter'     => $config->get('config_mail_parameter'),
				'smtp_hostname' => $config->get('config_mail_smtp_hostname'),
				'smtp_username' => $config->get('config_mail_smtp_username'),
				'smtp_password' => html_entity_decode((string)$config->get('config_mail_smtp_password'), ENT_QUOTES, 'UTF-8'),
				'smtp_port'     => $config->get('config_mail_smtp_port'),
				'smtp_timeout'  => $config->get('config_mail_smtp_timeout'),
			]);
			$mail->setTo($orderInfo['email']);
			$mail->setFrom($config->get('config_email'));
			$mail->setSender($storeName);
			$mail->setSubject(sprintf($texts['text_mail_subject'], $storeName, $orderId));
			$mail->setHtml($html);
			$mail->send();
		} catch (\Throwable $error) {
			$this->registry->get('log')->write('Gamma Wallet: reward email for order ' . $orderId . ' failed: ' . $error->getMessage());

			return false;
		}
		$this->saveRow($orderId, ['emailed' => time()]);

		return true;
	}
}

}
