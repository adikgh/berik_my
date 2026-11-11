<?php
// Online menu and QR menu: the company's dishes and ordering from them.
// QR menu is this same site opened by another host (alias), e.g. qr.berik.c:
// then the menu opens without login. Settings per company: company_menu (admin ▸ QR меню).

// Host alias that opens the QR menu
function qr_mode(): bool {
	// qr.berik.c, qrb.gprog.kz, menu.* ...
	return (bool)preg_match('~^(qr|menu)[a-z0-9]?[.-]~i', (string)($_SERVER['HTTP_HOST'] ?? ''));
}

// Dish photos live on the admin site
function img_url(?string $img): string {
	$img = (string)$img;
	if ($img === '') return '';
	if (preg_match('~^https?://~', $img)) return $img;
	$host = preg_match('~gprog\.kz$~i', (string)($_SERVER['HTTP_HOST'] ?? '')) ? '//admb.gprog.kz' : '//adm.berik.c';
	return $host . '/assets/uploads/products/' . rawurlencode($img);
}

function menu_on(): bool {
	static $on = null;
	if ($on === null) {
		$q = db()->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'company_menu'");
		$on = (int)$q->fetchColumn() === 1;
	}
	return $on;
}

function source_col(): bool {
	static $on = null;
	if ($on === null) {
		$q = db()->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'retail_orders' AND COLUMN_NAME = 'source'");
		$on = (int)$q->fetchColumn() === 1;
	}
	return $on;
}

// Company by its code from a link: ?c=n990 → company.code '#n990'
function company_by_code(string $code): ?array {
	$code = '#' . preg_replace('/[^a-z0-9]/', '', strtolower($code));
	if ($code === '#') return null;
	$q = db()->prepare('SELECT * FROM company WHERE LOWER(code) = ? AND (end_dt IS NULL OR end_dt > NOW()) LIMIT 1');
	$q->execute([$code]);
	return $q->fetch() ?: null;
}

function company_by_id(int $id): ?array {
	$q = db()->prepare('SELECT * FROM company WHERE id = ? AND (end_dt IS NULL OR end_dt > NOW())');
	$q->execute([$id]);
	return $q->fetch() ?: null;
}

function code_of(array $c): string {
	return ltrim(strtolower((string)$c['code']), '#');
}

// Remember the company the client came through (link ?c=<code>), for a year
function remember_company(): void {
	if (!isset($_GET['c'])) return;
	$c = company_by_code((string)$_GET['c']);
	if (!$c) return;
	$_SESSION['menu_c'] = (int)$c['id'];
	setcookie('menu_c', code_of($c), ['expires' => time() + 3600 * 24 * 365, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
}

// The company whose menu is shown now
function current_company(): ?array {
	if (!empty($_SESSION['menu_c'])) return company_by_id((int)$_SESSION['menu_c']);
	if (!empty($_COOKIE['menu_c'])) {
		$c = company_by_code((string)$_COOKIE['menu_c']);
		if ($c) { $_SESSION['menu_c'] = (int)$c['id']; return $c; }
	}
	return null;
}

// Settings; a company without a row has the menu turned off
function menu_set(int $cid): array {
	$def = ['is_on' => 0, 'order_on' => 1, 'delivery_on' => 1, 'pickup_on' => 1, 'table_on' => 1, 'tables' => 0, 'min_sum' => 0, 'note' => ''];
	if (!menu_on()) return $def;
	$q = db()->prepare('SELECT * FROM company_menu WHERE company_id = ?');
	$q->execute([$cid]);
	return array_merge($def, $q->fetch() ?: []);
}

// Look of the menu, set in admin «QR меню» → «Мәзір көрінісі» (company_menu.look, JSON)
function menu_look(array $s): array {
	$l = ['tpl' => 0, 'accent' => '#0ea768', 'layout' => 'list', 'photos' => 1, 'search' => 1, 'card' => 1, 'upsell' => 1];
	$j = json_decode((string)($s['look'] ?? ''), true);
	if (is_array($j)) $l = array_merge($l, array_intersect_key($j, $l));
	if (!preg_match('/^#[0-9a-f]{6}$/i', (string)$l['accent'])) $l['accent'] = '#0ea768';
	$l['tpl'] = in_array((int)$l['tpl'], [1, 2], true) ? (int)$l['tpl'] : 0; // 0 = негізгі, 1 = Шаблон-1, 2 = Шаблон-2
	return $l;
}

// Emoji for a dish or category with no photo (templates draw it on a soft colour)
function food_icon(string ...$names): string {
	static $map = [
		'акци|комбо|набор|combo|бокс|box|\+|\bсет' => '🍱', // sets first: «KING + КОЛА» is a set, not a drink
		'суши|ролл|roll|маки|темпур' => '🍣', 'пицц|pizza' => '🍕', 'бургер|burger|чизбург' => '🍔', 'шаурм|донер|лаваш|шаверм|doner' => '🌯',
		'фри|картоп|картош|наггетс' => '🍟', 'хот-?дог|hot ?dog|сосис' => '🌭', 'лапш|вок|wok|рамен|лагман|удон|паст|спагет' => '🍜',
		'салат' => '🥗', 'суп|сорпа|борщ|сорп|шорп' => '🍲', 'кофе|латте|капуч|американо|эспрес|раф' => '☕', 'шай|чай|tea' => '🍵',
		'торт|десерт|тәтті|пирож|чизкейк|выпеч|тоқаш|бәліш|самса' => '🍰', 'балмұздақ|морожен|мороже' => '🍨',
		'коктейл|смузи|милкшейк|шейк|лимонад|мохито' => '🍹', 'сусын|напит|кола|cola|сок|шырын|вода|фанта|спрайт|пепси|айран|компот' => '🥤',
		'тауық|курин|куриц|крыл|чикен|chicken|тандыр' => '🍗', 'шашлык|стейк|ет |мяс|кәуап|кебаб|плов|палау|манты|бешбармак|қуырдақ' => '🥩',
		'соус' => '🥫', 'нан|хлеб|лепеш|бауырсақ' => '🥖', 'завтрак|таңғы|омлет|яичн' => '🍳',
		'балық|рыб|fish' => '🐟', 'сэндвич|сендвич|тост|клаб' => '🥪', 'хинкал|пельмен|вареник' => '🥟',
	];
	foreach ($names as $n) {
		$n = mb_strtolower($n . ' ');
		foreach ($map as $re => $ic) if (preg_match('/' . $re . '/u', $n)) return $ic;
	}
	return '🍽️';
}

// Order types this company takes from the menu: [type id => name]
// 1 = Жеткізу, 2 = Өзі алып кету, 4 = Үстелге (кафе, тек QR үстел сілтемесімен)
function menu_types(array $c, array $s, bool $table): array {
	if (!$s['order_on']) return [];
	if ($table) return ($s['table_on'] && (int)$c['coffee']) ? [4 => 'Үстелге'] : [];
	$t = [];
	if ($s['delivery_on'] && (int)$c['delivery']) $t[1] = 'Жеткізу';
	if ($s['pickup_on'] && (int)$c['yourself']) $t[2] = 'Өзі алып кету';
	return $t;
}

function branches(int $cid): array {
	$q = db()->prepare('SELECT id, name, address FROM company_branch WHERE company_id = ? ORDER BY number, id');
	$q->execute([$cid]);
	return $q->fetchAll();
}

// Categories with their dishes (only for sale online, not archived)
function menu_items(int $cid): array {
	$cats = db()->prepare("SELECT id, COALESCE(NULLIF(name_kz, ''), name_ru) name FROM product_catalog
		WHERE company_id = ? AND type = 'main' AND arh IS NULL ORDER BY number, id");
	$cats->execute([$cid]);
	$out = [];
	foreach ($cats as $r) $out[(int)$r['id']] = ['name' => strip_tags((string)$r['name']), 'items' => []];
	if (!$out) return [];
	$p = db()->query("SELECT id, catalog_id, COALESCE(NULLIF(name_kz, ''), name_ru) name, description, price, img FROM product
		WHERE catalog_id IN (" . implode(',', array_keys($out)) . ") AND arh = 0 AND sale_online = 1 ORDER BY number IS NULL, number, name_kz");
	foreach ($p as $r) {
		$price = (int)preg_replace('/\D/', '', (string)$r['price']);
		if ($price <= 0) continue;
		$out[(int)$r['catalog_id']]['items'][] = [
			'id' => (int)$r['id'],
			'name' => trim(html_entity_decode(strip_tags((string)$r['name']), ENT_QUOTES, 'UTF-8')),
			'desc' => trim(strip_tags((string)$r['description'])),
			'price' => $price,
			'img' => img_url($r['img']),
		];
	}
	return array_filter($out, fn($c) => $c['items']);
}

// "Add something else?" dishes, starred in admin «Мәзір»: dishes starred one by one first,
// then dishes of starred categories, at most 12. Empty list = menu.js picks cheap dishes itself.
function menu_recommend(int $cid, array $cats, int $max = 12): array {
	try {
		$q = db()->prepare("SELECT p.id, k.recommend kr, p.recommend pr FROM product p JOIN product_catalog k ON k.id = p.catalog_id
			WHERE k.company_id = ? AND (p.recommend = 1 OR k.recommend = 1)");
		$q->execute([$cid]);
		$rec = [];
		foreach ($q as $r) $rec[(int)$r['id']] = (int)$r['pr'] ? 1 : 2;
	} catch (PDOException $e) { return []; } // columns not added yet
	$out = [1 => [], 2 => []];
	foreach ($cats as $k) foreach ($k['items'] as $i) if (isset($rec[$i['id']])) $out[$rec[$i['id']]][] = $i['id'];
	return array_slice(array_merge($out[1], $out[2]), 0, $max);
}

function zones(int $cid, int $bid): array {
	$q = db()->prepare('SELECT id, name, price FROM company_delivery_zone WHERE company_id = ? AND (branch_id IS NULL OR branch_id = 0 OR branch_id = ?) ORDER BY sort, name');
	$q->execute([$cid, $bid]);
	return $q->fetchAll();
}

// Business day 06:00 → 06:00, the same as the kassa
function day_window(): array {
	$s = date('H') < 6 ? date('Y-m-d 06:00:00', strtotime('-1 day')) : date('Y-m-d 06:00:00');
	return [$s, date('Y-m-d H:i:s', strtotime($s . ' +1 day'))];
}

// Next order number of the day for this branch (the same rule as the kassa)
function next_number(int $cid, int $bid): int {
	[$s, $e] = day_window();
	$pre = "(o.preorder_dt IS NOT NULL AND o.preorder_dt != '')";
	$acc = 'COALESCE(l.ins_dt, o.ins_dt)';
	$tt = "(DATE($acc) = DATE(o.ins_dt) OR DATE($acc - INTERVAL 6 HOUR) = DATE(o.ins_dt - INTERVAL 6 HOUR))";
	$q = db()->prepare("SELECT MAX(o.number) FROM retail_orders o LEFT JOIN retail_orders_log l ON l.order_id = o.id AND l.field = 'created'
		WHERE o.company_id = :c AND o.branch_id = :b
		AND ((NOT $pre AND o.ins_dt BETWEEN :s1 AND :e1) OR ($pre AND $tt AND $acc BETWEEN :s2 AND :e2) OR ($pre AND NOT $tt AND o.ins_dt BETWEEN :s3 AND :e3))");
	$q->execute(['c' => $cid, 'b' => $bid, 's1' => $s, 'e1' => $e, 's2' => $s, 'e2' => $e, 's3' => $s, 'e3' => $e]);
	return (int)$q->fetchColumn() + 1;
}

function order_log_add(int $oid, string $field, string $old, string $new): void {
	if ($old === $new) return;
	db()->prepare('INSERT INTO retail_orders_log (order_id, field, old_value, new_value, user_id, ins_dt) VALUES (?, ?, ?, ?, NULL, NOW())')
		->execute([$oid, $field, $old === '' ? null : $old, $new === '' ? null : mb_substr($new, 0, 1000)]);
}

// Client of this company by phone (created if new) and its user
function client_for(int $cid, string $phone, string $name, string $addr, string $floor_apt): int {
	$db = db();
	$db->prepare('INSERT IGNORE INTO user (name, phone) VALUES (?, ?)')->execute([$name !== '' ? $name : '', $phone]);
	$q = $db->prepare('SELECT id, name FROM user WHERE phone = ?');
	$q->execute([$phone]);
	$u = $q->fetch();
	$uid = $u ? (int)$u['id'] : null;
	if ($u && $name !== '' && trim((string)$u['name']) === '') $db->prepare('UPDATE user SET name = ? WHERE id = ?')->execute([$name, $uid]);

	$q = $db->prepare('SELECT * FROM company_client WHERE company_id = ? AND phone = ? LIMIT 1');
	$q->execute([$cid, $phone]);
	$cl = $q->fetch();
	if (!$cl) {
		$db->prepare('INSERT IGNORE INTO company_client (company_id, user_id, phone, name, first_dt, last_dt) VALUES (?, ?, ?, ?, NOW(), NOW())')
			->execute([$cid, $uid, $phone, $name !== '' ? $name : null]);
		$q->execute([$cid, $phone]);
		$cl = $q->fetch();
		if (!$cl) return 0;
	}
	$set = ['upd_dt = NOW()', 'last_dt = NOW()'];
	$args = [];
	if (empty($cl['user_id']) && $uid) { $set[] = 'user_id = ?'; $args[] = $uid; }
	if ($name !== '' && trim((string)$cl['name']) === '') { $set[] = 'name = ?'; $args[] = $name; }
	if ($addr !== '') {
		$set[] = 'address = ?'; $args[] = $addr;
		$set[] = 'floor_apt = ?'; $args[] = $floor_apt !== '' ? $floor_apt : null;
		$list = trim((string)$cl['addresses']);
		if (mb_stripos('; ' . $list . '; ', '; ' . $addr . '; ') === false) {
			$set[] = 'addresses = ?'; $args[] = $list === '' ? $addr : $list . '; ' . $addr;
		}
	}
	$args[] = (int)$cl['id'];
	$db->prepare('UPDATE company_client SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($args);
	return (int)$cl['id'];
}

// Telegram to the company chat, like the kassa does. The bot token is read from the kassa config.
function tg_token(): string {
	// The kassa folder is a sibling of this site (pos.berik.c locally, another name on live)
	foreach (glob(__DIR__ . '/../../*/config/var.php') ?: [] as $f)
		if (preg_match('~\$token\s*=\s*"([^"]+)"~', (string)file_get_contents($f), $m)) return $m[1];
	return '';
}

function tg_send(array $c, string $txt): void {
	if ((int)$c['chat_send'] !== 1 || empty($c['chat_id']) || !function_exists('curl_init')) return;
	$token = tg_token();
	if ($token === '') return;
	$ch = curl_init('https://api.telegram.org/bot' . $token . '/sendMessage');
	curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10, CURLOPT_POST => true,
		CURLOPT_POSTFIELDS => ['chat_id' => $c['chat_id'], 'parse_mode' => 'html', 'text' => $txt]]);
	if (curl_exec($ch) === false) error_log('telegram: ' . curl_error($ch));
	curl_close($ch);
}

// Places a menu order. $in: type, branch, table, items [id => qty], name, phone, address, floor_apt, zone, comment.
// Returns [order id, number] or throws RuntimeException with a message for the client.
function place_order(array $c, array $in, ?array $me): array {
	$cid = (int)$c['id'];
	$s = menu_set($cid);
	if (!$s['is_on']) throw new RuntimeException('Мәзір қазір жабық.');
	$table = (int)($in['table'] ?? 0);
	$types = menu_types($c, $s, $table > 0);
	$type = (int)($in['type'] ?? 0);
	if (!isset($types[$type])) throw new RuntimeException('Тапсырыс түрін таңдаңыз.');
	if ($table && $s['tables'] && $table > (int)$s['tables']) throw new RuntimeException('Үстел табылмады.');

	$br = branches($cid);
	$bid = (int)($in['branch'] ?? 0);
	$ids = array_map(fn($b) => (int)$b['id'], $br);
	if (!in_array($bid, $ids, true)) $bid = $ids[0] ?? 0;
	if (!$bid) throw new RuntimeException('Мекеме табылмады.');

	// Prices are taken from the base, never from the browser
	$want = [];
	foreach ((array)($in['items'] ?? []) as $pid => $q) {
		$q = (int)$q;
		if ((int)$pid > 0 && $q > 0) $want[(int)$pid] = min($q, 99);
	}
	if (!$want) throw new RuntimeException('Себет бос.');
	if (count($want) > 60) throw new RuntimeException('Тым көп тағам.');
	$p = db()->prepare("SELECT p.id, p.price, COALESCE(NULLIF(p.name_kz, ''), p.name_ru) name FROM product p JOIN product_catalog k ON k.id = p.catalog_id
		WHERE p.id IN (" . implode(',', array_keys($want)) . ") AND k.company_id = ? AND k.arh IS NULL AND p.arh = 0 AND p.sale_online = 1");
	$p->execute([$cid]);
	$lines = [];
	$food = 0;
	foreach ($p as $r) {
		$price = (int)preg_replace('/\D/', '', (string)$r['price']);
		if ($price <= 0) continue;
		$q = $want[(int)$r['id']];
		$lines[] = ['id' => (int)$r['id'], 'q' => $q, 'price' => $price, 'name' => trim(html_entity_decode(strip_tags((string)$r['name']), ENT_QUOTES, 'UTF-8'))];
		$food += $price * $q;
	}
	if (count($lines) !== count($want)) throw new RuntimeException('Кейбір тағам мәзірде жоқ. Бетті жаңартыңыз.');

	$phone = $me ? (string)$me['phone'] : phone10((string)($in['phone'] ?? ''));
	$name = $me ? trim((string)$me['name']) : mb_substr(trim(strip_tags((string)($in['name'] ?? ''))), 0, 60);
	if ($name === 'Қонақ') $name = '';
	if ($type !== 4 && (strlen($phone) !== 10 || $phone[0] !== '7')) throw new RuntimeException('Телефонды толық жазыңыз.');
	if ($phone !== '' && (strlen($phone) !== 10 || $phone[0] !== '7')) $phone = '';

	$addr = '';
	$floor_apt = '';
	$delivery = 0;
	if ($type === 1) {
		$addr = mb_substr(trim(preg_replace('/\s+/', ' ', str_replace(';', ',', strip_tags((string)($in['address'] ?? ''))))), 0, 200);
		if ($addr === '') throw new RuntimeException('Мекенжайды жазыңыз.');
		$floor_apt = mb_substr(trim(strip_tags((string)($in['floor_apt'] ?? ''))), 0, 30);
		$zs = zones($cid, $bid);
		if ($zs) {
			$z = array_values(array_filter($zs, fn($z) => (int)$z['id'] === (int)($in['zone'] ?? 0)));
			if (!$z) throw new RuntimeException('Аймақты (көшені) таңдаңыз.');
			$delivery = (int)$z[0]['price'];
		} else $delivery = (int)$c['delivery_price'];
		if ($s['min_sum'] && $food < (int)$s['min_sum']) throw new RuntimeException('Жеткізу үшін ең аз сома: ' . money((int)$s['min_sum']) . '.');
	}

	$note = mb_substr(trim(strip_tags((string)($in['comment'] ?? ''))), 0, 300);
	$add = trim(($table ? 'Үстел ' . $table . '. ' : '') . ($name !== '' && !$me ? $name . '. ' : '') . $note);
	$total = $food + $delivery;
	$src = qr_mode() ? 'qr' : 'my';

	$db = db();
	$db->beginTransaction();
	try {
		$client = $phone !== '' ? client_for($cid, $phone, $name, $addr, $floor_apt) : 0;
		$num = next_number($cid, $bid);
		$cols = 'number, total, quantity, paid, payment_method, pay_delivery, order_type, order_status, company_id, branch_id, address, floor_apt, phone, client_id, additional, ins_dt, upd_dt';
		$vals = [$num, $total, array_sum(array_column($lines, 'q')), 1, 'unpaid', $delivery, $type, 1, $cid, $bid,
			$addr !== '' ? $addr : null, $floor_apt !== '' ? $floor_apt : null, $phone !== '' ? $phone : null, $client ?: null, $add !== '' ? $add : null,
			date('Y-m-d H:i:s'), date('Y-m-d H:i:s')];
		if (source_col()) { $cols .= ', source'; $vals[] = $src; }
		$db->prepare("INSERT INTO retail_orders ($cols) VALUES (" . implode(',', array_fill(0, count($vals), '?')) . ')')->execute($vals);
		$oid = (int)$db->lastInsertId();
		$ins = $db->prepare('INSERT INTO retail_orders_products (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)');
		foreach ($lines as $l) $ins->execute([$oid, $l['id'], $l['q'], $l['price']]);
		order_log_add($oid, 'created', '', '#' . $num);
		order_log_add($oid, 'status', '', '1');
		order_log_add($oid, 'source', '', $src);
		order_log_add($oid, 'items', '', implode('; ', array_map(fn($l) => $l['name'] . ' ×' . $l['q'], $lines)));
		$db->commit();
	} catch (Throwable $e) {
		$db->rollBack();
		error_log('menu order: ' . $e->getMessage());
		throw new RuntimeException('Тапсырыс сақталмады. Қайта көріңіз.');
	}

	// Telegram, as from the kassa
	$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
	$bn = count($br) > 1 ? (array_values(array_filter($br, fn($b) => (int)$b['id'] === $bid))[0]['name'] ?? '') : '';
	$txt = '<b>Тапсырыс:</b> #' . $num . ' (' . ($src === 'qr' ? 'QR меню' : 'жеке кабинет') . ")\n";
	foreach (['Точка' => $bn, 'Формат' => $types[$type], 'Үстел' => $table ?: '', 'Аты' => $name, 'Телефон' => $phone, 'Адрес' => $addr, 'Этаж, кв' => $floor_apt, 'Қосымша' => $note] as $k => $v)
		if ((string)$v !== '') $txt .= "<b>$k:</b> " . $h($v) . "\n";
	$txt .= "\n<b>Тауарлар:</b>\n";
	foreach ($lines as $l) $txt .= '• ' . $h($l['name']) . ': ' . $l['q'] . ' × ' . money($l['price']) . ' = ' . money($l['q'] * $l['price']) . "\n";
	if ($delivery) $txt .= "\n<b>Жеткізу:</b> " . money($delivery);
	$txt .= "\n<b>Жалпы:</b> " . money($total) . "\n<b>Төлем:</b> төленбеген";
	tg_send($c, $txt);

	return [$oid, $num];
}
