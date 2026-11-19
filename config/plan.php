<?php
// Тариф бойынша рұқсат (2026-11-18), PDO нұсқасы. pos/adm/dlv/ktc-тағы config/plan.php-мен бір API:
// plan::load($company_id), plan::state(), plan::can('online'), plan::lim('dish').
// Бұл сайтты мейрамхананың клиенттері ашады: жолақ та, төлем туралы сөз де жоқ, мәзір әрқашан ашық.
// plan::blocked() true болса тек онлайн тапсырыс өшеді: «Онлайн тапсырыс уақытша қолжетімсіз».

if (!class_exists('plan', false)) {

if (!defined('PLAN_SITE')) define('PLAN_SITE', 'online');
if (!defined('PLAN_ENFORCE')) define('PLAN_ENFORCE', false);

class plan {
	public static $cid = 0;
	public static $sub = null;
	public static $tariff = null;
	public static $trial = false;
	public static $mods = [];
	public static $lims = ['kassa' => 0, 'branch' => 0, 'dish' => 0, 'order_month' => 0];
	public static $days = null;

	private static function rows($sql, $a = []) {
		$q = db()->prepare($sql); $q->execute($a);
		return $q->fetchAll(PDO::FETCH_ASSOC);
	}

	public static function load($cid) {
		self::$cid = (int)$cid; self::$sub = self::$tariff = null; self::$trial = false; self::$mods = []; self::$days = null;
		foreach (self::$lims as $k => $v) self::$lims[$k] = 0;
		try {
			$s = self::rows('SELECT * FROM company_sub WHERE company_id = ? LIMIT 1', [self::$cid])[0] ?? null;
			if (!$s || empty($s['paid_until'])) return;
			self::$sub = $s;
			self::$days = (int)floor((strtotime($s['paid_until']) - strtotime(date('Y-m-d'))) / 86400);
			$p = self::rows('SELECT method FROM company_payment WHERE company_id = ? AND arh = 0 ORDER BY id DESC LIMIT 1', [self::$cid])[0] ?? null;
			self::$trial = $p && $p['method'] === 'trial';
			$tid = self::$trial ? 0 : (int)$s['tariff_id'];
			if ($tid) {
				self::$tariff = self::rows('SELECT * FROM company_tariff WHERE id = ?', [$tid])[0] ?? null;
				foreach (self::$lims as $k => $v) self::$lims[$k] = (int)(self::$tariff['lim_' . $k] ?? 0);
			} else {
				foreach (self::rows("SELECT k, v FROM company_plan_conf WHERE k LIKE 'trial_lim_%'") as $r) {
					$k = substr($r['k'], 10);
					if (isset(self::$lims[$k])) self::$lims[$k] = (int)$r['v'];
				}
			}
			foreach (self::rows('SELECT module_code FROM company_tariff_module WHERE tariff_id = ?', [$tid]) as $r) self::$mods[$r['module_code']] = 1;
		} catch (Throwable $e) { self::$sub = null; }
	}

	public static function state() {
		if (!self::$sub) return 'none';
		$d = self::$days;
		if ($d > 5) return 'active';
		if ($d >= 0) return 'warn';
		if ($d >= -3) return 'grace';
		return 'expired';
	}
	public static function can($m) {
		if (!self::$sub) return !PLAN_ENFORCE;
		return isset(self::$mods[$m]);
	}
	public static function lim($k) {
		$k = preg_replace('/^lim_/', '', (string)$k);
		return (int)(self::$lims[$k] ?? 0);
	}
	// Мәзір әрқашан көрінеді. blocked() тек онлайн тапсырысты жабады: PLAN_ENFORCE қосулы және
	// мерзімі өтсе (grace-тен кейін) не 'online' модулі тарифте жоқ болса. Жазылымы жоқ ('none') — рұқсат.
	public static function blocked($m = PLAN_SITE) {
		if (!PLAN_ENFORCE || !self::$sub) return false;
		return self::state() === 'expired' || !isset(self::$mods[$m]);
	}
	public static function banner() { return ''; } // клиенттерге көрсетілмейді
}

}
