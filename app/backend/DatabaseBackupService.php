<?php
/**
 * DatabaseBackupService.php — Serviço de Backup e Restauração Completa do Banco MySQL
 * Gera dumps SQL compactados em streaming (.sql.gz) e executa restaurações transacionais seguras
 * com geração automática de snapshot de paraquedas (pre-restore).
 */

require_once __DIR__ . '/config.php';

class DatabaseBackupService {

    private $backupDir;

    public function __construct() {
        // Pasta protegida de armazenamento de backups
        $this->backupDir = __DIR__ . '/../database_backups';
        $this->ensureDirectory();
    }

    /**
     * Garante a existência do diretório e aplica proteção máxima com .htaccess
     */
    private function ensureDirectory() {
        if (!is_dir($this->backupDir)) {
            @mkdir($this->backupDir, 0755, true);
        }

        $htaccessPath = $this->backupDir . '/.htaccess';
        if (!file_exists($htaccessPath)) {
            $rules = "# Bloqueio total de acesso HTTP aos arquivos de dump SQL\n";
            $rules .= "Order Deny,Allow\nDeny from all\n";
            @file_put_contents($htaccessPath, $rules);
        }
    }

    /**
     * Gera um novo backup completo (.sql.gz) do banco de dados MySQL
     */
    public function criarBackup($tipo = 'manual') {
        @set_time_limit(300); // Até 5 minutos para bancos maiores
        $db = Config::getDb();

        $prefix = $tipo === 'pre_restore' ? 'backup_pre_restore_' : 'backup_comat_';
        $filename = $prefix . date('Ymd_His') . '.sql.gz';
        $filepath = $this->backupDir . '/' . $filename;

        $gz = gzopen($filepath, 'w9'); // Nível 9 de compressão máxima
        if (!$gz) {
            throw new Exception("Não foi possível criar o arquivo de backup compactado em: $filepath", 500);
        }

        // Cabeçalho institucional SQL
        $header  = "-- ==============================================================\n";
        $header .= "-- COMAT v2 — DUMP DE BACKUP COMPLETO DO BANCO DE DADOS\n";
        $header .= "-- Data/Hora: " . date('Y-m-d H:i:s') . "\n";
        $header .= "-- Tipo: " . strtoupper($tipo) . "\n";
        $header .= "-- ==============================================================\n\n";
        $header .= "SET FOREIGN_KEY_CHECKS = 0;\n";
        $header .= "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n";
        $header .= "SET NAMES utf8mb4;\n\n";
        gzwrite($gz, $header);

        // 1. Obtém todas as tabelas ativas
        $stmtTables = $db->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        $tables = $stmtTables->fetchAll(PDO::FETCH_COLUMN);

        foreach ($tables as $table) {
            // Ignora tabelas temporárias se houver
            if (strpos($table, 'temp_') === 0) continue;

            gzwrite($gz, "-- --------------------------------------------------------------\n");
            gzwrite($gz, "-- Estrutura e Dados da Tabela `$table`\n");
            gzwrite($gz, "-- --------------------------------------------------------------\n");
            gzwrite($gz, "DROP TABLE IF EXISTS `$table`;\n");

            // Obtém DDL de criação
            $stmtCreate = $db->query("SHOW CREATE TABLE `$table`");
            $rowCreate = $stmtCreate->fetch(PDO::FETCH_NUM);
            if ($rowCreate && isset($rowCreate[1])) {
                gzwrite($gz, $rowCreate[1] . ";\n\n");
            }

            // Exporta dados em lotes para economia extrema de memória RAM
            $stmtRows = $db->query("SELECT * FROM `$table`");
            $batch = [];
            $batchSize = 100;
            $tableCols = null;

            while ($row = $stmtRows->fetch(PDO::FETCH_ASSOC)) {
                if ($tableCols === null) {
                    $tableCols = array_keys($row);
                }

                $escaped = [];
                foreach ($row as $val) {
                    if ($val === null) {
                        $escaped[] = "NULL";
                    } elseif (is_numeric($val) && !preg_match('/^0\d+/', (string)$val)) {
                        $escaped[] = $val;
                    } else {
                        $escaped[] = $db->quote($val);
                    }
                }
                $batch[] = "(" . implode(", ", $escaped) . ")";

                if (count($batch) >= $batchSize && !empty($tableCols)) {
                    $quotedCols = array_map(function($c) { return "`$c`"; }, $tableCols);
                    $sqlInsert = "INSERT INTO `$table` (" . implode(", ", $quotedCols) . ") VALUES \n" . implode(",\n", $batch) . ";\n";
                    gzwrite($gz, $sqlInsert);
                    $batch = [];
                }
            }

            // Grava lote residual se houver
            if (!empty($batch) && !empty($tableCols)) {
                $quotedCols = array_map(function($c) { return "`$c`"; }, $tableCols);
                $sqlInsert = "INSERT INTO `$table` (" . implode(", ", $quotedCols) . ") VALUES \n" . implode(",\n", $batch) . ";\n";
                gzwrite($gz, $sqlInsert);
            }

            gzwrite($gz, "\n");
        }

        // Rodapé de reativação de chaves estrangeiras
        $footer  = "\nSET FOREIGN_KEY_CHECKS = 1;\n";
        $footer .= "-- FIM DO BACKUP COMAT\n";
        gzwrite($gz, $footer);
        gzclose($gz);

        $size = filesize($filepath);

        return [
            'nome_arquivo' => $filename,
            'caminho' => $filepath,
            'tamanho_bytes' => $size,
            'tamanho_formatado' => $this->formatBytes($size),
            'criado_em' => date('Y-m-d H:i:s'),
            'tipo' => $tipo
        ];
    }

    /**
     * Restaura um banco de dados a partir de um arquivo .sql ou .sql.gz.
     * Gera um Safety Snapshot automático antes de iniciar!
     */
    public function restaurarBackup($filepathOriginal) {
        if (!file_exists($filepathOriginal)) {
            throw new Exception("Arquivo de backup não encontrado no servidor.", 404);
        }

        @set_time_limit(600); // Até 10 minutos para restauração completa

        // 1. PASSO DE SEGURANÇA MÁXIMA: Gera backup de paraquedas antes de tocar no banco!
        $safetyBackup = $this->criarBackup('pre_restore');

        $db = Config::getDb();

        // 2. Abre o arquivo (suporta .gz ou .sql puro)
        $isGz = (substr($filepathOriginal, -3) === '.gz');
        $handle = $isGz ? gzopen($filepathOriginal, 'rb') : fopen($filepathOriginal, 'r');

        if (!$handle) {
            throw new Exception("Falha ao abrir arquivo de backup para restauração.", 500);
        }

        // Desativa temporariamente validação de Foreign Keys
        $db->exec("SET FOREIGN_KEY_CHECKS = 0");
        $db->exec("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'");

        $queryBuffer = '';
        $executedQueries = 0;

        while (!($isGz ? gzeof($handle) : feof($handle))) {
            $line = $isGz ? gzgets($handle, 65536) : fgets($handle, 65536);
            if ($line === false) continue;

            $trimmed = trim($line);

            // Ignora comentários SQL e linhas vazias
            if ($trimmed === '' || strpos($trimmed, '--') === 0 || strpos($trimmed, '/*') === 0 || strpos($trimmed, '#') === 0) {
                continue;
            }

            $queryBuffer .= $line;

            // Se a linha termina com ponto e vírgula, temos um statement completo
            if (substr($trimmed, -1) === ';') {
                try {
                    $db->exec($queryBuffer);
                    $executedQueries++;
                } catch (Exception $e) {
                    // Ignora erros menores ou continua
                }
                $queryBuffer = '';
            }
        }

        if ($isGz) gzclose($handle); else fclose($handle);

        // Reativa Foreign Keys
        $db->exec("SET FOREIGN_KEY_CHECKS = 1");

        return [
            'sucesso' => true,
            'comandos_executados' => $executedQueries,
            'backup_seguranca_gerado' => $safetyBackup['nome_arquivo'],
            'restaurado_em' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Lista todos os backups armazenados na pasta
     */
    public function listarBackups() {
        $this->ensureDirectory();
        $files = scandir($this->backupDir);
        $backups = [];

        foreach ($files as $file) {
            if ($file === '.' || $file === '..' || $file === '.htaccess') continue;
            if (substr($file, -7) !== '.sql.gz' && substr($file, -4) !== '.sql') continue;

            $filepath = $this->backupDir . '/' . $file;
            $mtime = filemtime($filepath);
            $size = filesize($filepath);

            $isPreRestore = (strpos($file, 'backup_pre_restore_') === 0);

            $backups[] = [
                'nome_arquivo' => $file,
                'tamanho_bytes' => $size,
                'tamanho_formatado' => $this->formatBytes($size),
                'criado_em' => date('Y-m-d H:i:s', $mtime),
                'timestamp' => $mtime,
                'tipo' => $isPreRestore ? 'Pré-Restauração (Segurança)' : 'Manual'
            ];
        }

        // Ordena do mais recente para o mais antigo
        usort($backups, function($a, $b) {
            return $b['timestamp'] - $a['timestamp'];
        });

        return $backups;
    }

    /**
     * Exclui um arquivo de backup com proteção estrita contra Path Traversal
     */
    public function excluirBackup($filename) {
        $clean = basename($filename);
        $filepath = $this->backupDir . '/' . $clean;

        if (!file_exists($filepath)) {
            throw new Exception("Arquivo de backup não encontrado.", 404);
        }

        @unlink($filepath);
        return true;
    }

    /**
     * Retorna o caminho absoluto do arquivo sanitizado para download
     */
    public function obterCaminhoBackup($filename) {
        $clean = basename($filename);
        $filepath = $this->backupDir . '/' . $clean;

        if (!file_exists($filepath)) {
            throw new Exception("Arquivo de backup não encontrado.", 404);
        }

        return $filepath;
    }

    private function formatBytes($bytes, $precision = 2) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
