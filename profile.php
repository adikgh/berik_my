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

head($first ? 'Анкета' : 'Профиль', 'profile');
?>
<div class="card<?= $first ? ' login' : '' ?>">
	<h1><?= $first ? 'Қош келдіңіз!' : 'Профиль' ?></h1>
	<p class="muted"><?= $first ? 'Кабинетті бастау үшін өзіңіз туралы толтырыңыз.' : 'Телефон: +7 ' . e($me['phone']) ?></p>
	<?php if ($err && ($form !== 'pass' || $first)): ?><p class="alert"><?= e($err) ?></p><?php endif; ?>
	<?php if ($msg && $form === 'data'): ?><p class="alert ok"><?= e($msg) ?></p><?php endif; ?>
	<form method="post">
		<input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
		<input type="hidden" name="form" value="data">
		<label>Аты *<input name="name" value="<?= e($name) ?>" maxlength="60" required autocomplete="given-name"></label>
		<label>Тегі<input name="surname" value="<?= e($surname) ?>" maxlength="60" autocomplete="family-name"></label>
		<label>Email<input type="email" name="mail" value="<?= e($mail) ?>" autocomplete="email"></label>
		<?php if ($first && !$staff && !$me['has_pass']): ?>
			<label>Пароль ойлап табыңыз *<input type="password" name="pass" minlength="6" required autocomplete="new-password"></label>
			<label>Парольді қайталаңыз *<input type="password" name="pass2" minlength="6" required autocomplete="new-password"></label>
			<p class="muted small">Келесі жолы телефон мен осы парольмен кіресіз.</p>
		<?php endif; ?>
		<button type="submit"><?= $first ? 'Жалғастыру' : 'Сақтау' ?></button>
	</form>
</div>

<?php if (!$first): ?>
<h2>Пароль</h2>
<div class="card">
	<?php if ($staff): ?>
		<p class="muted">Сіз қызметкерсіз, пароліңіз админкада өзгереді.</p>
	<?php else: ?>
		<?php if ($err && $form === 'pass'): ?><p class="alert"><?= e($err) ?></p><?php endif; ?>
		<?php if ($msg && $form === 'pass'): ?><p class="alert ok"><?= e($msg) ?></p><?php endif; ?>
		<form method="post">
			<input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
			<input type="hidden" name="form" value="pass">
			<?php if ($me['has_pass'] && !is_fresh()): ?>
				<label>Қазіргі пароль<input type="password" name="old" required autocomplete="current-password"></label>
			<?php endif; ?>
			<label>Жаңа пароль<input type="password" name="pass" minlength="6" required autocomplete="new-password"></label>
			<label>Жаңа парольді қайталаңыз<input type="password" name="pass2" minlength="6" required autocomplete="new-password"></label>
			<button type="submit">Парольді өзгерту</button>
		</form>
	<?php endif; ?>
</div>
<?php endif; ?>
<a class="logout" href="/exit.php"><svg viewBox="0 0 24 24" width="20" height="20"><path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3M10 17l-5-5 5-5M5 12h11"/></svg>Шығу</a>
<?php foot();
