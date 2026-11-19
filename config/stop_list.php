<?php
// Стоп-лист (company_stop_list), тек оқу. Басқару — админ /products/stop/, кесте сонда құрылады.
// Ереже админдегі config/stop_list.php-мен бірдей: mode 1 = стоп, 2 = «Сатуға рұқсат»;
// company_stop_setting.auto = 1 болса, граммовкадағы шикізат қалдығы <= 0 тағам да стопта; стоптағы тағам кірген сет те стопта.

// [product id => 1 стоп, 2 сеттің ішіндегі тағам стопта, 3 шикізаты бітті]
function stop_ids(int $cid): array {
	static $memo = [];
	if (isset($memo[$cid])) return $memo[$cid];
	$rows = function (string $sql, array $a = []) {
		try { $q = db()->prepare($sql); $q->execute($a); return $q->fetchAll(); } catch (PDOException $e) { return []; }
	};
	$out = []; $allow = [];
	foreach ($rows("SELECT product_id, mode FROM company_stop_list WHERE company_id = ?", [$cid]) as $r) {
		if ((int)$r['mode'] === 2) $allow[(int)$r['product_id']] = 1; else $out[(int)$r['product_id']] = 1;
	}
	$auto = $rows("SELECT auto FROM company_stop_setting WHERE company_id = ?", [$cid]);
	if ($auto && (int)$auto[0]['auto'] === 1) {
		foreach (stop_raw_out($cid, $rows) as $pid => $x) if (!isset($out[$pid]) && !isset($allow[$pid])) $out[$pid] = 3;
	}
	for ($lvl = 0; $out && $lvl < 2; $lvl++) {
		$add = 0;
		foreach ($rows("SELECT DISTINCT set_id FROM product_set_item WHERE product_id IN (" . implode(',', array_map('intval', array_keys($out))) . ")") as $r)
			if (!isset($out[(int)$r['set_id']]) && !isset($allow[(int)$r['set_id']])) { $out[(int)$r['set_id']] = 2; $add++; }
		if (!$add) break;
	}
	return $memo[$cid] = $out;
}

// Шикізаты біткен тағамдар: [product id => 1]
function stop_raw_out(int $cid, callable $rows): array {
	$out = [];
	foreach ($rows("SELECT st.raw_id, SUM(st.quantity) qty FROM product_raw_stock st JOIN product_raw r ON r.id = st.raw_id AND r.company_id = ?
		WHERE st.company_id = ? GROUP BY st.raw_id HAVING qty <= 0", [$cid, $cid]) as $r) $out[(int)$r['raw_id']] = 1;
	if (!$out) return [];
	$comps = [];
	foreach ($rows("SELECT id, raw_id FROM product_comp WHERE company_id = ?", [$cid]) as $r) $comps[(int)$r['id']] = ['raw' => (int)$r['raw_id'], 'parts' => []];
	if ($comps) foreach ($rows("SELECT comp_id, raw_id, sub_comp_id FROM product_comp_part WHERE comp_id IN (" . implode(',', array_keys($comps)) . ")") as $r)
		$comps[(int)$r['comp_id']]['parts'][] = $r;
	$craw = function ($id, $d = 0) use (&$craw, $comps) {
		$c = $comps[$id] ?? null; $o = [];
		if (!$c || $d > 8) return $o;
		if ($c['parts']) { foreach ($c['parts'] as $p) { if ($p['raw_id']) $o[(int)$p['raw_id']] = 1; elseif ($p['sub_comp_id']) $o += $craw((int)$p['sub_comp_id'], $d + 1); } }
		elseif ($c['raw']) $o[$c['raw']] = 1;
		return $o;
	};
	$dish = [];
	foreach ($rows("SELECT pi.product_id, pi.comp_id FROM product_item pi JOIN product p ON p.id = pi.product_id WHERE p.company_id = ? AND pi.comp_id > 0", [$cid]) as $r)
		$dish[(int)$r['product_id']] = ($dish[(int)$r['product_id']] ?? []) + $craw((int)$r['comp_id']);
	$sets = [];
	foreach ($rows("SELECT si.set_id, si.product_id, si.comp_id FROM product_set_item si JOIN product s ON s.id = si.set_id WHERE s.company_id = ?", [$cid]) as $r) $sets[(int)$r['set_id']][] = $r;
	foreach ($sets as $sid => $list) if (!isset($dish[$sid])) {
		$o = [];
		foreach ($list as $s) { if ($s['product_id']) $o += $dish[(int)$s['product_id']] ?? []; elseif ($s['comp_id']) $o += $craw((int)$s['comp_id']); }
		$dish[$sid] = $o;
	}
	$res = [];
	foreach ($dish as $pid => $raws) if (array_intersect_key($out, $raws)) $res[$pid] = 1;
	return $res;
}
