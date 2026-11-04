<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/layout.php';

$me = need_login();
$st = status_words('kz');
$ids = my_clients((int)$me['id']);
$per = 20;

$f = [
	'c' => (int)($_GET['c'] ?? 0),
	's' => in_array($_GET['s'] ?? '', ['active', 'done', 'refused'], true) ? $_GET['s'] : '',
	'p' => in_array($_GET['p'] ?? '', ['month', '3m', 'year'], true) ? $_GET['p'] : '',
	'q' => preg_replace('/\D/', '', (string)($_GET['q'] ?? '')),
];
$page = max(1, (int)($_GET['page'] ?? 1));

$where = ["o.client_id IN ($ids)"];
$args = [];
if ($f['c']) { $where[] = 'o.company_id = ?'; $args[] = $f['c']; }
$done = implode(',', DONE_ST);
$ref = implode(',', REFUSED_ST);
if ($f['s'] === 'active') $where[] = "o.order_status NOT IN ($done,$ref)";
if ($f['s'] === 'done') $where[] = "o.order_status IN ($done)";
if ($f['s'] === 'refused') $where[] = "o.order_status IN ($ref)";
$since = ['month' => date('Y-m-01'), '3m' => date('Y-m-d', strtotime('-3 month')), 'year' => date('Y-01-01')][$f['p']] ?? null;
if ($since) { $where[] = 'o.ins_dt >= ?'; $args[] = $since; }
if ($f['q'] !== '') { $where[] = '(o.number = ? OR o.id = ?)'; $args[] = (int)$f['q']; $args[] = (int)$f['q']; }
$w = implode(' AND ', $where);

$cnt = db()->prepare("SELECT COUNT(*) n, COALESCE(SUM(o.total), 0) s FROM retail_orders o WHERE $w");
$cnt->execute($args);
$tot = $cnt->fetch();
$pages = max(1, (int)ceil($tot['n'] / $per));
$page = min($page, $pages);

$q = db()->prepare("SELECT o.id, o.number, o.total, o.order_status, o.ins_dt, c.name AS company, t.name AS type_name
	FROM retail_orders o
	LEFT JOIN company c ON c.id = o.company_id
	LEFT JOIN retail_orders_type t ON t.id = o.order_type
	WHERE $w ORDER BY o.id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
$q->execute($args);
$orders = $q->fetchAll();

$companies = db()->query("SELECT DISTINCT c.id, c.name FROM company_client cc JOIN company c ON c.id = cc.company_id
	WHERE cc.id IN ($ids) ORDER BY c.name")->fetchAll();

function url(array $f, array $set): string {
	return '/orders.php?' . http_build_query(array_filter(array_merge($f, $set)));
}

head('Тапсырыстарым', 'orders');
?>
<h1>Тапсырыстарым</h1>

<div class="of">
	<form class="of-top" method="get">
		<label class="of-search">
			<svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
			<input type="search" name="q" value="<?= e($f['q']) ?>" inputmode="numeric" placeholder="Тапсырыс нөмірі">
		</label>
		<?php if (count($companies) > 1): ?>
			<select name="c" class="of-sel" onchange="this.form.submit()">
				<option value="">Барлық мекеме</option>
				<?php foreach ($companies as $c): ?><option value="<?= (int)$c['id'] ?>"<?= $f['c'] === (int)$c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
			</select>
		<?php endif; ?>
		<?php foreach (['s', 'p'] as $k): if ($f[$k]): ?><input type="hidden" name="<?= $k ?>" value="<?= e($f[$k]) ?>"><?php endif; endforeach; ?>
	</form>
	<div class="of-seg">
		<?php foreach (['' => 'Барлығы', 'month' => 'Осы ай', '3m' => '3 ай', 'year' => 'Осы жыл'] as $k => $v): ?>
			<a href="<?= e(url($f, ['p' => $k, 'page' => 0])) ?>"<?= $f['p'] === $k ? ' class="on"' : '' ?>><?= $v ?></a>
		<?php endforeach; ?>
	</div>
</div>

<nav class="of-tabs">
	<?php foreach (['' => 'Барлығы', 'active' => 'Орындалуда', 'done' => 'Аяқталған', 'refused' => 'Бас тартылған'] as $k => $v): ?>
		<a href="<?= e(url($f, ['s' => $k, 'page' => 0])) ?>"<?= $f['s'] === $k ? ' class="on"' : '' ?>><?= $v ?></a>
	<?php endforeach; ?>
</nav>

<div class="of-sum"><span><?= (int)$tot['n'] ?> тапсырыс</span><b><?= money((int)$tot['s']) ?></b></div>

<?php if (!$orders): ?>
	<p class="empty">Тапсырыс табылмады.</p>
<?php else: ?>
	<ul class="orders"><?php foreach ($orders as $o) order_card($o, $st); ?></ul>
	<?php if ($pages > 1): ?>
		<div class="pager">
			<?php if ($page > 1): ?><a href="<?= e(url($f, ['page' => $page - 1])) ?>">← Алдыңғы</a><?php endif; ?>
			<span><?= $page ?> / <?= $pages ?></span>
			<?php if ($page < $pages): ?><a href="<?= e(url($f, ['page' => $page + 1])) ?>">Келесі →</a><?php endif; ?>
		</div>
	<?php endif; ?>
<?php endif; ?>
<?php foot();
