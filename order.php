<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/layout.php';

$me = need_login();
$id = (int)($_GET['id'] ?? 0);
$ids = my_clients((int)$me['id']);

// Shown only if the order belongs to this person
$q = db()->prepare(
	"SELECT o.*, c.name AS company, t.name AS type_name, b.name AS branch
	 FROM retail_orders o
	 LEFT JOIN company c ON c.id = o.company_id
	 LEFT JOIN company_branch b ON b.id = o.branch_id
	 LEFT JOIN retail_orders_type t ON t.id = o.order_type
	 WHERE o.id = ? AND o.client_id IN ($ids)"
);
$q->execute([$id]);
$o = $q->fetch();
if (!$o) { http_response_code(404); head('Табылмады'); echo '<p class="empty">Тапсырыс табылмады.</p><p><a href="/orders.php">← Артқа</a></p>'; foot(); exit; }

$p = db()->prepare(
	"SELECT op.quantity, op.price, COALESCE(NULLIF(p.name_kz, ''), p.name_ru, 'Тағам') AS name
	 FROM retail_orders_products op
	 LEFT JOIN product p ON p.id = op.product_id
	 WHERE op.order_id = ?"
);
$p->execute([$id]);
$items = $p->fetchAll();

$st = status_words('kz');
$s = $st[(int)$o['order_status']] ?? null;

// Status history
$h = db()->prepare("SELECT new_value, ins_dt FROM retail_orders_log WHERE order_id = ? AND field = 'status' AND new_value <> '' ORDER BY id");
$h->execute([$id]);
$hist = $h->fetchAll();

$active = !in_array((int)$o['order_status'], array_merge(DONE_ST, REFUSED_ST), true);

$pay = array_filter([
	'Kaspi QR' => (int)$o['pay_qr'],
	'Қолма-қол' => (int)$o['pay_cash'],
	'Аударым' => (int)$o['pay_transfer'],
	'Алдын ала төлем' => (int)$o['pay_prepay'],
	'Бонус / жеңілдік' => (int)$o['pay_red'],
]);

head('Тапсырыс №' . order_no($o), 'orders', $active ? '<meta http-equiv="refresh" content="30">' : '');
?>
<p class="noprint"><a href="/orders.php">← Тапсырыстарым</a></p>
<div class="card">
	<div class="row">
		<h1>№<?= order_no($o) ?></h1>
		<?= badge($s) ?>
	</div>
	<p class="muted"><?= e($o['company']) ?><?= $o['branch'] && $o['branch'] !== $o['company'] ? ' · ' . e($o['branch']) : '' ?></p>

	<dl class="info">
		<dt>Түрі</dt><dd><?= e($o['type_name']) ?></dd>
		<dt>Уақыты</dt><dd><?= dt($o['ins_dt']) ?></dd>
		<?php if ($o['preorder_dt']): ?><dt>Алдын ала</dt><dd><?= e($o['preorder_dt']) ?></dd><?php endif; ?>
		<?php if ($o['address']): ?><dt>Мекенжай</dt><dd><?= e($o['address']) ?><?= $o['floor_apt'] ? ', ' . e($o['floor_apt']) : '' ?></dd><?php endif; ?>
		<?php if ($o['additional']): ?><dt>Ескертпе</dt><dd><?= e($o['additional']) ?></dd><?php endif; ?>
		<?php if ($o['refuse_reason'] && in_array((int)$o['order_status'], REFUSED_ST, true)): ?><dt>Себебі</dt><dd><?= e($o['refuse_reason']) ?></dd><?php endif; ?>
	</dl>

	<ul class="items">
	<?php foreach ($items as $i): ?>
		<li><span><?= e(strip_tags((string)$i['name'])) ?> <small>× <?= (int)$i['quantity'] ?></small></span><span><?= money((int)$i['price'] * (int)$i['quantity']) ?></span></li>
	<?php endforeach; ?>
	</ul>
	<?php if ((int)$o['pay_delivery'] > 0): ?><div class="row"><span>Жеткізу</span><span><?= money((int)$o['pay_delivery']) ?></span></div><?php endif; ?>
	<div class="row total"><span>Барлығы</span><span><?= money((int)$o['total']) ?></span></div>

	<?php if ($pay): ?>
		<div class="paid">
			<?php foreach ($pay as $k => $v): ?><div class="row muted"><span><?= $k ?></span><span><?= money($v) ?></span></div><?php endforeach; ?>
		</div>
	<?php elseif (!(int)$o['paid']): ?>
		<p class="muted small">Әлі төленбеген</p>
	<?php endif; ?>
</div>

<?php
// Cashback of this order
if (cashback_on()) {
	$cb = db()->prepare("SELECT h.type, SUM(h.amount) a FROM company_client_cashback h JOIN company c ON c.id = h.company_id AND c.cashback_percent > 0
		WHERE h.order_id = ? GROUP BY h.type");
	$cb->execute([$id]);
	$cbs = $cb->fetchAll(PDO::FETCH_KEY_PAIR);
	if ($cbs): ?>
		<div class="card cash-order">
			<?php foreach ($cbs as $t => $a): $a = (int)round((float)$a); if (!$a) continue; ?>
				<div class="row"><span>Кэшбэк: <?= e(mb_strtolower(CASH_TYPES[$t] ?? $t)) ?></span><b class="<?= $a > 0 ? 'plus' : 'minus' ?>"><?= $a > 0 ? '+' : '−' ?><?= money(abs($a)) ?></b></div>
			<?php endforeach; ?>
		</div>
	<?php endif;
}
// Gift given with this order
if (gift_on() && !empty($o['gift_name'])): ?>
	<div class="card cash-order"><div class="row"><span>🎁 Сыйлық</span><b class="plus"><?= e($o['gift_name']) ?></b></div></div>
<?php endif; ?>

<?php if ($hist): ?>
	<h2>Тапсырыс жолы</h2>
	<ol class="card timeline">
		<?php foreach ($hist as $r): $hs = $st[(int)$r['new_value']] ?? null; if (!$hs) continue; ?>
			<li style="--c: <?= e($hs['clr']) ?>"><b><?= e($hs['txt']) ?></b><span><?= dt($r['ins_dt']) ?></span></li>
		<?php endforeach; ?>
	</ol>
<?php endif; ?>

<p class="noprint center"><button type="button" class="ghost" onclick="print()">Чекті басып шығару</button></p>
<?php if ($active): ?><p class="muted small center noprint">Статус әр 30 секунд сайын жаңарады</p><?php endif; ?>
<?php foot();
