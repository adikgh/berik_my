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

function db(): PDO {
	static $pdo = null;
	if ($pdo === null) {
		$pdo = new PDO('mysql:host=localhost;dbname=berik_004;charset=utf8mb4', 'root', '', [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			PDO::ATTR_EMULATE_PREPARES => false,
		]);
	}
	return $pdo;
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

// Stamp card per place: done orders since the last gift (and since gift_from)
function gift_cards(string $ids): array {
	if (!gift_on()) return [];
	$rows = db()->query("SELECT cc.id, c.name, c.gift_every, c.gift_name, c.gift_from,
			(SELECT COALESCE(MAX(g.order_id), 0) FROM company_client_gift g WHERE g.client_id = cc.id) last_order,
			(SELECT COUNT(*) FROM company_client_gift g WHERE g.client_id = cc.id) got
		FROM company_client cc JOIN company c ON c.id = cc.company_id
		WHERE cc.id IN ($ids) AND c.gift_every > 0")->fetchAll();
	// Counted by order id, not time: preorders have a future ins_dt
	$cnt = db()->prepare('SELECT COUNT(*) FROM retail_orders WHERE client_id = ? AND order_status = 4 AND ins_dt >= ? AND id > ?');
	foreach ($rows as &$r) {
		$cnt->execute([$r['id'], $r['gift_from'] ?: '2000-01-01', (int)$r['last_order']]);
		$r['have'] = min((int)$cnt->fetchColumn(), (int)$r['gift_every'] - 1); // the N-th order itself is the gift
	}
	return $rows;
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
