<?php
// Registration and password recovery by a code sent to the phone (SMS or WhatsApp)
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/notify.php';
require_once __DIR__ . '/config/layout.php';

if (!notify_on()) { header('Location: /login.php'); exit; }
if (me()) { header('Location: /'); exit; }

$err = '';
$otp = $_SESSION['otp'] ?? null; // ['phone', 'hash', 'exp', 'sent']

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if (!csrf_ok()) $err = 'Бетті жаңартып, қайта көріңіз.';

	// Step 1: send the code
	elseif (isset($_POST['phone'])) {
		$phone = phone10((string)$_POST['phone']);
		if (strlen($phone) !== 10 || $phone[0] !== '7') $err = 'Телефон нөмірін толық жазыңыз: 8 (700) 000-00-00.';
		elseif ($otp && $otp['sent'] > time() - 60) $err = 'Кодты 1 минуттан кейін қайта сұрауға болады.';
		elseif (too_many('send', 3)) $err = 'Тым көп код сұралды. 15 минуттан кейін көріңіз.';
		else {
			hit('send');
			$code = (string)random_int(1000, 9999);
			if (send_code($phone, $code)) {
				$otp = $_SESSION['otp'] = [
					'phone' => $phone,
					'hash' => password_hash($code, PASSWORD_DEFAULT),
					'exp' => time() + 300,
					'sent' => time(),
				];
				unset($_SESSION['lim_code']);
			} else $err = 'Кодты жіберу мүмкін болмады. Кейінірек көріңіз.';
		}
	}

	// Step 2: check the code
	elseif (isset($_POST['code']) && $otp) {
		if ($otp['exp'] < time()) { $err = 'Кодтың уақыты өтті, жаңасын сұраңыз.'; $otp = null; unset($_SESSION['otp']); }
		elseif (too_many('code')) { $err = 'Тым көп қате. Жаңа код сұраңыз.'; $otp = null; unset($_SESSION['otp']); }
		elseif (!password_verify(preg_replace('/\D/', '', (string)$_POST['code']), $otp['hash'])) { hit('code'); $err = 'Код қате.'; }
		else {
			$q = db()->prepare('SELECT id, locked FROM user WHERE phone = ?');
			$q->execute([$otp['phone']]);
			$u = $q->fetch();
			if ($u && $u['locked'] !== null) $err = 'Аккаунт бұғатталған.';
			else {
				if (!$u) {
					db()->prepare('INSERT INTO user (phone) VALUES (?)')->execute([$otp['phone']]);
					$uid = (int)db()->lastInsertId();
				} else $uid = (int)$u['id'];
				unset($_SESSION['otp'], $_SESSION['test_code']);
				login_as($uid, true);
				header('Location: /profile.php');
				exit;
			}
		}
	}

	elseif (isset($_POST['back'])) { $otp = null; unset($_SESSION['otp']); }
}

head('Тіркелу', 'sign');
sign_open('Жеке кабинет', $otp ? 'Кодты жазыңыз' : 'Тіркелу', $otp ? 'Код ' . e(phone_fmt($otp['phone'])) . ' нөміріне жіберілді' : 'Телефоныңызға растау коды келеді. Парольді ұмытсаңыз да осы жерден кіресіз.');
?>
	<?php if ($err): ?><p class="alert"><?= e($err) ?></p><?php endif; ?>

	<?php if (!$otp): ?>
		<form method="post" class="sg-form">
			<input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
			<label>Телефон <?= phone_input('', true, true) ?></label>
			<button type="submit" class="sg-btn">Код алу</button>
		</form>
	<?php else: ?>
		<?php if (notify_test() && !empty($_SESSION['test_code'])): ?>
			<p class="alert ok">Тест режимі, код: <b><?= e($_SESSION['test_code']) ?></b></p>
		<?php endif; ?>
		<form method="post" class="sg-form">
			<input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
			<label>Код
				<input name="code" class="sg-code" inputmode="numeric" maxlength="4" pattern="\d{4}" placeholder="• • • •" required autofocus autocomplete="one-time-code">
			</label>
			<button type="submit" class="sg-btn">Растау</button>
		</form>
		<form method="post" class="sg-form inline">
			<input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
			<button type="submit" name="back" value="1" class="link">Басқа нөмір</button>
		</form>
	<?php endif; ?>
	<p class="sg-links"><a href="/login.php">Парольмен кіру</a></p>
<?php sign_close(); foot();
