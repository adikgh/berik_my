<?php
// Kaspi online payment of a menu order (xpayment). /pay.php?d=<draft id>
// The order is saved only after the payment; this page opens only in the browser that made the draft.
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/layout.php';
require_once __DIR__ . '/config/xpay.php';

$did = (int)($_GET['d'] ?? 0);
$d = xpay_ready() && in_array($did, array_map('intval', $_SESSION['my_drafts'] ?? []), true) ? xpay_draft($did) : null;
if (!$d) { header('Location: /menu.php'); exit; }
$me = me();
$c = company_by_id((int)$d['company_id']);
$cfg = xpay_cfg((int)$d['company_id']);

// Where the client goes when the order is made
$done = function (int $oid) use ($me): string {
	$_SESSION['my_orders'][] = $oid;
	$_SESSION['my_orders'] = array_slice(array_unique(array_map('intval', $_SESSION['my_orders'])), -20);
	return ($me && !qr_mode() ? '/order.php?id=' : '/menu.php?o=') . $oid;
};

function pay_out(array $r): void {
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode($r, JSON_UNESCAPED_UNICODE);
	exit;
}

// Status check from the page every few seconds; asks xpayment itself, so it works without the webhook too
if (isset($_GET['check'])) {
	if ((int)$d['order_id'] > 0) pay_out(['st' => 'paid', 'url' => $done((int)$d['order_id'])]);
	if ((int)$d['order_id'] === -2) pay_out(['st' => 'err']);
	$x = xpay_last($did);
	if (!$x) pay_out(['st' => 'none']);
	if (!xpay_final($x) && time() - (int)($_SESSION['xpay_ck'] ?? 0) >= 3) { $_SESSION['xpay_ck'] = time(); $x = xpay_sync($x); }
	$d = xpay_draft($did);
	if ((int)$d['order_id'] > 0) pay_out(['st' => 'paid', 'url' => $done((int)$d['order_id'])]);
	pay_out(['st' => xpay_alive($x) ? 'wait' : 'old']);
}

if ((int)$d['order_id'] > 0) { header('Location: ' . $done((int)$d['order_id'])); exit; }

$err = '';
$x = xpay_last($did);
if ((int)$d['order_id'] === -2) $err = 'Төлем түсті, бірақ тапсырыс жасалмады. Мекеме сізбен хабарласады.';
elseif ($x && !xpay_final($x)) {
	$x = xpay_sync($x);
	$d = xpay_draft($did);
	if ((int)$d['order_id'] > 0) { header('Location: ' . $done((int)$d['order_id'])); exit; }
}
if (!$err && !$cfg) $err = 'Бұл мекемеде онлайн төлем қазір өшірулі. Мекемеге хабарласыңыз.';
// New link when there is none yet, or the old one ran out and the client pressed «Жаңа сілтеме»
if (!$err && !xpay_alive($x) && (!$x || isset($_GET['new']))) {
	try {
		if (too_many('xpay_link', 10)) throw new RuntimeException('Тым көп әрекет. Сәл кейін көріңіз.');
		$x = xpay_new_link($cfg, $d);
		hit('xpay_link');
	} catch (RuntimeException $e) { $err = $e->getMessage(); $x = null; }
	if (isset($_GET['new']) && !$err) { header('Location: /pay.php?d=' . $did); exit; }
}
$alive = !$err && xpay_alive($x);
$pre = $d['mode'] === 'pre';

head('Kaspi төлем', 'menu', '', $c ? (string)$c['name'] : '');
?>
<p><a href="/menu.php">← Мәзір</a></p>
<div class="card center pay_box">
	<h1><?= $pre ? 'Предоплата' : 'Тапсырысты төлеу' ?></h1>
	<p class="muted"><?= e($c['name'] ?? '') ?> · Kaspi</p>
	<p class="pay_sum"><b><?= money((int)$d['amount']) ?></b></p>
	<?php if ($pre): ?><p class="muted small">Тапсырыс сомасы <?= money((int)$d['total']) ?>. Қалған <?= money((int)$d['total'] - (int)$d['amount']) ?> алғанда төленеді.</p><?php endif; ?>
	<?php if ($err): ?>
		<p class="alert"><?= e($err) ?></p>
	<?php elseif ($alive): ?>
		<p><a class="btn pay_go" href="<?= e($x['qr_token']) ?>">Kaspi-де төлеу</a></p>
		<div id="pay_qr" class="pay_qr" data-url="<?= e($x['qr_token']) ?>"></div>
		<p class="muted small pay_pc">Компьютерден болса, Kaspi қосымшасымен QR-ды сканерлеңіз.</p>
		<p class="muted small">Сілтеме <b id="pay_left"></b> жарамды. Төлем өткен соң тапсырыс қабылданады, бет өзі жаңарады.</p>
	<?php else: ?>
		<p class="muted">Сілтеменің уақыты өтті. Тапсырыс әлі қабылданған жоқ.</p>
		<p><a class="btn" href="/pay.php?d=<?= $did ?>&new=1">Жаңа сілтеме алу</a></p>
	<?php endif; ?>
	<p class="pay_ok" hidden>✓ Төленді, тапсырыс қабылданды</p>
</div>
<style>
.pay_box{max-width:420px;margin:0 auto}
.pay_sum{font-size:28px;margin:8px 0 12px}
.pay_go{display:inline-block;background:#F14635;color:#fff;font-weight:600}
.pay_qr{display:flex;justify-content:center;margin:12px 0}
.pay_qr svg{width:200px;height:200px}
.pay_ok{font-size:20px;color:var(--green,#0EA768);font-weight:600}
@media (max-width:700px){.pay_qr,.pay_pc{display:none}}
</style>
<?php if ($alive): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcode-generator/1.4.4/qrcode.min.js"></script>
<script>
(function () {
	var box = document.getElementById('pay_qr'), left = <?= max(0, strtotime((string)$x['expire_dt']) - time()) ?>, lt = document.getElementById('pay_left');
	// Phone: straight into the Kaspi app once per link; back in the browser the page keeps checking the payment
	var mob = window.matchMedia && matchMedia('(pointer: coarse)').matches && /Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent), fk = 'xp_open_' + box.dataset.url.slice(-12);
	try { if (mob && !sessionStorage.getItem(fk)) { sessionStorage.setItem(fk, '1'); location.href = box.dataset.url; } } catch (e) {}
	try { var qr = qrcode(0, 'M'); qr.addData(box.dataset.url); qr.make(); box.innerHTML = qr.createSvgTag(5, 8); } catch (e) {}
	var tick = function () { left = Math.max(0, left - 1); lt.textContent = Math.floor(left / 60) + ':' + ('0' + left % 60).slice(-2); };
	tick(); setInterval(tick, 1000);
	var busy = false, check = function () {
		if (busy) return; busy = true;
		fetch('/pay.php?d=<?= $did ?>&check=1', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (r) {
			busy = false;
			if (r.st === 'paid') { document.querySelector('.pay_ok').hidden = false; setTimeout(function () { location.href = r.url; }, 1200); return; }
			if (r.st === 'old' || r.st === 'err') { location.reload(); return; }
			setTimeout(check, 4000);
		}).catch(function () { busy = false; setTimeout(check, 6000); });
	};
	setTimeout(check, 4000);
	document.addEventListener('visibilitychange', function () { if (!document.hidden) check(); }); // back from the Kaspi app
})();
</script>
<?php endif; ?>
<?php foot();
