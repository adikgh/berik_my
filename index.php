<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/layout.php';

$me = need_login();
$st = status_words('kz');
$ids = my_clients((int)$me['id']);
$ok = OK_SQL;
$base = "FROM retail_orders o
	LEFT JOIN company c ON c.id = o.company_id
	LEFT JOIN retail_orders_type t ON t.id = o.order_type
	WHERE o.client_id IN ($ids)";
$cols = 'o.id, o.number, o.total, o.order_status, o.ins_dt, c.name AS company, t.name AS type_name';
$closed = implode(',', array_merge(DONE_ST, REFUSED_ST));

// Totals
$sum = db()->query("SELECT COUNT(*) n, COALESCE(SUM(o.total), 0) s, MIN(o.ins_dt) first_dt, MAX(o.ins_dt) last_dt
	FROM retail_orders o WHERE o.client_id IN ($ids) AND $ok")->fetch();
$n = (int)$sum['n'];
$s = (int)$sum['s'];

// This month
$mon = db()->query("SELECT COUNT(*) n, COALESCE(SUM(o.total), 0) s FROM retail_orders o
	WHERE o.client_id IN ($ids) AND $ok AND o.ins_dt >= '" . date('Y-m-01') . "'")->fetch();

// Orders in progress
$active = db()->query("SELECT $cols $base AND o.order_status NOT IN ($closed) AND o.ins_dt > NOW() - INTERVAL 2 DAY ORDER BY o.id DESC")->fetchAll();

// Last 12 months
$months = [];
for ($i = 11; $i >= 0; $i--) $months[date('Y-m', strtotime(date('Y-m-01') . " -$i month"))] = 0;
$mq = db()->query("SELECT DATE_FORMAT(o.ins_dt, '%Y-%m') m, SUM(o.total) s FROM retail_orders o
	WHERE o.client_id IN ($ids) AND $ok AND o.ins_dt >= '" . array_key_first($months) . "-01' GROUP BY m");
foreach ($mq as $r) if (isset($months[$r['m']])) $months[$r['m']] = (int)$r['s'];
$mmax = max(1, ...array_values($months));

// Favourite dishes
$top = db()->query("SELECT COALESCE(p.name_kz, p.name_ru) name, SUM(op.quantity) q
	FROM retail_orders_products op JOIN retail_orders o ON o.id = op.order_id LEFT JOIN product p ON p.id = op.product_id
	WHERE o.client_id IN ($ids) AND $ok AND p.id IS NOT NULL GROUP BY op.product_id ORDER BY q DESC LIMIT 5")->fetchAll();

// By place
$places = db()->query("SELECT c.name, COUNT(*) n, SUM(o.total) s FROM retail_orders o LEFT JOIN company c ON c.id = o.company_id
	WHERE o.client_id IN ($ids) AND $ok GROUP BY o.company_id ORDER BY n DESC")->fetchAll();

$last = db()->query("SELECT $cols $base ORDER BY o.id DESC LIMIT 5")->fetchAll();

// Cashback per place (only where the place gives it)
$cash = cashback_on() ? db()->query("SELECT c.name, c.cashback_percent pct, cc.cashback_balance bal
	FROM company_client cc JOIN company c ON c.id = cc.company_id
	WHERE cc.id IN ($ids) AND c.cashback_percent > 0 ORDER BY bal DESC")->fetchAll() : [];

$gifts = gift_cards($ids);

head('Басты', 'home');
?>
<h1>Сәлем, <?= e($me['name']) ?>!</h1>
<p class="muted">+7 <?= e($me['phone']) ?></p>

<?php if ($active): ?>
	<h2>Қазір орындалуда</h2>
	<ul class="orders live"><?php foreach ($active as $o) order_card($o, $st); ?></ul>
<?php endif; ?>

<div class="stats">
	<div><span>Тапсырыс</span><b><?= $n ?></b></div>
	<div><span>Жалпы сома</span><b><?= money($s) ?></b></div>
	<div><span>Орташа чек</span><b><?= money($n ? intdiv($s, $n) : 0) ?></b></div>
	<div><span>Осы айда</span><b><?= money((int)$mon['s']) ?></b><small><?= (int)$mon['n'] ?> тапсырыс</small></div>
</div>
<?php foreach ($gifts as $g): $every = (int)$g['gift_every']; $left = $every - 1 - $g['have']; ?>
	<div class="card stamp">
		<div class="row"><b><?= e($g['name']) ?></b><small><?= (int)$g['have'] ?> / <?= $every ?></small></div>
		<div class="dots">
			<?php for ($i = 1; $i <= $every; $i++): ?>
				<span class="<?= $i <= $g['have'] ? 'on' : '' ?><?= $i === $every ? ' gift' : '' ?>"><?= $i === $every ? '🎁' : ($i <= $g['have'] ? '✓' : $i) ?></span>
			<?php endfor; ?>
		</div>
		<p><?= $left > 0
			? 'Тағы <b>' . $left . '</b> тапсырыс, сосын ' . $every . '-шіге сыйлық: <b>' . e($g['gift_name']) . '</b>'
			: 'Келесі тапсырысыңызға сыйлық: <b>' . e($g['gift_name']) . '</b>' ?></p>
		<?php if ((int)$g['got']): ?><p class="muted small">Алынған сыйлық: <?= (int)$g['got'] ?></p><?php endif; ?>
	</div>
<?php endforeach; ?>
<?php if ($cash): ?>
	<div class="row"><h2>Кэшбэк</h2><a class="more" href="/cashback.php">Тарихы →</a></div>
	<ul class="card list cash">
		<?php foreach ($cash as $c): ?>
			<li><span><?= e($c['name']) ?> <small><?= rtrim(rtrim((string)$c['pct'], '0'), '.') ?>%</small></span><b><?= money((int)floor((float)$c['bal'])) ?></b></li>
		<?php endforeach; ?>
	</ul>
	<p class="muted small">Кэшбэкті тек сол мекемеден алған тапсырысқа жұмсауға болады.</p>
<?php endif; ?>
<?php if ($n): ?>
	<p class="muted small">Алғашқы тапсырыс: <?= dt($sum['first_dt'], false) ?> · соңғысы: <?= dt($sum['last_dt'], false) ?></p>
<?php endif; ?>

<?php if ($n): ?>
<h2>Ай бойынша</h2>
<div class="card chart">
	<?php foreach ($months as $m => $v): ?>
		<div class="bar" title="<?= e(money($v)) ?>">
			<i style="height: <?= round($v / $mmax * 100) ?>%"></i>
			<span><?= MONTHS[(int)substr($m, 5)] ?></span>
		</div>
	<?php endforeach; ?>
</div>

<div class="two">
	<?php if ($top): ?>
	<div>
		<h2>Сүйікті тағамдар</h2>
		<ol class="card list">
			<?php foreach ($top as $t): ?><li><span><?= e(strip_tags((string)$t['name'])) ?></span><b><?= (int)$t['q'] ?> рет</b></li><?php endforeach; ?>
		</ol>
	</div>
	<?php endif; ?>
	<div>
		<h2>Мекемелер</h2>
		<ul class="card list">
			<?php foreach ($places as $p): ?><li><span><?= e($p['name']) ?></span><b><?= (int)$p['n'] ?> · <?= money((int)$p['s']) ?></b></li><?php endforeach; ?>
		</ul>
	</div>
</div>
<?php endif; ?>

<div class="row"><h2>Соңғы тапсырыстар</h2><?php if ($last): ?><a class="more" href="/orders.php">Барлығы →</a><?php endif; ?></div>
<?php if (!$last): ?>
	<p class="empty">Әзірге тапсырыс жоқ.</p>
<?php else: ?>
	<ul class="orders"><?php foreach ($last as $o) order_card($o, $st); ?></ul>
<?php endif; ?>
<?php foot();
