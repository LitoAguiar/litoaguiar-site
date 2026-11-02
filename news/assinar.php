<?php
/*
 * Inscrição na newsletter "Do Dado à Decisão".
 * - valida nome e e-mail (WhatsApp opcional)
 * - registra a conversão "news-site" no RD Station Marketing (token fora do repositório, ver inc/rd-marketing.php)
 * - guarda cópia em news/dados/inscricoes.csv (pasta protegida por .htaccess)
 * - avisa por e-mail se o RD falhar, para ninguém se perder
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require dirname(__DIR__) . '/inc/rd-marketing.php';

const AVISO_PARA = 'lito@litoaguiar.com.br';
const REMETENTE  = 'site@litoaguiar.com.br';
const EVENTO     = 'news-site';

function responder(int $s, array $d): void { http_response_code($s); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') responder(405, ['ok' => false, 'erro' => 'Método não permitido.']);
$in = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($in)) $in = $_POST;
if (!empty($in['site'])) responder(200, ['ok' => true]); // armadilha para robôs

$c = fn(string $k, int $max = 160) => mb_substr(trim(strip_tags((string) ($in[$k] ?? ''))), 0, $max);
$nome = $c('nome'); $email = mb_strtolower($c('email')); $whats = $c('whatsapp', 40); $origem = $c('origem', 40) ?: 'site';

$erros = [];
if (mb_strlen($nome) < 2) $erros['nome'] = 'Informe seu nome.';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $erros['email'] = 'Informe um e-mail válido.';
if ($whats !== '' && strlen(preg_replace('/\D/', '', $whats)) < 10) $erros['whatsapp'] = 'Informe o WhatsApp com DDD, ou deixe em branco.';
if ($erros) responder(422, ['ok' => false, 'erros' => $erros]);

$quando = date('Y-m-d H:i:s');
$pasta = __DIR__ . '/dados';
if (!is_dir($pasta)) @mkdir($pasta, 0750, true);
if (!is_file($pasta . '/.htaccess')) @file_put_contents($pasta . '/.htaccess', "Require all denied\nDeny from all\n");

$rd = rdm_conversao(EVENTO, [
  'name' => $nome, 'email' => $email, 'mobile_phone' => $whats,
  'traffic_source' => 'litoaguiar.com.br/' . $origem,
  'tags' => ['news-assinante', 'news-origem-site'],
  'legal_bases' => [['category' => 'communications', 'type' => 'consent', 'status' => 'granted']],
]);

$csv = $pasta . '/inscricoes.csv'; $novo = !is_file($csv);
if ($fh = @fopen($csv, 'a')) {
  if ($novo) fputcsv($fh, ['data', 'nome', 'email', 'whatsapp', 'origem', 'rd']);
  fputcsv($fh, [$quando, $nome, $email, $whats, $origem, $rd['ok'] ? 'ok' : ($rd['erro'] ?? 'erro')]);
  fclose($fh);
}

if (!$rd['ok']) {
  $assunto = 'Nova inscrição na news (NÃO entrou no RD) · ' . $nome;
  $corpo = "Inscrição pelo site em $quando\n\nNome: $nome\nE-mail: $email\nWhatsApp: " . ($whats ?: '-') . "\nOrigem: $origem\n\nMotivo: " . ($rd['erro'] ?? '') . "\nCadastre manualmente no RD Marketing.\n";
  @mail(AVISO_PARA, '=?UTF-8?B?' . base64_encode($assunto) . '?=', $corpo,
    "From: Site Lito Aguiar <" . REMETENTE . ">\r\nReply-To: $email\r\nContent-Type: text/plain; charset=UTF-8\r\n", '-f' . REMETENTE);
}

responder(200, ['ok' => true]);
