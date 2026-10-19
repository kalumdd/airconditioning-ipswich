<?php
// Private viewing page for one e-survey. The link (with its secret token) is
// sent to you in the enquiry email. Anyone without the token gets "not found".
require __DIR__ . '/lib.php';

header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

$sid = $_GET['s'] ?? '';
$tok = $_GET['t'] ?? '';
$meta = esurvey_valid_sid($sid) ? esurvey_meta_load($sid) : null;

// Admin session may view without the token
if (session_status() !== PHP_SESSION_ACTIVE) { session_name('esurvey_admin'); session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Strict']); }
$isAdmin = !empty($_SESSION['esurvey_admin']);

if (!$meta || (!$isAdmin && (!is_string($tok) || !hash_equals($meta['view_token'], $tok)))) {
  http_response_code(404);
  echo '<!doctype html><meta charset="utf-8"><title>Not found</title><p style="font:16px system-ui;padding:40px">This survey link is not valid or has expired.</p>';
  exit;
}

$mimes = ['mp4'=>'video/mp4','m4v'=>'video/mp4','mov'=>'video/quicktime','3gp'=>'video/3gpp','webm'=>'video/webm',
  'avi'=>'video/x-msvideo','mkv'=>'video/x-matroska','hevc'=>'video/mp4','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png',
  'heic'=>'image/heic','heif'=>'image/heif','webp'=>'image/webp','gif'=>'image/gif','pdf'=>'application/pdf'];

// ---- Stream one file (supports seeking in video players) ----
if (isset($_GET['f'])) {
  $want = (int)$_GET['f'];
  $file = null;
  foreach ($meta['files'] as $f) if ($f['idx'] === $want) $file = $f;
  $path = $file ? esurvey_dir($sid) . '/' . $file['stored'] : '';
  if (!$file || !is_file($path)) { http_response_code(404); exit; }
  $ext = strtolower(pathinfo($file['stored'], PATHINFO_EXTENSION));
  $size = filesize($path);
  $start = 0; $end = $size - 1;
  header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
  header('Accept-Ranges: bytes');
  header('Cache-Control: private, max-age=3600');
  $dlName = preg_replace('/[^A-Za-z0-9._ -]/', '_', $file['name']) ?: $file['stored'];
  header('Content-Disposition: ' . (isset($_GET['dl']) ? 'attachment' : 'inline') . '; filename="' . $dlName . '"');
  if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
    if ($m[1] === '' && $m[2] !== '') { $start = max(0, $size - (int)$m[2]); }
    else { $start = (int)$m[1]; if ($m[2] !== '') $end = min((int)$m[2], $size - 1); }
    if ($start > $end || $start >= $size) { http_response_code(416); header("Content-Range: bytes */$size"); exit; }
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$size");
  }
  header('Content-Length: ' . ($end - $start + 1));
  if (function_exists('set_time_limit')) @set_time_limit(0);
  while (ob_get_level()) ob_end_clean();
  $fp = fopen($path, 'rb');
  fseek($fp, $start);
  $left = $end - $start + 1;
  while ($left > 0 && !feof($fp)) {
    $buf = fread($fp, (int)min(1048576, $left));
    echo $buf; flush();
    $left -= strlen($buf);
  }
  fclose($fp);
  exit;
}

// ---- Page ----
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$d = $meta['details'] ?? [];
$base = 'view.php?s=' . rawurlencode($sid) . '&t=' . rawurlencode($meta['view_token']);
$total = array_sum(array_column($meta['files'], 'size'));
$labels = ['name'=>'Name','contact'=>'Reply to','email'=>'Email','phone'=>'Phone','postcode'=>'Postcode','property'=>'Property',
  'service'=>'Looking for','rooms'=>'Rooms / areas','timescale'=>'Timescale','message'=>'Notes','page'=>'Sent from'];
?><!doctype html>
<html lang="en-GB"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>E-survey <?= $h(strtoupper(substr($sid, -8))) ?> · <?= $h($d['name'] ?? '') ?></title>
<style>
:root{--navy:#405372;--ink:#1f2a3c;--green:#8bc63f;--line:#e1e4e9;--mist:#f2f4f7}
*{box-sizing:border-box}body{margin:0;font:16px/1.55 system-ui,-apple-system,Segoe UI,sans-serif;color:#3a4a63;background:var(--mist)}
header{background:var(--navy);color:#fff;padding:18px 20px}header b{font-size:1.1rem}header span{opacity:.8;font-size:.9rem;display:block}
main{max-width:1100px;margin:0 auto;padding:20px 16px 60px}
.card{background:#fff;border:1px solid var(--line);border-radius:14px;padding:18px 20px;margin-bottom:18px}
dl{display:grid;grid-template-columns:max-content 1fr;gap:6px 18px;margin:0}dt{font-weight:700;color:var(--ink)}dd{margin:0;white-space:pre-wrap;word-break:break-word}
.files{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px}
.file{background:#fff;border:1px solid var(--line);border-radius:14px;overflow:hidden}
.file video,.file img{width:100%;display:block;background:#000;max-height:420px;object-fit:contain}
.file .meta{padding:10px 14px;display:flex;justify-content:space-between;gap:10px;font-size:.88rem}
a{color:var(--navy);font-weight:700}h2{font-size:1.05rem;color:var(--ink);margin:0 0 12px}
.note{font-size:.85rem;color:#66738a}
@media(max-width:560px){dl{grid-template-columns:1fr}dt{margin-top:8px}}
</style></head><body>
<header><b><?= $h($meta['site']) ?> · Video e-survey <?= $h(strtoupper(substr($sid, -8))) ?></b>
<span>Received <?= $h(date('D j M Y, H:i', $meta['completed'] ?? $meta['created'])) ?> · <?= count($meta['files']) ?> file(s), <?= $h(esurvey_human_size($total)) ?><?= empty($meta['complete']) ? ' · NOT SUBMITTED (upload abandoned)' : '' ?></span></header>
<main>
<div class="card"><h2>Customer details</h2><dl>
<?php foreach ($labels as $k => $label): if (!empty($d[$k])): ?>
<dt><?= $h($label) ?></dt><dd><?= $h($d[$k]) ?></dd>
<?php endif; endforeach; ?>
</dl></div>
<div class="files">
<?php foreach ($meta['files'] as $f):
  $ext = strtolower(pathinfo($f['stored'], PATHINFO_EXTENSION));
  $src = $base . '&f=' . $f['idx'];
  $isVid = str_starts_with($mimes[$ext] ?? '', 'video/');
  $isImg = in_array($ext, ['jpg','jpeg','png','webp','gif'], true); ?>
<div class="file">
<?php if ($isVid): ?><video controls preload="metadata" playsinline src="<?= $h($src) ?>"></video>
<?php elseif ($isImg): ?><a href="<?= $h($src) ?>" target="_blank"><img loading="lazy" src="<?= $h($src) ?>" alt=""></a><?php endif; ?>
<div class="meta"><span><?= $h($f['name']) ?> · <?= $h(esurvey_human_size($f['size'])) ?></span><a href="<?= $h($src) ?>&dl=1">Download</a></div>
</div>
<?php endforeach; ?>
</div>
<p class="note">If a video will not play in your browser (some iPhone .mov/HEVC files), use Download and open it on your phone or in VLC. Files are deleted automatically after <?= (int)ESURVEY_RETENTION_DAYS ?> days.</p>
</main></body></html>
