<?php
/*
 * Últimas colunas de Lito Aguiar em cada portal.
 * Consulta as páginas de colunista no servidor, guarda o resultado por 6 horas
 * e devolve JSON para a LP. Se um portal falhar, mantém o último resultado bom.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=900');

const TTL = 21600; // 6 horas
$cacheFile = __DIR__ . '/.colunas-cache.json';

$fontes = [
  'ndmais' => [
    'veiculo' => 'ND+',
    'pagina'  => 'https://ndmais.com.br/tag/lito-aguiar/',
    'feed'    => 'https://ndmais.com.br/tag/lito-aguiar/feed/',
  ],
  'economiasc' => [
    'veiculo' => 'Economia SC',
    'pagina'  => 'https://economiasc.com/author/litoaguiar/',
    'feed'    => 'https://economiasc.com/author/litoaguiar/feed/',
  ],
  'acontecendoaqui' => [
    'veiculo' => 'Acontecendo Aqui',
    'pagina'  => 'https://acontecendoaqui.com.br/colunista/lito-aguiar/',
    'feed'    => 'https://acontecendoaqui.com.br/colunista/lito-aguiar/feed/',
  ],
];

$cache = is_file($cacheFile) ? json_decode((string) file_get_contents($cacheFile), true) : null;
$forcar = isset($_GET['atualizar']);
if (!$forcar && $cache && isset($cache['geradoEm']) && time() - $cache['geradoEm'] < TTL) {
  echo json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function baixar(string $url): ?string {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 4,
    CURLOPT_TIMEOUT        => 12,
    CURLOPT_CONNECTTIMEOUT => 6,
    CURLOPT_ENCODING       => '',
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
    CURLOPT_HTTPHEADER     => ['Accept: text/html,application/xhtml+xml,application/rss+xml,application/xml;q=0.9,*/*;q=0.8', 'Accept-Language: pt-BR,pt;q=0.9'],
  ]);
  $corpo = curl_exec($ch);
  $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($corpo === false || $status >= 400 || strlen($corpo) < 200) return null;
  if (stripos($corpo, 'Verifying that you are not a robot') !== false || stripos($corpo, 'Bot Verification') !== false) return null;
  return $corpo;
}

function limpar(?string $t): string {
  return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $t), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
}

function doFeed(string $xml): ?array {
  libxml_use_internal_errors(true);
  $rss = simplexml_load_string($xml);
  if (!$rss || !isset($rss->channel->item[0])) return null;
  $item = $rss->channel->item[0];
  $img = null;
  $media = $item->children('http://search.yahoo.com/mrss/');
  if (isset($media->content)) $img = (string) $media->content->attributes()->url;
  if (!$img && isset($item->enclosure)) $img = (string) $item->enclosure->attributes()->url;
  if (!$img) {
    $html = (string) $item->children('http://purl.org/rss/1.0/modules/content/')->encoded . (string) $item->description;
    if (preg_match('/<img[^>]+src="([^"]+)"/i', $html, $m)) $img = $m[1];
  }
  $ts = strtotime((string) $item->pubDate);
  return [
    'titulo' => limpar((string) $item->title),
    'link'   => trim((string) $item->link),
    'data'   => $ts ? date('Y-m-d', $ts) : null,
    'imagem' => $img ?: null,
  ];
}

function daPagina(string $html, string $base): ?array {
  libxml_use_internal_errors(true);
  $doc = new DOMDocument();
  $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
  $xp = new DOMXPath($doc);
  $host = parse_url($base, PHP_URL_HOST);
  // primeiro artigo da listagem (WordPress / Elementor)
  $blocos = $xp->query('//article | //*[contains(@class,"elementor-post ")] | //*[contains(@class,"jeg_post ")]');
  foreach ($blocos as $bloco) {
    $a = $xp->query('.//h2//a | .//h3//a | .//h4//a | .//*[contains(@class,"title")]//a', $bloco)->item(0);
    if (!$a) continue;
    $href = $a->getAttribute('href');
    if (!$href || strpos($href, $host) === false) continue;
    $img = null;
    $im = $xp->query('.//img', $bloco)->item(0);
    if ($im) {
      foreach (['data-src', 'data-lazy-src', 'src'] as $attr) {
        $v = $im->getAttribute($attr);
        if ($v && strpos($v, 'data:') !== 0) { $img = $v; break; }
      }
    }
    $data = null;
    $t = $xp->query('.//time', $bloco)->item(0);
    if ($t) {
      $dt = $t->getAttribute('datetime');
      $data = $dt ? date('Y-m-d', strtotime($dt)) : null;
      if (!$data && preg_match('#(\d{2})/(\d{2})/(\d{4})#', $t->textContent, $m)) $data = "$m[3]-$m[2]-$m[1]";
    }
    if (!$data && preg_match('#/(\d{4})/(\d{2})/(\d{2})/#', $href, $m)) $data = "$m[1]-$m[2]-$m[3]";
    if (!$data && preg_match('#(\d{2})/(\d{2})/(\d{4})#', $bloco->textContent, $m)) $data = "$m[3]-$m[2]-$m[1]";
    return ['titulo' => limpar($a->textContent), 'link' => $href, 'data' => $data, 'imagem' => $img];
  }
  return null;
}

$saida = ['geradoEm' => time(), 'colunas' => []];
foreach ($fontes as $id => $f) {
  $art = null;
  $xml = baixar($f['feed']);
  if ($xml && stripos(ltrim($xml), '<?xml') === 0) $art = doFeed($xml);
  if (!$art) {
    $html = baixar($f['pagina']);
    if ($html) $art = daPagina($html, $f['pagina']);
  }
  if (!$art && isset($cache['colunas'][$id]['artigo'])) $art = $cache['colunas'][$id]['artigo'];
  $saida['colunas'][$id] = ['veiculo' => $f['veiculo'], 'pagina' => $f['pagina'], 'artigo' => $art];
}

@file_put_contents($cacheFile, json_encode($saida, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo json_encode($saida, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
