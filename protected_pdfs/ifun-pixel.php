<?php

// Handles the clicks to Like/Dislike buttons
// /home/creativi/protected_pdfs/ifun-like-api/ifun-pixel.php
// Minimal capture endpoint; bypasses WP. No expiry logic.

require_once __DIR__ . '/ifun_secrets.php';

// Read query
$act  = $_GET['act']  ?? '';
$aid  = $_GET['aid']  ?? '';
$book = $_GET['book'] ?? '';
$nn   = $_GET['nn']   ?? '';
$vid  = $_GET['vid']  ?? null;
$v    = $_GET['v']    ?? '1';
$sig  = $_GET['sig']  ?? '';

if (!in_array($act, ['like','dislike'], true)) { http_response_code(400); exit; }

// Build canonical string WITHOUT any expiry param
$canonical = canonicalize([
  'act'  => $act,
  'aid'  => $aid,
  'book' => $book,
  'nn'   => $nn,
  'v'    => $v,
] + (isset($vid) ? ['vid' => (string)$vid] : []));

// Verify HMAC
$calc = b64url(hash_hmac('sha256', $canonical, IFUN_PDF_FEEDBACK_SECRET, true));
if (!hash_equals($calc, (string)$sig)) { http_response_code(403); exit; }

// ---- Decide response based on User-Agent ----
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

$is_phone  = (bool) preg_match('/\b(iPhone|iPad|iPod|Android|Mobile)\b/i', $ua);
// Safari UAs include "Safari" but Chrome/Edge/Opera do too; exclude those.
$is_safari = (stripos($ua, 'Safari') !== false)
          && (stripos($ua, 'Chrome') === false)
          && (stripos($ua, 'Chromium') === false)
          && (stripos($ua, 'OPR') === false)   // Opera
          && (stripos($ua, 'Edg') === false);  // Edge

$should_show_message = true; // $is_phone || $is_safari;

// Common no-cache headers
$nocache = function () {
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('X-Robots-Tag: noindex, nofollow', true);
};

if ($should_show_message) {
  $title = ($act === 'like') ? 'Thanks for the thumbs-up!' : 'Thanks for the feedback!';
  $subtitle = 'Taking you back in a moment...';
  header('Content-Type: text/html; charset=UTF-8');
  $nocache();
  http_response_code(200);
  echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
    . '<meta name="viewport" content="width=device-width, initial-scale=1">'
    . '<title>Thanks</title>'
    . '<style>'
    . 'html,body{height:100%;margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#f8fafc;color:#0f172a;}'
    . '.wrap{height:100%;display:flex;align-items:center;justify-content:center;padding:20px;text-align:center;}'
    . '.card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:24px 26px;box-shadow:0 18px 40px rgba(15,23,42,.15);max-width:360px;width:100%;}'
    . '.icon{width:64px;height:64px;margin:0 auto 14px;border-radius:999px;display:grid;place-items:center;background:#e0f2fe;position:relative;}'
    . '.pulse{position:absolute;inset:-8px;border-radius:999px;border:2px solid #38bdf8;animation:pulse 1.4s ease-out infinite;opacity:.7;}'
    . '.spark{width:26px;height:26px;background:#38bdf8;border-radius:6px;transform:rotate(45deg);animation:spin 1.2s ease-in-out infinite;}'
    . 'h1{font-size:18px;margin:10px 0 4px;}'
    . 'p{margin:0;color:#475569;font-size:13px;}'
    . 'a{display:inline-block;margin-top:12px;color:#2563eb;text-decoration:none;font-weight:600;}'
    . '@keyframes pulse{0%{transform:scale(.7);opacity:.8}70%{transform:scale(1.2);opacity:0}100%{opacity:0}}'
    . '@keyframes spin{0%{transform:rotate(45deg) scale(1)}50%{transform:rotate(135deg) scale(1.08)}100%{transform:rotate(225deg) scale(1)}}'
    . '</style></head><body>'
    . '<div class="wrap"><div class="card">'
    . '<div class="icon"><span class="pulse"></span><span class="spark"></span></div>'
    . '<h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>'
    . '<p>' . htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8') . '</p>'
    . '<a href="javascript:history.back()">Go back now</a>'
    . '</div></div>'
    . '<script>(function(){setTimeout(function(){history.back();}, 2500);})();</script>'
    . '</body></html>';
  if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
} else {
  // Silent success for everything else
  http_response_code(204);
  $nocache();
  if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
}

// ---- After response: aggregate per-IP ----
$ipStr = client_ip_string();  // e.g. "203.0.113.7" or "::1"

$mysqli = @new mysqli(IFUN_DB_HOST, IFUN_DB_USER, IFUN_DB_PASS, IFUN_DB_NAME);
if ($mysqli && !$mysqli->connect_errno) {
    $is_like = ($act === 'like') ? 1 : 0;

    $sql = "INSERT INTO wpe5_ifun_pdf_feedback_ip (book_id, activity_id, ip, like_count, dislike_count)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
              like_count = like_count + VALUES(like_count),
              dislike_count = dislike_count + VALUES(dislike_count),
              last_seen = CURRENT_TIMESTAMP";

    if ($stmt = $mysqli->prepare($sql)) {
        $l = $is_like; $d = 1 - $is_like;
        $stmt->bind_param('sssii', $book, $aid, $ipStr, $l, $d);
        $stmt->execute();
        $stmt->close();
    }
    $mysqli->close();
}

// === helpers ===
function b64url(string $bin): string {
  return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}
function canonicalize(array $params): string {
  // Whitelist + sort to avoid param smuggling
  $allowed = ['act','aid','book','nn','v','vid'];
  $clean = [];
  foreach ($params as $k=>$v) {
    if (in_array($k, $allowed, true)) $clean[$k] = (string)$v;
  }
  ksort($clean, SORT_STRING);
  $pieces = [];
  foreach ($clean as $k=>$v) $pieces[] = $k . '=' . rawurlencode($v);
  return implode('&', $pieces);
}
/**
 * If you’re behind a trusted proxy (e.g., Cloudflare), prefer CF-Connecting-IP
 * but only after verifying the request source is really your proxy.
 */
function client_ip_string(): string {
  return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}
