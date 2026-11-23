<?php
// Жүйенің ортақ баптаулары мен кілттері базада: sys_setting кестесі (k, v). Файл барлық сайтта бірдей.
// Файл өзгертпей dev панелінен ауыстырылады (мысалы Telegram бот токені: dev ▸ Telegram бот).
//   sys_set('tg_token')            — мәні (кесте не жол жоқ болса $def)
//   sys_set_put('tg_token', $v, 5) — жазу (бар болса жаңартады)
// Байланыс: mysqli db::$connect (adm, pos, dlv, ktc) не PDO db() (my). PHP 7.4 және 8.1.
if (!function_exists('sys_set')) {

	function sys_set_all($reload = false) {
		static $all = null;
		if ($all !== null && !$reload) return $all;
		$rows = null;
		try {
			if (class_exists('db', false) && isset(db::$connect) && db::$connect instanceof mysqli) {
				$q = @mysqli_query(db::$connect, "SELECT k, v FROM sys_setting");
				$rows = [];
				if ($q) while ($r = mysqli_fetch_row($q)) $rows[$r[0]] = (string)$r[1];
			} elseif (function_exists('db')) {
				$rows = [];
				foreach (db()->query("SELECT k, v FROM sys_setting") as $r) $rows[$r['k']] = (string)$r['v'];
			}
		} catch (Throwable $e) { $rows = []; } // кесте әлі жоқ
		if ($rows === null) return []; // база әлі қосылмаған: кэштемейміз
		return $all = $rows;
	}

	function sys_set($k, $def = '') {
		$a = sys_set_all();
		return isset($a[$k]) && $a[$k] !== '' ? $a[$k] : $def;
	}

	function sys_set_put($k, $v, $uid = 0) {
		$k = substr(preg_replace('/[^a-z0-9_]/', '', strtolower((string)$k)), 0, 64);
		if ($k === '') return false;
		$v = (string)$v; $uid = (int)$uid;
		try {
			if (class_exists('db', false) && isset(db::$connect) && db::$connect instanceof mysqli) {
				$e = function ($s) { return mysqli_real_escape_string(db::$connect, $s); };
				$ok = (bool)mysqli_query(db::$connect, "INSERT INTO sys_setting (k, v, upd_dt, upd_user) VALUES ('" . $e($k) . "', '" . $e($v) . "', NOW(), " . ($uid ?: 'NULL') . ")
					ON DUPLICATE KEY UPDATE v = VALUES(v), upd_dt = NOW(), upd_user = VALUES(upd_user)");
			} elseif (function_exists('db')) {
				$ok = db()->prepare("INSERT INTO sys_setting (k, v, upd_dt, upd_user) VALUES (?, ?, NOW(), ?)
					ON DUPLICATE KEY UPDATE v = VALUES(v), upd_dt = NOW(), upd_user = VALUES(upd_user)")->execute([$k, $v, $uid ?: null]);
			} else $ok = false;
		} catch (Throwable $e) { error_log('sys_set_put: ' . $e->getMessage()); $ok = false; }
		sys_set_all(true);
		return $ok;
	}
}
