<?php
/**
 * WhatsApp.php — Despachador de mensagens de WhatsApp via REST API customizada
 */

require_once __DIR__ . '/config.php';

class WhatsApp {

    public static function send($toPhone, $message) {
        try {
            $db = Config::getDb();
            $stmt = $db->query("SELECT wa_ativo, wa_api_url, wa_token, wa_headers, wa_payload FROM parametros LIMIT 1");
            $row = $stmt->fetch();
            
            if (!$row || empty($row['wa_ativo']) || empty($row['wa_api_url'])) {
                return ['success' => false, 'error' => 'WhatsApp não configurado ou inativo.'];
            }

            $apiUrl = $row['wa_api_url'];
            $token = $row['wa_token'];
            $headersRaw = $row['wa_headers'];
            $payloadRaw = $row['wa_payload'];

            // Limpa o número de telefone (apenas números)
            $phoneDigits = preg_replace('/\D/', '', $toPhone);
            if (empty($phoneDigits)) {
                return ['success' => false, 'error' => 'Número de telefone inválido.'];
            }

            // Substitui placeholders no payload
            $payload = str_replace(
                ['{{phone}}', '{{telephone}}', '{{number}}', '{{message}}', '{{text}}', '{{token}}'],
                [$phoneDigits, $phoneDigits, $phoneDigits, $message, $message, $token],
                $payloadRaw
            );

            // Cabeçalhos
            $headers = [];
            $hasContentType = false;
            if (!empty($headersRaw)) {
                $lines = explode("\n", str_replace("\r", "", $headersRaw));
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (empty($line) || strpos($line, ':') === false) continue;
                    $line = str_replace('{{token}}', $token, $line);
                    $headers[] = $line;
                    if (stripos($line, 'Content-Type') !== false) {
                        $hasContentType = true;
                    }
                }
            }

            if (!$hasContentType) {
                $headers[] = 'Content-Type: application/json';
            }

            // cURL
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $apiUrl);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt_array($ch, [
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_TIMEOUT => 15
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                return ['success' => false, 'error' => $error];
            }

            if ($httpCode < 200 || $httpCode >= 300) {
                return ['success' => false, 'error' => "HTTP {$httpCode}: {$response}"];
            }

            return ['success' => true, 'response' => $response];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public static function sendAsync($toPhone, $message, callable $callback = null) {
        $res = self::send($toPhone, $message);
        if ($callback) {
            $callback($res['success'], $res['error'] ?? '');
        }
    }
}
