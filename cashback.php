<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/layout.php';

$me = need_login();
if (!cashback_on()) { header('Location: /'); exit; }
$ids = my_clients((int)$me['id']);

$rows = db()->query("SELECT h.type, h.amount, h.base_sum, h.percent, h.note, h.ins_dt, h.order_id,
		o.number, c.name AS company
	FROM company_client_cashback h
	JOIN company c ON c.id = h.company_id AND c.cashback_percent > 0
	LEFT JOIN retail_orders o ON o.id = h.order_id
	WHERE h.client_id IN ($ids)
	ORDER BY h.ins_dt DESC, h.id DESC LIMIT 200")->fetchAll();

$types = CASH_TYPES;

head('Кэшбэк', 'home');
?>
<p><a href="/">← Басты</a></p>
<h1>Кэшбэк тарихы</h1>
<?php if (!$rows): ?>
	<p class="empty">Әзірге кэшбэк жоқ.</p>
<?php else: ?>
	<ul class="card list cash">
		<?php foreach ($rows as $r): $amt = (float)$r['amount']; ?>
			<li>
				<span>
					<?= e($types[$r['type']] ?? $r['type']) ?> · <?= e($r['company']) ?><br>
					<small><?= dt($r['ins_dt']) ?><?php if ($r['order_id']): ?> · <a href="/order.php?id=<?= (int)$r['order_id'] ?>">№<?= (int)($r['number'] ?: $r['order_id']) ?></a><?php endif; ?><?php if ($r['note']): ?> · <?= e($r['note']) ?><?php endif; ?></small>
				</span>
				<b class="<?= $amt >= 0 ? 'plus' : 'minus' ?>"><?= $amt >= 0 ? '+' : '−' ?><?= money((int)round(abs($amt))) ?></b>
			</li>
		<?php endforeach; ?>
	</ul>
<?php endif; ?>
<?php foot();
