<?php
// xpayment webhook: https://<my site>/xpay_hook.php (set in the xpayment cabinet ▸ «Вебхуки», events payment.*).
// The body has no sum or status: we check the HMAC-SHA256 signature with the company's secret, then read the payment from the API.
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/xpay.php';

$raw = (string)file_get_contents('php://input');
$sig = strtolower(trim((string)($_SERVER['HTTP_X_XPAYMENT_SIGNATURE'] ?? '')));
$ev = json_decode($raw, true);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !is_array($ev) || empty($ev['payment_id']) || !xpay_ready()) { http_response_code(400); exit; }

$q = db()->prepare('SELECT x.*, s.hook_secret FROM retail_orders_xpay x JOIN company_xpay s ON s.company_id = x.company_id WHERE x.payment_id = ? ORDER BY x.id DESC LIMIT 1');
$q->execute([(string)$ev['payment_id']]);
$x = $q->fetch();
if (!$x) { http_response_code(200); exit; } // not ours (e.g. a payment made from the xpayment cabinet)
if ((string)$x['hook_secret'] === '' || !hash_equals(hash_hmac('sha256', $raw, (string)$x['hook_secret']), $sig)) { http_response_code(401); exit; }

http_response_code(200);
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request(); // answer within 10 s, then work
if (strpos((string)($ev['event'] ?? ''), 'payment.') === 0) xpay_sync($x);
