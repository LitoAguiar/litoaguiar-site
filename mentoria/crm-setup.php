<?php
/*
 * Configuração única do funil da mentoria no RD Station CRM.
 * Protegido por uma chave cujo hash está abaixo (a chave em si não fica no repositório).
 * Ações (POST JSON): {"chave":"...","acao":"configurar","token":"..."}
 *                    {"chave":"...","acao":"testar"}
 *                    {"chave":"...","acao":"status"}
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require __DIR__ . '/crm.php';

const SETUP_HASH = '3ba9ffa56c6d06b77d06a9d935f513e54169b599c83bea0cada9c8e2e0854b4d';
const FUNIL = 'Mentoria Fluxo de Autoridade';
const ETAPAS = [
  ['Aplicação recebida', 'Lead preencheu o formulário da LP. Ler a aplicação e checar o perfil.'],
  ['Contato no WhatsApp', 'Primeira mensagem enviada para marcar a conversa.'],
  ['Conversa agendada', 'Conversa de 30 minutos marcada na agenda.'],
  ['Conversa realizada', 'Conversa feita; decidir se faz sentido enviar proposta.'],
  ['Proposta enviada', 'Proposta da mentoria enviada.'],
  ['Negociação', 'Ajustes de condição e fechamento.'],
];

function sair(int $s, array $d): void { http_response_code($s); echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  header('Content-Type: text/html; charset=utf-8');
  header('X-Robots-Tag: noindex');
  ?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Conectar CRM</title>
<style>body{font:16px/1.5 system-ui,sans-serif;background:#F4F5F8;color:#21253A;margin:0;padding:40px 20px}main{max-width:560px;margin:auto;background:#fff;border-radius:12px;padding:32px;display:flex;flex-direction:column;gap:16px}label{font-weight:700;font-size:14px;display:flex;flex-direction:column;gap:6px}input{font:inherit;padding:12px;border:1px solid #B8BCC9;border-radius:6px}button{font:700 16px system-ui;min-height:48px;border:0;border-radius:6px;background:#E9A23B;color:#161A2B;cursor:pointer}button.sec{background:#21253A;color:#fff}pre{background:#161A2B;color:#CDD1DE;padding:16px;border-radius:8px;white-space:pre-wrap;font-size:13px;min-height:40px}</style></head>
<body><main><h1 style="margin:0">Conectar o formulário ao RD Station CRM</h1>
<p style="margin:0">Cria o funil <b>Mentoria Fluxo de Autoridade</b>, as etapas e a fonte, e liga o formulário da LP a ele.</p>
<label>Chave de configuração (enviada no chat)<input id="k" autocomplete="off"></label>
<label>Token do RD Station CRM<input id="t" autocomplete="off"></label>
<button id="go">Conectar e criar funil</button>
<button id="te" class="sec">Enviar oportunidade de teste</button>
<pre id="o">Aguardando…</pre></main>
<script>
const o=document.getElementById('o');
async function call(d){o.textContent='Processando…';try{const r=await fetch(location.pathname,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(d)});o.textContent=await r.text();}catch(e){o.textContent='Erro de conexão: '+e;}}
go.onclick=()=>call({chave:k.value.trim(),acao:'configurar',token:t.value.trim()});
te.onclick=()=>call({chave:k.value.trim(),acao:'testar'});
</script></body></html><?php
  exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') sair(405, ['ok' => false]);
$in = json_decode((string) file_get_contents('php://input'), true) ?: [];
if (!hash_equals(SETUP_HASH, hash('sha256', (string) ($in['chave'] ?? '')))) { usleep(800000); sair(403, ['ok' => false, 'erro' => 'chave inválida']); }

$acao = $in['acao'] ?? 'status';

if ($acao === 'status') {
  $c = crm_config();
  sair(200, ['ok' => true, 'configurado' => (bool) $c, 'funil' => $c['pipeline_id'] ?? null, 'etapa' => $c['stage_id'] ?? null, 'arquivo_fora_do_site' => crm_config_path()]);
}

if ($acao === 'diag') {
  $c = crm_config(); $t = $c['token']; $d = (string) ($in['deal'] ?? '');
  $o = crm_req($t, 'POST', '/organizations', ['organization' => ['name' => 'Empresa Teste']]);
  $oid = $o[1]['_id'] ?? null;
  $p1 = crm_req($t, 'PUT', '/deals/' . $d, ['deal' => ['organization_id' => $oid]]);
  $p2 = crm_req($t, 'PUT', '/deals/' . $d, ['organization' => ['_id' => $oid]]);
  $g = crm_req($t, 'GET', '/deals/' . $d);
  sair(200, ['p1' => [$p1[0], $p1[1]['organization'] ?? $p1[1]], 'p2' => [$p2[0], $p2[1]['organization'] ?? $p2[1]], 'get_org' => $g[1]['organization'] ?? null]);
}

if ($acao === 'testar') {
  $r = crm_abrir_oportunidade([
    'nome' => 'Teste Integração', 'email' => 'lito@litoaguiar.com.br', 'whatsapp' => '(48) 99999-0000',
    'empresa' => 'Empresa Teste', 'cargo' => 'Diretor', 'faturamento_rotulo' => 'R$ 20 a 50 mi', 'no_perfil' => true,
    'objetivo' => 'Teste automático da integração do formulário com o CRM. Pode excluir.',
  ], true);
  sair($r['ok'] ? 200 : 502, $r);
}

if ($acao !== 'configurar' || empty($in['token'])) sair(400, ['ok' => false, 'erro' => 'ação inválida']);
$t = trim((string) $in['token']);
$log = [];

// 1) token válido + usuário dono
[$st, $users] = crm_req($t, 'GET', '/users');
if ($st !== 200) sair(400, ['ok' => false, 'erro' => 'token recusado pelo RD Station CRM', 'http' => $st]);
$lista = $users['users'] ?? $users;
$userId = null;
foreach ((array) $lista as $u) { if (stripos($u['email'] ?? '', 'lito') !== false) { $userId = $u['id'] ?? $u['_id']; break; } }
if (!$userId && !empty($lista[0])) $userId = $lista[0]['id'] ?? $lista[0]['_id'];
$log[] = 'usuário responsável: ' . $userId;

// 2) funil (reaproveita se já existir)
[$st, $funis] = crm_req($t, 'GET', '/deal_pipelines');
$funil = null;
foreach ((array) $funis as $f) if (($f['name'] ?? '') === FUNIL) $funil = $f;
if (!$funil) {
  [$st, $funil] = crm_req($t, 'POST', '/deal_pipelines', ['deal_pipeline' => ['name' => FUNIL]]);
  if ($st < 200 || $st >= 300) sair(502, ['ok' => false, 'erro' => 'não consegui criar o funil', 'http' => $st, 'resp' => $funil]);
  $log[] = 'funil criado';
} else $log[] = 'funil já existia';
$pid = $funil['id'] ?? $funil['_id'];

// 3) etapas: renomeia as que vieram por padrão e cria as que faltarem
[$st, $resp] = crm_req($t, 'GET', '/deal_stages?deal_pipeline_id=' . urlencode($pid) . '&limit=50');
$atuais = $resp['deal_stages'] ?? $resp ?? [];
usort($atuais, fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));
$ids = [];
foreach (ETAPAS as $i => [$nome, $desc]) {
  $corpo = ['name' => $nome, 'description' => $desc, 'objective' => $desc, 'order' => $i + 1, 'deal_pipeline_id' => $pid];
  if (isset($atuais[$i])) {
    $sid = $atuais[$i]['id'] ?? $atuais[$i]['_id'];
    if (($atuais[$i]['name'] ?? '') !== $nome) {
      [$s1] = crm_req($t, 'PUT', '/deal_stages/' . $sid, $corpo);
      if ($s1 < 200 || $s1 >= 300) [$s1] = crm_req($t, 'PUT', '/deal_stages/' . $sid, ['deal_stage' => $corpo]);
      $log[] = "etapa " . ($i + 1) . " renomeada ($s1)";
    }
  } else {
    [$s1, $nova] = crm_req($t, 'POST', '/deal_stages', $corpo);
    $sid = $nova['id'] ?? $nova['_id'] ?? null;
    $log[] = "etapa " . ($i + 1) . " criada ($s1)";
  }
  $ids[] = $sid;
}

// 4) fonte
$fonteId = null;
[$st, $fontes] = crm_req($t, 'GET', '/deal_sources');
foreach ((array) ($fontes['deal_sources'] ?? $fontes) as $f) if (($f['name'] ?? '') === 'LP Mentoria (site)') $fonteId = $f['id'] ?? $f['_id'];
if (!$fonteId) {
  [$st, $f] = crm_req($t, 'POST', '/deal_sources', ['deal_source' => ['name' => 'LP Mentoria (site)']]);
  $fonteId = $f['id'] ?? $f['_id'] ?? null;
  $log[] = 'fonte criada (' . $st . ')';
}

$cfg = ['token' => $t, 'pipeline_id' => $pid, 'stage_id' => $ids[0], 'stage_ids' => $ids, 'user_id' => $userId, 'source_id' => $fonteId, 'configurado_em' => date('c')];
if (@file_put_contents(crm_config_path(), json_encode($cfg, JSON_PRETTY_PRINT)) === false) sair(500, ['ok' => false, 'erro' => 'não consegui gravar a configuração', 'log' => $log]);
@chmod(crm_config_path(), 0600);
unset($cfg['token']);
sair(200, ['ok' => true, 'config' => $cfg, 'log' => $log]);
