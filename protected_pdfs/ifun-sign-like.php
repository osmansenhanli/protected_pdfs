<?php
// Supplies the URL links for like/dislike icons when generating the Word documents
// Standalone signer (bypasses WordPress entirely).
// URL: https://your-site/wp-json/ifun/v1/sign-like  (routed to THIS file)

require_once __DIR__ . '/ifun_secrets.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo '{"error":"method_not_allowed"}';
  exit;
}

$raw = file_get_contents('php://input');
$in  = json_decode($raw, true);
if (!is_array($in)) {
  http_response_code(400);
  echo '{"error":"bad_json"}';
  exit;
}

$book = isset($in['book_id'])     ? (string)$in['book_id']     : '';
$aid  = isset($in['activity_id']) ? (string)$in['activity_id'] : '';
$act  = isset($in['action'])      ? (string)$in['action']      : '';
$vid  = isset($in['viewer_id'])   ? (string)$in['viewer_id']   : '';

if ($book === '' || $aid === '' || !in_array($act, ['like','dislike'], true)) {
  http_response_code(400);
  echo '{"error":"missing_or_bad_params"}';
  exit;
}

// 1-hour token
$nonce = uuidv4();

// Canonical params (sorted)
$params = [
  'v'    => '1',
  'book' => $book,
  'aid'  => $aid,
  'act'  => $act,
  'nn'   => $nonce,
];
if ($vid !== '') $params['vid'] = $vid;
ksort($params);

// k=v&k2=v2 (URL-encoded values)
$pieces = [];
foreach ($params as $k => $v) $pieces[] = $k . '=' . rawurlencode($v);
$canonical = implode('&', $pieces);

// HMAC-SHA256 → base64url
$sig = base64url(hash_hmac('sha256', $canonical, IFUN_PDF_FEEDBACK_SECRET, true));

// Build the capture URL (also bypasses WP)
$pixelBase    = IFUN_WEBSITE_URL . '/ifun-pixel';
$queryWithSig = $canonical . '&sig=' . rawurlencode($sig);
$url          = $pixelBase . '?' . $queryWithSig;

http_response_code(200);
echo json_encode(['url' => $url], JSON_UNESCAPED_SLASHES);
exit;


// ===== helpers =====
function base64url(string $bin): string {
  return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function uuidv4(): string {
  $data = random_bytes(16);
  $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // version 4
  $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // variant 10
  $hex = bin2hex($data);
  return sprintf('%s-%s-%s-%s-%s',
    substr($hex,0,8), substr($hex,8,4), substr($hex,12,4),
    substr($hex,16,4), substr($hex,20,12)
  );
}
