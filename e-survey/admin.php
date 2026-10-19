<?php
// List of all video e-surveys for this site. Password is set in config.php.
require __DIR__ . '/lib.php';

header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
session_name('esurvey_admin');
session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Strict', 'cookie_secure' => !empty($_SERVER['HTTPS'])]);
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$error = '';

if (isset($_POST['password'])) {
  sleep(1); // slow down guessing
  if (ESURVEY_ADMIN_PASSWORD !== '' && strpos(ESURVEY_ADMIN_PASSWORD, '__') !== 0
      && hash_equals(ESURVEY_ADMIN_PASSWORD, (string)$_POST['password'])) {
    session_regenerate_id(true);
    $_SESSION['esurvey_admin'] = true;
    header('Location: admin.php'); exit;
  }
  $error = 'Wrong password.';
}
if (isset($_GET['logout'])) { $_SESSION = []; session_destroy(); header('Location: admin.php'); exit; }

$authed = !empty($_SESSION['esurvey_admin']);

if ($authed && isset($_POST['delete'], $_POST['csrf']) && hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
  $sid = $_POST['delete'];
  if (esurvey_valid_sid($sid)) esurvey_rmdir(esurvey_dir($sid));
  header('Location: admin.php'); exit;
}
if ($authed && isset($_POST['done'], $_POST['csrf']) && hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
  $sid = $_POST['done'];
  if (esurvey_valid_sid($sid) && ($m = esurvey_meta_load($sid))) { $m['quoted'] = empty($m['quoted']) ? time() : null; esurvey_meta_save($sid, $m); }
  header('Location: admin.php'); exit;
}

?><!doctype html><html lang="en-GB"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>E-surveys · <?= $h(ESURVEY_SITE_NAME) ?></title>
<style>
body{margin:0;font:15px/1.5 system-ui,-apple-system,Segoe UI,sans-serif;color:#3a4a63;background:#f2f4f7}
header{background:#405372;color:#fff;padding:16px 20px;display:flex;justify-content:space-between;align-items:center}
header a{color:#fff}main{max-width:1100px;margin:0 auto;padding:20px 16px}
table{width:100%;border-collapse:collapse;background:#fff;border:1px solid #e1e4e9;border-radius:12px;overflow:hidden}
th,td{text-align:left;padding:10px 12px;border-bottom:1px solid #e1e4e9;vertical-align:top}th{background:#f8f9fb;font-size:.8rem;text-transform:uppercase;letter-spacing:.06em}
tr.quoted td{opacity:.55}button{font:inherit;border:1px solid #c9ced8;background:#fff;border-radius:8px;padding:4px 10px;cursor:pointer}
button.del{color:#b42318}.login{max-width:340px;margin:12vh auto;background:#fff;padding:24px;border-radius:14px;border:1px solid #e1e4e9}
input{font:inherit;width:100%;padding:10px;border:1px solid #c9ced8;border-radius:8px;margin:8px 0 12px}
.pill{font-size:.75rem;background:#eef6e2;color:#3d6a12;border-radius:99px;padding:2px 8px}.warn{background:#fdecea;color:#b42318}
@media(max-width:700px){td:nth-child(4),th:nth-child(4){display:none}}
</style></head><body>
<?php if (!$authed): ?>
<form class="login" method="post"><b>E-survey admin</b><br><small><?= $h(ESURVEY_SITE_NAME) ?></small>
<?php if (strpos(ESURVEY_ADMIN_PASSWORD, '__') === 0): ?><p class="pill warn">Set a password in e-survey/config.php first.</p><?php endif; ?>
<input type="password" name="password" placeholder="Password" autofocus required>
<?php if ($error): ?><p class="pill warn"><?= $h($error) ?></p><?php endif; ?>
<button type="submit">Sign in</button></form>
<?php else:
  esurvey_purge();
  $rows = [];
  foreach (glob(esurvey_data_dir() . '/*', GLOB_ONLYDIR) ?: [] as $d) {
    $sid = basename($d);
    if (esurvey_valid_sid($sid) && ($m = esurvey_meta_load($sid))) $rows[] = $m;
  }
  usort($rows, fn($a, $b) => $b['created'] <=> $a['created']);
?>
<header><b>Video e-surveys · <?= $h(ESURVEY_SITE_NAME) ?></b><a href="?logout=1">Sign out</a></header>
<main>
<?php if (!$rows): ?><p>No surveys yet.</p><?php else: ?>
<table><thead><tr><th>Received</th><th>Customer</th><th>Files</th><th>Notes</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $m): $d = $m['details'] ?? []; ?>
<tr class="<?= !empty($m['quoted']) ? 'quoted' : '' ?>">
<td><?= $h(date('j M, H:i', $m['completed'] ?? $m['created'])) ?><br><small><?= $h(strtoupper(substr($m['sid'], -8))) ?></small></td>
<td><b><?= $h($d['name'] ?? '(no details)') ?></b><br><?= $h($d['contact'] ?? ($d['email'] ?? '')) ?> <?= $h($d['postcode'] ?? '') ?>
<?= empty($m['complete']) ? '<br><span class="pill warn">Not submitted</span>' : '' ?><?= !empty($m['quoted']) ? '<br><span class="pill">Quoted</span>' : '' ?></td>
<td><a href="view.php?s=<?= $h($m['sid']) ?>&t=<?= $h($m['view_token']) ?>" target="_blank"><?= count($m['files']) ?> file(s)</a><br><small><?= $h(esurvey_human_size(array_sum(array_column($m['files'], 'size')))) ?></small></td>
<td><?= $h(mb_substr(($d['property'] ?? '') . ' ' . ($d['rooms'] ?? '') . ' ' . ($d['message'] ?? ''), 0, 140)) ?></td>
<td style="white-space:nowrap"><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>"><button name="done" value="<?= $h($m['sid']) ?>"><?= empty($m['quoted']) ? 'Mark quoted' : 'Unmark' ?></button></form>
<form method="post" style="display:inline" onsubmit="return confirm('Delete this survey and its videos?')"><input type="hidden" name="csrf" value="<?= $h($_SESSION['csrf']) ?>"><button class="del" name="delete" value="<?= $h($m['sid']) ?>">Delete</button></form></td>
</tr>
<?php endforeach; ?>
</tbody></table>
<?php endif; ?>
<p><small>Storage: <?= $h(esurvey_data_dir()) ?> · surveys are deleted automatically after <?= (int)ESURVEY_RETENTION_DAYS ?> days.</small></p>
</main>
<?php endif; ?>
</body></html>
