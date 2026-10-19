<?php
// Shared helpers for the video e-survey. No settings in here: see config.php.
require __DIR__ . '/config.php';

function esurvey_data_dir(): string {
  static $dir = null;
  if ($dir !== null) return $dir;
  $outside = ESURVEY_DATA_DIR;
  if ((is_dir($outside) || @mkdir($outside, 0750, true)) && is_writable($outside)) {
    $dir = realpath($outside);
  } else {
    // Fallback: inside the site, locked with .htaccess
    $dir = dirname(__DIR__) . '/esurvey-data';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
  }
  $ht = $dir . '/.htaccess';
  if (!file_exists($ht)) {
    @file_put_contents($ht, "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\nOptions -Indexes\n<FilesMatch \".*\">\n  SetHandler none\n</FilesMatch>\n");
  }
  if (!file_exists($dir . '/index.html')) @file_put_contents($dir . '/index.html', '');
  return $dir;
}

// Piece size: 4 MB, or smaller if the server's post_max_size is lower.
function esurvey_chunk_bytes(): int {
  $v = trim((string)ini_get('post_max_size'));
  $n = (int)$v; $u = strtolower(substr($v, -1));
  if ($u === 'g') $n *= 1073741824; elseif ($u === 'm') $n *= 1048576; elseif ($u === 'k') $n *= 1024;
  if ($n <= 0) return ESURVEY_CHUNK_BYTES;
  return (int)max(262144, min(ESURVEY_CHUNK_BYTES, $n - 65536));
}

function esurvey_json($data, int $code = 200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  header('X-Robots-Tag: noindex');
  echo json_encode($data);
  exit;
}

function esurvey_fail(string $msg, int $code = 400): void { esurvey_json(['ok' => false, 'error' => $msg], $code); }

function esurvey_valid_sid($sid): bool { return is_string($sid) && preg_match('/^\d{8}-\d{6}-[a-f0-9]{8}$/', $sid); }

function esurvey_dir(string $sid): string { return esurvey_data_dir() . '/' . $sid; }

function esurvey_meta_load(string $sid): ?array {
  $f = esurvey_dir($sid) . '/meta.json';
  if (!is_file($f)) return null;
  $m = json_decode((string)file_get_contents($f), true);
  return is_array($m) ? $m : null;
}

function esurvey_meta_save(string $sid, array $meta): void {
  $f = esurvey_dir($sid) . '/meta.json';
  $tmp = $f . '.tmp';
  file_put_contents($tmp, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
  rename($tmp, $f);
}

function esurvey_ext(string $name): string {
  $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
  return in_array($ext, ESURVEY_ALLOWED_EXT, true) ? $ext : '';
}

function esurvey_clean(string $s, int $max = 2000): string {
  $s = trim(str_replace("\0", '', $s));
  $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
  return mb_substr($s, 0, $max);
}

function esurvey_base_url(): string {
  $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
  $host = preg_replace('/[^a-z0-9.\-:]/i', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
  return ($https ? 'https' : 'http') . '://' . $host;
}

function esurvey_human_size(int $b): string {
  if ($b >= 1073741824) return round($b / 1073741824, 2) . ' GB';
  if ($b >= 1048576) return round($b / 1048576, 1) . ' MB';
  return max(1, round($b / 1024)) . ' KB';
}

function esurvey_rmdir(string $dir): void {
  if (!is_dir($dir)) return;
  foreach (scandir($dir) as $f) {
    if ($f === '.' || $f === '..') continue;
    $p = $dir . '/' . $f;
    is_dir($p) ? esurvey_rmdir($p) : @unlink($p);
  }
  @rmdir($dir);
}

// Delete surveys older than the retention period, and abandoned ones after 2 days.
function esurvey_purge(): void {
  $base = esurvey_data_dir();
  $now = time();
  foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $d) {
    $sid = basename($d);
    if (!esurvey_valid_sid($sid)) continue;
    $m = esurvey_meta_load($sid);
    $age = $now - ($m['created'] ?? filemtime($d));
    $done = !empty($m['complete']);
    if ($age > ESURVEY_RETENTION_DAYS * 86400 || (!$done && $age > 2 * 86400)) esurvey_rmdir($d);
  }
}

function esurvey_view_url(string $sid, array $meta): string {
  return esurvey_base_url() . '/e-survey/view.php?s=' . rawurlencode($sid) . '&t=' . rawurlencode($meta['view_token']);
}
