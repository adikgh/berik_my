<?php
// Client cabinet personal link. The same file lives in adm, pos and my config folders; it holds no secret.
// Secret: DB sys_setting.client_link_secret (dev ▸ Кілттер), else the gitignored config/client_link_secret.php.
// Changing the secret turns off all old links.
// Address follows the host: live (*.gprog.kz) -> cabinet_url (default myb.gprog.kz), local -> my.berik.c
require_once __DIR__ . '/sys_set.php';
if (!defined('CABINET_URL')) define('CABINET_URL', preg_match('~gprog\.kz$~i', (string)($_SERVER['HTTP_HOST'] ?? '')) ? sys_set('cabinet_url', 'https://myb.gprog.kz') : 'http://my.berik.c');
if (!defined('CLIENT_LINK_SECRET')) {
	$cl_sec = sys_set('client_link_secret');
	if ($cl_sec === '' && is_file(__DIR__ . '/client_link_secret.php')) $cl_sec = (string)(include __DIR__ . '/client_link_secret.php');
	define('CLIENT_LINK_SECRET', $cl_sec);
	unset($cl_sec);
}

function client_link_sig(int $uid): string {
	if (CLIENT_LINK_SECRET === '') return bin2hex(random_bytes(16)); // no secret: no link may pass
	return substr(hash_hmac('sha256', 'client-link|' . $uid, CLIENT_LINK_SECRET), 0, 32);
}

function client_link(int $uid): string {
	return CABINET_URL . '/in.php?u=' . $uid . '&t=' . client_link_sig($uid);
}
