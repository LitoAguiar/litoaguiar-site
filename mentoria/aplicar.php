<?php
/*
 * Recebe o formulário "Agendar minha conversa" da LP da mentoria.
 * - valida os campos
 * - envia um e-mail para AVISO_PARA com os dados do lead
 * - guarda uma cópia em mentoria/dados/leads.csv (pasta protegida por .htaccess)
 * - registra a conversão no RD Station Marketing (se rd-marketing.json existir no servidor)
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require __DIR__ . '/crm.php';

const AVISO_PARA = 'lito@litoaguiar.com.br';
const REMETENTE  = 'site@litoaguiar.com.br';
const RD_EVENTO  = 'aplicacao-mentoria-fluxo-de-autoridade';

function responder(int $status, array $dados): void {
  http_response_code($status);
  echo json_encode($dados, JSON_UNESCAPED_UNICODE);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') responder(405, ['ok' => false, 'erro' => 'Método não permitido.']);

$entrada = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($entrada)) $entrada = $_POST;

// armadilha para robôs: campo invisível que pessoas não preenchem
if (!empty($entrada['site'])) responder(200, ['ok' => true]);

$campo = fn(string $k, int $max = 200) => mb_substr(trim(strip_tags((string) ($entrada[$k] ?? ''))), 0, $max);
$lead = [
  'nome'        => $campo('nome'),
  'email'       => $campo('email'),
  'whatsapp'    => $campo('whatsapp', 40),
  'empresa'     => $campo('empresa'),
  'cargo'       => $campo('cargo'),
  'faturamento' => $campo('faturamento', 40),
  'objetivo'    => $campo('objetivo', 1500),
];

$erros = [];
if (mb_strlen($lead['nome']) < 3) $erros['nome'] = 'Informe seu nome completo.';
if (!filter_var($lead['email'], FILTER_VALIDATE_EMAIL)) $erros['email'] = 'Informe um e-mail válido, como nome@empresa.com.br.';
if (strlen(preg_replace('/\D/', '', $lead['whatsapp'])) < 10) $erros['whatsapp'] = 'Informe o WhatsApp com DDD.';
if ($lead['empresa'] === '') $erros['empresa'] = 'Informe o nome da empresa.';
if ($lead['cargo'] === '') $erros['cargo'] = 'Informe seu cargo.';
$faixas = ['ate-20', '20-50', '50-100', 'acima-100'];
if (!in_array($lead['faturamento'], $faixas, true)) $erros['faturamento'] = 'Escolha a faixa de faturamento anual.';
if (empty($entrada['consentimento'])) $erros['consentimento'] = 'Confirme que podemos entrar em contato.';
if ($erros) responder(422, ['ok' => false, 'erros' => $erros]);

$rotulos = ['ate-20' => 'Até R$ 20 mi', '20-50' => 'R$ 20 a 50 mi', '50-100' => 'R$ 50 a 100 mi', 'acima-100' => 'Acima de R$ 100 mi'];
$noPerfil = $lead['faturamento'] !== 'ate-20';
$quando = date('Y-m-d H:i:s');

// 1) cópia local
$pasta = __DIR__ . '/dados';
if (!is_dir($pasta)) @mkdir($pasta, 0750, true);
if (!is_file($pasta . '/.htaccess')) @file_put_contents($pasta . '/.htaccess', "Require all denied\nDeny from all\n");
$csv = $pasta . '/leads.csv';
$novo = !is_file($csv);
if ($fh = @fopen($csv, 'a')) {
  if ($novo) fputcsv($fh, ['data', 'nome', 'email', 'whatsapp', 'empresa', 'cargo', 'faturamento', 'no_perfil', 'objetivo']);
  fputcsv($fh, [$quando, $lead['nome'], $lead['email'], $lead['whatsapp'], $lead['empresa'], $lead['cargo'], $rotulos[$lead['faturamento']], $noPerfil ? 'sim' : 'nao', $lead['objetivo']]);
  fclose($fh);
}

// 2) oportunidade no RD Station CRM
$crm = crm_abrir_oportunidade($lead + ['faturamento_rotulo' => $rotulos[$lead['faturamento']], 'no_perfil' => $noPerfil]);
if (!$crm['ok']) @file_put_contents($pasta . '/crm-erros.log', $quando . ' ' . ($crm['erro'] ?? '') . "\n", FILE_APPEND);

// 3) aviso por e-mail
$assunto = ($noPerfil ? '[NO PERFIL] ' : '[FORA DO PERFIL] ') . 'Nova aplicação · Fluxo de Autoridade · ' . $lead['nome'];
$corpo = "Nova aplicação para a mentoria Fluxo de Autoridade\n\n"
  . "Nome: {$lead['nome']}\nEmpresa: {$lead['empresa']}\nCargo: {$lead['cargo']}\n"
  . "Faturamento anual: {$rotulos[$lead['faturamento']]}" . ($noPerfil ? '' : ' (abaixo do perfil)') . "\n"
  . "WhatsApp: {$lead['whatsapp']}\nE-mail: {$lead['email']}\n\n"
  . "O que quer que a autoridade gere em 6 meses:\n" . ($lead['objetivo'] ?: '(não respondeu)') . "\n\n"
  . "Recebido em: $quando\n"
  . ($crm['ok'] ? "Oportunidade aberta no RD Station CRM (funil Mentoria Fluxo de Autoridade).\n" : "ATENÇÃO: não entrou no CRM (" . ($crm['erro'] ?? '') . "). Cadastre manualmente.\n");
$cab = "From: Site Lito Aguiar <" . REMETENTE . ">\r\nReply-To: {$lead['email']}\r\nContent-Type: text/plain; charset=UTF-8\r\n";
$enviado = @mail(AVISO_PARA, '=?UTF-8?B?' . base64_encode($assunto) . '?=', $corpo, $cab, '-f' . REMETENTE);

// 4) RD Station Marketing (token em rd-marketing.json, fora do repositório; ver inc/rd-marketing.php)
require_once dirname(__DIR__) . '/inc/rd-marketing.php';
rdm_conversao(RD_EVENTO, [
  'name' => $lead['nome'], 'email' => $lead['email'], 'mobile_phone' => $lead['whatsapp'],
  'company_name' => $lead['empresa'], 'job_title' => $lead['cargo'],
  'cf_faturamento_anual' => $rotulos[$lead['faturamento']],
  'tags' => [$noPerfil ? 'mentoria-no-perfil' : 'mentoria-fora-do-perfil'],
  'legal_bases' => [['category' => 'communications', 'type' => 'consent', 'status' => 'granted']],
]);

responder(200, ['ok' => true, 'noPerfil' => $noPerfil, 'avisado' => (bool) $enviado, 'crm' => $crm['ok']]);
