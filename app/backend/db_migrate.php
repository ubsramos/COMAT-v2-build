<?php
/**
 * db_migrate.php — Migrador Automático e Resiliente de Banco de Dados — COMAT v2
 * Executa verificações em todas as tabelas e adiciona automaticamente qualquer coluna faltante.
 */

require_once __DIR__ . '/config.php';

try {
    $db = Config::getDb();

    // Função auxiliar para verificar se coluna existe
    if (!function_exists('columnExists')) {
        function columnExists($db, $table, $column) {
            try {
                $stmt = $db->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
                return $stmt->rowCount() > 0;
            } catch (Exception $e) {
                return false;
            }
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
        ['funcionario', 'funcao', 'ALTER TABLE `funcionario` ADD COLUMN `funcao` INT NOT NULL DEFAULT 1 AFTER `acesso`'],
        ['funcionario', 'hash', 'ALTER TABLE `funcionario` ADD COLUMN `hash` VARCHAR(64) NULL AFTER `funcao`'],
        
        // Tabela: departamento
        ['departamento', 'descricao_completa', 'ALTER TABLE `departamento` ADD COLUMN `descricao_completa` TEXT NULL AFTER `descricao`'],
        ['departamento', 'conta', 'ALTER TABLE `departamento` ADD COLUMN `conta` VARCHAR(50) NULL AFTER `descricao_completa`'],
        ['departamento', 'sub_conta', 'ALTER TABLE `departamento` ADD COLUMN `sub_conta` VARCHAR(50) NULL AFTER `conta`'],
        ['departamento', 'centro_custo', 'ALTER TABLE `departamento` ADD COLUMN `centro_custo` VARCHAR(50) NULL AFTER `sub_conta`'],
        
        // Tabela: requisicao
        ['requisicao', 'data_pedido', 'ALTER TABLE `requisicao` ADD COLUMN `data_pedido` DATETIME NULL DEFAULT CURRENT_TIMESTAMP AFTER `numero`'],
        ['requisicao', 'descricao', 'ALTER TABLE `requisicao` ADD COLUMN `descricao` VARCHAR(255) NULL AFTER `data_pedido`'],
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
        ['requisicao_item', 'valor_produto', 'ALTER TABLE `requisicao_item` ADD COLUMN `valor_produto` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `qtde`'],
        
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
        ['parametros', 'wa_payload', 'ALTER TABLE `parametros` ADD COLUMN `wa_payload` TEXT NULL'],

        // Tabela: correspondencia
        ['correspondencia', 'data_chegada', 'ALTER TABLE `correspondencia` ADD COLUMN `data_chegada` DATETIME NULL DEFAULT CURRENT_TIMESTAMP AFTER `id`'],
        ['correspondencia', 'tipo', 'ALTER TABLE `correspondencia` ADD COLUMN `tipo` VARCHAR(100) NULL AFTER `data_chegada`'],
        ['correspondencia', 'rastreio', 'ALTER TABLE `correspondencia` ADD COLUMN `rastreio` VARCHAR(100) NULL AFTER `remetente`'],
        ['correspondencia', 'descricao', 'ALTER TABLE `correspondencia` ADD COLUMN `descricao` TEXT NULL AFTER `rastreio`'],
        ['correspondencia', 'func_destino_id', 'ALTER TABLE `correspondencia` ADD COLUMN `func_destino_id` INT NULL AFTER `descricao`'],
        ['correspondencia', 'depto_destino_id', 'ALTER TABLE `correspondencia` ADD COLUMN `depto_destino_id` INT NULL AFTER `func_destino_id`'],
        ['correspondencia', 'email_destino', 'ALTER TABLE `correspondencia` ADD COLUMN `email_destino` VARCHAR(255) NULL AFTER `depto_destino_id`'],
        ['correspondencia', 'recebedor_tipo', 'ALTER TABLE `correspondencia` ADD COLUMN `recebedor_tipo` VARCHAR(50) NULL DEFAULT \'almoxarifado\' AFTER `email_destino`'],
        ['correspondencia', 'recebedor_id', 'ALTER TABLE `correspondencia` ADD COLUMN `recebedor_id` INT NULL AFTER `recebedor_tipo`'],
        ['correspondencia', 'email_enviado', 'ALTER TABLE `correspondencia` ADD COLUMN `email_enviado` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`'],
        ['correspondencia', 'email_erro', 'ALTER TABLE `correspondencia` ADD COLUMN `email_erro` TEXT NULL AFTER `email_enviado`'],
        ['correspondencia', 'obs_retirada', 'ALTER TABLE `correspondencia` ADD COLUMN `obs_retirada` TEXT NULL AFTER `data_retirada`'],
        ['correspondencia', 'func_retirada_id', 'ALTER TABLE `correspondencia` ADD COLUMN `func_retirada_id` INT NULL AFTER `obs_retirada`'],
        ['correspondencia', 'retirado_por_manual', 'ALTER TABLE `correspondencia` ADD COLUMN `retirado_por_manual` VARCHAR(255) NULL AFTER `func_retirada_id`']
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

    // Criar tabela tag caso nao exista
    $db->exec("CREATE TABLE IF NOT EXISTS `tag` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `descricao` VARCHAR(100) NOT NULL,
      `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    // Sincronizar dados iniciais
    $db->exec("UPDATE `parametros` SET 
      `campo_nome` = COALESCE(`campo_nome`, `empresa_nome`, 'Associação Paulista'),
      `campo_sigla` = COALESCE(`campo_sigla`, 'ASPA'),
      `sistema_nome` = COALESCE(`sistema_nome`, 'COMAT — Controle de Material'),
      `sistema_sigla` = COALESCE(`sistema_sigla`, 'COMAT')
    WHERE id = 1;");

    $db->exec("UPDATE `requisicao` SET `data_pedido` = COALESCE(`data_pedido`, `data_solicitacao`, NOW()) WHERE `data_pedido` IS NULL;");
    $db->exec("UPDATE `correspondencia` SET `data_chegada` = COALESCE(`data_chegada`, `data_recebimento`, NOW()) WHERE `data_chegada` IS NULL;");
    $db->exec("UPDATE `correspondencia` SET `func_destino_id` = COALESCE(`func_destino_id`, `destinatario_id`) WHERE `func_destino_id` IS NULL AND `destinatario_id` IS NOT NULL;");

    if ($executed === 0) {
        echo "[OK] Todas as tabelas e colunas estao 100% atualizadas.\n";
    } else {
        echo "[SUCESSO] $executed migrações aplicadas no banco de dados.\n";
    }

} catch (Exception $e) {
    echo "[ERRO] " . $e->getMessage() . "\n";
}
