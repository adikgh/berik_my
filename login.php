<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/notify.php';
require_once __DIR__ . '/config/layout.php';

if (me()) { header('Location: /'); exit; }

$err = '';
$phone = phone10((string)($_POST['phone'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if (!csrf_ok()) $err = 'Бетті жаңартып, қайта көріңіз.';
	elseif (too_many('login')) $err = 'Тым көп әрекет. 15 минуттан кейін көріңіз.';
	elseif (strlen($phone) !== 10) $err = 'Телефонды толық жазыңыз.';
	else {
		hit('login');
		$q = db()->prepare('SELECT id, password FROM user WHERE phone = ? AND locked IS NULL');
		$q->execute([$phone]);
		$u = $q->fetch();
		if ($u && pass_ok((string)($_POST['pass'] ?? ''), (string)$u['password'])) {
			login_as((int)$u['id']);
			header('Location: /');
			exit;
		}
		$err = 'Телефон немесе пароль қате.';
	}
}

head('Кіру');
?>
<div class="card login">
	<h1>Жеке кабинет</h1>
	<p class="muted">Телефон мен парольмен кіріңіз</p>
	<?php if ($err): ?><p class="alert"><?= e($err) ?></p><?php endif; ?>
	<form method="post">
		<input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
		<label>Телефон
			<input type="tel" name="phone" value="<?= e($phone) ?>" inputmode="numeric" placeholder="7XX XXX XX XX" required autofocus autocomplete="tel">
		</label>
		<label>Пароль
			<input type="password" name="pass" required autocomplete="current-password">
		</label>
		<button type="submit">Кіру</button>
	</form>
	<?php if (notify_on()): ?>
		<p class="small"><a href="/register.php">Тіркелу</a> · <a href="/register.php">Парольді ұмыттым</a></p>
	<?php else: ?>
		<p class="muted small">Пароліңіз жоқ болса, кафеден жеке сілтемеңізді сұраңыз.</p>
	<?php endif; ?>
</div>
<?php foot();
