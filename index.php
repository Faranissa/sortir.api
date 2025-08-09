<?php
// index.php — API sortir cepat untuk Railway

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(200);
  echo json_encode([
    "ok" => true,
    "message" => "Kirim POST JSON: {\"ids\":[\"12345\",\"ABCDE\", ...]}",
    "example" => "/",
  ]);
  exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$ids = isset($input['ids']) && is_array($input['ids']) ? $input['ids'] : [];

$ids = array_values(array_filter(array_map(function($v){
  $v = trim((string)$v);
  // biarkan alnum saja; kalau kamu butuh format lain, tinggal ubah regex ini
  return $v !== '' ? $v : null;
}, $ids)));

if (!$ids) {
  http_response_code(400);
  echo json_encode(["error" => "Input tidak valid. Kirim {\"ids\":[...]}"]);
  exit;
}

// ———————————————————————————————
// Konfigurasi target endpoint & header (meniru curl kamu)
$TARGET_URL = "https://i.mnigx5.com/web/infullRequest.do";
$COMMON_HEADERS = [
  "Content-Type: application/x-www-form-urlencoded; charset=UTF-8",
  "X-Requested-With: XMLHttpRequest",
  "Origin: https://i.mnigx5.com",
  "Referer: https://i.mnigx5.com",
  // beberapa user-agent populer, diacak untuk kurangi blokir heuristik
];
$UAS = [
  "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36",
  "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0 Safari/537.36",
  "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15",
  "Mozilla/5.0 (Linux; Android 12; Mobile) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0 Mobile Safari/537.36",
];

// ———————————————————————————————
// Helper klasifikasi hasil
function klasifikasi($rawJson) {
  $data = json_decode($rawJson, true);
  $code = $data['code'] ?? null;
  $msg  = $data['message'] ?? '';

  // Aturan dari kamu:
  // - "Item yang dibeli tidak ada atau telah dihapus." => AMAN
  // - "Sistem sedang dalam maintenance." => BANNED
  // Tambahan kode yang biasa muncul:
  // - 1025 / 201 sering dianggap AMAN
  // - 1135 / 1125 sering dianggap BANNED
  $m = mb_strtolower($msg);

  if (strpos($m, "item yang dibeli tidak ada atau telah dihapus") !== false) {
    return ["status" => "AMAN", "code" => $code, "message" => $msg];
  }
  if (strpos($m, "sistem sedang dalam maintenance") !== false) {
    return ["status" => "BANNED", "code" => $code, "message" => $msg];
  }

  if (in_array($code, ["1025","201"], true)) {
    return ["status" => "AMAN", "code" => $code, "message" => $msg];
  }
  if (in_array($code, ["1135","1125"], true)) {
    return ["status" => "BANNED", "code" => $code, "message" => $msg];
  }

  return ["status" => "UNKNOWN", "code" => $code, "message" => $msg];
}

// ———————————————————————————————
// curl_multi paralel biar cepat
$mh = curl_multi_init();
$chs = [];
$results = [];

// Batasi paralel biar stabil di Railway (mis. 20). Ubah kalau perlu.
$MAX_CONCURRENCY = 20;
$queue = $ids;
$active = 0;

// fungsi untuk menyiapkan 1 handle
$spawn = function($id) use ($TARGET_URL, $COMMON_HEADERS, $UAS) {
  $ch = curl_init();
  $ua = $UAS[array_rand($UAS)];
  curl_setopt_array($ch, [
    CURLOPT_URL            => $TARGET_URL,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
      "userId"     => $id,
      "infullType" => "domino",
      "version"    => "1.0",
    ]),
    CURLOPT_HTTPHEADER     => array_merge($COMMON_HEADERS, ["User-Agent: $ua"]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => false,
    CURLOPT_TIMEOUT        => 12,       // jangan terlalu besar
    CURLOPT_CONNECTTIMEOUT => 6,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
  ]);
  // simpan id ke handle untuk mapping
  curl_setopt($ch, CURLOPT_PRIVATE, $id);
  return $ch;
};

// isi slot awal
while ($active < $MAX_CONCURRENCY && !empty($queue)) {
  $id = array_shift($queue);
  $ch = $spawn($id);
  $chs[(int)$ch] = $ch;
  curl_multi_add_handle($mh, $ch);
  $active++;
}

// loop event
do {
  // jalankan
  $status = curl_multi_exec($mh, $running);
  if ($status > CURLM_OK) break;

  // ambil yang selesai
  while ($info = curl_multi_info_read($mh)) {
    /** @var resource $done */
    $done = $info['handle'];
    $id = curl_getinfo($done, CURLINFO_PRIVATE);
    $http = curl_getinfo($done, CURLINFO_HTTP_CODE);
    $body = curl_multi_getcontent($done);
    $err  = curl_error($done);

    if ($err) {
      $results[] = ["id"=>$id, "http"=>$http, "error"=>$err, "raw"=>$body];
    } else {
      $results[] = ["id"=>$id, "http"=>$http, "error"=>null, "raw"=>$body];
    }

    curl_multi_remove_handle($mh, $done);
    curl_close($done);
    unset($chs[(int)$done]);
    $active--;

    // isi slot lagi dari antrean
    if (!empty($queue)) {
      $nextId = array_shift($queue);
      $ch = $spawn($nextId);
      $chs[(int)$ch] = $ch;
      curl_multi_add_handle($mh, $ch);
      $active++;
    }
  }

  // tunggu event IO sebentar biar CPU nggak 100%
  if ($running) curl_multi_select($mh, 0.5);

} while ($running || !empty($queue));

curl_multi_close($mh);

// ———————————————————————————————
// susun hasil akhir
$aman = [];
$banned = [];
$lainnya = [];

foreach ($results as $r) {
  if ($r['error']) {
    $lainnya[] = ["id"=>$r['id'], "status"=>"ERROR", "note"=>$r['error']];
    continue;
  }
  $k = klasifikasi($r['raw']);
  if ($k['status'] === "AMAN") {
    $aman[] = $r['id'];
  } elseif ($k['status'] === "BANNED") {
    $banned[] = $r['id'];
  } else {
    $lainnya[] = ["id"=>$r['id'], "status"=>"UNKNOWN", "code"=>$k['code'] ?? null, "note"=>$k['message'] ?? null];
  }
}

echo json_encode([
  "aman"    => array_values($aman),
  "banned"  => array_values($banned),
  "lainnya" => $lainnya,
], JSON_UNESCAPED_UNICODE);