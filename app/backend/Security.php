<?php
/**
 * Security.php — Segurança JWT HMAC-SHA256 do zero, hashing Bcrypt sênior e controle de privilégios
 */

require_once __DIR__ . '/config.php';

class Security {

    // ─── Hashing de Senhas (Bcrypt Altamente Seguro) ─────────────────────────

    public static function hashSenha($plain) {
        // Usa Bcrypt com custo adaptativo padrão (10) para máxima segurança sênior
        return password_hash($plain, PASSWORD_BCRYPT);
    }

    public static function verificarSenha($plain, $hashed) {
        return password_verify($plain, $hashed);
    }

    // ─── Utilitários Base64URL (Segurança e Padrão RFC 7515) ─────────────────

    private static function base64UrlEncode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode($data) {
        return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT));
    }

    // ─── Geração e Validação de JWT (Escrito do Zero) ────────────────────────

    public static function gerarToken($userId, $userType) {
        $secret = Config::get('SECRET_KEY', 'comat-chave-secreta-padrao');
        $expireMinutes = (int)Config::get('ACCESS_TOKEN_EXPIRE_MINUTES', 480);

        $header = json_encode([
            'alg' => 'HS256',
            'typ' => 'JWT'
        ], JSON_UNESCAPED_SLASHES);

        $now = time();
        $payload = json_encode([
            'sub'  => (string)$userId,
            'type' => $userType,
            'iat'  => $now,
            'exp'  => $now + ($expireMinutes * 60)
        ], JSON_UNESCAPED_SLASHES);

        $headerEnc  = self::base64UrlEncode($header);
        $payloadEnc = self::base64UrlEncode($payload);

        $signatureSource = "$headerEnc.$payloadEnc";
        $signature = hash_hmac('sha256', $signatureSource, $secret, true);
        $signatureEnc = self::base64UrlEncode($signature);

        return "$headerEnc.$payloadEnc.$signatureEnc";
    }

    public static function decodeToken($token) {
        if (empty($token)) return null;

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        list($headerEnc, $payloadEnc, $signatureEnc) = $parts;
        $secret = Config::get('SECRET_KEY', 'comat-chave-secreta-padrao');

        // Valida Assinatura HMAC-SHA256
        $signatureSource = "$headerEnc.$payloadEnc";
        $expectedSignature = hash_hmac('sha256', $signatureSource, $secret, true);
        $expectedSignatureEnc = self::base64UrlEncode($expectedSignature);

        // hash_equals protege contra ataques de temporização (Timing Attacks)
        if (!hash_equals($signatureEnc, $expectedSignatureEnc)) {
            return null;
        }

        $payload = json_decode(self::base64UrlDecode($payloadEnc), true);
        if (!$payload) {
            return null;
        }

        // Valida Expiração (exp)
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            return null;
        }

        return $payload;
    }

    // ─── Controle e Injeção de Usuário nas Requisições ───────────────────────

    public static function getCurrentUser() {
        $headers = getallheaders();
        $authHeader = '';

        // Busca o header Authorization de forma insensível a maiúsculas/minúsculas
        foreach ($headers as $key => $value) {
            if (strtolower($key) === 'authorization') {
                $authHeader = $value;
                break;
            }
        }

        if (empty($authHeader) || !preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            http_response_code(401);
            echo json_encode(['detail' => 'Token não fornecido ou malformado.']);
            exit;
        }

        $token = $matches[1];
        $payload = self::decodeToken($token);

        if (!$payload) {
            http_response_code(401);
            echo json_encode(['detail' => 'Token inválido ou expirado.']);
            exit;
        }

        $userId = $payload['sub'] ?? null;
        $userType = $payload['type'] ?? null;

        if (!$userId || !in_array($userType, ['usuario', 'funcionario'])) {
            http_response_code(401);
            echo json_encode(['detail' => 'Token malformado.']);
            exit;
        }

        $db = Config::getDb();

        if ($userType === 'usuario') {
            $stmt = $db->prepare("SELECT id, login, nivel, ativo, acesso FROM usuario WHERE id = ?");
            $stmt->execute([$userId]);
            $row = $stmt->fetch();

            if (!$row || !$row['ativo']) {
                http_response_code(401);
                echo json_encode(['detail' => 'Usuário inativo ou não encontrado.']);
                exit;
            }

            $info = self::getUserDeptosAndName($db, (int)$row['id'], 'usuario', $row['login'], (int)$row['nivel'], $row['acesso'] ?? '');

            return [
                'id'            => (int)$row['id'],
                'login'         => $row['login'],
                'nome'          => $info['nome'],
                'nivel'         => (int)$row['nivel'],
                'type'          => 'usuario',
                'acesso'        => $row['acesso'] ?? '',
                'todos_deptos'  => $info['todos_deptos'],
                'departamentos' => $info['departamentos'],
                'deptos_nomes'  => $info['deptos_nomes'],
            ];
        } else {
            $stmt = $db->prepare(
                "SELECT id, nome, login_ldap, status, acesso, admin_estoque, depto_id " .
                "FROM funcionario WHERE id = ?"
            );
            $stmt->execute([$userId]);
            $row = $stmt->fetch();

            if (!$row || !$row['status']) {
                http_response_code(401);
                echo json_encode(['detail' => 'Funcionário inativo ou não encontrado.']);
                exit;
            }

            $info = self::getUserDeptosAndName($db, (int)$row['id'], 'funcionario', $row['nome'], 9, $row['acesso'] ?? '', $row['depto_id'], $row['nome']);

            return [
                'id'            => (int)$row['id'],
                'login'         => $row['nome'],
                'nome'          => $row['nome'],
                'nivel'         => 9, // Funcionário padrão
                'type'          => 'funcionario',
                'acesso'        => $row['acesso'] ?? '',
                'depto_id'      => $row['depto_id'] ? (int)$row['depto_id'] : null,
                'admin_estoque' => (int)($row['admin_estoque'] ?? 0),
                'login_ldap'    => $row['login_ldap'],
                'todos_deptos'  => $info['todos_deptos'],
                'departamentos' => $info['departamentos'],
                'deptos_nomes'  => $info['deptos_nomes'],
            ];
        }
    }

    /**
     * Auxiliar para recuperar lista de departamentos autorizados e nome completo do usuário
     */
    public static function getUserDeptosAndName($db, $userId, $userType, $login, $nivel, $acesso, $directDeptoId = null, $directNome = null) {
        $isAdmin = ($userType === 'usuario' && ((int)$nivel <= 1 || $login === 'admin' || strpos((string)$acesso, 'ALL') !== false));
        
        // Administrador tem acesso a todos os departamentos da instituição
        if ($isAdmin) {
            try {
                $stmt = $db->query("SELECT id, descricao FROM departamento ORDER BY descricao");
                $allDeptos = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Exception $e) {
                $allDeptos = [];
            }
            return [
                'nome' => $directNome ?: ($login === 'admin' ? 'Administrador' : $login),
                'todos_deptos' => true,
                'departamentos' => $allDeptos,
                'deptos_nomes' => array_column($allDeptos, 'descricao')
            ];
        }

        $funcId = null;
        $nome = $directNome ?: $login;
        $deptoId = $directDeptoId;

        if ($userType === 'usuario') {
            try {
                $stmt = $db->prepare("SELECT id, nome, depto_id FROM funcionario WHERE usuario_id = ? OR login_ldap = ? LIMIT 1");
                $stmt->execute([$userId, $login]);
                $func = $stmt->fetch();
                if ($func) {
                    $funcId = (int)$func['id'];
                    $nome = $func['nome'];
                    $deptoId = $func['depto_id'];
                }
            } catch (Exception $e) {}
        } else {
            $funcId = $userId;
        }

        $deptos = [];
        if ($funcId) {
            // 1. Departamentos na tabela associativa funcionario_depto
            try {
                $stmt = $db->prepare("SELECT d.id, d.descricao 
                                      FROM departamento d 
                                      INNER JOIN funcionario_depto fd ON fd.depto_id = d.id 
                                      WHERE fd.funcionario_id = ? 
                                      ORDER BY d.descricao");
                $stmt->execute([$funcId]);
                $deptos = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Exception $e) {}

            // 2. Garante inclusão do depto_id principal caso ainda não conste na lista
            if ($deptoId) {
                $hasPrimary = false;
                foreach ($deptos as $d) {
                    if ((int)$d['id'] === (int)$deptoId) {
                        $hasPrimary = true;
                        break;
                    }
                }
                if (!$hasPrimary) {
                    try {
                        $stmt = $db->prepare("SELECT id, descricao FROM departamento WHERE id = ?");
                        $stmt->execute([$deptoId]);
                        $primary = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($primary) {
                            array_unshift($deptos, $primary);
                        }
                    } catch (Exception $e) {}
                }
            }
        }

        return [
            'nome' => $nome,
            'todos_deptos' => false,
            'departamentos' => $deptos,
            'deptos_nomes' => array_column($deptos, 'descricao')
        ];
    }

    /**
     * Valida se o usuário autenticado possui algum dos privilégios requisitados
     */
    public static function checkAccess($user, array $codes) {
        // Admin local (nível 0 ou 1, login 'admin' ou acesso 'ALL') tem privilégio absoluto para tudo
        if ($user['type'] === 'usuario' && ((int)$user['nivel'] <= 1 || $user['login'] === 'admin' || strpos($user['acesso'], 'ALL') !== false)) {
            return;
        }

        $acessosUsuario = array_filter(array_map('trim', explode(',', $user['acesso'])));
        
        // Verifica se há interseção de códigos de privilégio
        $possuiAcesso = false;
        foreach ($codes as $code) {
            if (in_array($code, $acessosUsuario)) {
                $possuiAcesso = true;
                break;
            }
        }

        if (!$possuiAcesso) {
            http_response_code(403);
            echo json_encode(['detail' => 'Acesso negado. Privilégio insuficiente.']);
            exit;
        }
    }

    // ─── Simulação de Autenticação LDAP NLM Nativa ───────────────────────────

    public static function tryLdapAuth($username, $password) {
        $ldapHost = Config::get('LDAP_SERVER'); // Deixe em branco/nulo se LDAP não for configurado
        if (empty($ldapHost)) {
            return false;
        }

        $ldapDomain = Config::get('LDAP_DOMAIN');
        $port = 389;

        // Limpa o hostname se tiver prefixo
        $ldapHostClean = preg_replace('/^(ldaps?:\/\/)/i', '', $ldapHost);
        if (strpos($ldapHostClean, ':') !== false) {
            list($ldapHostClean, $port) = explode(':', $ldapHostClean, 2);
        }

        // Tenta conexão LDAP nativa do PHP
        if (function_exists('ldap_connect')) {
            $conn = @ldap_connect($ldapHostClean, (int)$port);
            if ($conn) {
                @ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
                @ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
                
                $bindDn = !empty($ldapDomain) ? "$ldapDomain\\$username" : $username;
                $bind = @ldap_bind($conn, $bindDn, $password);
                if ($bind) {
                    @ldap_close($conn);
                    return true;
                }
                @ldap_close($conn);
            }
        }
        
        return false;
    }
}
