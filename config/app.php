<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Aqtau');

session_set_cookie_params([
	'lifetime' => 3600 * 24 * 90,
	'path' => '/',
	'httponly' => true,
	'samesite' => 'Lax',
]);
session_start();

// Connection settings: config/db.php (not in git, differs on live), else local defaults.
// config/db.example.php shows the format.
function db(): PDO {
	static $pdo = null;
	if ($pdo === null) {
		$c = ['host' => 'localhost', 'name' => 'berik_004', 'user' => 'root', 'pass' => ''];
		if (is_file(__DIR__ . '/db.php')) $c = array_merge($c, (array)require __DIR__ . '/db.php');
		try {
			$pdo = new PDO('mysql:host=' . $c['host'] . ';dbname=' . $c['name'] . ';charset=utf8mb4', $c['user'], $c['pass'], [
				PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
				PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
				PDO::ATTR_EMULATE_PREPARES => false,
			]);
		} catch (PDOException $ex) {
			error_log('my.berik: db connect failed: ' . $ex->getMessage());
			db_down();
		}
	}
	return $pdo;
}

// Shown instead of a blank page when the database is not reachable
function db_down(): void {
	http_response_code(503);
	header('Content-Type: text/html; charset=utf-8');
	echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
		. '<title>Уақытша қолжетімсіз</title><body style="margin:0;min-height:100vh;display:grid;place-items:center;font:16px/1.5 system-ui,sans-serif;background:#f4f6f5;color:#1d2421;text-align:center;padding:24px;box-sizing:border-box">'
		. '<div><h1 style="font-size:22px;margin:0 0 8px">Сервис уақытша қолжетімсіз</h1><p style="margin:0;color:#6b7570">Базаға қосылу мүмкін болмады. Біраздан кейін қайта көріңіз.</p></div>';
	exit;
}

function e(?string $s): string {
	return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function csrf(): string {
	if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
	return $_SESSION['csrf'];
}

function csrf_ok(): bool {
	return isset($_POST['csrf']) && hash_equals($_SESSION['csrf'] ?? '', (string)$_POST['csrf']);
}

function phone10(string $s): string {
	return substr(preg_replace('/\D/', '', $s), -10);
}

// 7074321999 -> (707) 432-19-99, as the phone field shows it after +7
function phone_fmt(string $p): string {
	$d = preg_replace('/\D/', '', $p);
	if (strlen($d) !== 10) return $d;
	return '(' . substr($d, 0, 3) . ') ' . substr($d, 3, 3) . '-' . substr($d, 6, 2) . '-' . substr($d, 8, 2);
}

function money(int $n): string {
	return number_format($n, 0, '.', ' ') . ' ₸';
}

// Current client (user row) or null
function me(): ?array {
	static $me = false;
	if ($me === false) {
		$me = null;
		if (!empty($_SESSION['uid'])) {
			$st = db()->prepare('SELECT id, name, surname, mail, phone, `right`, (password IS NOT NULL AND password <> \'\') AS has_pass FROM user WHERE id = ? AND locked IS NULL');
			$st->execute([(int)$_SESSION['uid']]);
			$me = $st->fetch() ?: null;
			if (!$me) unset($_SESSION['uid']);
		}
	}
	return $me;
}

// $fresh: entered by personal link or SMS code, so a new password may be set
// without the old one for 30 minutes
function login_as(int $uid, bool $fresh = false): void {
	session_regenerate_id(true);
	$_SESSION['uid'] = $uid;
	$_SESSION['fresh'] = $fresh ? time() : 0;
}

function is_fresh(): bool {
	return ($_SESSION['fresh'] ?? 0) > time() - 1800;
}

// Orders that count in stats: paid and not refused
const OK_SQL = 'o.paid = 1 AND o.order_status NOT IN (5, 6, 10)';
const DONE_ST = [4];
const REFUSED_ST = [5, 6, 10];

// company_client ids of this person, as a safe SQL list
function my_clients(int $uid): string {
	static $c = [];
	if (!isset($c[$uid])) {
		$q = db()->prepare('SELECT id FROM company_client WHERE user_id = ?');
		$q->execute([$uid]);
		$ids = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
		$c[$uid] = $ids ? implode(',', $ids) : '0';
	}
	return $c[$uid];
}

function order_no(array $o): int {
	return (int)($o['number'] ?: $o['id']);
}

function dt(string $s, bool $time = true): string {
	return date($time ? 'd.m.Y H:i' : 'd.m.Y', strtotime($s));
}

// Cashback works only after its tables exist (built in another task)
function cashback_on(): bool {
	static $on = null;
	if ($on === null) {
		$q = db()->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
			AND ((TABLE_NAME = 'company' AND COLUMN_NAME = 'cashback_percent')
			OR (TABLE_NAME = 'company_client' AND COLUMN_NAME = 'cashback_balance'))");
		$t = db()->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'company_client_cashback'");
		$on = (int)$q->fetchColumn() === 2 && (int)$t->fetchColumn() === 1;
	}
	return $on;
}

const CASH_TYPES = ['earn' => 'Түсті', 'spend' => 'Жұмсалды', 'cancel' => 'Бас тартылды', 'refund' => 'Қайтарылды', 'manual' => 'Түзету'];

// Gift card ("every N-th order") works only after its columns exist
function gift_on(): bool {
	static $on = null;
	if ($on === null) {
		$q = db()->query("SELECT
			(SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'company' AND COLUMN_NAME IN ('gift_every', 'gift_name', 'gift_from'))
			+ (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'retail_orders' AND COLUMN_NAME = 'gift_name')
			+ (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'company_client_gift')");
		$on = (int)$q->fetchColumn() === 5;
	}
	return $on;
}

function table_on(string $t): bool {
	static $c = [];
	if (!isset($c[$t])) {
		$q = db()->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
		$q->execute([$t]);
		$c[$t] = (int)$q->fetchColumn() === 1;
	}
	return $c[$t];
}

// Gift tiers of a company, smallest "every" first. Same rules as the kassa (config/loyalty.php):
// active tiers from company_gift_tier, else the old single gift_every/gift_name.
function gift_tiers(array $c): array {
	$out = [];
	if (table_on('company_gift_tier')) {
		$q = db()->prepare('SELECT every, name FROM company_gift_tier WHERE company_id = ? AND active = 1 AND every > 1 ORDER BY every, id');
		$q->execute([(int)$c['company_id']]);
		foreach ($q as $t) $out[] = ['every' => (int)$t['every'], 'name' => trim((string)$t['name'])];
	} elseif ((int)$c['gift_every'] > 1 && trim((string)$c['gift_name']) !== '') {
		$out[] = ['every' => (int)$c['gift_every'], 'name' => trim((string)$c['gift_name'])];
	}
	return $out;
}

// Tier for the k-th order: the biggest "every" that divides k
function gift_tier_for(array $tiers, int $k): ?array {
	$hit = null;
	foreach ($tiers as $t) if ($k > 0 && $k % $t['every'] === 0) $hit = $t;
	return $hit;
}

// Gift progress per place where this person is a client.
// Orders count like the kassa: paid, not refused, since gift_from (orders on the way count too).
function gift_cards(string $ids): array {
	if (!gift_on()) return [];
	$rows = db()->query("SELECT cc.id, cc.company_id, c.name, c.gift_every, c.gift_name, c.gift_from,
			(SELECT COUNT(*) FROM company_client_gift g WHERE g.client_id = cc.id) got
		FROM company_client cc JOIN company c ON c.id = cc.company_id
		WHERE cc.id IN ($ids)")->fetchAll();
	$cnt = db()->prepare('SELECT COUNT(*) FROM retail_orders WHERE client_id = ? AND company_id = ? AND paid = 1 AND order_status NOT IN (5, 6, 10) AND ins_dt >= ?');
	$out = [];
	foreach ($rows as $r) {
		$tiers = gift_tiers($r);
		if (!$tiers) continue;
		$cnt->execute([$r['id'], $r['company_id'], $r['gift_from'] ?: '2000-01-01']);
		$n = (int)$cnt->fetchColumn();
		$max = max(array_column($tiers, 'every'));
		$next = null;
		for ($k = $n + 1; $k <= $n + $max; $k++) if ($t = gift_tier_for($tiers, $k)) { $next = ['k' => $k, 'left' => $k - $n - 1, 'name' => $t['name']]; break; }
		$prev = 0; // the last gift step already passed
		for ($k = $n; $k > 0; $k--) if (gift_tier_for($tiers, $k)) { $prev = $k; break; }
		$out[(int)$r['company_id']] = ['name' => $r['name'], 'count' => $n, 'got' => (int)$r['got'], 'tiers' => $tiers, 'next' => $next, 'prev' => $prev];
	}
	return $out;
}

// Cashback per place: balance and percent steps (company_cashback_step), only where the place gives it
function cash_cards(string $ids): array {
	if (!cashback_on()) return [];
	$rows = db()->query("SELECT c.id company_id, c.name, c.cashback_percent pct, cc.cashback_balance bal
		FROM company_client cc JOIN company c ON c.id = cc.company_id
		WHERE cc.id IN ($ids) AND c.cashback_percent > 0 ORDER BY bal DESC")->fetchAll();
	$steps = table_on('company_cashback_step') ? db()->prepare('SELECT from_sum, percent FROM company_cashback_step WHERE company_id = ? ORDER BY from_sum') : null;
	$out = [];
	foreach ($rows as $r) {
		$st = [];
		if ($steps) { $steps->execute([$r['company_id']]); foreach ($steps as $x) $st[] = ['from' => (int)$x['from_sum'], 'pct' => pct_txt((float)$x['percent'])]; }
		$out[(int)$r['company_id']] = ['name' => $r['name'], 'bal' => (int)floor((float)$r['bal']), 'pct' => pct_txt((float)$r['pct']), 'steps' => $st];
	}
	return $out;
}

function pct_txt(float $v): string {
	return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
}

const MONTHS =['', 'Қаң', 'Ақп', 'Нау', 'Сәу', 'Мам', 'Мау', 'Шіл', 'Там', 'Қыр', 'Қаз', 'Қар', 'Жел'];

// Profile is filled when there is a real name and a password
function profile_done(array $u): bool {
	$name = trim((string)$u['name']);
	return $name !== '' && $name !== 'Қонақ' && $u['has_pass'];
}

// Staff passwords are stored as plain text by the other sites; client ones are hashed
function pass_ok(string $input, string $stored): bool {
	if ($stored === '') return false;
	if (password_get_info($stored)['algo']) return password_verify($input, $stored);
	return hash_equals($stored, $input);
}

function need_login(bool $profile = true): array {
	$me = me();
	if (!$me) { header('Location: /login.php'); exit; }
	if ($profile && !profile_done($me)) { header('Location: /profile.php'); exit; }
	return $me;
}

// Simple per-session attempt limit: max $n tries per 15 minutes
function too_many(string $key, int $n = 5): bool {
	$k = 'lim_' . $key;
	$a = array_filter($_SESSION[$k] ?? [], fn($t) => $t > time() - 900);
	$_SESSION[$k] = $a;
	return count($a) >= $n;
}

function hit(string $key): void {
	$_SESSION['lim_' . $key][] = time();
}

// Status name translated via site_word "st:<name_ru>"
function status_words(string $lang = 'kz'): array {
	$rows = db()->prepare(
		"SELECT s.id, s.clr, s.name_ru, COALESCE(w.txt, s.name_kz) AS txt
		 FROM retail_orders_status s
		 LEFT JOIN site_word w ON w.name = CONCAT('st:', s.name_ru) AND w.lang = ?"
	);
	$rows->execute([$lang]);
	$out = [];
	foreach ($rows as $r) $out[(int)$r['id']] = $r;
	return $out;
}

// Menu and ordering (online / QR menu)
require_once __DIR__ . '/shop.php';
remember_company();

// The QR host shows only the menu
if (qr_mode() && basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) !== 'menu.php') {
	$qs = $_SERVER['QUERY_STRING'] ?? '';
	header('Location: /menu.php' . ($qs !== '' ? '?' . $qs : ''));
	exit;
}
