<?php
/*
 * "Leve o Lito para o seu evento": recebe o pedido, registra a conversão
 * "convite-evento" no RD Station Marketing, guarda cópia em eventos/dados/ e avisa por e-mail.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require dirname(__DIR__) . '/inc/rd-marketing.php';

const AVISO_PARA = 'lito@litoaguiar.com.br';
const REMETENTE  = 'site@litoaguiar.com.br';

function responder(int $s, array $d): void { http_response_code($s); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') responder(405, ['ok' => false]);
$in = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($in)) $in = $_POST;
if (!empty($in['site'])) responder(200, ['ok' => true]);

$c = fn(string $k, int $max = 160) => mb_substr(trim(strip_tags((string) ($in[$k] ?? ''))), 0, $max);
$d = [
  'nome' => $c('nome'), 'email' => mb_strtolower($c('email')), 'whatsapp' => $c('whatsapp', 40),
  'evento' => $c('evento'), 'data' => $c('data', 40), 'cidade' => $c('cidade', 80),
  'publico' => $c('publico', 40), 'formato' => $c('formato', 60), 'detalhes' => $c('detalhes', 1500),
];
$formatos = ['palestra' => 'Palestra', 'mediacao' => 'Mediação de painel', 'podcast' => 'No Fim do Dia ao vivo', 'cobertura' => 'Cobertura e entrevistas', 'outro' => 'Outro'];

$erros = [];
if (mb_strlen($d['nome']) < 3) $erros['nome'] = 'Informe seu nome.';
if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL)) $erros['email'] = 'Informe um e-mail válido.';
if (strlen(preg_replace('/\D/', '', $d['whatsapp'])) < 10) $erros['whatsapp'] = 'Informe o WhatsApp com DDD.';
if ($d['evento'] === '') $erros['evento'] = 'Informe o nome do evento.';
if (!isset($formatos[$d['formato']])) $erros['formato'] = 'Escolha o formato.';
if ($erros) responder(422, ['ok' => false, 'erros' => $erros]);

$quando = date('Y-m-d H:i:s');
$pasta = __DIR__ . '/dados';
if (!is_dir($pasta)) @mkdir($pasta, 0750, true);
if (!is_file($pasta . '/.htaccess')) @file_put_contents($pasta . '/.htaccess', "Require all denied\nDeny from all\n");

$rd = rdm_conversao('convite-evento', [
  'name' => $d['nome'], 'email' => $d['email'], 'mobile_phone' => $d['whatsapp'],
  'company_name' => $d['evento'], 'city' => $d['cidade'],
  'tags' => ['convite-evento', 'evento-' . $d['formato']],
  'legal_bases' => [['category' => 'communications', 'type' => 'consent', 'status' => 'granted']],
]);

$csv = $pasta . '/convites.csv'; $novo = !is_file($csv);
if ($fh = @fopen($csv, 'a')) {
  if ($novo) fputcsv($fh, array_merge(['recebido'], array_keys($d), ['rd']));
  fputcsv($fh, array_merge([$quando], array_values($d), [$rd['ok'] ? 'ok' : ($rd['erro'] ?? 'erro')]));
  fclose($fh);
}

$assunto = 'Convite para evento: ' . $d['evento'] . ' (' . $formatos[$d['formato']] . ')';
$corpo = "Novo convite pelo site em $quando\n\n"
  . "Evento: {$d['evento']}\nData: " . ($d['data'] ?: '-') . "\nCidade: " . ($d['cidade'] ?: '-') . "\n"
  . "Público esperado: " . ($d['publico'] ?: '-') . "\nFormato: {$formatos[$d['formato']]}\n\n"
  . "Contato: {$d['nome']}\nE-mail: {$d['email']}\nWhatsApp: {$d['whatsapp']}\n\n"
  . "Detalhes:\n" . ($d['detalhes'] ?: '(sem detalhes)') . "\n\n"
  . ($rd['ok'] ? "Registrado no RD Marketing (conversão convite-evento).\n" : "ATENÇÃO: não entrou no RD (" . ($rd['erro'] ?? '') . ").\n");
@mail(AVISO_PARA, '=?UTF-8?B?' . base64_encode($assunto) . '?=', $corpo,
  "From: Site Lito Aguiar <" . REMETENTE . ">\r\nReply-To: {$d['email']}\r\nContent-Type: text/plain; charset=UTF-8\r\n", '-f' . REMETENTE);

responder(200, ['ok' => true]);
