<?php
require_once __DIR__ . '/config.php';

try {
    $db = Config::getDb();

    // Função auxiliar para verificar se coluna existe
    function columnExists($db, $table, $column) {
        $stmt = $db->query("DESCRIBE $table");
        $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return in_array($column, $cols);
    }

    $alterations = [
        'email_ativo' => 'ALTER TABLE parametros ADD COLUMN email_ativo TINYINT(1) DEFAULT 0',
        'smtp_host' => 'ALTER TABLE parametros ADD COLUMN smtp_host VARCHAR(255) NULL',
        'smtp_porta' => 'ALTER TABLE parametros ADD COLUMN smtp_porta INT NULL',
        'smtp_user' => 'ALTER TABLE parametros ADD COLUMN smtp_user VARCHAR(255) NULL',
        'smtp_pass' => 'ALTER TABLE parametros ADD COLUMN smtp_pass VARCHAR(255) NULL',
        'smtp_cripto' => 'ALTER TABLE parametros ADD COLUMN smtp_cripto VARCHAR(50) NULL',
        'email_sistema' => 'ALTER TABLE parametros ADD COLUMN email_sistema VARCHAR(255) NULL',
        'wa_ativo' => 'ALTER TABLE parametros ADD COLUMN wa_ativo TINYINT(1) DEFAULT 0',
        'wa_api_url' => 'ALTER TABLE parametros ADD COLUMN wa_api_url VARCHAR(512) NULL',
        'wa_token' => 'ALTER TABLE parametros ADD COLUMN wa_token VARCHAR(255) NULL',
        'wa_headers' => 'ALTER TABLE parametros ADD COLUMN wa_headers TEXT NULL',
        'wa_payload' => 'ALTER TABLE parametros ADD COLUMN wa_payload TEXT NULL'
    ];

    foreach ($alterations as $col => $sql) {
        if (!columnExists($db, 'parametros', $col)) {
            $db->exec($sql);
            echo "Coluna '$col' adicionada com sucesso na tabela parametros.\n";
        } else {
            echo "Coluna '$col' já existe na tabela parametros.\n";
        }
    }

    // Adiciona coluna telefone na tabela funcionario se não existir
    if (!columnExists($db, 'funcionario', 'telefone')) {
        $db->exec("ALTER TABLE funcionario ADD COLUMN telefone VARCHAR(50) NULL");
        echo "Coluna 'telefone' adicionada com sucesso na tabela funcionario.\n";
    } else {
        echo "Coluna 'telefone' já existe na tabela funcionario.\n";
    }

    echo "Migração concluída com sucesso!\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
