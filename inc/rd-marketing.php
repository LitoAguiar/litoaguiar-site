<?php
/*
 * Envio de conversões ao RD Station Marketing.
 * O token público fica FORA do repositório, num arquivo JSON no servidor:
 *   <pasta acima de public_html>/rd-marketing.json   →   {"token_publico": "..."}
 * (alternativa: inc/dados/rd-marketing.json, protegida por .htaccess)
 * Sem o arquivo, as funções não fazem nada e devolvem ok=false.
 */
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'rd-marketing.php') { http_response_code(404); exit; }

function rdm_token(): string {
  foreach ([dirname(__DIR__, 2) . '/rd-marketing.json', __DIR__ . '/dados/rd-marketing.json'] as $p) {
    if (is_file($p)) {
      $c = json_decode((string) file_get_contents($p), true);
      if (is_array($c) && !empty($c['token_publico'])) return (string) $c['token_publico'];
    }
  }
  return '';
}

/** Registra uma conversão. $payload recebe email, name, mobile_phone, tags etc. */
function rdm_conversao(string $evento, array $payload): array {
  $token = rdm_token();
  if ($token === '') return ['ok' => false, 'erro' => 'token do RD Marketing não configurado'];
  if (!function_exists('curl_init')) return ['ok' => false, 'erro' => 'cURL indisponível'];
  $payload = ['conversion_identifier' => $evento] + array_filter($payload, fn($v) => $v !== '' && $v !== null);
  $ch = curl_init('https://api.rd.services/platform/conversions?api_key=' . urlencode($token));
  curl_setopt_array($ch, [
    CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode(['event_type' => 'CONVERSION', 'event_family' => 'CDP', 'payload' => $payload], JSON_UNESCAPED_UNICODE),
  ]);
  $r = curl_exec($ch);
  $st = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return ($st >= 200 && $st < 300) ? ['ok' => true] : ['ok' => false, 'erro' => "HTTP $st " . substr((string) $r, 0, 200)];
}
