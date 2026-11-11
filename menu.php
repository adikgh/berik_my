<?php
// Company menu with ordering. In the cabinet it is the "Мәзір" tab;
// on the QR host (qr.*) it is the whole site and needs no login.
// Links: /menu.php?c=<company code>[&b=<branch id>][&t=<table no>]
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/layout.php';

$me = me();
if ($me && !profile_done($me) && !qr_mode()) $me = null; // unfinished profile orders like a guest
$c = current_company();
if ($c && !empty($_GET['c']) && code_of($c) !== preg_replace('/[^a-z0-9]/', '', strtolower((string)$_GET['c']))) $c = null;
$s = $c ? menu_set((int)$c['id']) : null;
// A remembered place whose menu is off: show the list of open ones instead
if ($c && !$s['is_on'] && empty($_GET['c'])) { $c = null; $s = null; }

// Table and branch from the QR link stay for this company while the session lives
if ($c && isset($_GET['t'])) $_SESSION['menu_t'][(int)$c['id']] = max(0, (int)$_GET['t']);
if ($c && isset($_GET['b'])) $_SESSION['menu_b'][(int)$c['id']] = (int)$_GET['b'];
$table = $c ? (int)($_SESSION['menu_t'][(int)$c['id']] ?? 0) : 0;
if ($table && $s && $s['tables'] && $table > (int)$s['tables']) $table = 0;

function out(array $r): void {
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode($r, JSON_UNESCAPED_UNICODE);
	exit;
}

// Send the order
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['send'])) {
	if (!csrf_ok()) out(['ok' => false, 'msg' => 'Бетті жаңартып, қайта көріңіз.']);
	if (!$c) out(['ok' => false, 'msg' => 'Мекеме табылмады.']);
	if (too_many('order', 5)) out(['ok' => false, 'msg' => 'Тым көп тапсырыс. Сәл кейін көріңіз.']);
	try {
		[$oid, $num] = place_order($c, [
			'type' => $_POST['type'] ?? 0,
			'branch' => $_SESSION['menu_b'][(int)$c['id']] ?? ($_POST['branch'] ?? 0),
			'table' => $table,
			'items' => $_POST['items'] ?? [],
			'name' => $_POST['name'] ?? '',
			'phone' => $_POST['phone'] ?? '',
			'address' => $_POST['address'] ?? '',
			'floor_apt' => $_POST['floor_apt'] ?? '',
			'zone' => $_POST['zone'] ?? 0,
			'comment' => $_POST['comment'] ?? '',
		], $me);
	} catch (RuntimeException $e) {
		out(['ok' => false, 'msg' => $e->getMessage()]);
	}
	hit('order');
	$_SESSION['my_orders'][] = $oid;
	$_SESSION['my_orders'] = array_slice(array_unique($_SESSION['my_orders']), -20);
	out(['ok' => true, 'num' => $num, 'url' => ($me && !qr_mode() ? '/order.php?id=' : '/menu.php?o=') . $oid]);
}

// Status of an order made from this browser (guests, QR)
if (isset($_GET['o'])) {
	$oid = (int)$_GET['o'];
	if (!in_array($oid, array_map('intval', $_SESSION['my_orders'] ?? []), true)) { header('Location: /menu.php'); exit; }
	$q = db()->prepare('SELECT o.*, c.name company, t.name type_name FROM retail_orders o LEFT JOIN company c ON c.id = o.company_id
		LEFT JOIN retail_orders_type t ON t.id = o.order_type WHERE o.id = ?');
	$q->execute([$oid]);
	$o = $q->fetch();
	if (!$o) { header('Location: /menu.php'); exit; }
	$p = db()->prepare("SELECT op.quantity, op.price, COALESCE(NULLIF(p.name_kz, ''), p.name_ru) name FROM retail_orders_products op LEFT JOIN product p ON p.id = op.product_id WHERE op.order_id = ?");
	$p->execute([$oid]);
	$items = $p->fetchAll();
	$st = status_words('kz');
	$active = !in_array((int)$o['order_status'], array_merge(DONE_ST, REFUSED_ST), true);
	head('Тапсырыс №' . order_no($o), 'menu', $active ? '<meta http-equiv="refresh" content="30">' : '', $c ? (string)$c['name'] : '');
	?>
	<p><a href="/menu.php">← Мәзір</a></p>
	<div class="card">
		<div class="row"><h1>№<?= order_no($o) ?></h1><?= badge($st[(int)$o['order_status']] ?? null) ?></div>
		<p class="muted"><?= e($o['company']) ?> · <?= e($o['type_name']) ?> · <?= dt($o['ins_dt']) ?></p>
		<ul class="items">
			<?php foreach ($items as $i): ?>
				<li><span><?= e(strip_tags((string)$i['name'])) ?> <small>× <?= (int)$i['quantity'] ?></small></span><span><?= money((int)$i['price'] * (int)$i['quantity']) ?></span></li>
			<?php endforeach; ?>
		</ul>
		<?php if ((int)$o['pay_delivery']): ?><div class="row"><span>Жеткізу</span><span><?= money((int)$o['pay_delivery']) ?></span></div><?php endif; ?>
		<div class="row total"><span>Барлығы</span><span><?= money((int)$o['total']) ?></span></div>
	</div>
	<?php if ($active): ?><p class="muted small center">Статус әр 30 секунд сайын жаңарады</p><?php endif; ?>
	<?php foot();
	exit;
}

// No company chosen: every place whose menu is turned on (this client's places first).
// If there is only one, it opens right away.
if (!$c) {
	$places = [];
	if (menu_on()) {
		$mine = $me ? my_clients((int)$me['id']) : '0';
		$places = db()->query("SELECT c.id, c.name, c.code, c.city,
				(SELECT MAX(cc.last_dt) FROM company_client cc WHERE cc.company_id = c.id AND cc.id IN ($mine)) mine
			FROM company c JOIN company_menu m ON m.company_id = c.id AND m.is_on = 1
			WHERE c.end_dt IS NULL OR c.end_dt > NOW()
			ORDER BY mine IS NULL, mine DESC, c.number, c.id")->fetchAll();
	}
	if (count($places) === 1) { header('Location: /menu.php?c=' . rawurlencode(code_of($places[0]))); exit; }
	head('Мәзір', 'menu');
	?>
	<h1>Мәзір</h1>
	<?php if ($places): ?>
		<p class="muted">Мекемені таңдаңыз</p>
		<ul class="card list">
			<?php foreach ($places as $p): ?>
				<li><a href="/menu.php?c=<?= e(code_of($p)) ?>"><?= e($p['name']) ?></a><b><?= e(ucfirst((string)$p['city'])) ?></b></li>
			<?php endforeach; ?>
		</ul>
	<?php else: ?>
		<p class="empty">Әзірге мәзірі ашық мекеме жоқ.</p>
	<?php endif; ?>
	<?php foot();
	exit;
}

$name = (string)$c['name'];
if (!$s['is_on']) {
	head('Мәзір', 'menu', '', $name);
	echo '<h1>' . e($name) . '</h1><p class="empty">Мәзір әзірге ашылмаған.</p>';
	foot();
	exit;
}

$cats = menu_items((int)$c['id']);
$look = menu_look($s);
$types = menu_types($c, $s, $table > 0);
$br = branches((int)$c['id']);
$bfix = (int)($_SESSION['menu_b'][(int)$c['id']] ?? 0);
if ($bfix && !in_array($bfix, array_map(fn($b) => (int)$b['id'], $br), true)) $bfix = 0;
$zbr = $bfix ?: (int)($br[0]['id'] ?? 0);
$zs = isset($types[1]) ? zones((int)$c['id'], $zbr) : [];

// Saved addresses of this client at this company
$addrs = [];
if ($me) {
	$q = db()->prepare('SELECT addresses FROM company_client WHERE company_id = ? AND user_id = ?');
	$q->execute([(int)$c['id'], (int)$me['id']]);
	$addrs = array_values(array_filter(array_map('trim', explode(';', (string)$q->fetchColumn())), 'strlen'));
}

$cfg = [
	'cid' => (int)$c['id'],
	'csrf' => csrf(),
	'types' => $types,
	'delivery' => (int)$c['delivery_price'],
	'zones' => array_map(fn($z) => ['id' => (int)$z['id'], 'name' => $z['name'], 'price' => (int)$z['price']], $zs),
	'min' => (int)$s['min_sum'],
	'guest' => !$me,
	'table' => $table,
	'branches' => $bfix || count($br) < 2 || $table ? [] : array_map(fn($b) => ['id' => (int)$b['id'], 'name' => $b['name'], 'addr' => (string)$b['address']], $br),
	'addrs' => $addrs,
	'card' => (int)$look['card'],
	'upsell' => (int)$look['upsell'],
	'upsell_ids' => menu_recommend((int)$c['id'], $cats),
];

$tpl = $look['tpl'];
$tcss = $tpl ? '<link rel="stylesheet" href="/assets/css/menu-t' . $tpl . '.css?v=' . @filemtime(__DIR__ . '/assets/css/menu-t' . $tpl . '.css') . '">' : '';
head('Мәзір · ' . $name, 'menu', '<link rel="stylesheet" href="/assets/css/menu.css?v=' . @filemtime(__DIR__ . '/assets/css/menu.css') . '">' . $tcss
	. '<style>:root{--green:' . e($look['accent']) . '}</style>', $name);
// Templates: a soft tile with an emoji stands in for a missing photo; a broken photo falls back to it too
$media = function (array $i, string $cat) use ($tpl, $look): string {
	if (!$tpl) return $i['img'] && $look['photos'] ? '<img src="' . e($i['img']) . '" alt="" loading="lazy" onerror="this.remove()">' : '';
	$img = $i['img'] && $look['photos'] ? '<img src="' . e($i['img']) . '" alt="" loading="lazy" onerror="this.remove()">' : '';
	return '<div class="mn_ph" style="--h:' . ($i['id'] * 47 % 360) . '"><em aria-hidden="true">' . food_icon($i['name'], $cat) . '</em>' . $img . '</div>';
};
?>
<div class="mn_tpl<?= $tpl ? ' mn_t' . $tpl : '' ?>">
<div class="mn_head">
	<?php if ($tpl): ?><p class="mn_hi"><?= $table ? 'Қош келдіңіз!' : 'Бүгін не жейміз?' ?></p><?php endif; ?>
	<h1><?= e($name) ?></h1>
	<?php if ($table): ?><p class="mn_table">🍽 Үстел №<?= $table ?></p><?php endif; ?>
	<?php if (trim((string)$s['note']) !== ''): ?><p class="mn_note"><?= nl2br(e((string)$s['note'])) ?></p><?php endif; ?>
	<?php if (!$types): ?><p class="alert">Қазір мәзірден тапсырыс қабылданбайды, тек қарауға болады.</p><?php endif; ?>
</div>

<?php if (!$cats): ?>
	<p class="empty">Мәзір бос.</p>
<?php else: ?>
	<?php if ($look['search']): ?><div class="mn_find"><input type="search" id="mn_q" placeholder="Мәзірден іздеу" autocomplete="off" aria-label="Мәзірден іздеу"></div><?php endif; ?>
	<nav class="mn_cats" id="cats">
		<?php foreach ($cats as $id => $k): ?><a href="#cat<?= $id ?>"><?php if ($tpl): ?><i aria-hidden="true"><?= food_icon($k['name'], (string)($k['items'][0]['name'] ?? '')) ?></i><?php endif; ?><span><?= e($k['name']) ?></span></a><?php endforeach; ?>
	</nav>
	<p class="empty mn_none" hidden>Ештеңе табылмады.</p>
	<?php foreach ($cats as $id => $k): ?>
		<section class="mn_sec" id="cat<?= $id ?>">
			<h2><?= e($k['name']) ?><?php if ($tpl): ?> <small><?= count($k['items']) ?></small><?php endif; ?></h2>
			<div class="mn_grid<?= $look['layout'] === 'grid' ? ' mn_tiles' : '' ?>">
				<?php foreach ($k['items'] as $i): ?>
					<div class="mn_it" data-id="<?= $i['id'] ?>" data-cat="<?= $id ?>" data-name="<?= e($i['name']) ?>" data-price="<?= $i['price'] ?>" data-desc="<?= e($i['desc']) ?>" data-img="<?= $look['photos'] ? e($i['img']) : '' ?>"<?= $tpl ? ' data-ico="' . e(food_icon($i['name'], $k['name'])) . '" data-h="' . ($i['id'] * 47 % 360) . '"' : '' ?>>
						<?= $media($i, $k['name']) ?>
						<div class="mn_it_b">
							<b><?= e($i['name']) ?></b>
							<?php if ($i['desc'] !== ''): ?><small><?= e($i['desc']) ?></small><?php endif; ?>
							<div class="row">
								<span class="mn_pr"><?= money($i['price']) ?></span>
								<?php if ($types): ?>
									<span class="mn_q"><button type="button" class="mn_minus" aria-label="Азайту">−</button><i>0</i><button type="button" class="mn_plus" aria-label="Қосу">+</button></span>
								<?php endif; ?>
							</div>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		</section>
	<?php endforeach; ?>
<?php endif; ?>

<?php if ($cats): ?>
<div class="mn_sheet mn_card" hidden>
	<div class="mn_box">
		<button type="button" class="mn_x mn_close" aria-label="Жабу">✕</button>
		<img class="mn_c_img" alt="" hidden>
		<?php if ($tpl): ?><div class="mn_c_ph" hidden><em aria-hidden="true"></em></div><?php endif; ?>
		<h2 class="mn_c_name"></h2>
		<p class="mn_c_desc"></p>
		<p class="mn_c_pr"></p>
		<div class="mn_up" hidden><h3>Тағы қосасыз ба?</h3><div class="mn_up_list"></div></div>
		<?php if ($types): ?>
			<div class="mn_c_foot">
				<span class="mn_q mn_c_q"><button type="button" class="mn_c_minus" aria-label="Азайту">−</button><i>1</i><button type="button" class="mn_c_plus" aria-label="Көбейту">+</button></span>
				<button type="button" class="mn_c_add">Қосу</button>
			</div>
		<?php endif; ?>
	</div>
</div>
<?php endif; ?>

<?php if ($types): ?>
<button type="button" class="mn_bar" hidden><span>Себет · <b class="mn_cnt">0</b></span><b class="mn_sum">0 ₸</b></button>

<div class="mn_sheet" hidden>
	<div class="mn_box">
		<div class="row"><h2>Себет</h2><button type="button" class="link mn_close" aria-label="Жабу">✕</button></div>
		<ul class="items mn_lines"></ul>
		<div class="mn_up mn_up_cart" hidden><h3>Тағы қосасыз ба?</h3><div class="mn_up_list"></div></div>
		<form class="mn_form">
			<?php if (count($types) > 1): ?>
				<div class="chips mn_types">
					<?php foreach ($types as $t => $tn): ?><label><input type="radio" name="type" value="<?= $t ?>"<?= $t === array_key_first($types) ? ' checked' : '' ?>><span><?= e($tn) ?></span></label><?php endforeach; ?>
				</div>
			<?php else: ?>
				<input type="hidden" name="type" value="<?= array_key_first($types) ?>">
				<p class="muted"><?= e(reset($types)) ?></p>
			<?php endif; ?>

			<?php if ($cfg['branches']): ?>
				<label class="mn_f_branch">Мекеме
					<select name="branch">
						<?php foreach ($cfg['branches'] as $b): ?><option value="<?= $b['id'] ?>"><?= e($b['name'] . ($b['addr'] !== '' ? ' · ' . $b['addr'] : '')) ?></option><?php endforeach; ?>
					</select>
				</label>
			<?php endif; ?>

			<?php if (!$me): ?>
				<label>Атыңыз <input name="name" maxlength="60" autocomplete="given-name"<?= $table ? '' : ' required' ?>></label>
				<label>Телефон <?= phone_input('', !$table) ?></label>
			<?php endif; ?>

			<div class="mn_dlv" data-t="1">
				<?php if ($cfg['zones']): ?>
					<label>Көше / аудан
						<select name="zone">
							<option value="">Таңдаңыз</option>
							<?php foreach ($cfg['zones'] as $z): ?><option value="<?= $z['id'] ?>" data-price="<?= $z['price'] ?>"><?= e($z['name']) ?> · <?= money($z['price']) ?></option><?php endforeach; ?>
						</select>
					</label>
				<?php endif; ?>
				<label>Мекенжай
					<input name="address" maxlength="200" list="mn_addrs" placeholder="Көше, үй" autocomplete="street-address">
					<?php if ($addrs): ?><datalist id="mn_addrs"><?php foreach ($addrs as $a): ?><option value="<?= e($a) ?>"><?php endforeach; ?></datalist><?php endif; ?>
				</label>
				<label>Қабат, пәтер <input name="floor_apt" maxlength="30" placeholder="4 қабат, 15 пәтер"></label>
			</div>
			<label>Ескертпе <input name="comment" maxlength="300" placeholder="Мысалы: ащы болмасын"></label>

			<div class="mn_tot">
				<div class="row mn_dlv" data-t="1"><span>Жеткізу</span><span class="mn_dsum"></span></div>
				<div class="row total"><span>Барлығы</span><span class="mn_total"></span></div>
				<p class="muted small">Төлем тапсырысты алғанда (қолма-қол немесе Kaspi).</p>
			</div>
			<p class="alert mn_err" hidden></p>
			<button type="submit">Тапсырыс беру</button>
		</form>
	</div>
</div>

<div class="mn_sheet mn_done" hidden>
	<div class="mn_box center">
		<div class="mn_ok">✓</div>
		<h2>Тапсырыс қабылданды</h2>
		<p>Тапсырыс нөмірі <b class="mn_num"></b>. Жақында хабарласамыз.</p>
		<p><a class="btn mn_link" href="#">Тапсырысты көру</a></p>
	</div>
</div>
<?php endif; ?>
</div>
<?php if ($cats): ?>
<script>window.MENU = <?= json_encode($cfg, JSON_UNESCAPED_UNICODE) ?>;</script>
<script src="/assets/js/menu.js?v=<?= @filemtime(__DIR__ . '/assets/js/menu.js') ?>"></script>
<?php endif; ?>
<?php foot();
