<?php
/**
 * Gamma Wallet for OpenCart — the calls to the Gamma Integration API, and the settings both the
 * shop and the admin read. Loaded with require_once from the extension's controllers and model.
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
	public const VERSION = '1.0.0';

	public function __construct(private string $token) {}

	public static function baseUrl(): string {
		return rtrim(defined('GAMMA_WALLET_API_URL') ? GAMMA_WALLET_API_URL : self::DEFAULT_URL, '/');
	}

	/** Who the token belongs to: business, currency, whether customers can claim, token expiry. */
	public function connection(int $timeout = 20): array {
		return $this->send('GET', '/api/Connection/Me', null, $timeout);
	}

	/** Declares a paid order. Safe to repeat with the same reference: Gamma returns the same bill. */
	public function createBill(array $bill): array {
		return $this->send('POST', '/api/Bill/Create', $bill);
	}

	public function getBill(string $billId): array {
		return $this->send('GET', '/api/Bill/Get/' . rawurlencode($billId));
	}

	/** A new store-credit request for the whole order. */
	public function startCredit(array $order): array {
		return $this->send('POST', '/api/Credit/Start', $order);
	}

	/** Waiting, Paid or Expired, with the seconds left. */
	public function checkCredit(string $creditRequest): array {
		return $this->send('POST', '/api/Credit/Check', ['creditRequest' => $creditRequest]);
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
 * The extension's settings and the cached connection check. The settings the shop owner saves are
 * "payment_gamma_wallet_*"; what the extension keeps for itself (the connection check, the secret
 * that signs the customer's status links) is "gamma_wallet_data_*", so saving the form never wipes it.
 */
class GammaWallet {
	/** Paid outside the shop, after the order is placed. */
	public const PAY_LATER = ['cod', 'bank_transfer', 'cheque'];
	public const STALE_SECONDS = 3600;
	public const DATA_CODE = 'gamma_wallet_data';

	public function __construct(private $registry) {}

	private function config() {
		return $this->registry->get('config');
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
		$db = $this->registry->get('db');
		$full = self::DATA_CODE . '_' . $key;
		$db->query("DELETE FROM `" . DB_PREFIX . "setting` WHERE `store_id` = '0' AND `code` = '" . self::DATA_CODE . "' AND `key` = '" . $db->escape($full) . "'");
		$db->query("INSERT INTO `" . DB_PREFIX . "setting` SET `store_id` = '0', `code` = '" . self::DATA_CODE . "', `key` = '" . $db->escape($full) . "', `value` = '" . $db->escape($value) . "', `serialized` = '0'");
		$this->config()->set($full, $value);
	}

	/** A key that only this shop's server can make, for the customer's order status links. */
	public function orderKey(int $orderId): string {
		$secret = (string)$this->data('secret');
		if ($secret === '') {
			$secret = bin2hex(random_bytes(24));
			$this->setData('secret', $secret);
		}

		return substr(hash_hmac('sha256', 'order:' . $orderId, $secret), 0, 32);
	}

	/**
	 * The reference Gamma knows an order by: "OC-3f9a1c-42". Order numbers start again at 1 in every
	 * OpenCart installation, so a business with two shops (or a reinstalled one) would reuse them; the
	 * short tag, fixed for this installation, keeps them apart.
	 */
	public function reference(int $orderId): string {
		$this->orderKey(0); // makes sure the installation secret exists

		return 'OC-' . substr(hash('sha256', 'reference:' . (string)$this->data('secret')), 0, 6) . '-' . $orderId;
	}

	// ---------------------------------------------------------------- the connection, cached

	public function connection(): ?array {
		$value = json_decode((string)$this->data('connection'), true);

		return is_array($value) ? $value : null;
	}

	public function checkConnection(int $timeout = 20): ?array {
		$api = $this->api();
		if (!$api) {
			$this->setData('connection', '');

			return null;
		}
		try {
			$connection = $api->connection($timeout);
			$connection['checkedOn'] = time();
		} catch (GammaWalletApiError $e) {
			$connection = ['error' => self::explain($e), 'checkedOn' => time()];
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

	public static function explain(GammaWalletApiError $e): string {
		switch ($e->identifier) {
			case '0388': return 'Gamma does not recognise this token. Copy it again from Gamma Business → Integrations.';
			case '0389': return 'This token was disabled or replaced. Create a new one in Gamma Business → Integrations.';
			case '0390': return 'This token has expired. Create a new one in Gamma Business → Integrations.';
			case '0393': return 'The business this token belongs to is not available in Gamma.';
		}
		if ($e->status === 0) {
			return 'Gamma could not be reached. Check that this server can make outgoing HTTPS connections.';
		}
		if ($e->status === 429) {
			return 'Too many requests to Gamma. Try again in a minute.';
		}

		return $e->getMessage();
	}

	/** "GWINT_Ab12Cd3…x9Yz": enough to recognise a token, never enough to use it. */
	public static function masked(string $token): string {
		return strlen($token) > 17 ? substr($token, 0, 13) . '…' . substr($token, -4) : '…';
	}
}

}
