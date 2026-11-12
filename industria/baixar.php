<?php
/*
 * Captura do roteiro "IA na indústria sem desmontar o time".
 * - valida nome, e-mail e WhatsApp; empresa e cargo opcionais
 * - registra a conversão "roteiro-ia-industria" no RD Station Marketing (token fora do repositório, ver inc/rd-marketing.php)
 * - se a pessoa marcar, também registra a conversão "news-site" (newsletter Do Dado à Decisão)
 * - guarda cópia em industria/dados/leads.csv (pasta protegida por .htaccess)
 * - avisa por e-mail se o RD falhar, para ninguém se perder
 * - devolve o link do PDF
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require dirname(__DIR__) . '/inc/rd-marketing.php';

const AVISO_PARA = 'lito@litoaguiar.com.br';
const REMETENTE  = 'site@litoaguiar.com.br';
const EVENTO     = 'roteiro-ia-industria';
const PDF        = '/industria/roteiro-ia-industria-lito-aguiar-r7k2.pdf';

function responder(int $s, array $d): void { http_response_code($s); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') responder(405, ['ok' => false, 'erro' => 'Método não permitido.']);
$in = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($in)) $in = $_POST;
if (!empty($in['site'])) responder(200, ['ok' => true, 'pdf' => PDF]); // armadilha para robôs

$c = fn(string $k, int $max = 160) => mb_substr(trim(strip_tags((string) ($in[$k] ?? ''))), 0, $max);
$nome = $c('nome'); $email = mb_strtolower($c('email')); $whats = $c('whatsapp', 40);
$empresa = $c('empresa', 120); $cargo = $c('cargo', 80);
$origem = preg_replace('/[^a-z0-9_-]/', '', mb_strtolower($c('origem', 40))) ?: 'direto';
$news = !empty($in['news']);

$erros = [];
if (mb_strlen($nome) < 2) $erros['nome'] = 'Informe seu nome.';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $erros['email'] = 'Informe um e-mail válido.';
if (strlen(preg_replace('/\D/', '', $whats)) < 10) $erros['whatsapp'] = 'Informe o WhatsApp com DDD.';
if ($erros) responder(422, ['ok' => false, 'erros' => $erros]);

$quando = date('Y-m-d H:i:s');
$pasta = __DIR__ . '/dados';
if (!is_dir($pasta)) @mkdir($pasta, 0750, true);
if (!is_file($pasta . '/.htaccess')) @file_put_contents($pasta . '/.htaccess', "Require all denied\nDeny from all\n");

$tags = ['lead-roteiro-ia-industria', 'interesse-mentoria', 'origem-' . $origem];
$rd = rdm_conversao(EVENTO, [
  'name' => $nome, 'email' => $email, 'mobile_phone' => $whats,
  'company_name' => $empresa, 'job_title' => $cargo,
  'traffic_source' => 'litoaguiar.com.br/industria/' . $origem,
  'tags' => $tags,
]);
$rdNews = ['ok' => true];
if ($news) {
  $rdNews = rdm_conversao('news-site', [
    'name' => $nome, 'email' => $email, 'mobile_phone' => $whats,
    'traffic_source' => 'litoaguiar.com.br/industria',
    'tags' => ['news-assinante', 'news-origem-roteiro-ia-industria'],
    'legal_bases' => [['category' => 'communications', 'type' => 'consent', 'status' => 'granted']],
  ]);
}

$csv = $pasta . '/leads.csv'; $novo = !is_file($csv);
if ($fh = @fopen($csv, 'a')) {
  if ($novo) fputcsv($fh, ['data', 'nome', 'email', 'whatsapp', 'empresa', 'cargo', 'origem', 'news', 'rd']);
  fputcsv($fh, [$quando, $nome, $email, $whats, $empresa, $cargo, $origem, $news ? 'sim' : 'nao', $rd['ok'] ? 'ok' : ($rd['erro'] ?? 'erro')]);
  fclose($fh);
}

if (!$rd['ok'] || !$rdNews['ok']) {
  $assunto = 'Lead do roteiro IA na indústria (NÃO entrou no RD) · ' . $nome;
  $corpo = "Lead pelo site em $quando\n\nNome: $nome\nE-mail: $email\nWhatsApp: $whats\nEmpresa: " . ($empresa ?: '-') . "\nCargo: " . ($cargo ?: '-') .
    "\nOrigem: $origem\nNewsletter: " . ($news ? 'sim' : 'não') . "\n\nMotivo: " . ($rd['erro'] ?? '') . ' ' . ($rdNews['erro'] ?? '') . "\nCadastre manualmente no RD Marketing.\n";
  @mail(AVISO_PARA, '=?UTF-8?B?' . base64_encode($assunto) . '?=', $corpo,
    "From: Site Lito Aguiar <" . REMETENTE . ">\r\nReply-To: $email\r\nContent-Type: text/plain; charset=UTF-8\r\n", '-f' . REMETENTE);
}

responder(200, ['ok' => true, 'pdf' => PDF]);
