<?php
// ==== CORS agar bisa dipanggil langsung dari web ====
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

header('Content-Type: application/json; charset=UTF-8');

// ==== Input ====
$in  = json_decode(file_get_contents('php://input'), true) ?: [];
$ids = isset($in['ids']) && is_array($in['ids']) ? $in['ids'] : [];
$ids = array_values(array_filter(array_map(fn($v)=>trim((string)$v), $ids), fn($v)=>$v!==''));
if (!$ids) { http_response_code(400); echo json_encode(["aman"=>[],"banned"=>[]]); exit; }

// ==== Target endpoints ====
$ENDPOINTS = [
  ["https://i.mnigx5.com/web/infullRequest.do","https://i.mnigx5.com"],
  ["https://i.xnzeq.com/infull/infullRequest.do","https://i.xnzeq.com"], // fallback
];

// ==== Tuning anti-spam ====
// Proses SEKUENSIAL dalam 1 koneksi (keep-alive).
// Tidak ada delay global. Jeda mikro (120–250ms) HANYA saat 1025 (rate limit).
define('JITTER_MIN_MS', 120);
define('JITTER_MAX_MS', 250);

// ==== Header mirip curl Android ====
function baseHeaders($origin){
  return [
    "Content-Type: application/x-www-form-urlencoded; charset=UTF-8",
    "X-Requested-With: XMLHttpRequest",
    "Origin: $origin",
    "Referer: $origin",
    "User-Agent: Mozilla/5.0 (Linux; Android 12) AppleWebKit/537.36 (KHTML, like Gecko) Chrome Mobile Safari/537.36",
    "Accept: application/json,text/plain,*/*",
    "Connection: keep-alive",
  ];
}

// ==== Parser & mapping ====
function parse_code_msg(string $resp): array {
  $j = json_decode($resp, true);
  if (is_array($j)) {
    $code = isset($j['code']) ? (string)$j['code'] : null;
    $msg  = isset($j['message']) ? (string)$j['message'] : null;
    if ($code !== null || $msg !== null) return [$code,$msg];
  }
  $plain = trim(strip_tags($resp));
  if (preg_match('/^\s*(\d{3,4})(?:\s*[#:\-]\s*|\s+)?(.*)$/su', $plain, $m)) {
    $code = $m[1]; $msg = trim($m[2] ?? '');
    return [$code, $msg !== '' ? $msg : null];
  }
  if (preg_match('/"message"\s*:\s*"(?P<m>.*?)"/isu', $resp, $mm)) {
    return [null, html_entity_decode($mm['m'], ENT_QUOTES|ENT_HTML5, 'UTF-8')];
  }
  return [null,null];
}
function norm(?string $s): string {
  if(!$s) return '';
  $s = html_entity_decode($s, ENT_QUOTES|ENT_HTML5, 'UTF-8');
  $s = preg_replace('/\s+/u',' ', $s);
  return mb_strtolower(trim($s), 'UTF-8');
}
function decide($code,$msg): string {
  $c = (string)($code ?? '');
  $m = norm($msg ?? '');
  // by code
  if ($c === '1135' || $c === '1125') return 'BANNED';
  if ($c === '1025' || $c === '201')  return 'AMAN';
  if ($c === '0035')                  return 'SKIP';
  // by message
  if (strpos($m,'maintenance') !== false) return 'BANNED';
  if (strpos($m,'item yang dibeli tidak ada atau telah dihapus') !== false) return 'AMAN';
  if (strpos($m,'kesalahan id pengguna') !== false) return 'AMAN';
  if (strpos($m,'terlalu sering') !== false) return 'AMAN';
  return 'SKIP';
}

// ==== Helper bikin handle cURL (reuse / keep-alive) ====
function new_handle(string $url, string $origin) {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => baseHeaders($origin),
    CURLOPT_ENCODING       => "", // gzip/deflate/br
    CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
    CURLOPT_CONNECTTIMEOUT => 6,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 2,
    CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
    CURLOPT_FORBID_REUSE   => false,
    CURLOPT_FRESH_CONNECT  => false,
  ]);
  return $ch;
}

// ==== Siapkan 2 handle: utama & fallback ====
[$url1,$origin1] = $ENDPOINTS[0];
[$url2,$origin2] = $ENDPOINTS[1];
$h1 = new_handle($url1, $origin1);
$h2 = new_handle($url2, $origin2);

// ==== Proses semua ID, sekuensial, jitter mikro hanya saat 1025 ====
$aman = []; $banned = [];

foreach ($ids as $id) {
  $payload = http_build_query([
    "userId"     => $id,
    "infullType" => "domino",
    "version"    => "1.0"
  ]);

  // try endpoint utama
  curl_setopt($h1, CURLOPT_POSTFIELDS, $payload);
  $raw  = curl_exec($h1);
  $http = curl_getinfo($h1, CURLINFO_RESPONSE_CODE) ?: 0;

  $code = null; $msg = null;
  if ($http === 200 && is_string($raw) && $raw !== '') {
    [$code,$msg] = parse_code_msg($raw);
  }

  // rate-limit → coba sekali ke endpoint fallback + jitter mikro
  if ((string)$code === '1025') {
    $delay = random_int(JITTER_MIN_MS, JITTER_MAX_MS) * 1000; // us
    usleep($delay); // hanya saat 1025; tidak ada delay lain
    curl_setopt($h2, CURLOPT_POSTFIELDS, $payload);
    $raw2  = curl_exec($h2);
    $http2 = curl_getinfo($h2, CURLINFO_RESPONSE_CODE) ?: 0;
    if ($http2 === 200 && is_string($raw2) && $raw2 !== '') {
      [$code,$msg] = parse_code_msg($raw2);
    }
  }

  $st = decide($code,$msg);
  if ($st === 'AMAN')       $aman[]   = $id;
  elseif ($st === 'BANNED') $banned[] = $id;
  // SKIP diabaikan agar output tetap {aman:[], banned:[]}
}

curl_close($h1);
curl_close($h2);

// ==== Output ====
echo json_encode([
  "aman"   => array_values(array_unique($aman)),
  "banned" => array_values(array_unique($banned))
], JSON_UNESCAPED_UNICODE);
