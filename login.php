<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/notify.php';
require_once __DIR__ . '/config/layout.php';

if (me()) { header('Location: /'); exit; }

$err = '';
$phone = phone10((string)($_POST['phone'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if (!csrf_ok()) $err = 'Бетті жаңартып, қайта көріңіз.';
	elseif (too_many('login')) $err = 'Тым көп қате әрекет. 15 минуттан кейін көріңіз.';
	elseif (strlen($phone) !== 10 || $phone[0] !== '7') $err = 'Телефон нөмірін толық жазыңыз: +7 (700) 000-00-00.';
	else {
		$q = db()->prepare('SELECT id, password FROM user WHERE phone = ? AND locked IS NULL');
		$q->execute([$phone]);
		$u = $q->fetch();
		if ($u && pass_ok((string)($_POST['pass'] ?? ''), (string)$u['password'])) {
			unset($_SESSION['lim_login']);
			login_as((int)$u['id']);
			header('Location: /');
			exit;
		}
		hit('login'); // only wrong tries count towards the limit
		$err = 'Телефон немесе пароль қате.';
	}
}

head('Кіру', 'sign');
sign_open('Жеке кабинет', 'Қош келдіңіз!', 'Телефон нөміріңіз бен парольді жазыңыз');
?>
	<?php if ($err): ?><p class="alert"><?= e($err) ?></p><?php endif; ?>
	<form method="post" class="sg-form">
		<input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
		<label>Телефон <?= phone_input($phone, true, $phone === '') ?></label>
		<label>Пароль
			<span class="pw"><input type="password" name="pass" required autocomplete="current-password" placeholder="Пароліңіз"<?= $phone !== '' ? ' autofocus' : '' ?>><button type="button" class="pw-eye" aria-label="Парольді көрсету"><svg viewBox="0 0 24 24"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg></button></span>
		</label>
		<button type="submit" class="sg-btn">Кіру</button>
	</form>
	<?php if (notify_on()): ?>
		<p class="sg-links"><a href="/register.php">Тіркелу</a><span>·</span><a href="/register.php">Парольді ұмыттым</a></p>
	<?php else: ?>
		<p class="sg-note">Пароліңіз жоқ болса, кафеден жеке сілтемеңізді сұраңыз. Сілтеме арқылы кіріп, профильде пароль қоясыз.</p>
	<?php endif; ?>
<?php sign_close(); foot();
