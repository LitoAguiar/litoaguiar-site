<?php
/*
 * Integração com o RD Station CRM (API v1).
 * O token e os IDs do funil ficam num arquivo FORA da pasta pública (nunca no repositório):
 *   <pasta acima de public_html>/rd-crm.json
 * Este arquivo é só biblioteca; não responde a acesso direto.
 */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'crm.php') { http_response_code(404); exit; }

const CRM_API = 'https://crm.rdstation.com/api/v1';

function crm_config_path(): string {
  $fora = dirname(__DIR__, 2) . '/rd-crm.json';
  if (is_file($fora) || is_writable(dirname($fora))) return $fora;
  // alternativa: pasta de dados protegida por .htaccess
  $d = __DIR__ . '/dados';
  if (!is_dir($d)) @mkdir($d, 0750, true);
  if (!is_file($d . '/.htaccess')) @file_put_contents($d . '/.htaccess', "Require all denied\nDeny from all\n");
  return $d . '/rd-crm.json';
}

function crm_config(): ?array {
  $p = crm_config_path();
  if (!is_file($p)) return null;
  $c = json_decode((string) file_get_contents($p), true);
  return (is_array($c) && !empty($c['token'])) ? $c : null;
}

/** Chamada à API. Devolve [status, corpo decodificado]. */
function crm_req(string $token, string $metodo, string $caminho, ?array $corpo = null): array {
  $url = CRM_API . $caminho . (strpos($caminho, '?') === false ? '?' : '&') . 'token=' . urlencode($token);
  $ch = curl_init($url);
  $op = [
    CURLOPT_CUSTOMREQUEST  => $metodo,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 12,
    CURLOPT_CONNECTTIMEOUT => 6,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
  ];
  if ($corpo !== null) $op[CURLOPT_POSTFIELDS] = json_encode($corpo, JSON_UNESCAPED_UNICODE);
  curl_setopt_array($ch, $op);
  $r = curl_exec($ch);
  $st = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return [$st, $r === false ? null : json_decode((string) $r, true)];
}

/**
 * Abre a oportunidade no funil da mentoria.
 * $lead: nome, email, whatsapp, empresa, cargo, faturamento_rotulo, no_perfil (bool), objetivo
 * Devolve ['ok'=>bool, 'deal_id'=>?, 'erro'=>?]
 */
function crm_abrir_oportunidade(array $lead, bool $teste = false): array {
  $c = crm_config();
  if (!$c || empty($c['stage_id'])) return ['ok' => false, 'erro' => 'CRM não configurado'];
  $t = $c['token'];

  // empresa
  $orgId = null;
  [$st, $org] = crm_req($t, 'POST', '/organizations', ['organization' => ['name' => $lead['empresa']]]);
  if ($st >= 200 && $st < 300) $orgId = $org['_id'] ?? $org['id'] ?? null;

  $nome = ($teste ? '[TESTE] ' : '') . $lead['empresa'] . ' · ' . $lead['nome'];
  $deal = [
    'deal' => [
      'name'          => mb_substr($nome, 0, 120),
      'deal_stage_id' => $c['stage_id'],
      'rating'        => $lead['no_perfil'] ? 3 : 1,
    ],
    'contacts' => [[
      'name'        => $lead['nome'],
      'title'       => $lead['cargo'],
      'emails'      => [['email' => $lead['email']]],
      'phones'      => [['phone' => preg_replace('/\D/', '', $lead['whatsapp']), 'type' => 'cellphone']],
      'legal_bases' => [['category' => 'communications', 'status' => 'granted', 'type' => 'consent']],
    ]],
    'deal_products' => [[
      'name' => 'Mentoria Fluxo de Autoridade', 'description' => 'Turma fundadora',
      'amount' => 1, 'base_price' => 35000, 'price' => 35000, 'discount_type' => 'value', 'recurrence' => 'spare', 'total' => 35000,
    ]],
  ];
  if (!empty($c['user_id'])) $deal['deal']['user_id'] = $c['user_id'];
  if (!empty($c['source_id'])) $deal['deal_source'] = ['_id' => $c['source_id']];
  if ($orgId) $deal['organization'] = ['_id' => $orgId];

  [$st, $res] = crm_req($t, 'POST', '/deals', $deal);
  if ($st < 200 || $st >= 300) {
    // tenta de novo sem os opcionais que podem ser recusados pela conta
    unset($deal['deal_products'], $deal['contacts'][0]['legal_bases']);
    [$st, $res] = crm_req($t, 'POST', '/deals', $deal);
  }
  if ($st < 200 || $st >= 300) return ['ok' => false, 'erro' => 'HTTP ' . $st . ' ' . substr(json_encode($res, JSON_UNESCAPED_UNICODE), 0, 300)];
  $dealId = $res['_id'] ?? $res['id'] ?? null;

  // anotação com os detalhes da aplicação
  if ($dealId) {
    $txt = "Aplicação pela LP da mentoria (litoaguiar.com.br/mentoria)\n"
      . "Faturamento anual: {$lead['faturamento_rotulo']}" . ($lead['no_perfil'] ? '' : ' (ABAIXO DO PERFIL)') . "\n"
      . "Cargo: {$lead['cargo']}\nWhatsApp: {$lead['whatsapp']}\nE-mail: {$lead['email']}\n"
      . "Objetivo em 6 meses: " . ($lead['objetivo'] ?: '(não respondeu)');
    crm_req($t, 'POST', '/activities', ['activity' => ['deal_id' => $dealId, 'text' => $txt, 'user_id' => $c['user_id'] ?? null]]);
  }
  return ['ok' => true, 'deal_id' => $dealId];
}
