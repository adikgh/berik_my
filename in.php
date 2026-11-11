<?php
// Personal link: /in.php?u=<user id>&t=<key>
// Key = user.cab_token (касса жасайды, базада сақталады). Ескі сілтемелер үшін config/client_link.php болса, оның қолтаңбасы да жарайды.
require_once __DIR__ . '/config/app.php';
if (is_file(__DIR__ . '/config/client_link.php')) require_once __DIR__ . '/config/client_link.php';
require_once __DIR__ . '/config/layout.php';

$uid = (int)($_GET['u'] ?? 0);
$t = (string)($_GET['t'] ?? '');

$ok = false;
if ($uid > 0 && $t !== '') {
	if (function_exists('client_link_sig') && hash_equals(client_link_sig($uid), $t)) $ok = true;
	elseif (strlen($t) == 32 && db()->query("SHOW COLUMNS FROM user LIKE 'cab_token'")->fetch()) {
		$st = db()->prepare('SELECT cab_token FROM user WHERE id = ?');
		$st->execute([$uid]);
		$tok = (string)$st->fetchColumn();
		$ok = $tok !== '' && hash_equals($tok, $t);
	}
}
if ($ok) {
	$st = db()->prepare('SELECT id FROM user WHERE id = ? AND locked IS NULL');
	$st->execute([$uid]);
	if ($st->fetchColumn()) {
		login_as($uid, true);
		header('Location: /');
		exit;
	}
}

http_response_code(404);
head('Сілтеме жарамсыз', 'sign');
sign_open('Жеке кабинет', 'Сілтеме жарамсыз', 'Сілтеме қате немесе ескірген. Кафеден жаңа сілтеме сұраңыз немесе телефонмен кіріңіз.');
?>
	<a class="sg-btn" href="/login.php">Кіру</a>
<?php sign_close(); foot();
