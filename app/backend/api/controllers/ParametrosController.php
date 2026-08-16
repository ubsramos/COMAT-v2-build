<?php
/**
 * ParametrosController.php — Leitura e atualização de parâmetros do sistema
 */

class ParametrosController {

    private function fmt($row) {
        if (!$row) return $row;
        $row['id'] = (int)$row['id'];
        $row['ldap'] = isset($row['ldap']) ? (int)$row['ldap'] : 0;
        $row['email_ativo'] = isset($row['email_ativo']) ? (int)$row['email_ativo'] : 0;
        $row['smtp_porta'] = isset($row['smtp_porta']) && $row['smtp_porta'] !== '' ? (int)$row['smtp_porta'] : null;
        $row['wa_ativo'] = isset($row['wa_ativo']) ? (int)$row['wa_ativo'] : 0;
        return $row;
    }

    public function getParametros() {
        Security::getCurrentUser(); // Valida autenticação genérica

        $db = Config::getDb();
        $sql = "SELECT id, entidade, campo_sigla, campo_nome, campo_cnpj, campo_endereco, 
                       sistema_nome, sistema_sigla, ldap, ldap_host, ldap_dominio_search, 
                       ldap_dominio_email, email_ativo, smtp_host, smtp_porta, smtp_user, 
                       smtp_pass, smtp_cripto, email_sistema, wa_ativo, wa_api_url, 
                       wa_token, wa_headers, wa_payload FROM parametros LIMIT 1";
        
        $stmt = $db->query($sql);
        $row = $stmt->fetch();

        if (!$row) {
            throw new Exception("Parâmetros não configurados", 404);
        }

        return $this->fmt($row);
    }

    public function atualizar($id) {
        $currentUser = Security::getCurrentUser();
        Security::checkAccess($currentUser, ["CM11"]);

        $d = getJsonBody();
        $db = Config::getDb();

        $stmt = $db->prepare("SELECT id FROM parametros WHERE id = ?");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            throw new Exception("Parâmetros não encontrados", 404);
        }

        $sql = "UPDATE parametros SET campo_nome = ?, campo_sigla = ?, campo_cnpj = ?, 
                                      campo_endereco = ?, sistema_nome = ?, sistema_sigla = ?, 
                                      ldap = ?, ldap_host = ?, ldap_dominio_search = ?, ldap_dominio_email = ?,
                                      email_ativo = ?, smtp_host = ?, smtp_porta = ?, smtp_user = ?, 
                                      smtp_pass = ?, smtp_cripto = ?, email_sistema = ?, wa_ativo = ?, 
                                      wa_api_url = ?, wa_token = ?, wa_headers = ?, wa_payload = ?
                WHERE id = ?";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([
            $d['campo_nome'] ?? null,
            $d['campo_sigla'] ?? null,
            $d['campo_cnpj'] ?? null,
            $d['campo_endereco'] ?? null,
            $d['sistema_nome'] ?? null,
            $d['sistema_sigla'] ?? null,
            isset($d['ldap']) ? (int)$d['ldap'] : 0,
            $d['ldap_host'] ?? null,
            $d['ldap_dominio_search'] ?? null,
            $d['ldap_dominio_email'] ?? null,
            isset($d['email_ativo']) ? (int)$d['email_ativo'] : 0,
            $d['smtp_host'] ?? null,
            !empty($d['smtp_porta']) ? (int)$d['smtp_porta'] : null,
            $d['smtp_user'] ?? null,
            $d['smtp_pass'] ?? null,
            $d['smtp_cripto'] ?? null,
            $d['email_sistema'] ?? null,
            isset($d['wa_ativo']) ? (int)$d['wa_ativo'] : 0,
            $d['wa_api_url'] ?? null,
            $d['wa_token'] ?? null,
            $d['wa_headers'] ?? null,
            $d['wa_payload'] ?? null,
            $id
        ]);

        $sqlOne = "SELECT id, entidade, campo_sigla, campo_nome, campo_cnpj, campo_endereco, 
                          sistema_nome, sistema_sigla, ldap, ldap_host, ldap_dominio_search, 
                          ldap_dominio_email, email_ativo, smtp_host, smtp_porta, smtp_user, 
                          smtp_pass, smtp_cripto, email_sistema, wa_ativo, wa_api_url, 
                          wa_token, wa_headers, wa_payload FROM parametros WHERE id = ?";
        $stmt = $db->prepare($sqlOne);
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $this->fmt($row);
    }

    public function testarEmail() {
        Security::getCurrentUser(); // Valida autenticação

        $d = getJsonBody();
        $destinatario = trim($d['destinatario'] ?? '');
        if (empty($destinatario)) {
            throw new Exception("E-mail de destino é obrigatório para o teste.", 400);
        }

        // Carrega configurações temporárias enviadas pelo frontend
        $smtpConfig = [
            'email_sistema' => $d['email_sistema'] ?? null,
            'smtp_host'     => $d['smtp_host'] ?? null,
            'smtp_porta'    => $d['smtp_porta'] ?? null,
            'smtp_user'     => $d['smtp_user'] ?? null,
            'smtp_pass'     => $d['smtp_pass'] ?? null,
            'smtp_cripto'   => $d['smtp_cripto'] ?? null,
        ];

        require_once __DIR__ . '/../SmtpEmail.php';

        $subject = "COMAT — Teste de Configuração SMTP";
        $htmlBody = "
        <div style='font-family: Arial, sans-serif; padding: 20px; background-color: #f8fafc;'>
            <div style='max-width: 600px; margin: auto; background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 24px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);'>
                <h2 style='color: #0f766e; margin-top: 0;'>🎉 Configuração SMTP bem-sucedida!</h2>
                <p style='color: #334155; font-size: 15px; line-height: 1.6;'>
                    Este é um e-mail de teste disparado pelo <strong>Sistema COMAT — Controle de Materiais</strong> para validar as credenciais do servidor SMTP.
                </p>
                <div style='margin: 20px 0; padding: 16px; background-color: #f1f5f9; border-radius: 6px; border-left: 4px solid #0f766e;'>
                    <strong>Detalhes da Conexão:</strong><br>
                    <span style='font-size: 13px; color: #475569;'>
                        • Host: {$smtpConfig['smtp_host']}<br>
                        • Porta: {$smtpConfig['smtp_porta']}<br>
                        • Usuário: {$smtpConfig['smtp_user']}<br>
                        • Criptografia: {$smtpConfig['smtp_cripto']}<br>
                        • E-mail do Sistema: {$smtpConfig['email_sistema']}
                    </span>
                </div>
                <p style='font-size: 13px; color: #64748b; margin-bottom: 0;'>
                    Data e Hora do Disparo: " . date('d/m/Y H:i:s') . "
                </p>
            </div>
        </div>";

        $res = SmtpEmail::send($destinatario, $subject, $htmlBody, $smtpConfig);
        if (!$res['success']) {
            throw new Exception($res['error'], 400);
        }

        return ['success' => true, 'message' => 'E-mail de teste enviado com sucesso!'];
    }

    public function testarWhatsapp() {
        Security::getCurrentUser(); // Valida autenticação

        $d = getJsonBody();
        $telefone = trim($d['telefone'] ?? '');
        if (empty($telefone)) {
            throw new Exception("Número de telefone de destino é obrigatório.", 400);
        }

        $apiUrl = trim($d['wa_api_url'] ?? '');
        if (empty($apiUrl)) {
            throw new Exception("URL da API do WhatsApp é obrigatória.", 400);
        }

        $headersRaw = $d['wa_headers'] ?? '';
        $payloadRaw = $d['wa_payload'] ?? '';
        $token = trim($d['wa_token'] ?? '');

        // Prepara mensagem
        $mensagem = "COMAT — Teste de Integração WhatsApp realizado com sucesso em " . date('d/m/Y H:i:s') . "!";

        // Processa payload substituindo {{phone}} e {{message}}
        $telefoneApenasNumeros = preg_replace('/\D/', '', $telefone);
        
        $payload = str_replace(
            ['{{phone}}', '{{telephone}}', '{{number}}', '{{message}}', '{{text}}', '{{token}}'],
            [$telefoneApenasNumeros, $telefoneApenasNumeros, $telefoneApenasNumeros, $mensagem, $mensagem, $token],
            $payloadRaw
        );

        // Processa Headers
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

        // Executa chamada HTTP via cURL
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
            throw new Exception("Erro de conexão com o Gateway WhatsApp: " . $error, 400);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new Exception("O servidor WhatsApp retornou HTTP {$httpCode}. Resposta: " . $response, 400);
        }

        return [
            'success' => true,
            'http_code' => $httpCode,
            'response' => json_decode($response, true) ?: $response,
            'message' => 'Mensagem de teste disparada com sucesso!'
        ];
    }

    public function listarTags() {
        Security::getCurrentUser(); // Valida autenticação genérica

        $db = Config::getDb();
        $stmt = $db->query("SELECT * FROM tag ORDER BY descricao");
        return $stmt->fetchAll();
    }
}
