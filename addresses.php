<?php
// Saved addresses. They live in company_client.addresses ("; " list), the same
// list the cashier sees in POS, so a new address is added for every place.
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/layout.php';

$me = need_login();
$ids = my_clients((int)$me['id']);
$err = '';

[$rows, $list] = addr_load($ids);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$add = trim(preg_replace('/\s+/', ' ', str_replace(';', ',', (string)($_POST['add'] ?? ''))));
	$del = (string)($_POST['del'] ?? '');

	if (!csrf_ok()) $err = 'Бетті жаңартып, қайта көріңіз.';
	elseif (!$rows) $err = 'Мекенжай сақтау үшін алдымен бір тапсырыс беру керек.';
	elseif ($add !== '' && mb_strlen($add) > 200) $err = 'Мекенжай тым ұзын.';
	elseif ($add !== '' && count($list) >= 10) $err = 'Ең көбі 10 мекенжай.';
	else {
		$up = db()->prepare('UPDATE company_client SET addresses = ?, upd_dt = NOW() WHERE id = ?');
		db()->beginTransaction();
		foreach ($rows as $r) {
			$cur = addr_split($r['addresses']);
			$low = array_map('mb_strtolower', $cur);
			if ($add !== '' && !in_array(mb_strtolower($add), $low, true)) $cur[] = $add;
			if ($del !== '') $cur = array_values(array_filter($cur, fn($a) => mb_strtolower($a) !== mb_strtolower($del)));
			$new = implode('; ', $cur);
			if ($new !== (string)$r['addresses']) $up->execute([$new !== '' ? $new : null, $r['id']]);
		}
		db()->commit();
		header('Location: /profile.php#addr');
		exit;
	}
	$_SESSION['addr_err'] = $err;
}
// The list and the form live in Профиль now
header('Location: /profile.php#addr');
exit;
