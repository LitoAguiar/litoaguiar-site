<?php
/*
 * Últimos episódios COMPLETOS do No Fim do Dia (canal oficial no YouTube), via feed RSS.
 * Usa a playlist UULF (só vídeos longos: o YouTube já exclui Shorts e lives).
 * Reserva: feed do canal, descartando links /shorts/ e títulos com #shorts.
 * Só entra como episódio o vídeo no formato "Título | Convidado" (padrão dos episódios
 * completos); cortes e Shorts sem convidado no título ficam de fora.
 * Cache de 6 horas; se o YouTube falhar, mantém o último resultado bom.
 * Uso: /nfd.php → JSON {episodios:[{id,titulo,convidado,data,url,thumb}]}
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=1800');

const CANAL = 'UCxLeZ6P5umXqda9Ft58lVnw';
const TTL = 21600;
const QTD = 3;
$cacheFile = __DIR__ . '/.nfd-cache-v3.json';

$cache = is_file($cacheFile) ? json_decode((string) file_get_contents($cacheFile), true) : null;
if (!isset($_GET['atualizar']) && $cache && time() - ($cache['geradoEm'] ?? 0) < TTL) {
  echo json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit;
}

function nfd_feed(string $qs): ?string {
  if (!function_exists('curl_init')) return null;
  $ch = curl_init('https://www.youtube.com/feeds/videos.xml?' . $qs);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_FOLLOWLOCATION => true]);
  $xml = curl_exec($ch);
  $ok = curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
  curl_close($ch);
  return ($ok && $xml && strpos($xml, '<entry>') !== false) ? $xml : null;
}

// UC... → UULF...: playlist de uploads só com vídeos longos
$xml = nfd_feed('playlist_id=UULF' . substr(CANAL, 2)) ?? nfd_feed('channel_id=' . CANAL);

$eps = [];
if ($xml && preg_match_all('#<entry>(.*?)</entry>#s', $xml, $m)) {
  foreach ($m[1] as $e) {
    if (!preg_match('#<yt:videoId>([^<]+)#', $e, $id)) continue;
    preg_match('#<title>([^<]*)#', $e, $t);
    preg_match('#<published>([^<]+)#', $e, $p);
    $titulo = html_entity_decode($t[1] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (stripos($titulo, '#shorts') !== false || stripos($e, 'youtube.com/shorts/') !== false) continue;
    $partes = explode('|', $titulo, 2);
    if (count($partes) < 2 || trim($partes[1]) === '') continue;
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
