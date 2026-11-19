<?php
// Sign-in for the client cabinet and the QR menu (qr.* host): phone + password,
// then a WhatsApp code when it is on for this site (dev ▸ WhatsApp ▸ My / QR) and the device is not remembered.
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/notify.php';
require_once __DIR__ . '/config/login_code.php';
require_once __DIR__ . '/config/layout.php';

$home = qr_mode() ? '/menu.php' : '/';
if (me()) { header('Location: ' . $home); exit; }

$err = '';
$phone = phone10((string)($_POST['phone'] ?? ''));
$wait = $_SESSION['login_wait'] ?? null; // password was right, the code is awaited: ['uid', 'phone', 'at']
if ($wait && ($wait['at'] < time() - 600 || !code_on())) { $wait = null; unset($_SESSION['login_wait']); }

// Sends the code. Returns '' when sent, 'wait' when one was sent less than a minute ago, else an error text.
function send_login_code(int $uid, string $phone): string {
	$r = otp_start('my_login', $uid, $phone);
	if ($r === 'sent' || $r === 'wait') return $r === 'wait' ? 'wait' : '';
	if ($r === 'limit') return 'Тым көп код сұралды. 15 минуттан кейін көріңіз.';
	return 'Кодты жіберу мүмкін болмады. Кейінірек көріңіз.';
}

function pass_of(int $uid): string {
	$q = db()->prepare('SELECT password FROM user WHERE id = ? AND locked IS NULL');
	$q->execute([$uid]);
	return (string)$q->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if (!csrf_ok()) $err = 'Бетті жаңартып, қайта көріңіз.';

	// Code step
	elseif ($wait && isset($_POST['back'])) { $wait = null; unset($_SESSION['login_wait'], $_SESSION['otp']['my_login']); }
	elseif ($wait && isset($_POST['resend'])) {
		$err = send_login_code((int)$wait['uid'], (string)$wait['phone']);
		if ($err === 'wait') $err = 'Кодты 1 минуттан кейін қайта сұрауға болады.';
	}
	elseif ($wait && isset($_POST['code'])) {
		$r = otp_check('my_login', (string)$_POST['code']);
		if (is_int($r) && $r === (int)$wait['uid']) {
			unset($_SESSION['login_wait'], $_SESSION['lim_login']);
			code_trust($r, pass_of($r));
			login_as($r);
			header('Location: ' . $home);
			exit;
		}
		if ($r === 'wrong') $err = 'Код қате.';
		else $err = $r === 'limit' ? 'Тым көп қате. Жаңа код сұраңыз.' : 'Кодтың уақыты өтті, жаңасын сұраңыз.';
	}

	// Password step
	elseif (too_many('login')) $err = 'Тым көп қате әрекет. 15 минуттан кейін көріңіз.';
	elseif (strlen($phone) !== 10 || $phone[0] !== '7') $err = 'Телефон нөмірін толық жазыңыз: 8 (700) 000-00-00.';
	else {
		$q = db()->prepare('SELECT id, password FROM user WHERE phone = ? AND locked IS NULL');
		$q->execute([$phone]);
		$u = $q->fetch();
		if ($u && pass_ok((string)($_POST['pass'] ?? ''), (string)$u['password'])) {
			$uid = (int)$u['id'];
			if (!code_on() || code_trusted($uid, (string)$u['password'])) {
				unset($_SESSION['lim_login']);
				login_as($uid);
				header('Location: ' . $home);
				exit;
			}
			$err = send_login_code($uid, $phone);
			if ($err === '' || $err === 'wait') {
				$wait = $_SESSION['login_wait'] = ['uid' => $uid, 'phone' => $phone, 'at' => time()];
				$err = '';
			}
		} else {
			hit('login'); // only wrong tries count towards the limit
			$err = 'Телефон немесе пароль қате.';
		}
	}
}

head('Кіру', 'sign');
if ($wait):
	sign_open('Жеке кабинет', 'Кодты жазыңыз', 'WhatsApp-қа растау коды жіберілді: ' . e(wa_mask((string)$wait['phone'])));
	$test = otp_test_code();
?>
	<?php if ($err): ?><p class="alert"><?= e($err) ?></p><?php endif; ?>
	<?php if ($test !== ''): ?><p class="alert ok">Тест режимі, код: <b><?= e($test) ?></b></p><?php endif; ?>
	<form method="post" class="sg-form">
		<input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
		<label>Код
			<input name="code" class="sg-code" inputmode="numeric" maxlength="4" pattern="\d{4}" placeholder="• • • •" required autofocus autocomplete="one-time-code">
		</label>
		<button type="submit" class="sg-btn">Растау</button>
	</form>
	<form method="post" class="sg-form inline">
		<input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
		<button type="submit" name="resend" value="1" class="link">Кодты қайта жіберу</button>
		<button type="submit" name="back" value="1" class="link">Артқа</button>
	</form>
<?php else:
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
	<?php if (qr_mode()): ?><p class="sg-links"><a href="/menu.php">← Мәзірге қайту</a></p><?php endif; ?>
<?php endif;
sign_close(); foot();
