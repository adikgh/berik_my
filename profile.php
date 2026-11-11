<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/layout.php';

$me = need_login(false);
$first = !profile_done($me);
$staff = (int)$me['right'] === 1; // staff password is managed in the admin site
$err = $msg = '';
$form = (string)($_POST['form'] ?? '');

$name = trim((string)($form === 'data' || $first ? ($_POST['name'] ?? '') : ''));
if ($_SERVER['REQUEST_METHOD'] !== 'POST') $name = $me['name'] === 'Қонақ' ? '' : (string)$me['name'];
$surname = trim((string)($_POST['surname'] ?? $me['surname']));
$mail = trim((string)($_POST['mail'] ?? $me['mail']));

function check_pass(string $p, string $p2): string {
	if (mb_strlen($p) < 6) return 'Пароль кемінде 6 таңба болсын.';
	if ($p !== $p2) return 'Парольдер сәйкес емес.';
	return '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$pass = (string)($_POST['pass'] ?? '');
	$pass2 = (string)($_POST['pass2'] ?? '');

	if (!csrf_ok()) $err = 'Бетті жаңартып, қайта көріңіз.';

	// Personal data (and password on the first visit)
	elseif ($form === 'data' || $first) {
		if ($name === '' || mb_strlen($name) > 60) $err = 'Атыңызды жазыңыз.';
		elseif (mb_strlen($surname) > 60) $err = 'Тегі тым ұзын.';
		elseif ($mail !== '' && !filter_var($mail, FILTER_VALIDATE_EMAIL)) $err = 'Email қате жазылған.';
		elseif ($first && !$staff && !$me['has_pass']) $err = check_pass($pass, $pass2);
		if (!$err && $mail !== '') {
			$q = db()->prepare('SELECT id FROM user WHERE mail = ? AND id <> ?');
			$q->execute([$mail, $me['id']]);
			if ($q->fetchColumn()) $err = 'Бұл email басқа аккаунтта тұр.';
		}
		if (!$err) {
			$sql = 'UPDATE user SET name = ?, surname = ?, mail = ?, upd_dt = NOW()';
			$args = [$name, $surname !== '' ? $surname : null, $mail !== '' ? $mail : null];
			if ($first && !$staff && !$me['has_pass']) { $sql .= ', password = ?'; $args[] = password_hash($pass, PASSWORD_DEFAULT); }
			$args[] = $me['id'];
			db()->prepare($sql . ' WHERE id = ?')->execute($args);
			if ($first) { header('Location: /'); exit; }
			$msg = 'Деректер сақталды.';
		}
	}

	// Password change
	elseif ($form === 'pass' && !$staff) {
		$cur = db()->prepare('SELECT password FROM user WHERE id = ?');
		$cur->execute([$me['id']]);
		$stored = (string)$cur->fetchColumn();
		if (too_many('pass')) $err = 'Тым көп әрекет. 15 минуттан кейін көріңіз.';
		elseif ($stored !== '' && !is_fresh() && !pass_ok((string)($_POST['old'] ?? ''), $stored)) { hit('pass'); $err = 'Қазіргі пароль қате.'; }
		elseif ($err = check_pass($pass, $pass2)) {}
		else {
			db()->prepare('UPDATE user SET password = ?, upd_dt = NOW() WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $me['id']]);
			$msg = 'Пароль өзгертілді.';
		}
	}
}

// Addresses (saved by addresses.php)
$ids = my_clients((int)$me['id']);
[$arows, $alist] = addr_load($ids);
$aerr = (string)($_SESSION['addr_err'] ?? '');
unset($_SESSION['addr_err']);

if ($first) {
	head('Анкета', 'sign');
	sign_open('Жеке кабинет', 'Қош келдіңіз!', 'Кабинетті бастау үшін өзіңіз туралы толтырыңыз');
	?>
	<?php if ($err): ?><p class="alert"><?= e($err) ?></p><?php endif; ?>
	<form method="post" class="sg-form">
		<input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
		<input type="hidden" name="form" value="data">
		<label>Атыңыз *<input name="name" value="<?= e($name) ?>" maxlength="60" required autocomplete="given-name" placeholder="Мысалы, Айгерім"></label>
		<label>Тегіңіз<input name="surname" value="<?= e($surname) ?>" maxlength="60" autocomplete="family-name"></label>
		<label>Email<input type="email" name="mail" value="<?= e($mail) ?>" autocomplete="email" placeholder="міндетті емес"></label>
		<?php if (!$staff && !$me['has_pass']): ?>
			<label>Пароль ойлап табыңыз *<span class="pw"><input type="password" name="pass" minlength="6" required autocomplete="new-password" placeholder="кемінде 6 таңба"><button type="button" class="pw-eye" aria-label="Парольді көрсету"><svg viewBox="0 0 24 24"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg></button></span></label>
			<label>Парольді қайталаңыз *<input type="password" name="pass2" minlength="6" required autocomplete="new-password"></label>
		<?php endif; ?>
		<button type="submit" class="sg-btn">Жалғастыру</button>
	</form>
	<p class="sg-note">Келесі жолы <?= e(phone_fmt($me['phone'])) ?> нөмірі мен осы парольмен кіресіз.</p>
	<p class="sg-links"><a href="/exit.php">Шығу</a></p>
	<?php sign_close(); foot();
	exit;
}

$full = trim($me['name'] . ' ' . (string)$me['surname']);
$ini = mb_strtoupper(mb_substr(trim((string)$me['name']), 0, 1) . mb_substr(trim((string)$me['surname']), 0, 1));
head('Профиль', 'profile');
?>
<div class="pf-hero">
	<span class="pf-ava"><?= e($ini) ?></span>
	<div><b><?= e($full) ?></b><span><?= e(phone_fmt($me['phone'])) ?></span></div>
</div>

<h2>Жеке деректер</h2>
<div class="card">
	<?php if ($err && $form === 'data'): ?><p class="alert"><?= e($err) ?></p><?php endif; ?>
	<?php if ($msg && $form === 'data'): ?><p class="alert ok"><?= e($msg) ?></p><?php endif; ?>
	<form method="post" class="pf-form">
		<input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
		<input type="hidden" name="form" value="data">
		<label>Аты *<input name="name" value="<?= e($name) ?>" maxlength="60" required autocomplete="given-name"></label>
		<label>Тегі<input name="surname" value="<?= e($surname) ?>" maxlength="60" autocomplete="family-name"></label>
		<label class="pf-wide">Email<input type="email" name="mail" value="<?= e($mail) ?>" autocomplete="email"></label>
		<button type="submit" class="pf-wide">Сақтау</button>
	</form>
</div>

<h2 id="addr">Мекенжайларым</h2>
<div class="card">
	<p class="muted small" style="margin-top:0">Тапсырыс бергенде кассир осы тізімнен таңдай алады.</p>
	<?php if ($aerr): ?><p class="alert"><?= e($aerr) ?></p><?php endif; ?>
	<?php if ($alist): ?>
		<ul class="list addr">
			<?php foreach ($alist as $a): ?>
				<li>
					<span class="addr-t"><svg viewBox="0 0 24 24"><path d="M12 22s7-6.2 7-12a7 7 0 0 0-14 0c0 5.8 7 12 7 12zM12 12.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z"/></svg><?= e($a) ?></span>
					<form method="post" action="/addresses.php" onsubmit="return confirm('Өшіру керек пе?')">
						<input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
						<input type="hidden" name="del" value="<?= e($a) ?>">
						<button type="submit" class="addr-del" aria-label="Өшіру"><svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg></button>
					</form>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php else: ?>
		<p class="addr-empty">Әзірге мекенжай жоқ</p>
	<?php endif; ?>
	<?php if ($arows): ?>
		<form method="post" action="/addresses.php" class="addr-new">
			<input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
			<div class="addr-row">
				<input name="add" maxlength="200" required placeholder="Көше, үй, подъезд, қабат, пәтер" autocomplete="off" aria-label="Жаңа мекенжай">
				<button type="submit">Қосу</button>
			</div>
		</form>
	<?php endif; ?>
</div>

<h2>Пароль</h2>
<div class="card">
	<?php if ($staff): ?>
		<p class="muted">Сіз қызметкерсіз, пароліңіз админкада өзгереді.</p>
	<?php else: ?>
		<?php if ($err && $form === 'pass'): ?><p class="alert"><?= e($err) ?></p><?php endif; ?>
		<?php if ($msg && $form === 'pass'): ?><p class="alert ok"><?= e($msg) ?></p><?php endif; ?>
		<form method="post" class="pf-form">
			<input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
			<input type="hidden" name="form" value="pass">
			<?php if ($me['has_pass'] && !is_fresh()): ?>
				<label class="pf-wide">Қазіргі пароль<input type="password" name="old" required autocomplete="current-password"></label>
			<?php endif; ?>
			<label>Жаңа пароль<input type="password" name="pass" minlength="6" required autocomplete="new-password"></label>
			<label>Қайталаңыз<input type="password" name="pass2" minlength="6" required autocomplete="new-password"></label>
			<button type="submit" class="pf-wide ghost">Парольді өзгерту</button>
		</form>
	<?php endif; ?>
</div>

<a class="logout" href="/exit.php"><svg viewBox="0 0 24 24" width="20" height="20"><path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3M10 17l-5-5 5-5M5 12h11"/></svg>Шығу</a>
<?php foot();
