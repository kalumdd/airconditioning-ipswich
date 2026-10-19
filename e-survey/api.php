<?php
// Video e-survey upload API. Called by /assets/esurvey.js.
//   action=start  -> creates a survey, returns sid + upload key
//   action=chunk  -> appends one piece of a file (files are sent in 4 MB pieces,
//                    so phone videos of any length get past hosting upload limits)
//   action=finish -> saves the customer's details, returns the private viewing link
//   action=notify -> backup email if the normal Formspree message failed
require __DIR__ . '/lib.php';

header('X-Content-Type-Options: nosniff');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') esurvey_fail('POST only', 405);

// Same-site requests only
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== parse_url(esurvey_base_url(), PHP_URL_HOST)) {
  esurvey_fail('Bad origin', 403);
}

// Video pieces arrive as a raw request body with their details in the URL
// (avoids PHP's upload_max_filesize, which is only 2 MB on some hosts).
$P = $_POST + $_GET;
$action = $P['action'] ?? '';

function esurvey_auth(): array {
  global $P;
  $sid = $P['sid'] ?? '';
  $key = $P['key'] ?? '';
  if (!esurvey_valid_sid($sid) || !is_string($key) || strlen($key) !== 32) esurvey_fail('Bad session', 403);
  $meta = esurvey_meta_load($sid);
  if (!$meta || !hash_equals($meta['key_hash'], hash('sha256', $key))) esurvey_fail('Bad session', 403);
  return [$sid, $meta];
}

if ($action === 'start') {
  if (!empty($P['_gotcha'])) esurvey_fail('Rejected', 400); // spam honeypot

  // Simple per-IP rate limit
  $ip = $_SERVER['REMOTE_ADDR'] ?? '0';
  $rl = esurvey_data_dir() . '/ratelimit.json';
  $log = is_file($rl) ? (json_decode((string)file_get_contents($rl), true) ?: []) : [];
  $cut = time() - 3600;
  foreach ($log as $k => $times) { $log[$k] = array_values(array_filter($times, fn($t) => $t > $cut)); if (!$log[$k]) unset($log[$k]); }
  $ipk = hash('sha256', $ip);
  if (count($log[$ipk] ?? []) >= ESURVEY_STARTS_PER_HOUR) esurvey_fail('Too many uploads from this connection. Please try again later or email us.', 429);
  $log[$ipk][] = time();
  file_put_contents($rl, json_encode($log), LOCK_EX);

  if (mt_rand(1, 20) === 1) esurvey_purge();

  $sid = date('Ymd-His') . '-' . bin2hex(random_bytes(4));
  $key = bin2hex(random_bytes(16));
  if (!@mkdir(esurvey_dir($sid), 0750, true)) esurvey_fail('Storage unavailable', 500);
  esurvey_meta_save($sid, [
    'sid' => $sid,
    'created' => time(),
    'site' => ESURVEY_SITE_NAME,
    'host' => $_SERVER['HTTP_HOST'] ?? '',
    'key_hash' => hash('sha256', $key),
    'view_token' => bin2hex(random_bytes(16)),
    'complete' => false,
    'files' => [],
    'details' => [],
  ]);
  esurvey_json(['ok' => true, 'sid' => $sid, 'key' => $key, 'chunk' => esurvey_chunk_bytes(),
    'maxFiles' => ESURVEY_MAX_FILES, 'maxFile' => ESURVEY_MAX_FILE_BYTES, 'maxTotal' => ESURVEY_MAX_TOTAL]);
}

if ($action === 'chunk') {
  [$sid, $meta] = esurvey_auth();
  if (!empty($meta['complete'])) esurvey_fail('Survey already sent', 409);

  $idx    = (int)($P['idx'] ?? -1);
  $name   = esurvey_clean((string)($P['name'] ?? ''), 200);
  $size   = (int)($P['size'] ?? 0);
  $offset = (int)($P['offset'] ?? -1);
  $ext    = esurvey_ext($name);

  if ($idx < 0 || $idx >= ESURVEY_MAX_FILES) esurvey_fail('Too many files');
  if ($ext === '') esurvey_fail('That file type is not supported. Please send videos or photos.');
  if ($size <= 0 || $size > ESURVEY_MAX_FILE_BYTES) esurvey_fail('That file is too large (max ' . esurvey_human_size(ESURVEY_MAX_FILE_BYTES) . ').');

  // Copy the raw body to a temp file (max one chunk)
  $tmp = tempnam(sys_get_temp_dir(), 'esv');
  $in = fopen('php://input', 'rb');
  $t = fopen($tmp, 'wb');
  $len = (int)stream_copy_to_stream($in, $t, esurvey_chunk_bytes() + 1);
  fclose($in); fclose($t);
  register_shutdown_function(fn() => @unlink($tmp));
  if ($len <= 0) esurvey_fail('Upload interrupted, retrying', 400);
  if ($len > esurvey_chunk_bytes() || $offset < 0 || $offset + $len > $size) esurvey_fail('Bad chunk');

  // Total size across finished files plus this one
  $other = 0;
  foreach ($meta['files'] as $f) if ($f['idx'] !== $idx) $other += $f['size'];
  if ($other + $size > ESURVEY_MAX_TOTAL) esurvey_fail('This survey is over the ' . esurvey_human_size(ESURVEY_MAX_TOTAL) . ' total limit.');

  $stored = sprintf('%02d', $idx) . '.' . $ext;
  $part = esurvey_dir($sid) . '/' . $stored . '.part';
  $final = esurvey_dir($sid) . '/' . $stored;

  clearstatcache();
  $have = is_file($part) ? filesize($part) : 0;
  if ($offset === 0 && $have > 0 && $have !== $len) { @unlink($part); $have = 0; }

  if ($have === $offset + $len) {
    // This chunk already arrived (retry after a dropped response): skip writing.
  } elseif ($have === $offset) {
    $in = fopen($tmp, 'rb');
    $out = fopen($part, 'ab');
    if (!$in || !$out) esurvey_fail('Storage error', 500);
    flock($out, LOCK_EX);
    stream_copy_to_stream($in, $out);
    flock($out, LOCK_UN);
    fclose($in); fclose($out);
  } else {
    esurvey_json(['ok' => false, 'error' => 'resync', 'have' => $have], 409);
  }

  clearstatcache();
  $now = filesize($part);
  if ($now >= $size) {
    if ($now !== $size) { @unlink($part); esurvey_fail('File size mismatch, please try again'); }
    rename($part, $final);
    $meta = esurvey_meta_load($sid);
    $meta['files'] = array_values(array_filter($meta['files'], fn($f) => $f['idx'] !== $idx));
    $meta['files'][] = ['idx' => $idx, 'name' => $name, 'stored' => $stored, 'size' => $size];
    usort($meta['files'], fn($a, $b) => $a['idx'] <=> $b['idx']);
    esurvey_meta_save($sid, $meta);
    esurvey_json(['ok' => true, 'done' => true]);
  }
  esurvey_json(['ok' => true, 'done' => false, 'have' => $now]);
}

if ($action === 'finish') {
  [$sid, $meta] = esurvey_auth();
  if (!$meta['files']) esurvey_fail('No files arrived. Please try again.');
  $fields = ['name' => 120, 'contact' => 200, 'email' => 200, 'phone' => 60, 'postcode' => 20, 'property' => 80,
             'service' => 120, 'rooms' => 40, 'timescale' => 80, 'message' => 4000, 'page' => 300];
  $details = [];
  foreach ($fields as $k => $max) {
    if (isset($_POST[$k]) && $_POST[$k] !== '') $details[$k] = esurvey_clean((string)$_POST[$k], $max);
  }
  if (empty($details['name']) || (empty($details['contact']) && empty($details['email']) && empty($details['phone']))) {
    esurvey_fail('Please add your name and how we should reply.');
  }
  $meta['details'] = $details;
  $meta['complete'] = true;
  $meta['completed'] = time();
  esurvey_meta_save($sid, $meta);
  $total = array_sum(array_column($meta['files'], 'size'));
  esurvey_json(['ok' => true, 'ref' => strtoupper(substr($sid, -8)), 'view' => esurvey_view_url($sid, $meta),
    'files' => count($meta['files']), 'size' => esurvey_human_size($total)]);
}

if ($action === 'notify') {
  [$sid, $meta] = esurvey_auth();
  if (empty($meta['complete']) || !empty($meta['notified']) || ESURVEY_NOTIFY_EMAIL === '') esurvey_json(['ok' => true]);
  $d = $meta['details'];
  $lines = ["New video e-survey from " . ESURVEY_SITE_NAME, ''];
  foreach ($d as $k => $v) $lines[] = ucfirst($k) . ': ' . $v;
  $lines[] = '';
  $lines[] = 'Files: ' . count($meta['files']);
  $lines[] = 'Watch: ' . esurvey_view_url($sid, $meta);
  $host = preg_replace('/^www\./', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
  $headers = "From: " . ESURVEY_SITE_NAME . " <noreply@{$host}>\r\nContent-Type: text/plain; charset=utf-8";
  $replyTo = filter_var($d['email'] ?? $d['contact'] ?? '', FILTER_VALIDATE_EMAIL);
  if ($replyTo) $headers .= "\r\nReply-To: {$replyTo}";
  @mail(ESURVEY_NOTIFY_EMAIL, 'New video e-survey - ' . ($d['name'] ?? '') . ' ' . ($d['postcode'] ?? ''), implode("\n", $lines), $headers);
  $meta['notified'] = time();
  esurvey_meta_save($sid, $meta);
  esurvey_json(['ok' => true]);
}

esurvey_fail('Unknown action');
