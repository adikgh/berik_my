<?php
// Personal link: /in.php?u=<user id>&t=<signature>
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/client_link.php';
require_once __DIR__ . '/config/layout.php';

$uid = (int)($_GET['u'] ?? 0);
$t = (string)($_GET['t'] ?? '');

if ($uid > 0 && hash_equals(client_link_sig($uid), $t)) {
	$st = db()->prepare('SELECT id FROM user WHERE id = ? AND locked IS NULL');
	$st->execute([$uid]);
	if ($st->fetchColumn()) {
		login_as($uid, true);
		header('Location: /');
		exit;
	}
}

http_response_code(404);
head('Сілтеме жарамсыз');
?>
<div class="card login">
	<h1>Сілтеме жарамсыз</h1>
	<p class="muted">Сілтеме қате немесе ескірген. Кафеден жаңа сілтеме сұраңыз немесе телефонмен кіріңіз.</p>
	<p><a class="btn" href="/login.php">Кіру</a></p>
</div>
<?php foot();
