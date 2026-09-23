<?php
/**
 * db_migrate.php — Migrador Automático e Resiliente de Banco de Dados — COMAT v2
 * Garante a criação de todas as tabelas (CREATE TABLE IF NOT EXISTS),
 * adição de colunas faltantes em bancos legados (ALTER TABLE) e
 * carga de dados iniciais padrão (SEED).
 * Pode ser executado via CLI (linha de comando) ou via Runtime PHP no primeiro request.
 */

require_once __DIR__ . '/config.php';

class DatabaseMigrator {
    public static function run($db = null, $verbose = true) {
        if ($db === null) {
            $db = Config::getRawDbConnection();
        }

        $log = function ($msg) use ($verbose) {
            if ($verbose && (php_sapi_name() === 'cli' || !headers_sent())) {
                echo $msg . "\n";
            }
        };

        // Função auxiliar para verificar se coluna existe
        $colExists = function ($table, $column) use ($db) {
            try {
                $stmt = $db->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
                return $stmt && $stmt->rowCount() > 0;
            } catch (Exception $e) {
                return false;
            }
        };

        // Função auxiliar para verificar se tabela existe
        $tableExists = function ($table) use ($db) {
            try {
                $stmt = $db->query("SHOW TABLES LIKE '$table'");
                return $stmt && $stmt->rowCount() > 0;
            } catch (Exception $e) {
                return false;
            }
        };

        // ══════════════════════════════════════════════════════════════════════
        // ETAPA 1: CRIAÇÃO DE TODAS AS 16 TABELAS CASO NÃO EXISTAM
        // ══════════════════════════════════════════════════════════════════════

        // 1. Tabela: usuario
        $db->exec("CREATE TABLE IF NOT EXISTS `usuario` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `login` VARCHAR(100) NOT NULL UNIQUE,
          `senha` VARCHAR(255) NOT NULL,
          `email` VARCHAR(255) NULL,
          `nivel` INT NOT NULL DEFAULT 1,
          `ativo` TINYINT(1) NOT NULL DEFAULT 1,
          `acesso` TEXT NULL,
          `hash` VARCHAR(64) NULL,
          `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 2. Tabela: departamento
        $db->exec("CREATE TABLE IF NOT EXISTS `departamento` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `descricao` VARCHAR(255) NOT NULL,
          `descricao_completa` TEXT NULL,
          `conta` VARCHAR(50) NULL,
          `sub_conta` VARCHAR(50) NULL,
          `centro_custo` VARCHAR(50) NULL,
          `user_auth` VARCHAR(100) NULL,
          `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 2.1 Tabela: departamento_email (Múltiplos e-mails por departamento para notificações)
        $db->exec("CREATE TABLE IF NOT EXISTS `departamento_email` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `depto_id` INT NOT NULL,
          `email` VARCHAR(255) NOT NULL,
          `nome` VARCHAR(255) NULL,
          `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP,
          INDEX `idx_depto_email_depto` (`depto_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 3. Tabela: grupo
        $db->exec("CREATE TABLE IF NOT EXISTS `grupo` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `descricao` VARCHAR(255) NOT NULL,
          `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 4. Tabela: motivo
        $db->exec("CREATE TABLE IF NOT EXISTS `motivo` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `descricao` VARCHAR(255) NOT NULL,
          `tipo` VARCHAR(20) NOT NULL DEFAULT 'SAIDA',
          `ativo` TINYINT(1) NOT NULL DEFAULT 1,
          `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 5. Tabela: funcionario
        $db->exec("CREATE TABLE IF NOT EXISTS `funcionario` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `nome` VARCHAR(255) NOT NULL,
          `login_ldap` VARCHAR(100) NULL,
          `depto_id` INT NULL,
          `status` TINYINT(1) NOT NULL DEFAULT 1,
          `acesso` TEXT NULL,
          `funcao` INT NOT NULL DEFAULT 1,
          `admin_estoque` TINYINT(1) NOT NULL DEFAULT 0,
          `email` VARCHAR(255) NULL,
          `telefone` VARCHAR(50) NULL,
          `usuario_id` INT NULL,
          `hash` VARCHAR(64) NULL,
          `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 6. Tabela: funcionario_depto (Multi-Departamentos)
        $db->exec("CREATE TABLE IF NOT EXISTS `funcionario_depto` (
          `funcionario_id` INT NOT NULL,
          `depto_id` INT NOT NULL,
          PRIMARY KEY (`funcionario_id`, `depto_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 7. Tabela: produto
        $db->exec("CREATE TABLE IF NOT EXISTS `produto` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `codigo` VARCHAR(50) NULL,
          `codigo_barra` VARCHAR(100) NULL,
          `codigo_interno` VARCHAR(100) NULL,
          `descricao_resumo` VARCHAR(255) NOT NULL,
          `descricao_completa` TEXT NULL,
          `depto_id` INT NULL,
          `grupo_id` INT NULL,
          `unidade` VARCHAR(20) NOT NULL DEFAULT 'UN',
          `qtde_estoque` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          `qtde_reservado` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          `qtde_min` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          `qtde_max` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          `valor_compra` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          `custo_medio` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          `foto` VARCHAR(255) NULL,
          `status` INT NOT NULL DEFAULT 1,
          `ativo` TINYINT(1) NOT NULL DEFAULT 1,
          `entidade_id` INT NULL,
          `hash` VARCHAR(64) NULL,
          `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 8. Tabela: requisicao
        $db->exec("CREATE TABLE IF NOT EXISTS `requisicao` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `numero` VARCHAR(50) NULL,
          `data_pedido` DATETIME DEFAULT CURRENT_TIMESTAMP,
          `descricao` VARCHAR(255) NULL,
          `tag` VARCHAR(100) NULL,
          `numero_nf` VARCHAR(100) NULL,
          `hash` VARCHAR(64) NULL,
          `depto_destino_id` INT NULL,
          `depto_origem_id` INT NULL,
          `departamento_id` INT NULL,
          `usuario_solicitante_id` INT NULL,
          `usuario_aprovador_id` INT NULL,
          `usuario_atendente_id` INT NULL,
          `motivo_id` INT NULL,
          `data_solicitacao` DATETIME DEFAULT CURRENT_TIMESTAMP,
          `data_aprovacao` DATETIME NULL,
          `data_processamento` DATETIME NULL,
          `data_atendido` DATETIME NULL,
          `status` INT NOT NULL DEFAULT 0,
          `observacao` TEXT NULL,
          `tipo` VARCHAR(20) NOT NULL DEFAULT 'SAIDA',
          `entidade_id` INT NULL,
          `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 9. Tabela: requisicao_item
        $db->exec("CREATE TABLE IF NOT EXISTS `requisicao_item` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `request_id` INT NOT NULL,
          `produto_id` INT NOT NULL,
          `qtde` DECIMAL(12,2) NOT NULL DEFAULT 1.00,
          `quantidade` DECIMAL(12,2) NOT NULL DEFAULT 1.00,
          `valor_produto` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          `valor_unitario` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          `status` INT NOT NULL DEFAULT 0,
          `motivo_devolucao` VARCHAR(255) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 10. Tabela: correspondencia_tipo
        $db->exec("CREATE TABLE IF NOT EXISTS `correspondencia_tipo` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `descricao` VARCHAR(100) NOT NULL,
          `ativo` TINYINT(1) NOT NULL DEFAULT 1,
          `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 11. Tabela: correspondencia
        $db->exec("CREATE TABLE IF NOT EXISTS `correspondencia` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `data_chegada` DATETIME DEFAULT CURRENT_TIMESTAMP,
          `tipo` VARCHAR(100) NOT NULL DEFAULT 'Carta',
          `tipo_id` INT NULL,
          `remetente` VARCHAR(255) NOT NULL,
          `rastreio` VARCHAR(100) NULL,
          `descricao` TEXT NULL,
          `func_destino_id` INT NULL,
          `depto_destino_id` INT NULL,
          `destinatario_id` INT NULL,
          `destinatario_manual` VARCHAR(255) NULL,
          `email_destino` VARCHAR(255) NULL,
          `recebedor_tipo` VARCHAR(50) DEFAULT 'almoxarifado',
          `recebedor_id` INT NULL,
          `ponto_recepcao` VARCHAR(100) DEFAULT 'Recepção Central',
          `status` VARCHAR(50) NOT NULL DEFAULT 'aguardando',
          `data_recebimento` DATETIME DEFAULT CURRENT_TIMESTAMP,
          `data_retirada` DATETIME NULL,
          `retirado_por` VARCHAR(255) NULL,
          `retirado_por_manual` VARCHAR(255) NULL,
          `retirado_por_proprio` TINYINT(1) NOT NULL DEFAULT 0,
          `meio_retirada` VARCHAR(100) NULL,
          `func_retirada_id` INT NULL,
          `obs_retirada` TEXT NULL,
          `email_enviado` TINYINT(1) NOT NULL DEFAULT 0,
          `email_erro` TEXT NULL,
          `observacao` TEXT NULL,
          `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 12. Tabela: parametros
        $db->exec("CREATE TABLE IF NOT EXISTS `parametros` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `entidade` VARCHAR(255) NULL DEFAULT 'ASPA',
          `campo_nome` VARCHAR(255) NOT NULL DEFAULT 'Associação Paulista',
          `campo_sigla` VARCHAR(50) NOT NULL DEFAULT 'ASPA',
          `campo_cnpj` VARCHAR(50) NULL DEFAULT '',
          `campo_endereco` VARCHAR(255) NULL DEFAULT '',
          `sistema_nome` VARCHAR(255) NOT NULL DEFAULT 'COMAT — Controle de Material',
          `sistema_sigla` VARCHAR(50) NOT NULL DEFAULT 'COMAT',
          `ldap` TINYINT(1) NOT NULL DEFAULT 0,
          `ldap_host` VARCHAR(255) NULL,
          `ldap_dominio_search` VARCHAR(255) NULL,
          `ldap_dominio_email` VARCHAR(255) NULL,
          `empresa_nome` VARCHAR(255) NOT NULL DEFAULT 'COMAT — Controle de Material',
          `email_ativo` TINYINT(1) NOT NULL DEFAULT 0,
          `smtp_host` VARCHAR(255) NULL,
          `smtp_porta` INT NULL DEFAULT 587,
          `smtp_user` VARCHAR(255) NULL,
          `smtp_pass` VARCHAR(255) NULL,
          `smtp_cripto` VARCHAR(50) NULL DEFAULT 'tls',
          `email_sistema` VARCHAR(255) NULL,
          `wa_ativo` TINYINT(1) NOT NULL DEFAULT 0,
          `wa_api_url` VARCHAR(512) NULL,
          `wa_token` VARCHAR(255) NULL,
          `wa_headers` TEXT NULL,
          `wa_payload` TEXT NULL,
          `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 13. Tabela: tag
        $db->exec("CREATE TABLE IF NOT EXISTS `tag` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `descricao` VARCHAR(100) NOT NULL,
          `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 14. Tabela: movimento
        $db->exec("CREATE TABLE IF NOT EXISTS `movimento` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `data` DATETIME DEFAULT CURRENT_TIMESTAMP,
          `qtde` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          `valor_produto` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          `produto_id` INT NOT NULL,
          `request_item_id` INT NULL,
          `hash` VARCHAR(100) NULL,
          `tipo` VARCHAR(30) NULL DEFAULT 'REQUISICAO',
          `justificativa` TEXT NULL,
          `usuario_nome` VARCHAR(255) NULL,
          `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 15. Tabela: estoque_ajuste (Auditoria de Ajustes Físicos de Estoque)
        $db->exec("CREATE TABLE IF NOT EXISTS `estoque_ajuste` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `produto_id` INT NOT NULL,
          `tipo` VARCHAR(20) NOT NULL,
          `qtde_anterior` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          `qtde_ajuste` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          `qtde_nova` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          `valor_anterior` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          `valor_novo` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          `motivo` VARCHAR(255) NOT NULL,
          `justificativa` TEXT NOT NULL,
          `usuario_id` INT NULL,
          `usuario_nome` VARCHAR(255) NULL,
          `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // 16. Tabela: estoque_backup_log (Snapshots e Backups de Segurança de Estoque)
        $db->exec("CREATE TABLE IF NOT EXISTS `estoque_backup_log` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `nome_tabela` VARCHAR(100) NOT NULL UNIQUE,
          `total_produtos` INT NOT NULL DEFAULT 0,
          `qtde_total_estoque` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          `valor_total_estoque` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          `usuario_nome` VARCHAR(255) NULL,
          `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

        // ══════════════════════════════════════════════════════════════════════
        // ETAPA 2: ADIÇÃO DINÂMICA DE COLUNAS FALTANTES (ALTER TABLE RESILIENTE)
        // ══════════════════════════════════════════════════════════════════════

        $migrations = [
            // Tabela: produto
            ['produto', 'codigo_barra', 'ALTER TABLE `produto` ADD COLUMN `codigo_barra` VARCHAR(100) NULL AFTER `codigo`'],
            ['produto', 'codigo_interno', 'ALTER TABLE `produto` ADD COLUMN `codigo_interno` VARCHAR(100) NULL AFTER `codigo_barra`'],
            ['produto', 'descricao_completa', 'ALTER TABLE `produto` ADD COLUMN `descricao_completa` TEXT NULL AFTER `descricao_resumo`'],
            ['produto', 'qtde_reservado', 'ALTER TABLE `produto` ADD COLUMN `qtde_reservado` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `qtde_estoque`'],
            ['produto', 'custo_medio', 'ALTER TABLE `produto` ADD COLUMN `custo_medio` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `valor_compra`'],
            ['produto', 'status', 'ALTER TABLE `produto` ADD COLUMN `status` INT NOT NULL DEFAULT 1 AFTER `foto`'],
            ['produto', 'entidade_id', 'ALTER TABLE `produto` ADD COLUMN `entidade_id` INT NULL AFTER `ativo`'],
            ['produto', 'hash', 'ALTER TABLE `produto` ADD COLUMN `hash` VARCHAR(64) NULL AFTER `entidade_id`'],

            // Tabela: usuario
            ['usuario', 'email', 'ALTER TABLE `usuario` ADD COLUMN `email` VARCHAR(255) NULL AFTER `senha`'],
            ['usuario', 'hash', 'ALTER TABLE `usuario` ADD COLUMN `hash` VARCHAR(64) NULL AFTER `acesso`'],

            // Tabela: funcionario
            ['funcionario', 'telefone', 'ALTER TABLE `funcionario` ADD COLUMN `telefone` VARCHAR(50) NULL AFTER `email`'],
            ['funcionario', 'funcao', 'ALTER TABLE `funcionario` ADD COLUMN `funcao` INT NOT NULL DEFAULT 1 AFTER `acesso`'],
            ['funcionario', 'admin_estoque', 'ALTER TABLE `funcionario` ADD COLUMN `admin_estoque` TINYINT(1) NOT NULL DEFAULT 0 AFTER `funcao`'],
            ['funcionario', 'usuario_id', 'ALTER TABLE `funcionario` ADD COLUMN `usuario_id` INT NULL AFTER `id`'],
            ['funcionario', 'hash', 'ALTER TABLE `funcionario` ADD COLUMN `hash` VARCHAR(64) NULL AFTER `funcao`'],

            // Tabela: departamento
            ['departamento', 'descricao_completa', 'ALTER TABLE `departamento` ADD COLUMN `descricao_completa` TEXT NULL AFTER `descricao`'],
            ['departamento', 'conta', 'ALTER TABLE `departamento` ADD COLUMN `conta` VARCHAR(50) NULL AFTER `descricao_completa`'],
            ['departamento', 'sub_conta', 'ALTER TABLE `departamento` ADD COLUMN `sub_conta` VARCHAR(50) NULL AFTER `conta`'],
            ['departamento', 'centro_custo', 'ALTER TABLE `departamento` ADD COLUMN `centro_custo` VARCHAR(50) NULL AFTER `sub_conta`'],

            // Tabela: requisicao
            ['requisicao', 'data_pedido', 'ALTER TABLE `requisicao` ADD COLUMN `data_pedido` DATETIME NULL DEFAULT CURRENT_TIMESTAMP AFTER `numero`'],
            ['requisicao', 'data_aprovacao', 'ALTER TABLE `requisicao` ADD COLUMN `data_aprovacao` DATETIME NULL AFTER `data_pedido`'],
            ['requisicao', 'data_processamento', 'ALTER TABLE `requisicao` ADD COLUMN `data_processamento` DATETIME NULL AFTER `data_aprovacao`'],
            ['requisicao', 'data_atendido', 'ALTER TABLE `requisicao` ADD COLUMN `data_atendido` DATETIME NULL AFTER `data_processamento`'],
            ['requisicao', 'descricao', 'ALTER TABLE `requisicao` ADD COLUMN `descricao` VARCHAR(255) NULL AFTER `data_atendido`'],
            ['requisicao', 'tag', 'ALTER TABLE `requisicao` ADD COLUMN `tag` VARCHAR(100) NULL AFTER `descricao`'],
            ['requisicao', 'numero_nf', 'ALTER TABLE `requisicao` ADD COLUMN `numero_nf` VARCHAR(100) NULL AFTER `tag`'],
            ['requisicao', 'hash', 'ALTER TABLE `requisicao` ADD COLUMN `hash` VARCHAR(64) NULL AFTER `numero_nf`'],
            ['requisicao', 'depto_destino_id', 'ALTER TABLE `requisicao` ADD COLUMN `depto_destino_id` INT NULL AFTER `hash`'],
            ['requisicao', 'depto_origem_id', 'ALTER TABLE `requisicao` ADD COLUMN `depto_origem_id` INT NULL AFTER `depto_destino_id`'],
            ['requisicao', 'usuario_aprovador_id', 'ALTER TABLE `requisicao` ADD COLUMN `usuario_aprovador_id` INT NULL AFTER `usuario_solicitante_id`'],
            ['requisicao', 'usuario_atendente_id', 'ALTER TABLE `requisicao` ADD COLUMN `usuario_atendente_id` INT NULL AFTER `usuario_aprovador_id`'],
            ['requisicao', 'entidade_id', 'ALTER TABLE `requisicao` ADD COLUMN `entidade_id` INT NULL AFTER `tipo`'],

            // Tabela: requisicao_item
            ['requisicao_item', 'qtde', 'ALTER TABLE `requisicao_item` ADD COLUMN `qtde` DECIMAL(12,2) NOT NULL DEFAULT 1.00 AFTER `produto_id`'],
            ['requisicao_item', 'quantidade', 'ALTER TABLE `requisicao_item` ADD COLUMN `quantidade` DECIMAL(12,2) NOT NULL DEFAULT 1.00 AFTER `qtde`'],
            ['requisicao_item', 'valor_produto', 'ALTER TABLE `requisicao_item` ADD COLUMN `valor_produto` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `quantidade`'],
            ['requisicao_item', 'valor_unitario', 'ALTER TABLE `requisicao_item` ADD COLUMN `valor_unitario` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `valor_produto`'],

            // Tabela: correspondencia
            ['correspondencia', 'data_chegada', 'ALTER TABLE `correspondencia` ADD COLUMN `data_chegada` DATETIME NULL DEFAULT CURRENT_TIMESTAMP AFTER `id`'],
            ['correspondencia', 'tipo', 'ALTER TABLE `correspondencia` ADD COLUMN `tipo` VARCHAR(100) NOT NULL DEFAULT \'Carta\' AFTER `data_chegada`'],
            ['correspondencia', 'rastreio', 'ALTER TABLE `correspondencia` ADD COLUMN `rastreio` VARCHAR(100) NULL AFTER `remetente`'],
            ['correspondencia', 'descricao', 'ALTER TABLE `correspondencia` ADD COLUMN `descricao` TEXT NULL AFTER `rastreio`'],
            ['correspondencia', 'func_destino_id', 'ALTER TABLE `correspondencia` ADD COLUMN `func_destino_id` INT NULL AFTER `descricao`'],
            ['correspondencia', 'depto_destino_id', 'ALTER TABLE `correspondencia` ADD COLUMN `depto_destino_id` INT NULL AFTER `func_destino_id`'],
            ['correspondencia', 'destinatario_id', 'ALTER TABLE `correspondencia` ADD COLUMN `destinatario_id` INT NULL AFTER `depto_destino_id`'],
            ['correspondencia', 'destinatario_manual', 'ALTER TABLE `correspondencia` ADD COLUMN `destinatario_manual` VARCHAR(255) NULL AFTER `destinatario_id`'],
            ['correspondencia', 'email_destino', 'ALTER TABLE `correspondencia` ADD COLUMN `email_destino` VARCHAR(255) NULL AFTER `destinatario_manual`'],
            ['correspondencia', 'recebedor_tipo', 'ALTER TABLE `correspondencia` ADD COLUMN `recebedor_tipo` VARCHAR(50) NULL DEFAULT \'almoxarifado\' AFTER `email_destino`'],
            ['correspondencia', 'recebedor_id', 'ALTER TABLE `correspondencia` ADD COLUMN `recebedor_id` INT NULL AFTER `recebedor_tipo`'],
            ['correspondencia', 'email_enviado', 'ALTER TABLE `correspondencia` ADD COLUMN `email_enviado` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`'],
            ['correspondencia', 'email_erro', 'ALTER TABLE `correspondencia` ADD COLUMN `email_erro` TEXT NULL AFTER `email_enviado`'],
            ['correspondencia', 'data_retirada', 'ALTER TABLE `correspondencia` ADD COLUMN `data_retirada` DATETIME NULL AFTER `data_recebimento`'],
            ['correspondencia', 'obs_retirada', 'ALTER TABLE `correspondencia` ADD COLUMN `obs_retirada` TEXT NULL AFTER `data_retirada`'],
            ['correspondencia', 'func_retirada_id', 'ALTER TABLE `correspondencia` ADD COLUMN `func_retirada_id` INT NULL AFTER `obs_retirada`'],
            ['correspondencia', 'retirado_por_manual', 'ALTER TABLE `correspondencia` ADD COLUMN `retirado_por_manual` VARCHAR(255) NULL AFTER `func_retirada_id`'],
            ['correspondencia', 'retirado_por_proprio', 'ALTER TABLE `correspondencia` ADD COLUMN `retirado_por_proprio` TINYINT(1) NOT NULL DEFAULT 0 AFTER `retirado_por_manual`'],
            ['correspondencia', 'meio_retirada', 'ALTER TABLE `correspondencia` ADD COLUMN `meio_retirada` VARCHAR(100) NULL AFTER `retirado_por_proprio`'],

            // Tabela: movimento
            ['movimento', 'tipo', 'ALTER TABLE `movimento` ADD COLUMN `tipo` VARCHAR(30) NULL DEFAULT \'REQUISICAO\' AFTER `hash`'],
            ['movimento', 'justificativa', 'ALTER TABLE `movimento` ADD COLUMN `justificativa` TEXT NULL AFTER `tipo`'],
            ['movimento', 'usuario_nome', 'ALTER TABLE `movimento` ADD COLUMN `usuario_nome` VARCHAR(255) NULL AFTER `justificativa`'],

            // Tabela: parametros
            ['parametros', 'entidade', 'ALTER TABLE `parametros` ADD COLUMN `entidade` VARCHAR(255) NULL DEFAULT \'ASPA\' AFTER `id`'],
            ['parametros', 'campo_nome', 'ALTER TABLE `parametros` ADD COLUMN `campo_nome` VARCHAR(255) NULL DEFAULT \'Associação Paulista\' AFTER `entidade`'],
            ['parametros', 'campo_sigla', 'ALTER TABLE `parametros` ADD COLUMN `campo_sigla` VARCHAR(50) NULL DEFAULT \'ASPA\' AFTER `campo_nome`'],
            ['parametros', 'campo_cnpj', 'ALTER TABLE `parametros` ADD COLUMN `campo_cnpj` VARCHAR(50) NULL DEFAULT \'\' AFTER `campo_sigla`'],
            ['parametros', 'campo_endereco', 'ALTER TABLE `parametros` ADD COLUMN `campo_endereco` VARCHAR(255) NULL DEFAULT \'\' AFTER `campo_cnpj`'],
            ['parametros', 'sistema_nome', 'ALTER TABLE `parametros` ADD COLUMN `sistema_nome` VARCHAR(255) NULL DEFAULT \'COMAT — Controle de Material\' AFTER `campo_endereco`'],
            ['parametros', 'sistema_sigla', 'ALTER TABLE `parametros` ADD COLUMN `sistema_sigla` VARCHAR(50) NULL DEFAULT \'COMAT\' AFTER `sistema_nome`'],
            ['parametros', 'ldap', 'ALTER TABLE `parametros` ADD COLUMN `ldap` TINYINT(1) NOT NULL DEFAULT 0 AFTER `sistema_sigla`'],
            ['parametros', 'ldap_host', 'ALTER TABLE `parametros` ADD COLUMN `ldap_host` VARCHAR(255) NULL AFTER `ldap`'],
            ['parametros', 'ldap_dominio_search', 'ALTER TABLE `parametros` ADD COLUMN `ldap_dominio_search` VARCHAR(255) NULL AFTER `ldap_host`'],
            ['parametros', 'ldap_dominio_email', 'ALTER TABLE `parametros` ADD COLUMN `ldap_dominio_email` VARCHAR(255) NULL AFTER `ldap_dominio_search`'],
            ['parametros', 'email_ativo', 'ALTER TABLE `parametros` ADD COLUMN `email_ativo` TINYINT(1) DEFAULT 0'],
            ['parametros', 'smtp_host', 'ALTER TABLE `parametros` ADD COLUMN `smtp_host` VARCHAR(255) NULL'],
            ['parametros', 'smtp_porta', 'ALTER TABLE `parametros` ADD COLUMN `smtp_porta` INT NULL DEFAULT 587'],
            ['parametros', 'smtp_user', 'ALTER TABLE `parametros` ADD COLUMN `smtp_user` VARCHAR(255) NULL'],
            ['parametros', 'smtp_pass', 'ALTER TABLE `parametros` ADD COLUMN `smtp_pass` VARCHAR(255) NULL'],
            ['parametros', 'smtp_cripto', 'ALTER TABLE `parametros` ADD COLUMN `smtp_cripto` VARCHAR(50) NULL DEFAULT \'tls\''],
            ['parametros', 'email_sistema', 'ALTER TABLE `parametros` ADD COLUMN `email_sistema` VARCHAR(255) NULL'],
            ['parametros', 'wa_ativo', 'ALTER TABLE `parametros` ADD COLUMN `wa_ativo` TINYINT(1) DEFAULT 0'],
            ['parametros', 'wa_api_url', 'ALTER TABLE `parametros` ADD COLUMN `wa_api_url` VARCHAR(512) NULL'],
            ['parametros', 'wa_token', 'ALTER TABLE `parametros` ADD COLUMN `wa_token` VARCHAR(255) NULL'],
            ['parametros', 'wa_headers', 'ALTER TABLE `parametros` ADD COLUMN `wa_headers` TEXT NULL'],
            ['parametros', 'wa_payload', 'ALTER TABLE `parametros` ADD COLUMN `wa_payload` TEXT NULL']
        ];

        $executed = 0;
        foreach ($migrations as $item) {
            list($table, $col, $sql) = $item;
            if (!$colExists($table, $col)) {
                try {
                    $db->exec($sql);
                    $log("[OK] Coluna '$col' adicionada na tabela '$table'.");
                    $executed++;
                } catch (Exception $e) {
                    $log("[AVISO] Falha ao adicionar '$col' em '$table': " . $e->getMessage());
                }
            }
        }

        // ══════════════════════════════════════════════════════════════════════
        // ETAPA 3: CARGA DE DADOS ESSENCIAIS (SEED) CASO TABELAS ESTEJAM VAZIAS
        // ══════════════════════════════════════════════════════════════════════

        // 1. Administrador Padrão (Login: admin / Senha: admin)
        try {
            $checkAdmin = $db->query("SELECT COUNT(*) FROM `usuario` WHERE `login` = 'admin'");
            if ($checkAdmin && $checkAdmin->fetchColumn() == 0) {
                $hash = password_hash('admin', PASSWORD_DEFAULT);
                $db->exec("INSERT INTO `usuario` (`login`, `senha`, `nivel`, `ativo`, `acesso`, `email`) 
                           VALUES ('admin', '$hash', 1, 1, 'ALL,CM0,CM11,CM12,CM13,CM14,CM15,CM16,CM17,CM21,CM22,CM24,CM25,CM31,CM32,CM40', 'admin@local')");
                $log("[OK] Usuário 'admin' padrão inicializado.");
            }
        } catch (Exception $e) {}

        // 2. Departamentos Básicos
        try {
            $checkDepto = $db->query("SELECT COUNT(*) FROM `departamento`");
            if ($checkDepto && $checkDepto->fetchColumn() == 0) {
                $db->exec("INSERT INTO `departamento` (`descricao`, `user_auth`) VALUES
                    ('ALMOXARIFADO / FARMÁCIA CENTRAL', 'admin'),
                    ('ADMINISTRAÇÃO GERAL', 'admin'),
                    ('ENGENHARIA CLÍNICA / TI', 'admin'),
                    ('ENFERMAGEM E ASSISTENCIAL', 'admin'),
                    ('RECEPÇÃO E ATENDIMENTO', 'admin');");
                $log("[OK] Departamentos padrão inicializados.");
            }
        } catch (Exception $e) {}

        // 3. Grupos Básicos
        try {
            $checkGrupo = $db->query("SELECT COUNT(*) FROM `grupo`");
            if ($checkGrupo && $checkGrupo->fetchColumn() == 0) {
                $db->exec("INSERT INTO `grupo` (`descricao`) VALUES
                    ('MATERIAIS DE ESCRITÓRIO E EXPEDIENTE'),
                    ('MATERIAIS MÉDICO-HOSPITALARES'),
                    ('HIGIENE, LIMPEZA E DESCARTÁVEIS'),
                    ('EQUIPAMENTOS E INFORMÁTICA'),
                    ('MEDICAMENTOS E SOLUÇÕES');");
                $log("[OK] Grupos de produtos inicializados.");
            }
        } catch (Exception $e) {}

        // 4. Motivos Básicos
        try {
            $checkMotivo = $db->query("SELECT COUNT(*) FROM `motivo`");
            if ($checkMotivo && $checkMotivo->fetchColumn() == 0) {
                $db->exec("INSERT INTO `motivo` (`descricao`, `tipo`, `ativo`) VALUES
                    ('CONSUMO SETORIAL ROTINEIRO', 'SAIDA', 1),
                    ('ENTRADA DE NOTA FISCAL / COMPRA', 'ENTRADA', 1),
                    ('TRANSFERÊNCIA ENTRE UNIDADES', 'SAIDA', 1),
                    ('DEVOLUÇÃO DE SOBRA DE MATERIAL', 'ENTRADA', 1),
                    ('DESCARTE / AVARIA / VENCIMENTO', 'SAIDA', 1);");
                $log("[OK] Motivos de movimentação inicializados.");
            }
        } catch (Exception $e) {}

        // 5. Tipos de Correspondência Básicos
        try {
            $checkTipoC = $db->query("SELECT COUNT(*) FROM `correspondencia_tipo`");
            if ($checkTipoC && $checkTipoC->fetchColumn() == 0) {
                $db->exec("INSERT INTO `correspondencia_tipo` (`descricao`, `ativo`) VALUES
                    ('ENCOMENDA / PACOTE', 1),
                    ('CARTA / DOCUMENTO REGISTRADO', 1),
                    ('MALOTE INTERNO', 1),
                    ('SEDEX / TRANSPORTADORA', 1);");
                $log("[OK] Tipos de correspondência inicializados.");
            }
        } catch (Exception $e) {}

        // 6. Parâmetros Iniciais
        try {
            $checkParam = $db->query("SELECT COUNT(*) FROM `parametros`");
            if ($checkParam && $checkParam->fetchColumn() == 0) {
                $db->exec("INSERT INTO `parametros` (`id`, `entidade`, `campo_nome`, `campo_sigla`, `sistema_nome`, `sistema_sigla`, `email_ativo`, `wa_ativo`) 
                           VALUES (1, 'ASPA', 'Associação Paulista', 'ASPA', 'COMAT — Controle de Material', 'COMAT', 0, 0)");
                $log("[OK] Parâmetros institucionais inicializados.");
            }
        } catch (Exception $e) {}

        // ══════════════════════════════════════════════════════════════════════
        // ETAPA 4: SINCRONIZAÇÃO E AJUSTES DE RETROCOMPATIBILIDADE
        // ══════════════════════════════════════════════════════════════════════

        // Sincronizar depto_id inicial de cada funcionário para tabela associativa
        try {
            $db->exec("INSERT IGNORE INTO `funcionario_depto` (`funcionario_id`, `depto_id`)
                       SELECT `id`, `depto_id` FROM `funcionario` WHERE `depto_id` IS NOT NULL AND `depto_id` > 0;");
        } catch (Exception $e) {}

        // Garantir produtos com status ativo caso flag status estivesse 0 indevidamente
        try {
            $db->exec("UPDATE `produto` SET `status` = 1 WHERE `status` = 0 AND `ativo` = 1;");
        } catch (Exception $e) {}

        // Migração de campos legados
        try {
            if ($colExists('parametros', 'empresa_nome')) {
                $db->exec("UPDATE `parametros` SET `campo_nome` = COALESCE(`campo_nome`, `empresa_nome`) WHERE `campo_nome` IS NULL OR `campo_nome` = '';");
            }
            if ($colExists('requisicao', 'data_solicitacao')) {
                $db->exec("UPDATE `requisicao` SET `data_pedido` = COALESCE(`data_pedido`, `data_solicitacao`) WHERE `data_pedido` IS NULL;");
            }
            if ($colExists('correspondencia', 'data_recebimento')) {
                $db->exec("UPDATE `correspondencia` SET `data_chegada` = COALESCE(`data_chegada`, `data_recebimento`) WHERE `data_chegada` IS NULL;");
            }
            if ($colExists('correspondencia', 'destinatario_id')) {
                $db->exec("UPDATE `correspondencia` SET `func_destino_id` = COALESCE(`func_destino_id`, `destinatario_id`) WHERE `func_destino_id` IS NULL AND `destinatario_id` IS NOT NULL;");
            }
        } catch (Exception $e) {}

        // ══════════════════════════════════════════════════════════════════════
        // ETAPA 5: REGISTRO DA FLAG DE SUCESSO EM DISCO
        // ══════════════════════════════════════════════════════════════════════

        try {
            $flagFile = sys_get_temp_dir() . '/comat_db_migrated_v2_1.flag';
            @file_put_contents($flagFile, json_encode([
                'timestamp' => date('Y-m-d H:i:s'),
                'executed'  => $executed,
                'status'    => 'ok'
            ]));
        } catch (Exception $e) {}

        if ($executed === 0) {
            $log("[OK] Todas as 16 tabelas e colunas estao 100% atualizadas e sincronizadas.");
        } else {
            $log("[SUCESSO] $executed modificacoes/colunas aplicadas no banco de dados.");
        }

        return true;
    }
}

// Execução direta via CLI (ou por script bash)
if (php_sapi_name() === 'cli' || basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'db_migrate.php') {
    try {
        DatabaseMigrator::run(null, true);
    } catch (Exception $e) {
        echo "[ERRO FATAL DE MIGRACAO] " . $e->getMessage() . "\n";
        exit(1);
    }
}
