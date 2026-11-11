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
<link rel="stylesheet" href="/assets/css/main.css?v=7">
<?= $extra ?>
</head>
<body<?= $qr ? ' class="qr"' : ($tab === 'sign' ? ' class="sign"' : ($me ? ' class="in"' : '')) ?>>
<?php if ($tab !== 'sign' && ($qr || !$me)): // signed-in cabinet has no top bar; Шығу is in Профиль ?>
<header class="top">
	<?php if ($qr): ?>
		<a class="brand" href="/menu.php"><img src="/assets/img/logo/berik-mark.svg" alt="" width="32" height="32"><span class="cmp"><?= e($brand !== '' ? $brand : 'Мәзір') ?></span></a>
	<?php else: ?>
		<a class="brand" href="/"><img src="/assets/img/logo/berik-mark.svg" alt="" width="32" height="32"><span>BERIK <b>POS</b></span></a>
		<?php if ($tab === 'menu'): ?><a class="out" href="/login.php">Кіру</a><?php endif; ?>
	<?php endif; ?>
</header>
<?php endif; ?>
<?php if ($tab !== 'sign' && $me && profile_done($me)): ?>
<nav class="tabs">
	<a class="tabs-brand" href="/"><img src="/assets/img/logo/berik-mark.svg" alt="" width="28" height="28"><span>BERIK <b>POS</b></span></a>
	<?php foreach (['home' => ['/', 'Басты', 'M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z'],
		'menu' => ['/menu.php', 'Мәзір', 'M4 5h16M4 12h16M4 19h10'],
		'orders' => ['/orders.php', 'Тапсырыстар', 'M6 2h12l2 4v15a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6zM4 6h16M9 11h6M9 15h6'],
		'profile' => ['/profile.php', 'Профиль', 'M12 12a4.5 4.5 0 1 0 0-9 4.5 4.5 0 0 0 0 9zM3.5 21a8.5 8.5 0 0 1 17 0']] as $k => [$href, $label, $d]): ?>
		<a href="<?= $href ?>"<?= $tab === $k || ($tab === 'addr' && $k === 'profile') ? ' class="on"' : '' ?>><svg viewBox="0 0 24 24" width="22" height="22"><path d="<?= $d ?>"/></svg><?= $label ?></a>
	<?php endforeach; ?>
</nav>
<?php endif; ?>
<main class="wrap">
<?php }

function foot(): void { ?>
</main>
<script src="/assets/js/phone.js?v=1" defer></script>
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

// Phone field: mask 8 (700) 000-00-00, the same as the other Berik sites; the server keeps the last 10 digits
function phone_input(string $value = '', bool $required = true, bool $focus = false): string {
	return '<span class="ph"><input type="tel" name="phone" class="js-phone" value="' . e($value !== '' ? phone_fmt($value) : '')
		. '" inputmode="numeric" maxlength="17" placeholder="8 (700) 000-00-00" autocomplete="tel-national"'
		. ($required ? ' required' : '') . ($focus ? ' autofocus' : '') . '></span>';
}

// Sign pages (login, register, link): brand panel + form card
function sign_open(string $badge, string $title, string $sub): void { ?>
<div class="sg">
	<div class="sg-card">
		<aside class="sg-side">
			<a class="sg-brand" href="/"><span class="sg-logo"><img src="/assets/img/logo/berik-icon.svg" alt="" width="34" height="34"></span><span>BERIK <b>POS</b></span></a>
			<div class="sg-pitch">
				<h2>Сүйікті кафеңіз бір жерде</h2>
				<ul>
					<li><svg viewBox="0 0 24 24"><path d="M6 2h12l2 4v15a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6zM4 6h16M9 11h6M9 15h6"/></svg>Тапсырыстарыңыз бен статусы</li>
					<li><svg viewBox="0 0 24 24"><path d="M20 12v9H4v-9M2 7h20v5H2zM12 21V7M12 7H7.5a2.5 2.5 0 1 1 0-5C11 2 12 7 12 7zM12 7h4.5a2.5 2.5 0 1 0 0-5C13 2 12 7 12 7z"/></svg>Кэшбэк пен сыйлықтар</li>
					<li><svg viewBox="0 0 24 24"><path d="M12 22s7-6.2 7-12a7 7 0 0 0-14 0c0 5.8 7 12 7 12zM12 12.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z"/></svg>Мекенжайлар және мәзір</li>
				</ul>
			</div>
			<small>© Berik POS, <?= date('Y') ?></small>
		</aside>
		<section class="sg-main">
			<div class="sg-box">
				<span class="sg-badge"><?= e($badge) ?></span>
				<h1><?= e($title) ?></h1>
				<p class="sg-sub"><?= $sub ?></p>
<?php }

function sign_close(): void { ?>
			</div>
			<a class="sg-made" href="https://gprog.kz" target="_blank" rel="noopener">#gprog-та жасалған</a>
		</section>
	</div>
</div>
<?php }
