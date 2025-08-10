<?php
header('Access-Control-Allow-Origin:*');
header('Access-Control-Allow-Methods:POST,OPTIONS');
header('Access-Control-Allow-Headers:Content-Type');
if ($_SERVER['REQUEST_METHOD']==='OPTIONS'){http_response_code(204);exit;}
header('Content-Type: application/json; charset=UTF-8');

// ==== ISI URL WORKER DI SINI ====
$WORKERS = [
  // contoh:
  // "https://worker-a.example.com/worker.php",
  // "https://worker-b.trycloudflare.com/worker.php",
];

$in = json_decode(file_get_contents('php://input'), true) ?: [];
$ids = isset($in['ids']) && is_array($in['ids']) ? $in['ids'] : [];
$ids = array_values(array_filter(array_map(fn($v)=>trim((string)$v), $ids), fn($v)=>$v!==''));

if (!$ids) { http_response_code(400); echo json_encode(["error"=>"IDs kosong"]); exit; }
if (!$WORKERS) { http_response_code(503); echo json_encode(["error"=>"Belum ada WORKER terdaftar"]); exit; }

$CHUNK_SIZE = 80;
$TIMEOUT    = 25;

$chunks=[]; for($i=0;$i<count($ids);$i+=$CHUNK_SIZE) $chunks[]=array_slice($ids,$i,$CHUNK_SIZE);

$mh = curl_multi_init();
$handles = [];
$wCount = count($WORKERS);
$debug  = [];

foreach ($chunks as $i => $chunk) {
  $target = $WORKERS[$i % $wCount];
  $ch = curl_init($target);
  curl_setopt_array($ch, [
    CURLOPT_POST=>true,
    CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
    CURLOPT_POSTFIELDS=>json_encode(['ids'=>$chunk], JSON_UNESCAPED_UNICODE),
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_CONNECTTIMEOUT=>6,
    CURLOPT_TIMEOUT=>$TIMEOUT,
    CURLOPT_SSL_VERIFYPEER=>true,
    CURLOPT_SSL_VERIFYHOST=>2,
  ]);
  curl_setopt($ch, CURLOPT_PRIVATE, (string)$i);
  $handles[] = $ch;
  curl_multi_add_handle($mh, $ch);
}

$results = [];
do {
  $stat = curl_multi_exec($mh, $running);
  if ($stat > CURLM_OK) break;

  while ($info = curl_multi_info_read($mh)) {
    $ch = $info['handle'];
    $idx = (int) curl_getinfo($ch, CURLINFO_PRIVATE);
    $target = $WORKERS[$idx % $wCount];
    $body = curl_multi_getcontent($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);

    if ($http===200) {
      $json = json_decode($body, true);
      if (is_array($json)) {
        $results[] = $json;
      } else {
        $debug[] = ["worker"=>$target, "http"=>$http, "error"=>"Respon bukan JSON", "sample"=>substr($body,0,200)];
      }
    } else {
      $debug[] = ["worker"=>$target, "http"=>$http, "error"=>$err ?: "HTTP $http", "sample"=>substr($body,0,200)];
    }

    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);
  }
  if ($running) curl_multi_select($mh, 0.5);
} while ($running);
curl_multi_close($mh);

// gabung
$aman=[]; $banned=[]; $lainnya=[];
foreach ($results as $r){
  if (!empty($r['aman']))   $aman   = array_merge($aman,   $r['aman']);
  if (!empty($r['banned'])) $banned = array_merge($banned, $r['banned']);
  if (!empty($r['lainnya']))$lainnya= array_merge($lainnya,$r['lainnya']);
}

echo json_encode([
  "aman"    => array_values(array_unique($aman)),
  "banned"  => array_values(array_unique($banned)),
  "lainnya" => $lainnya,
  "debug"   => $debug  // <<— lihat ini kalau “tidak ada hasil”
], JSON_UNESCAPED_UNICODE);
