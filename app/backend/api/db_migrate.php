<?php
/**
 * db_migrate.php — Migrador Automático e Resiliente de Banco de Dados — COMAT v2
 * Executa verificações em todas as tabelas e adiciona automaticamente qualquer coluna faltante.
 */

require_once __DIR__ . '/config.php';

try {
    $db = Config::getDb();

    // Função auxiliar para verificar se coluna existe
    function columnExists($db, $table, $column) {
        try {
            $stmt = $db->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            return false;
        }
    }

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
        
        // Tabela: requisicao
        ['requisicao', 'depto_destino_id', 'ALTER TABLE `requisicao` ADD COLUMN `depto_destino_id` INT NULL AFTER `numero`'],
        ['requisicao', 'depto_origem_id', 'ALTER TABLE `requisicao` ADD COLUMN `depto_origem_id` INT NULL AFTER `depto_destino_id`'],
        ['requisicao', 'usuario_aprovador_id', 'ALTER TABLE `requisicao` ADD COLUMN `usuario_aprovador_id` INT NULL AFTER `usuario_solicitante_id`'],
        ['requisicao', 'usuario_atendente_id', 'ALTER TABLE `requisicao` ADD COLUMN `usuario_atendente_id` INT NULL AFTER `usuario_aprovador_id`'],
        ['requisicao', 'entidade_id', 'ALTER TABLE `requisicao` ADD COLUMN `entidade_id` INT NULL AFTER `tipo`'],
        
        // Tabela: requisicao_item
        ['requisicao_item', 'qtde', 'ALTER TABLE `requisicao_item` ADD COLUMN `qtde` DECIMAL(12,2) NOT NULL DEFAULT 1.00 AFTER `produto_id`'],
        ['requisicao_item', 'valor_produto', 'ALTER TABLE `requisicao_item` ADD COLUMN `valor_produto` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `qtde`'],
        
        // Tabela: parametros
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
        if (!columnExists($db, $table, $col)) {
            $db->exec($sql);
            echo "[OK] Coluna '$col' adicionada na tabela '$table'.\n";
            $executed++;
        }
    }

    if ($executed === 0) {
        echo "[OK] Todas as tabelas e colunas estao 100% atualizadas.\n";
    } else {
        echo "[SUCESSO] $executed migrações aplicadas no banco de dados.\n";
    }

} catch (Exception $e) {
    echo "[ERRO] " . $e->getMessage() . "\n";
}
