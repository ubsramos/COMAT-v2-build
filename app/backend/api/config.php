<?php
/**
 * config.php — Carregamento de ambiente (.env) e conexão PDO MySQL
 */

// Define fuso horário padrão
date_default_timezone_set('America/Sao_Paulo');

class Config {
    private static $env = [];
    private static $pdo = null;

    /**
     * Carrega variáveis do arquivo .env
     */
    public static function loadEnv($path = __DIR__ . '/../.env') {
        if (!file_exists($path)) {
            // Fallback para variáveis de ambiente do sistema ou valores padrões
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || strpos($line, '#') === 0 || strpos($line, '=') === false) {
                continue;
            }

            list($key, $val) = explode('=', $line, 2);
            $key = trim($key);
            $val = trim($val);
            
            // Remove aspas simples/duplas das extremidades
            if ((strpos($val, '"') === 0 && strrpos($val, '"') === strlen($val) - 1) ||
                (strpos($val, "'") === 0 && strrpos($val, "'") === strlen($val) - 1)) {
                $val = substr($val, 1, -1);
            }

            self::$env[$key] = $val;
            putenv("$key=$val");
            $_ENV[$key] = $val;
            $_SERVER[$key] = $val;
        }
    }

    /**
     * Obtém uma variável de ambiente com valor padrão
     */
    public static function get($key, $default = null) {
        if (isset(self::$env[$key])) {
            return self::$env[$key];
        }
        $val = getenv($key);
        return $val !== false ? $val : $default;
    }

    /**
     * Retorna a conexão PDO MySQL ativa (Singleton)
     */
    public static function getDb() {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $dbUrl = self::get('DATABASE_URL', 'mysql://root:root@127.0.0.1:3306/comat');

        // Parser robusto para DATABASE_URL: mysql://user:password@host:port/dbname
        // Trata senhas com caracteres especiais como '@', '#', ':', etc.
        $raw = preg_replace('/^mysql(?:\+pymysql)?:\/\//', '', $dbUrl);
        $lastAt = strrpos($raw, '@');
        if ($lastAt !== false) {
            $creds = substr($raw, 0, $lastAt);
            $hostAndDb = substr($raw, $lastAt + 1);

            $colonPos = strpos($creds, ':');
            if ($colonPos !== false) {
                $user = substr($creds, 0, $colonPos);
                $pass = substr($creds, $colonPos + 1);
            } else {
                $user = $creds;
                $pass = '';
            }

            if (preg_match('/^([^:\/]+)(?::(\d+))?\/([^?]+)/', $hostAndDb, $hMatches)) {
                $host = $hMatches[1];
                $port = !empty($hMatches[2]) ? (int)$hMatches[2] : 3306;
                $name = $hMatches[3];
            } else {
                throw new Exception("Host ou nome do banco inválido na DATABASE_URL.");
            }
        } else {
            throw new Exception("DATABASE_URL inválida ou malformada.");
        }

        $dsn = "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4";
        
        try {
            self::$pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            return self::$pdo;
        } catch (PDOException $e) {
            $errorMsg = $e->getMessage();
            $ip = null;

            // Tenta extrair o IP da mensagem de erro do MySQL (ex: Host '200.100.50.25' is not allowed...)
            if (preg_match('/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/', $errorMsg, $matches)) {
                $ip = $matches[0];
            }

            // Se não encontrou na mensagem, tenta pegar o IP público externo via API
            if (!$ip) {
                $ctx = stream_context_create(['http' => ['timeout' => 1.5]]);
                $externalIp = @file_get_contents('https://api.ipify.org', false, $ctx);
                if ($externalIp && filter_var(trim($externalIp), FILTER_VALIDATE_IP)) {
                    $ip = trim($externalIp);
                }
            }

            // Se ainda assim falhar, pega REMOTE_ADDR ou cabeçalhos
            if (!$ip) {
                $ip = $_SERVER['REMOTE_ADDR'] ?? '';
                if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                    $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
                }
            }

            // Garante cabeçalhos globais e CORS se não enviados
            if (!headers_sent()) {
                header("Access-Control-Allow-Origin: *");
                header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS, PATCH");
                header("Access-Control-Allow-Headers: Authorization, Content-Type, Accept, Origin, X-Requested-With");
                header("Content-Type: application/json; charset=utf-8");
                http_response_code(503);
            }
            
            echo json_encode([
                'error' => 'ip_blocked',
                'ip' => $ip,
                'detail' => $errorMsg
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}

// Inicializa o carregamento do .env
Config::loadEnv();
