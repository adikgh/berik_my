<?php
// $brand: company name shown in the header of the QR menu
function head(string $title, string $tab = '', string $extra = '', string $brand = ''): void {
	$qr = qr_mode();
	$me = $qr ? null : me(); ?>
<!doctype html>
<html lang="kk">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0EA768">
<title><?= e($title) ?><?= $qr ? '' : ' · BERIK POS' ?></title>
<link rel="icon" href="/assets/img/logo/favicon-32.png">
<link rel="apple-touch-icon" href="/assets/img/logo/apple-touch-icon.png">
<link rel="manifest" href="/manifest.json">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800&display=swap">
<link rel="stylesheet" href="/assets/css/main.css?v=3">
<?= $extra ?>
</head>
<body<?= $qr ? ' class="qr"' : ($me ? ' class="in"' : '') ?>>
<header class="top">
	<?php if ($qr): ?>
		<a class="brand" href="/menu.php"><img src="/assets/img/logo/berik-mark.svg" alt="" width="32" height="32"><span class="cmp"><?= e($brand !== '' ? $brand : 'Мәзір') ?></span></a>
	<?php else: ?>
		<a class="brand" href="/"><img src="/assets/img/logo/berik-mark.svg" alt="" width="32" height="32"><span>BERIK <b>POS</b></span></a>
		<?php if ($me): ?><a class="out" href="/exit.php">Шығу</a><?php elseif ($tab === 'menu'): ?><a class="out" href="/login.php">Кіру</a><?php endif; ?>
	<?php endif; ?>
</header>
<?php if ($me && profile_done($me)): ?>
<nav class="tabs">
	<?php foreach (['home' => ['/', 'Басты', 'M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z'],
		'menu' => ['/menu.php', 'Мәзір', 'M4 5h16M4 12h16M4 19h10'],
		'orders' => ['/orders.php', 'Тапсырыстар', 'M6 2h12l2 4v15a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6zM4 6h16M9 11h6M9 15h6'],
		'addr' => ['/addresses.php', 'Мекенжай', 'M12 22s7-6.2 7-12a7 7 0 0 0-14 0c0 5.8 7 12 7 12zM12 12.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z'],
		'profile' => ['/profile.php', 'Профиль', 'M12 12a4.5 4.5 0 1 0 0-9 4.5 4.5 0 0 0 0 9zM3.5 21a8.5 8.5 0 0 1 17 0']] as $k => [$href, $label, $d]): ?>
		<a href="<?= $href ?>"<?= $tab === $k ? ' class="on"' : '' ?>><svg viewBox="0 0 24 24" width="22" height="22"><path d="<?= $d ?>"/></svg><?= $label ?></a>
	<?php endforeach; ?>
</nav>
<?php endif; ?>
<main class="wrap">
<?php }

function foot(): void { ?>
</main>
</body>
</html>
<?php }

function badge(?array $s): string {
	return $s ? '<span class="badge" style="--c: ' . e($s['clr']) . '">' . e($s['txt']) . '</span>' : '';
}

function order_card(array $o, array $st): void { ?>
	<li>
		<a href="/order.php?id=<?= (int)$o['id'] ?>">
			<div class="row">
				<b>№<?= order_no($o) ?></b>
				<?= badge($st[(int)$o['order_status']] ?? null) ?>
			</div>
			<div class="row muted">
				<span><?= e($o['company']) ?> · <?= e($o['type_name']) ?></span>
				<span><?= dt($o['ins_dt']) ?></span>
			</div>
			<div class="sum"><?= money((int)$o['total']) ?></div>
		</a>
	</li>
<?php }
