<?php
// Confirmation code at sign-in (WhatsApp), for the client cabinet (my) and the QR menu (qr).
// Turned on in the developer panel ▸ WhatsApp ▸ «Қай сайтқа кіргенде код сұралады» (login[my] / login[qr]).
// The shared WhatsApp code (adm.berik.c/config/wa_lib.php) is loaded by config/notify.php.
// After a right code the device is remembered for trust_days (cookie mtd, signed), then only the password is asked.

function code_site(): string {
	return qr_mode() ? 'qr' : 'my';
}

// Is the code asked on this host
function code_on(): bool {
	return NOTIFY_CHANNEL === 'admin' && notify_admin() && function_exists('wa_login_on') && wa_login_on(code_site());
}

// Secret for the device cookie: config/auth_key.php (not in git), made on first use
function code_key(): string {
	static $k = null;
	if ($k === null) {
		$f = __DIR__ . '/auth_key.php';
		$k = is_file($f) ? (string)(include $f) : '';
		if (strlen($k) < 32) {
			$k = bin2hex(random_bytes(32));
			@file_put_contents($f, "<?php\n// Device cookie secret (my.berik.c). Not in git. Made automatically.\nreturn '" . $k . "';\n", LOCK_EX);
		}
	}
	return $k;
}

// Signature ties the cookie to the user and his current password: a new password asks the code again
function code_sig(int $uid, int $exp, string $pass): string {
	return hash_hmac('sha256', $uid . '|' . $exp . '|' . code_site() . '|' . substr(hash('sha256', $pass), 0, 16), code_key());
}

function code_trusted(int $uid, string $pass): bool {
	$p = explode('.', (string)($_COOKIE['mtd'] ?? ''));
	if (count($p) !== 3 || (int)$p[0] !== $uid || (int)$p[1] < time()) return false;
	return hash_equals(code_sig($uid, (int)$p[1], $pass), $p[2]);
}

function code_trust(int $uid, string $pass): void {
	$days = max(1, min(365, (int)(wa_conf()['trust_days'] ?? 30)));
	$exp = time() + 86400 * $days;
	$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
	setcookie('mtd', $uid . '.' . $exp . '.' . code_sig($uid, $exp, $pass), ['expires' => $exp, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
}
