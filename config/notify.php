<?php
// Where the confirmation code is sent. Turn on by choosing a channel and filling its keys.
//   'off'      - registration is hidden, clients enter by personal link
//   'smsc'     - SMS through smsc.kz
//   'whatsapp' - WhatsApp through green-api.com
//   'test'     - nothing is sent, the code is shown on screen (local testing only)
//   'admin'    - the shared WhatsApp setting from the admin site (adm.berik.c/config/wa_conf.php, page /wa/)
const NOTIFY_CHANNEL = 'admin';

const SMSC_LOGIN = '';
const SMSC_PASSWORD = '';
const SMSC_SENDER = '';

const WA_API_URL = 'https://api.green-api.com';
const WA_INSTANCE = '';
const WA_TOKEN = '';

const CODE_TEXT = 'BERIK POS: кіру коды %s';

function notify_admin(): bool {
	static $ok = null;
	if ($ok === null) {
		$f = __DIR__ . '/../../adm.berik.c/config/wa_lib.php';
		$ok = is_file($f) && (require_once $f) && function_exists('wa_on');
	}
	return $ok;
}

function notify_on(): bool {
	if (NOTIFY_CHANNEL === 'admin') return notify_admin() && wa_on();
	return NOTIFY_CHANNEL !== 'off';
}

// Code is shown on screen (nothing sent)
function notify_test(): bool {
	if (NOTIFY_CHANNEL === 'admin') return notify_admin() && wa_conf()['channel'] === 'test';
	return NOTIFY_CHANNEL === 'test';
}

// Sends the code to a 10-digit phone. Returns true on success.
function send_code(string $phone, string $code): bool {
	$text = sprintf(CODE_TEXT, $code);
	$full = '7' . $phone;

	switch (NOTIFY_CHANNEL) {
		case 'admin':
			if (!notify_admin() || !wa_send_code($phone, $code)) return false;
			if (wa_conf()['channel'] === 'test') $_SESSION['test_code'] = $code;
			return true;

		case 'test':
			$_SESSION['test_code'] = $code;
			return true;

		case 'smsc':
			$res = http_post('https://smsc.kz/sys/send.php', [
				'login' => SMSC_LOGIN, 'psw' => SMSC_PASSWORD, 'phones' => $full,
				'mes' => $text, 'sender' => SMSC_SENDER, 'charset' => 'utf-8', 'fmt' => 3,
			]);
			return $res !== null && isset($res['id']);

		case 'whatsapp':
			$url = WA_API_URL . '/waInstance' . WA_INSTANCE . '/sendMessage/' . WA_TOKEN;
			$res = http_post($url, ['chatId' => $full . '@c.us', 'message' => $text], true);
			return $res !== null && isset($res['idMessage']);
	}
	return false;
}

function http_post(string $url, array $data, bool $json = false): ?array {
	$ch = curl_init($url);
	curl_setopt_array($ch, [
		CURLOPT_POST => true,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT => 10,
		CURLOPT_POSTFIELDS => $json ? json_encode($data) : http_build_query($data),
		CURLOPT_HTTPHEADER => $json ? ['Content-Type: application/json'] : [],
	]);
	$body = curl_exec($ch);
	curl_close($ch);
	$res = is_string($body) ? json_decode($body, true) : null;
	return is_array($res) ? $res : null;
}
