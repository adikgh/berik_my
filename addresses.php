<?php
// Saved addresses. They live in company_client.addresses ("; " list), the same
// list the cashier sees in POS, so a new address is added for every place.
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/layout.php';

$me = need_login();
$ids = my_clients((int)$me['id']);
$err = '';

function addr_split(?string $s): array {
	return array_values(array_filter(array_map('trim', explode(';', (string)$s)), 'strlen'));
}

function addr_load(string $ids): array {
	$rows = db()->query("SELECT id, addresses FROM company_client WHERE id IN ($ids)")->fetchAll();
	$all = [];
	foreach ($rows as $r) foreach (addr_split($r['addresses']) as $a) $all[mb_strtolower($a)] ??= $a;
	return [$rows, array_values($all)];
}

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
		header('Location: /addresses.php');
		exit;
	}
}

head('Мекенжайларым', 'addr');
?>
<h1>Мекенжайларым</h1>
<p class="muted">Тапсырыс бергенде кассир осы тізімнен таңдай алады.</p>
<?php if ($err): ?><p class="alert"><?= e($err) ?></p><?php endif; ?>

<?php if ($list): ?>
	<ul class="card list addr">
		<?php foreach ($list as $a): ?>
			<li>
				<span>📍 <?= e($a) ?></span>
				<form method="post" onsubmit="return confirm('Өшіру керек пе?')">
					<input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
					<input type="hidden" name="del" value="<?= e($a) ?>">
					<button type="submit" class="link" aria-label="Өшіру">✕</button>
				</form>
			</li>
		<?php endforeach; ?>
	</ul>
<?php else: ?>
	<p class="empty">Әзірге мекенжай жоқ.</p>
<?php endif; ?>

<?php if ($rows): ?>
<form method="post" class="card">
	<input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
	<label>Жаңа мекенжай
		<input name="add" maxlength="200" required placeholder="Көше, үй, подъезд, қабат, пәтер" autocomplete="street-address">
	</label>
	<button type="submit">Қосу</button>
</form>
<?php endif; ?>
<?php foot();
