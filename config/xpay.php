<?php
// Kaspi online payment through xpayment.kz (API v2). Every company connects its own xpayment account:
// key, cashbox name (device_name), webhook secret and payment options live in company_xpay
// (admin ▸ Компания ▸ Онлайн төлем, or straight in the database).
// Flow: menu order → a draft (retail_orders_draft, not an order yet) → /pay.php makes a Kaspi link (5 min) →
// client pays (full or prepay) → webhook /xpay_hook.php or the pay page's own check reads the payment →
// only then the order is saved (place_order), marked paid and sent to the kassa and Telegram.

const XPAY_API = 'https://api.xpayment.kz/v2';

function xpay_ready(): bool {
	static $ok = null;
	if ($ok === null) {
		try {
			$ok = (int)db()->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('company_xpay', 'retail_orders_xpay', 'retail_orders_draft')")->fetchColumn() === 3;
		} catch (Throwable $e) { $ok = false; }
	}
	return $ok;
}

// Settings of a company whose online payment is on, or null
function xpay_cfg(int $cid): ?array {
	if (!$cid || !xpay_ready() || !function_exists('curl_init')) return null;
	$q = db()->prepare("SELECT * FROM company_xpay WHERE company_id = ? AND is_on = 1 AND api_key <> '' AND device_name <> ''");
	$q->execute([$cid]);
	$r = $q->fetch() ?: null;
	if ($r && !xpay_opts($r)['full'] && !xpay_opts($r)['pre'] && !xpay_opts($r)['later']) return null;
	return $r;
}

// What the client may choose: pre = prepay sum (0 = no prepay), full, later (pay on receipt, no Kaspi). def = first allowed.
// Prepay is only for delivery and takeaway; a cafe/table order pays in full.
function xpay_opts(array $cfg): array {
	$pre = (int)($cfg['allow_prepay'] ?? 1) ? max(0, (int)($cfg['prepay_sum'] ?? 0)) : 0;
	$full = (int)($cfg['allow_full'] ?? 1) === 1;
	$later = (int)($cfg['allow_later'] ?? 0) === 1;
	$def = $pre ? 'pre' : ($full ? 'full' : 'later');
	return ['pre' => $pre, 'full' => $full, 'later' => $later, 'def' => $def];
}

// One call to the API. Returns [http code, decoded body or null]
function xpay_call(string $key, string $method, string $path, ?array $body = null): array {
	$ch = curl_init(XPAY_API . $path);
	$h = ['Authorization: Bearer ' . $key, 'Accept: application/json'];
	if ($body !== null) { $h[] = 'Content-Type: application/json'; curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE)); }
	curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h, CURLOPT_RETURNTRANSFER => true,
		CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 20]);
	$res = curl_exec($ch);
	$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
	if ($res === false) error_log('xpay: ' . curl_error($ch));
	curl_close($ch);
	$j = is_string($res) ? json_decode($res, true) : null;
	if ($code >= 400) error_log('xpay ' . $method . ' ' . $path . ': ' . $code . ' ' . mb_substr((string)$res, 0, 300));
	return [$code, is_array($j) ? $j : null];
}

// Checks the order (prices from the base, zones, stop list) and keeps it as a draft until it is paid. Returns the draft id.
function xpay_draft_new(array $cfg, array $c, array $in, ?array $me, string $mode): int {
	$in['src'] = qr_mode() ? 'qr' : 'my';
	$chk = place_order($c, $in, $me, true);
	$total = (int)$chk['total'];
	$o = xpay_opts($cfg);
	$can_pre = $o['pre'] > 0 && in_array((int)$chk['type'], [1, 2], true) && $o['pre'] < $total;
	if ($mode === 'pre' && !$can_pre) $mode = $o['full'] ? 'full' : ($can_pre ? 'pre' : '');
	if ($mode === 'full' && !$o['full']) $mode = $can_pre ? 'pre' : '';
	if ($mode === '') throw new RuntimeException('Бұл тапсырысты онлайн төлеу мүмкін емес. Мекемеге хабарласыңыз.');
	$amount = $mode === 'pre' ? $o['pre'] : $total;
	$data = ['in' => $in, 'me' => $me ? ['id' => (int)$me['id'], 'phone' => (string)$me['phone'], 'name' => (string)$me['name']] : null];
	db()->prepare('INSERT INTO retail_orders_draft (company_id, data, total, amount, mode, order_id, ins_dt) VALUES (?, ?, ?, ?, ?, 0, NOW())')
		->execute([(int)$c['id'], json_encode($data, JSON_UNESCAPED_UNICODE), $total, $amount, $mode]);
	$id = (int)db()->lastInsertId();
	$_SESSION['my_drafts'][] = $id;
	$_SESSION['my_drafts'] = array_slice(array_unique(array_map('intval', $_SESSION['my_drafts'])), -20);
	return $id;
}

function xpay_draft(int $id): ?array {
	$q = db()->prepare('SELECT * FROM retail_orders_draft WHERE id = ?');
	$q->execute([$id]);
	return $q->fetch() ?: null;
}

// Last Kaspi link of the draft
function xpay_last(int $did): ?array {
	$q = db()->prepare('SELECT * FROM retail_orders_xpay WHERE draft_id = ? ORDER BY id DESC LIMIT 1');
	$q->execute([$did]);
	return $q->fetch() ?: null;
}

function xpay_final(?array $x): bool {
	return $x && in_array(strtoupper((string)$x['status']), ['COMPLETED', 'CANCELLED', 'EXPIRED', 'FAILED', 'REFUNDED', 'PARTIALLY_REFUNDED'], true);
}

function xpay_alive(?array $x): bool {
	return $x && !xpay_final($x) && $x['expire_dt'] && strtotime($x['expire_dt']) > time();
}

// New 5-minute Kaspi link. Earlier unpaid links of the same draft are cancelled by xpayment itself (same merchant_order_id).
function xpay_new_link(array $cfg, array $d): array {
	[$code, $r] = xpay_call($cfg['api_key'], 'POST', '/payments/link', [
		'device_name' => $cfg['device_name'], 'amount' => (int)$d['amount'], 'merchant_order_id' => 'berik-d' . (int)$d['id'],
	]);
	if (($code !== 200 && $code !== 201) || empty($r['payment_id']) || empty($r['qr_token'])) {
		$err = (string)($r['error'] ?? '');
		throw new RuntimeException(in_array($err, ['NO_ACTIVE_BILLING', 'INSUFFICIENT_BALANCE', 'PAYMENT_BLOCKED', 'DEVICE_INACTIVE', 'DEVICE_TOKEN_REVOKED', 'KASPI_ERROR', 'DEVICE_NOT_FOUND', 'MONTHLY_PAYMENT_LIMIT_REACHED', 'DAILY_TX_LIMIT_REACHED'], true)
			? 'Kaspi төлемі уақытша істемей тұр. Мекемеге хабарласыңыз.' : 'Kaspi сілтемесі ашылмады. Қайта көріңіз.');
	}
	$exp = !empty($r['expire_date']) ? date('Y-m-d H:i:s', strtotime($r['expire_date'])) : date('Y-m-d H:i:s', time() + 290);
	db()->prepare('INSERT INTO retail_orders_xpay (draft_id, order_id, company_id, payment_id, qr_token, amount, status, env, expire_dt, ins_dt, upd_dt) VALUES (?, 0, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())')
		->execute([(int)$d['id'], (int)$d['company_id'], $r['payment_id'], $r['qr_token'], (int)$d['amount'], strtoupper((string)($r['status'] ?? 'QR_TOKEN_CREATED')), (string)($r['environment'] ?? 'live'), $exp]);
	return xpay_last((int)$d['id']);
}

// Reads the payment from xpayment (never trusts the webhook body); once COMPLETED for the right sum the order is made
function xpay_sync(array $x): array {
	$cfg = xpay_cfg((int)$x['company_id']);
	if (!$cfg) {
		$q = db()->prepare('SELECT * FROM company_xpay WHERE company_id = ?'); // paid while the switch was turned off: still finish it
		$q->execute([(int)$x['company_id']]);
		$cfg = $q->fetch() ?: null;
		if (!$cfg || $cfg['api_key'] === '') return $x;
	}
	[$code, $p] = xpay_call($cfg['api_key'], 'GET', '/payments/' . rawurlencode($x['payment_id']));
	if ($code !== 200 || !$p || empty($p['status'])) return $x;
	$st = strtoupper((string)$p['status']);
	db()->prepare('UPDATE retail_orders_xpay SET status = ?, upd_dt = NOW() WHERE id = ?')->execute([$st, (int)$x['id']]);
	$x['status'] = $st;
	if ($st === 'COMPLETED' && (int)round((float)$p['amount']) === (int)$x['amount']) $x['order_id'] = xpay_paid($x);
	return $x;
}

// Payment received: saves the order from the draft once (even if the webhook and the page come together), marks it paid
function xpay_paid(array $x): int {
	$db = db();
	$did = (int)$x['draft_id'];
	$claim = $db->prepare('UPDATE retail_orders_draft SET order_id = -1 WHERE id = ? AND order_id = 0');
	$claim->execute([$did]);
	$d = xpay_draft($did);
	if (!$claim->rowCount()) return max(0, (int)($d['order_id'] ?? 0)); // already done (or being done right now)
	$c = company_by_id((int)$d['company_id']);
	$data = json_decode((string)$d['data'], true) ?: [];
	$sum = (int)$x['amount'];
	$in = (array)($data['in'] ?? []);
	$in['pay_txt'] = 'Kaspi онлайн ' . ($d['mode'] === 'pre' ? 'предоплата ' : '') . money($sum) . ' ✅';
	try {
		if (!$c) throw new RuntimeException('company');
		[$oid] = place_order($c, $in, $data['me'] ?? null, false, true);
	} catch (Throwable $e) {
		error_log('xpay: draft ' . $did . ' paid but order failed: ' . $e->getMessage());
		$db->prepare('UPDATE retail_orders_draft SET order_id = -2 WHERE id = ?')->execute([$did]);
		if ($c) tg_send($c, "⚠️ <b>Kaspi онлайн төлем түсті, бірақ тапсырыс жасалмады</b>\n<b>Сома:</b> " . money($sum) . "\n<b>Төлем:</b> " . $x['payment_id'] . "\nКлиентпен хабарласыңыз.");
		return 0;
	}
	xpay_mark_paid((int)$oid, $sum);
	order_log_add((int)$oid, 'xpay', '', (string)$x['payment_id']);
	$db->prepare('UPDATE retail_orders_draft SET order_id = ?, paid_dt = NOW() WHERE id = ?')->execute([(int)$oid, $did]);
	$db->prepare('UPDATE retail_orders_xpay SET order_id = ? WHERE draft_id = ?')->execute([(int)$oid, $did]);
	return (int)$oid;
}

// Writes the Kaspi payment as the kassa's «Қабылдау» would: prepayment pay_prepay + prepay_method,
// Kaspi part in pay_qr, the rest in pay_cash (courier / on pickup). The status stays «Жаңа» (1) for the kassa to accept.
function xpay_mark_paid(int $oid, int $sum): void {
	$db = db();
	$q = $db->prepare('SELECT * FROM retail_orders WHERE id = ?');
	$q->execute([$oid]);
	$o = $q->fetch();
	if (!$o || (string)$o['payment_method'] !== 'unpaid') return;
	$total = (int)$o['total'];
	$qr = min($sum, $total);
	$rest = $total - $qr;
	$has = function (string $col) use ($db): bool {
		$s = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'retail_orders' AND COLUMN_NAME = ?");
		$s->execute([$col]);
		return (bool)$s->fetchColumn();
	};
	$cols = ['payment_method' => $rest > 0 ? 'mixed' : 'qr', 'pay_qr' => $qr, 'pay_cash' => $rest, 'paid' => 1];
	if ($has('pay_prepay')) $cols['pay_prepay'] = $qr;
	if ($has('prepay_method')) $cols['prepay_method'] = 'qr';
	$set = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($cols)));
	$u = $db->prepare("UPDATE retail_orders SET $set, upd_dt = NOW() WHERE id = ? AND payment_method = 'unpaid'");
	$u->execute(array_merge(array_values($cols), [$oid]));
	if (!$u->rowCount()) return;
	foreach ($cols as $k => $v) if ((string)($o[$k] ?? '') !== (string)$v) order_log_add($oid, $k, (string)($o[$k] ?? ''), (string)$v);
	if ($sum > $total) error_log('xpay: order ' . $oid . ' paid ' . $sum . ' > total ' . $total);
}
