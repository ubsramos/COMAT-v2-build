<?php
/**
 * EstoqueBackupController.php — Módulo de Backup Dinâmico e Reset/Importação de Estoque
 * Permite listar e visualizar backups históricos físicos (produto_backup_*) e
 * executar o processo de reset de estoque com substituição de catálogo via XLSX,
 * preservando a integridade referencial das Foreign Keys (requisicao_item, movimento, balanco).
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../Security.php';
require_once __DIR__ . '/../ExcelReader.php';

class EstoqueBackupController {

    private function ensureLogTable($db) {
        $sql = "CREATE TABLE IF NOT EXISTS `estoque_backup_log` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `nome_tabela` VARCHAR(100) NOT NULL UNIQUE,
          `total_produtos` INT NOT NULL DEFAULT 0,
          `qtde_total_estoque` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          `valor_total_estoque` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
          `usuario_nome` VARCHAR(255) NULL,
          `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $db->exec($sql);
    }

    /**
     * Lista todas as tabelas de backup existentes no banco de dados.
     */
    public function listarBackups() {
        $currentUser = Security::getCurrentUser();
        $db = Config::getDb();
        $this->ensureLogTable($db);

        // 1. Descobre todas as tabelas produto_backup_% fisicamente presentes no MySQL
        $stmt = $db->query("SHOW TABLES LIKE 'produto_backup_%'");
        $rawTables = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // 2. Garante que cada tabela física esteja registrada no log com suas métricas
        foreach ($rawTables as $tabela) {
            $checkStmt = $db->prepare("SELECT id FROM estoque_backup_log WHERE nome_tabela = ?");
            $checkStmt->execute([$tabela]);
            if (!$checkStmt->fetch()) {
                // Calcula estatísticas a partir da tabela física existente
                try {
                    $st = $db->query("SELECT COUNT(*) as total, 
                                             COALESCE(SUM(qtde_estoque), 0) as total_qtde, 
                                             COALESCE(SUM(qtde_estoque * valor_compra), 0) as total_valor 
                                      FROM `$tabela`");
                    $stats = $st->fetch(PDO::FETCH_ASSOC);

                    // Tenta extrair a data do nome da tabela (ex: produto_backup_20260904_113000)
                    $criadoEm = date('Y-m-d H:i:s');
                    if (preg_match('/produto_backup_(\d{4})(\d{2})(\d{2})_(\d{2})(\d{2})(\d{2})/', $tabela, $m)) {
                        $criadoEm = "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}";
                    }

                    $ins = $db->prepare("INSERT INTO estoque_backup_log 
                        (nome_tabela, total_produtos, qtde_total_estoque, valor_total_estoque, usuario_nome, criado_em)
                        VALUES (?, ?, ?, ?, 'Sistema / Migração', ?)");
                    $ins->execute([
                        $tabela,
                        (int)($stats['total'] ?? 0),
                        (float)($stats['total_qtde'] ?? 0),
                        (float)($stats['total_valor'] ?? 0),
                        $criadoEm
                    ]);
                } catch (Exception $e) {
                    // Ignora tabelas corrompidas
                }
            }
        }

        // 3. Retorna lista consolidada ordenada pelo backup mais recente
        $stmt = $db->query("SELECT * FROM estoque_backup_log ORDER BY criado_em DESC, id DESC");
        $backups = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($backups as $b) {
            // Verifica se a tabela física ainda existe
            if (in_array($b['nome_tabela'], $rawTables)) {
                $result[] = [
                    'id' => (int)$b['id'],
                    'nome_tabela' => $b['nome_tabela'],
                    'total_produtos' => (int)$b['total_produtos'],
                    'qtde_total_estoque' => (float)$b['qtde_total_estoque'],
                    'valor_total_estoque' => (float)$b['valor_total_estoque'],
                    'usuario_nome' => $b['usuario_nome'] ?: 'Administrador',
                    'criado_em' => $b['criado_em']
                ];
            }
        }

        return $result;
    }

    /**
     * Retorna os produtos armazenados dentro de uma tabela de backup específica.
     */
    public function obterDadosBackup($tabela) {
        $currentUser = Security::getCurrentUser();
        
        // Validação estrita de segurança contra SQL Injection no nome da tabela
        if (!preg_match('/^produto_backup_[0-9_]+$/', $tabela)) {
            throw new Exception("Nome de tabela de backup inválido.", 400);
        }

        $db = Config::getDb();

        // Verifica se a tabela existe
        $check = $db->query("SHOW TABLES LIKE '$tabela'")->fetch();
        if (!$check) {
            throw new Exception("A tabela de backup especificada não existe.", 404);
        }

        $sql = "SELECT p.id,
                       p.descricao_resumo,
                       p.descricao_completa,
                       p.qtde_estoque,
                       p.qtde_reservado,
                       p.valor_compra,
                       p.custo_medio,
                       p.status,
                       COALESCE(d.descricao, 'Não Definido') AS depto_nome,
                       COALESCE(g.descricao, 'Geral')        AS grupo_nome
                FROM `$tabela` p
                LEFT JOIN departamento d ON d.id = p.depto_id
                LEFT JOIN grupo        g ON g.id = p.grupo_id
                ORDER BY p.descricao_resumo ASC";

        $stmt = $db->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $produtos = [];
        foreach ($rows as $r) {
            $qtde = (float)$r['qtde_estoque'];
            $valor = (float)$r['valor_compra'];
            $produtos[] = [
                'id' => (int)$r['id'],
                'descricao' => $r['descricao_resumo'],
                'departamento' => $r['depto_nome'],
                'agrupamento' => $r['grupo_nome'],
                'qtde_estoque' => $qtde,
                'valor_unitario' => $valor,
                'valor_total' => $qtde * $valor,
                'status' => (int)$r['status'] === 1 ? 'Ativo' : 'Inativo'
            ];
        }

        return [
            'tabela' => $tabela,
            'total_itens' => count($produtos),
            'produtos' => $produtos
        ];
    }

    /**
     * Faz o parse da planilha enviada e retorna o preview estruturado para a DataGridHT.
     */
    public function previewPlanilha() {
        $currentUser = Security::getCurrentUser();

        if (empty($_FILES['file']['tmp_name'])) {
            throw new Exception("Nenhum arquivo de planilha foi enviado.", 400);
        }

        $filePath = $_FILES['file']['tmp_name'];
        $rows = ExcelReader::read($filePath);

        if (empty($rows)) {
            throw new Exception("A planilha está vazia ou ilegível.", 400);
        }

        $itens = [];
        $totalQtde = 0;
        $totalValor = 0.0;
        $first = true;

        foreach ($rows as $index => $row) {
            // Pula a linha de cabeçalho
            if ($first) {
                $first = false;
                continue;
            }

            $desc = isset($row[0]) ? trim((string)$row[0]) : '';
            if ($desc === '') {
                continue;
            }

            $depto = isset($row[1]) ? trim((string)$row[1]) : 'GERAL';
            $grupo = isset($row[2]) ? trim((string)$row[2]) : 'DIVERSOS';
            
            // Trata quantidade (limpa caracteres estranhos)
            $qtdeRaw = isset($row[3]) ? (string)$row[3] : '0';
            $qtdeRaw = str_replace(',', '.', trim($qtdeRaw));
            $qtde = (float)$qtdeRaw;

            // Trata valor unitário
            $valRaw = isset($row[4]) ? (string)$row[4] : '0';
            $valRaw = str_replace(',', '.', trim($valRaw));
            $valor = (float)$valRaw;

            $subtotal = $qtde * $valor;
            $totalQtde += $qtde;
            $totalValor += $subtotal;

            $itens[] = [
                'id' => $index,
                'linha' => $index + 1,
                'descricao' => $desc,
                'departamento' => $depto ?: 'GERAL',
                'agrupamento' => $grupo ?: 'DIVERSOS',
                'qtde' => $qtde,
                'valor_unitario' => $valor,
                'valor_total' => $subtotal
            ];
        }

        return [
            'resumo' => [
                'total_itens' => count($itens),
                'qtde_total' => $totalQtde,
                'valor_total' => $totalValor
            ],
            'itens' => $itens
        ];
    }

    /**
     * Executa a transação segura de Reset e Importação:
     * 1. Cria a tabela de backup físico clone com timestamp.
     * 2. Registra o log do backup.
     * 3. Zera o saldo de estoque e inativa os produtos que não estiverem na nova planilha
     *    (mantendo integridade de FK com requisicao_item, movimento e balanco).
     * 4. Insere/atualiza os produtos da nova planilha modelo.
     */
    public function executarResetEImportacao() {
        $currentUser = Security::getCurrentUser();
        // Apenas perfis com permissão de parâmetros ou estoque podem executar
        Security::checkAccess($currentUser, ["CM11", "CM17"]);

        if (empty($_FILES['file']['tmp_name'])) {
            throw new Exception("Arquivo da planilha obrigatório para concluir o reset.", 400);
        }

        $filePath = $_FILES['file']['tmp_name'];
        $rows = ExcelReader::read($filePath);

        if (count($rows) < 2) {
            throw new Exception("A planilha enviada não contém produtos válidos.", 400);
        }

        $db = Config::getDb();
        $this->ensureLogTable($db);

        // 1. Gera nome único para a tabela física de backup
        $timestamp = date('Ymd_His');
        $nomeTabelaBackup = "produto_backup_" . $timestamp;

        // 2. Clone físico e registro de backup ANTES de iniciar a transação
        // (No MySQL, comandos DDL como CREATE TABLE encerram transações ativas com commit implícito)
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS `$nomeTabelaBackup` LIKE `produto`");
            $db->exec("INSERT INTO `$nomeTabelaBackup` SELECT * FROM `produto`");

            // 3. Calcula métricas do snapshot para auditoria no log
            $stStats = $db->query("SELECT COUNT(*) as total, 
                                          COALESCE(SUM(qtde_estoque), 0) as total_qtde, 
                                          COALESCE(SUM(qtde_estoque * valor_compra), 0) as total_valor 
                                   FROM `produto`");
            $snapStats = $stStats->fetch(PDO::FETCH_ASSOC);

            $userName = $currentUser['nome'] ?? $currentUser['login'] ?? 'Usuário';

            $insLog = $db->prepare("INSERT INTO estoque_backup_log 
                (nome_tabela, total_produtos, qtde_total_estoque, valor_total_estoque, usuario_nome, criado_em)
                VALUES (?, ?, ?, ?, ?, NOW())");
            $insLog->execute([
                $nomeTabelaBackup,
                (int)($snapStats['total'] ?? 0),
                (float)($snapStats['total_qtde'] ?? 0),
                (float)($snapStats['total_valor'] ?? 0),
                $userName
            ]);
        } catch (Exception $e) {
            throw new Exception("Falha ao gerar o backup prévio do estoque: " . $e->getMessage(), 500);
        }

        // 4. Início da transação atômica puramente DML
        $db->beginTransaction();

        try {
            // ZERA O SALDO DE ESTOQUE mantendo os produtos ativos no catálogo (status = 1)
            // (Isso protege as 1.680 Foreign Keys históricas de requisicao_item e movimento)
            $db->exec("UPDATE `produto` SET `qtde_estoque` = 0, `qtde_reservado` = 0 WHERE `status` = 1 OR `ativo` = 1");

            // 5. Mapeia departamentos existentes no banco em memória para alta performance
            $deptos = $db->query("SELECT id, TRIM(UPPER(descricao)) as nome FROM departamento")->fetchAll(PDO::FETCH_KEY_PAIR);
            // Inverte: [NOME_UPPER => id]
            $deptosMap = array_flip($deptos);

            // Mapeia grupos existentes
            $grupos = $db->query("SELECT id, TRIM(UPPER(descricao)) as nome FROM grupo")->fetchAll(PDO::FETCH_KEY_PAIR);
            $gruposMap = array_flip($grupos);

            // Busca entidade padrão
            $paramRow = $db->query("SELECT id, entidade FROM parametros LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            $entidadeId = $paramRow ? (int)$paramRow['id'] : 1;

            $stmtInsDepto = $db->prepare("INSERT INTO departamento (descricao) VALUES (?)");
            $stmtInsGrupo = $db->prepare("INSERT INTO grupo (descricao) VALUES (?)");

            $stmtFindProd = $db->prepare("SELECT id FROM produto WHERE TRIM(UPPER(descricao_resumo)) = ? LIMIT 1");
            $stmtUpdateProd = $db->prepare("UPDATE produto SET 
                descricao_completa = ?, 
                qtde_estoque = ?, 
                valor_compra = ?, 
                custo_medio = ?, 
                status = 1, 
                depto_id = ?, 
                grupo_id = ?, 
                entidade_id = ?
                WHERE id = ?");

            $stmtInsertProd = $db->prepare("INSERT INTO produto 
                (descricao_resumo, descricao_completa, qtde_estoque, qtde_reservado, valor_compra, custo_medio, status, depto_id, grupo_id, entidade_id, hash)
                VALUES (?, ?, ?, 0, ?, ?, 1, ?, ?, ?, ?)");

            $countNovos = 0;
            $countAtualizados = 0;
            $totalValorNovo = 0.0;
            $first = true;

            foreach ($rows as $row) {
                if ($first) {
                    $first = false;
                    continue;
                }

                $desc = isset($row[0]) ? trim((string)$row[0]) : '';
                if ($desc === '') continue;

                $deptoNome = isset($row[1]) ? trim((string)$row[1]) : 'GERAL';
                if ($deptoNome === '') $deptoNome = 'GERAL';
                $deptoUpper = mb_strtoupper($deptoNome, 'UTF-8');

                $grupoNome = isset($row[2]) ? trim((string)$row[2]) : 'DIVERSOS';
                if ($grupoNome === '') $grupoNome = 'DIVERSOS';
                $grupoUpper = mb_strtoupper($grupoNome, 'UTF-8');

                $qtde = (float)str_replace(',', '.', trim((string)($row[3] ?? '0')));
                $valor = (float)str_replace(',', '.', trim((string)($row[4] ?? '0')));

                // Resolve Departamento
                if (isset($deptosMap[$deptoUpper])) {
                    $deptoId = $deptosMap[$deptoUpper];
                } else {
                    $stmtInsDepto->execute([$deptoNome]);
                    $deptoId = (int)$db->lastInsertId();
                    $deptosMap[$deptoUpper] = $deptoId;
                }

                // Resolve Grupo
                if (isset($gruposMap[$grupoUpper])) {
                    $grupoId = $gruposMap[$grupoUpper];
                } else {
                    $stmtInsGrupo->execute([$grupoNome]);
                    $grupoId = (int)$db->lastInsertId();
                    $gruposMap[$grupoUpper] = $grupoId;
                }

                $totalValorNovo += ($qtde * $valor);
                $descUpper = mb_strtoupper($desc, 'UTF-8');

                // Verifica se já existe na base
                $stmtFindProd->execute([$descUpper]);
                $prodExistente = $stmtFindProd->fetch(PDO::FETCH_ASSOC);

                if ($prodExistente) {
                    // Reativa e atualiza com os novos dados e saldo da planilha
                    $stmtUpdateProd->execute([
                        $desc,
                        $qtde,
                        $valor,
                        $valor,
                        $deptoId,
                        $grupoId,
                        $entidadeId,
                        (int)$prodExistente['id']
                    ]);
                    $countAtualizados++;
                } else {
                    // Cadastra como novo produto ativo
                    $hash = md5(uniqid($desc, true));
                    $stmtInsertProd->execute([
                        $desc,
                        $desc,
                        $qtde,
                        $valor,
                        $valor,
                        $deptoId,
                        $grupoId,
                        $entidadeId,
                        $hash
                    ]);
                    $countNovos++;
                }
            }

            // Confirma todas as operações no banco de dados
            $db->commit();

            return [
                'sucesso' => true,
                'tabela_backup' => $nomeTabelaBackup,
                'backup_produtos_anteriores' => (int)($snapStats['total'] ?? 0),
                'total_importados' => $countNovos + $countAtualizados,
                'novos_cadastrados' => $countNovos,
                'reativados_atualizados' => $countAtualizados,
                'valor_total_novo_estoque' => $totalValorNovo
            ];

        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw new Exception("Falha ao processar o reset e importação: " . $e->getMessage(), 500);
        }
    }

    /**
     * Restaura a tabela de produtos a partir de um snapshot histórico específico.
     * Cria automaticamente um novo snapshot de segurança do estado atual antes da reversão.
     */
    public function restaurarBackup() {
        $currentUser = Security::getCurrentUser();
        Security::checkAccess($currentUser, ["CM11", "CM17"]);

        $body = getJsonBody();
        $tabela = trim($body['tabela'] ?? ($_POST['tabela'] ?? ''));

        if (!preg_match('/^produto_backup_[0-9_]+$/', $tabela)) {
            throw new Exception("Nome de tabela de backup inválido.", 400);
        }

        $db = Config::getDb();

        // 1. Verifica se a tabela de backup de origem existe
        $check = $db->query("SHOW TABLES LIKE '$tabela'")->fetch();
        if (!$check) {
            throw new Exception("A tabela de backup especificada não existe.", 404);
        }

        // 2. Cria snapshot de segurança do estado atual antes de restaurar
        $preRestoreTabela = 'produto_backup_' . date('Ymd_His');
        $db->exec("CREATE TABLE `$preRestoreTabela` LIKE produto");
        $db->exec("INSERT INTO `$preRestoreTabela` SELECT * FROM produto");

        $statsPre = $db->query("SELECT COUNT(*) as total, 
                                       COALESCE(SUM(qtde_estoque), 0) as total_qtde,
                                       COALESCE(SUM(qtde_estoque * valor_compra), 0) as total_valor 
                                FROM `$preRestoreTabela`")->fetch(PDO::FETCH_ASSOC);

        $usuarioNome = $currentUser['nome'] ?? 'Administrador';
        $insLog = $db->prepare("INSERT INTO estoque_backup_log 
            (nome_tabela, total_produtos, qtde_total_estoque, valor_total_estoque, usuario_nome, criado_em)
            VALUES (?, ?, ?, ?, ?, NOW())");
        $insLog->execute([
            $preRestoreTabela,
            (int)($statsPre['total'] ?? 0),
            (float)($statsPre['total_qtde'] ?? 0),
            (float)($statsPre['total_valor'] ?? 0),
            $usuarioNome . ' (Pré-Restauração)'
        ]);

        // 3. Executa a restauração dentro de uma transação segura
        $db->beginTransaction();
        try {
            // Inativa e zera produtos atuais que não constem no backup selecionado
            $db->exec("UPDATE produto SET qtde_estoque = 0, status = 0 WHERE id NOT IN (SELECT id FROM `$tabela`)");

            // Restaura/atualiza todos os registros do backup de origem
            $sqlRestore = "INSERT INTO produto (
                id, descricao_resumo, descricao_completa, qtde_estoque, qtde_reservado,
                valor_compra, custo_medio, codigo_barra, codigo_interno, status,
                hash, grupo_id, depto_id, foto, entidade_id
            )
            SELECT 
                id, descricao_resumo, descricao_completa, qtde_estoque, qtde_reservado,
                valor_compra, custo_medio, codigo_barra, codigo_interno, status,
                hash, grupo_id, depto_id, foto, entidade_id
            FROM `$tabela`
            ON DUPLICATE KEY UPDATE
                descricao_resumo = VALUES(descricao_resumo),
                descricao_completa = VALUES(descricao_completa),
                qtde_estoque = VALUES(qtde_estoque),
                qtde_reservado = VALUES(qtde_reservado),
                valor_compra = VALUES(valor_compra),
                custo_medio = VALUES(custo_medio),
                codigo_barra = VALUES(codigo_barra),
                codigo_interno = VALUES(codigo_interno),
                status = VALUES(status),
                grupo_id = VALUES(grupo_id),
                depto_id = VALUES(depto_id),
                foto = VALUES(foto),
                entidade_id = VALUES(entidade_id)";

            $db->exec($sqlRestore);

            $db->commit();

            // Estatísticas do estado restaurado
            $statsPos = $db->query("SELECT COUNT(*) as total, 
                                           COALESCE(SUM(qtde_estoque), 0) as total_qtde,
                                           COALESCE(SUM(qtde_estoque * valor_compra), 0) as total_valor 
                                    FROM produto WHERE status = 1")->fetch(PDO::FETCH_ASSOC);

            return [
                'sucesso' => true,
                'tabela_restaurada' => $tabela,
                'snapshot_seguranca' => $preRestoreTabela,
                'total_produtos_ativos' => (int)($statsPos['total'] ?? 0),
                'qtde_total_estoque' => (float)($statsPos['total_qtde'] ?? 0),
                'valor_total_estoque' => (float)($statsPos['total_valor'] ?? 0),
                'mensagem' => "Estoque restaurado com sucesso a partir de {$tabela}!"
            ];
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw new Exception("Erro ao restaurar dados do backup: " . $e->getMessage(), 500);
        }
    }
}
