<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/layout.php';

// Came by a company link (?c=<code>): open that company's menu
if (isset($_GET['c']) && current_company()) { header('Location: /menu.php'); exit; }

$me = need_login();
$mc = current_company();
if ($mc && !menu_set((int)$mc['id'])['is_on']) $mc = null;
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

// Bonuses per place: cashback and gifts, each by the place's own settings
$cash = cash_cards($ids);
$gifts = gift_cards($ids);
$bonus = [];
foreach ($cash + $gifts as $cid => $x) $bonus[$cid] = ['name' => $x['name'], 'cash' => $cash[$cid] ?? null, 'gift' => $gifts[$cid] ?? null];

head('Басты', 'home');
?>
<h1>Сәлем, <?= e($me['name']) ?>!</h1>
<p class="muted">+7 <?= e($me['phone']) ?></p>

<?php if ($mc): ?>
	<a class="card go-menu" href="/menu.php"><span>🍽 <b><?= e($mc['name']) ?></b> мәзірі</span><span class="more">Тапсырыс беру →</span></a>
<?php endif; ?>

<?php if ($bonus): ?>
	<div class="row"><h2>Бонустарым</h2><?php if ($cash): ?><a class="more" href="/cashback.php">Кэшбэк тарихы →</a><?php endif; ?></div>
	<div class="bonus">
		<?php foreach ($bonus as $b): $c = $b['cash']; $g = $b['gift']; ?>
			<div class="bn">
				<div class="bn-head"><b><?= e($b['name']) ?></b><?php if ($c): ?><span class="bn-pct"><?= $c['steps'] ? e(reset($c['steps'])['pct'] . '–' . end($c['steps'])['pct']) : e($c['pct']) ?>% кэшбэк</span><?php endif; ?></div>
				<?php if ($c): ?>
					<div class="bn-bal"><small>Кэшбэк балансы</small><b><?= money($c['bal']) ?></b></div>
					<?php if (count($c['steps']) > 1): ?>
						<div class="bn-steps"><?php foreach ($c['steps'] as $st): ?><span><b><?= e($st['pct']) ?>%</b><?= $st['from'] > 0 ? money($st['from']) . '-дан' : 'кез келген' ?></span><?php endforeach; ?></div>
					<?php endif; ?>
					<p class="bn-note"><?= count($c['steps']) > 1 ? 'Тапсырыс сомасы көп болса, кэшбэк пайызы да көп. ' : '' ?>Кэшбэкті тек осы мекеменің тапсырысына жұмсауға болады.</p>
				<?php endif; ?>
				<?php if ($g): $nx = $g['next']; ?>
					<div class="bn-gift">
						<div class="row"><span>🎁 Сыйлыққа дейін</span><small><?= (int)$g['count'] ?> тапсырыс</small></div>
						<?php if ($nx): $span = max(1, $nx['k'] - $g['prev']); $done = $g['count'] - $g['prev']; ?>
							<div class="bn-bar"><i style="width: <?= round($done / $span * 100) ?>%"></i></div>
							<p><?= $nx['left'] > 0
								? 'Тағы <b>' . $nx['left'] . '</b> тапсырыс, сосын ' . $nx['k'] . '-шіге: <b>' . e($nx['name']) . '</b>'
								: 'Келесі тапсырысыңызға сыйлық: <b>' . e($nx['name']) . '</b>' ?></p>
						<?php endif; ?>
						<ul class="bn-tiers">
							<?php foreach ($g['tiers'] as $t): ?><li><span>Әр <?= $t['every'] ?>-ші тапсырыс</span><b><?= e($t['name']) ?></b></li><?php endforeach; ?>
						</ul>
						<?php if ($g['got']): ?><p class="muted small">Алынған сыйлық: <?= $g['got'] ?></p><?php endif; ?>
					</div>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
	</div>
<?php endif; ?>

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
