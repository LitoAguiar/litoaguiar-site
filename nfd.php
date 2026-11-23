<?php
/*
 * Últimos episódios do No Fim do Dia (canal oficial no YouTube), via feed RSS.
 * Cache de 6 horas; se o YouTube falhar, mantém o último resultado bom.
 * Uso: /nfd.php → JSON {episodios:[{id,titulo,convidado,data,url,thumb}]}
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=1800');

const CANAL = 'UCxLeZ6P5umXqda9Ft58lVnw';
const TTL = 21600;
const QTD = 3;
$cacheFile = __DIR__ . '/.nfd-cache.json';

$cache = is_file($cacheFile) ? json_decode((string) file_get_contents($cacheFile), true) : null;
if (!isset($_GET['atualizar']) && $cache && time() - ($cache['geradoEm'] ?? 0) < TTL) {
  echo json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit;
}

$xml = null;
if (function_exists('curl_init')) {
  $ch = curl_init('https://www.youtube.com/feeds/videos.xml?channel_id=' . CANAL);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_FOLLOWLOCATION => true]);
  $xml = curl_exec($ch);
  if (curl_getinfo($ch, CURLINFO_HTTP_CODE) !== 200) $xml = null;
  curl_close($ch);
}

$eps = [];
if ($xml && preg_match_all('#<entry>(.*?)</entry>#s', $xml, $m)) {
  foreach ($m[1] as $e) {
    if (!preg_match('#<yt:videoId>([^<]+)#', $e, $id)) continue;
    preg_match('#<title>([^<]*)#', $e, $t);
    preg_match('#<published>([^<]+)#', $e, $p);
    $titulo = html_entity_decode($t[1] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (stripos($titulo, '#shorts') !== false) continue;
    $partes = explode('|', $titulo, 2);
    $eps[] = [
      'id' => $id[1],
      'titulo' => trim($partes[0]),
      'convidado' => trim($partes[1] ?? ''),
      'data' => substr($p[1] ?? '', 0, 10),
      'url' => 'https://www.youtube.com/watch?v=' . $id[1],
      'thumb' => 'https://i.ytimg.com/vi/' . $id[1] . '/maxresdefault.jpg',
    ];
    if (count($eps) >= QTD) break;
  }
}

if ($eps) {
  $cache = ['geradoEm' => time(), 'episodios' => $eps];
  @file_put_contents($cacheFile, json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}
echo json_encode($cache ?: ['geradoEm' => 0, 'episodios' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
