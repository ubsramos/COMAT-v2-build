<?php
/**
 * SmtpEmail.php — Despachador de e-mail customizado via Socket SMTP nativo
 * Implementado totalmente do zero via conexões de socket (fsockopen) e SMTP RFC 5321.
 * Inclui fallback automático e seguro para a função nativa mail() do PHP.
 */

require_once __DIR__ . '/config.php';

class SmtpEmail {

    /**
     * Envia um e-mail HTML de forma síncrona/rápida.
     * Retorna um array contendo ['success' => bool, 'error' => string]
     */
    public static function send($to, $subject, $htmlBody, $customCfg = null) {
        $cfg = $customCfg ?: self::loadSmtpConfig();
        
        if (!$cfg) {
            // Se não houver configuração de banco de dados, tenta fallback imediato para mail()
            return self::sendPhpMail($to, $subject, $htmlBody, "Sistema COMAT <contato@i-evento.com>");
        }

        $fromName = "Sistema COMAT";
        $fromEmail = !empty($cfg['email_sistema']) ? $cfg['email_sistema'] : (!empty($cfg['smtp_user']) ? $cfg['smtp_user'] : 'contato@i-evento.com');
        $host = !empty($cfg['smtp_host']) ? $cfg['smtp_host'] : '';
        $port = !empty($cfg['smtp_porta']) ? (int)$cfg['smtp_porta'] : 587;
        $user = !empty($cfg['smtp_user']) ? $cfg['smtp_user'] : $fromEmail;
        $pass = !empty($cfg['smtp_pass']) ? $cfg['smtp_pass'] : '';
        $cripto = !empty($cfg['smtp_cripto']) ? $cfg['smtp_cripto'] : 'none';

        try {
            return self::sendViaSmtpSocket($host, $port, $user, $pass, $cripto, $fromEmail, $fromName, $to, $subject, $htmlBody);
        } catch (Exception $e) {
            // Em caso de teste dinâmico, não fazemos o fallback silencioso para mail() do PHP!
            if ($customCfg) {
                return ['success' => false, 'error' => $e->getMessage()];
            }
            // Em caso de erro na comunicação socket normal, faz o fallback silencioso para a função mail()
            return self::sendPhpMail($to, $subject, $htmlBody, "$fromName <$fromEmail>", $e->getMessage());
        }
    }

    /**
     * Dispara o envio de e-mail de forma assíncrona/não bloqueante
     * Em PHP, simulamos o comportamento não bloqueante de forma limpa
     */
    public static function sendAsync($to, $subject, $htmlBody, callable $callback = null) {
        // Como o HostGator roda de forma síncrona na web, executamos o envio e chamamos o callback
        $res = self::send($to, $subject, $htmlBody);
        if ($callback) {
            $callback($res['success'], $res['error']);
        }
    }

    /**
     * Carrega as configurações de SMTP do banco de dados (tabela parametros)
     */
    private static function loadSmtpConfig() {
        try {
            $db = Config::getDb();
            $stmt = $db->query("SELECT email_sistema, smtp_host, smtp_porta, smtp_user, smtp_pass, smtp_cripto FROM parametros LIMIT 1");
            $row = $stmt->fetch();
            if ($row && !empty($row['smtp_host']) && !empty($row['email_sistema'])) {
                return $row;
            }
        } catch (Exception $e) {
            // Silencia erro para usar o fallback
        }
        return null;
    }

    /**
     * Envia e-mail via Socket SMTP Puro (RFC 5321) do zero
     */
    private static function sendViaSmtpSocket($host, $port, $user, $pass, $cripto, $fromEmail, $fromName, $to, $subject, $htmlBody) {
        // Limpa o hostname
        $host = preg_replace('/^(ssl:\/\/|tls:\/\/)/i', '', $host);
        
        $socketHost = $host;
        if ($cripto === 'ssl') {
            $socketHost = 'ssl://' . $host;
        }

        // Abre o socket
        $socket = @fsockopen($socketHost, $port, $errno, $errstr, 10);
        if (!$socket) {
            throw new Exception("Não foi possível conectar ao servidor SMTP ($socketHost:$port): $errstr ($errno)");
        }

        self::readResponse($socket, 220);

        // Envia EHLO
        self::sendCommand($socket, "EHLO " . ($_SERVER['SERVER_NAME'] ?? 'localhost'), 250);

        // Se for TLS ou se port for 587/25 e cripto não for SSL nem explicitly NONE
        if ($cripto === 'tls' || (($port === 587 || $port === 25) && $cripto !== 'ssl' && $cripto !== 'none')) {
            self::sendCommand($socket, "STARTTLS", 220);
            if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($socket);
                throw new Exception("Falha ao iniciar criptografia TLS segura.");
            }
            // Reenvia EHLO sob o canal criptografado
            self::sendCommand($socket, "EHLO " . ($_SERVER['SERVER_NAME'] ?? 'localhost'), 250);
        }

        // Tenta autenticação apenas se o usuário for configurado
        if (!empty($user)) {
            try {
                self::sendCommand($socket, "AUTH LOGIN", 334);
                self::sendCommand($socket, base64_encode($user), 334);
                self::sendCommand($socket, base64_encode($pass), 235);
            } catch (Exception $e) {
                // Se a autenticação falhar mas o SMTP aceitar envio sem auth (ex: local relay), tentamos seguir.
                if (strpos($e->getMessage(), '535') !== false || strpos($e->getMessage(), 'Authentication failed') !== false) {
                    fclose($socket);
                    throw new Exception("Falha de Autenticação SMTP (Usuário/Senha incorretos): " . $e->getMessage());
                }
            }
        }

        // Envia MAIL FROM
        self::sendCommand($socket, "MAIL FROM:<$fromEmail>", 250);

        // Envia RCPT TO
        self::sendCommand($socket, "RCPT TO:<$to>", 250);

        // Envia DATA
        self::sendCommand($socket, "DATA", 354);

        // Gera um Message-ID único e válido para evitar que caia no Spam
        $cleanHost = preg_replace('/^(ssl:\/\/|tls:\/\/|mail\.)/i', '', $host);
        $domain = !empty($cleanHost) ? $cleanHost : 'localhost';
        $messageId = "<" . time() . '.' . bin2hex(random_bytes(4)) . "@" . $domain . ">";

        // Monta os Headers da Mensagem
        $headers = [
            "MIME-Version: 1.0",
            "Content-type: text/html; charset=utf-8",
            "Content-Transfer-Encoding: 8bit",
            "To: <$to>",
            "From: =?utf-8?B?" . base64_encode($fromName) . "?= <$fromEmail>",
            "Reply-To: <$fromEmail>",
            "Subject: =?utf-8?B?" . base64_encode($subject) . "?=",
            "Date: " . date('r'),
            "Message-ID: $messageId",
            "X-Mailer: COMAT-PHP-Mailer/2.1",
        ];

        $data = implode("\r\n", $headers) . "\r\n\r\n" . $htmlBody . "\r\n.\r\n";
        // Normaliza quebras de linha para CRLF exigidas pela RFC do protocolo SMTP
        $data = str_replace("\r\n", "\n", $data);
        $data = str_replace("\n", "\r\n", $data);
        
        @fwrite($socket, $data);
        self::readResponse($socket, 250);

        // Finaliza sessão
        self::sendCommand($socket, "QUIT", 221);
        fclose($socket);

        return ['success' => true, 'error' => ''];
    }

    /**
     * Envia e-mail utilizando a função mail() nativa do PHP como fallback robusto
     */
    private static function sendPhpMail($to, $subject, $htmlBody, $fromHeader, $previousError = '') {
        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=utf-8',
            'From: ' . $fromHeader,
            'Reply-To: ' . $fromHeader,
            'X-Mailer: PHP/' . phpversion()
        ];

        // Tenta enviar via sendmail nativo
        $ok = @mail($to, $subject, $htmlBody, implode("\r\n", $headers));
        
        if ($ok) {
            return ['success' => true, 'error' => ''];
        } else {
            $err = "Falha no envio de e-mail.";
            if ($previousError) {
                $err .= " SMTP Socket Error: $previousError";
            }
            return ['success' => false, 'error' => $err];
        }
    }

    private static function sendCommand($socket, $command, $expectedCode) {
        @fwrite($socket, $command . "\r\n");
        return self::readResponse($socket, $expectedCode);
    }

    private static function readResponse($socket, $expectedCode) {
        $response = "";
        while ($line = @fgets($socket, 515)) {
            $response .= $line;
            if (substr($line, 3, 1) === " ") {
                break;
            }
        }
        $code = (int)substr($response, 0, 3);
        if ($code !== $expectedCode) {
            throw new Exception("Comando SMTP recusado pelo servidor. Retorno: " . trim($response));
        }
        return $response;
    }

    /**
     * Constrói o HTML do e-mail de notificação de correspondências (mantido idêntico ao layout premium original)
     */
    public static function buildEmailCorrespondencia($dados) {
        $tipo = $dados['tipo'] ?? 'Objeto';
        $remetente = !empty($dados['remetente']) ? $dados['remetente'] : 'Não informado';
        $rastreio = !empty($dados['rastreio']) ? $dados['rastreio'] : '';
        $dataChegada = $dados['data_chegada'] ?? '';
        $recebedor = $dados['recebedor_nome'] ?? '';
        $local = ($dados['recebedor_tipo'] ?? '') === 'portaria' ? 'Portaria' : 'Almoxarifado';

        $rastreioLink = '—';
        if ($rastreio) {
            $url = "https://rastreamento.correios.com.br/app/index.php?objeto=$rastreio";
            $rastreioLink = "<a href=\"$url\" style=\"color:#3b82f6\">$rastreio</a>";
        }

        return '
        <!DOCTYPE html>
        <html lang="pt-BR">
        <head><meta charset="utf-8"><title>Nova Correspondência</title></head>
        <body style="margin:0;padding:0;font-family:Arial,sans-serif;background:#f1f5f9">
          <table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:32px 0">
            <tr><td align="center">
              <table width="600" cellpadding="0" cellspacing="0"
                     style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 16px rgba(0,0,0,0.08)">
                <tr>
                  <td style="background:linear-gradient(135deg,#1e40af,#3b82f6);padding:28px 32px">
                    <p style="margin:0;font-size:11px;color:#93c5fd;text-transform:uppercase;letter-spacing:0.1em">
                      Sistema COMAT — Controle de Material
                    </p>
                    <h1 style="margin:8px 0 0;font-size:22px;color:#ffffff;font-weight:700">
                      📦 Nova ' . $tipo . ' aguardando retirada
                    </h1>
                  </td>
                </tr>
                <tr>
                  <td style="padding:28px 32px">
                    <p style="margin:0 0 20px;font-size:15px;color:#374151">
                      Uma correspondência chegou e está aguardando sua retirada. Confira os detalhes abaixo:
                    </p>
                    <table width="100%" cellpadding="0" cellspacing="0"
                           style="border:1px solid #e5e7eb;border-radius:8px;overflow:hidden">
                      <tr style="background:#f9fafb">
                        <td style="padding:12px 16px;font-size:13px;font-weight:600;color:#6b7280;width:140px">Tipo</td>
                        <td style="padding:12px 16px;font-size:14px;color:#111827;font-weight:500">' . $tipo . '</td>
                      </tr>
                      <tr>
                        <td style="padding:12px 16px;font-size:13px;font-weight:600;color:#6b7280;border-top:1px solid #e5e7eb">Remetente</td>
                        <td style="padding:12px 16px;font-size:14px;color:#111827;border-top:1px solid #e5e7eb">' . $remetente . '</td>
                      </tr>
                      <tr style="background:#f9fafb">
                        <td style="padding:12px 16px;font-size:13px;font-weight:600;color:#6b7280;border-top:1px solid #e5e7eb">Rastreio</td>
                        <td style="padding:12px 16px;font-size:14px;color:#111827;border-top:1px solid #e5e7eb">' . $rastreioLink . '</td>
                      </tr>
                      <tr>
                        <td style="padding:12px 16px;font-size:13px;font-weight:600;color:#6b7280;border-top:1px solid #e5e7eb">Chegou em</td>
                        <td style="padding:12px 16px;font-size:14px;color:#111827;border-top:1px solid #e5e7eb">' . $dataChegada . '</td>
                      </tr>
                      <tr style="background:#f9fafb">
                        <td style="padding:12px 16px;font-size:13px;font-weight:600;color:#6b7280;border-top:1px solid #e5e7eb">Recebido em</td>
                        <td style="padding:12px 16px;font-size:14px;color:#111827;border-top:1px solid #e5e7eb">' . $local . ' — ' . $recebedor . '</td>
                      </tr>
                    </table>
                    <div style="margin-top:24px;padding:16px;background:#eff6ff;border-radius:8px;border-left:4px solid #3b82f6">
                      <p style="margin:0;font-size:14px;color:#1e40af;font-weight:500">
                        📍 Dirija-se ao <strong>' . $local . '</strong> para retirar seu objeto.
                      </p>
                    </div>
                  </td>
                </tr>
                <tr>
                  <td style="padding:16px 32px;background:#f9fafb;border-top:1px solid #e5e7eb">
                    <p style="margin:0;font-size:12px;color:#9ca3af;text-align:center">
                      E-mail gerado automaticamente pelo Sistema COMAT — não responda a esta mensagem.
                    </p>
                  </td>
                </tr>
              </table>
            </td></tr>
          </table>
        </body>
        </html>';
    }
}
