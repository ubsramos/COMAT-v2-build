<?php
/**
 * RequisicoesController.php — Fluxo completo de requisições de estoque
 */

class RequisicoesController {

    private function parseDate($s) {
        if (empty($s)) return null;
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $s, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }
        return $s;
    }

    private function fmtReq($row) {
        if (!$row) return $row;
        
        $row['id'] = (int)$row['id'];
        $row['status'] = (int)$row['status'];
        $row['motivo_id'] = $row['motivo_id'] ? (int)$row['motivo_id'] : null;
        $row['depto_destino_id'] = $row['depto_destino_id'] ? (int)$row['depto_destino_id'] : null;
        $row['depto_origem_id'] = $row['depto_origem_id'] ? (int)$row['depto_origem_id'] : null;
        $row['usuario_solicitante_id'] = $row['usuario_solicitante_id'] ? (int)$row['usuario_solicitante_id'] : null;
        $row['usuario_aprovador_id'] = $row['usuario_aprovador_id'] ? (int)$row['usuario_aprovador_id'] : null;
        $row['usuario_atendente_id'] = $row['usuario_atendente_id'] ? (int)$row['usuario_atendente_id'] : null;
        $row['entidade_id'] = $row['entidade_id'] ? (int)$row['entidade_id'] : null;

        $deptoDestDesc = $row['depto_destino_descricao'] ?? null;
        $deptoDestId = $row['depto_destino_id'];
        unset($row['depto_destino_descricao']);
        $row['depto_destino'] = [
            'id' => $deptoDestId,
            'descricao' => $deptoDestDesc
        ];

        $motivoDesc = $row['motivo_descricao'] ?? null;
        $motivoId = $row['motivo_id'];
        unset($row['motivo_descricao']);
        $row['motivo'] = [
            'id' => $motivoId,
            'descricao' => $motivoDesc
        ];

        $solicitanteId = $row['solicitante_id'] ?? null;
        $solicitanteNome = $row['solicitante_nome'] ?? null;
        unset($row['solicitante_id'], $row['solicitante_nome']);
        $row['usuario_solicitante'] = [
            'id' => $solicitanteId ? (int)$solicitanteId : null,
            'nome' => $solicitanteNome
        ];

        $aprovadorId = $row['aprovador_id'] ?? null;
        $aprovadorNome = $row['aprovador_nome'] ?? null;
        unset($row['aprovador_id'], $row['aprovador_nome']);
        $row['usuario_aprovador'] = [
            'id' => $aprovadorId ? (int)$aprovadorId : null,
            'nome' => $aprovadorNome
        ];

        $atendenteId = $row['atendente_id'] ?? null;
        $atendenteNome = $row['atendente_nome'] ?? null;
        unset($row['atendente_id'], $row['atendente_nome']);
        $row['usuario_atendente'] = [
            'id' => $atendenteId ? (int)$atendenteId : null,
            'nome' => $atendenteNome
        ];

        return $row;
    }

    private function getReqWithItens($db, $reqId) {
        $sql = "SELECT r.*,
                       dd.descricao AS depto_destino_descricao,
                       mot.descricao AS motivo_descricao,
                       sol.nome AS solicitante_nome, sol.id AS solicitante_id,
                       apr.nome AS aprovador_nome,  apr.id AS aprovador_id,
                       ate.nome AS atendente_nome,  ate.id AS atendente_id
                FROM requisicao r
                LEFT JOIN departamento dd  ON dd.id  = r.depto_destino_id
                LEFT JOIN motivo       mot ON mot.id = r.motivo_id
                LEFT JOIN funcionario  sol ON sol.id = r.usuario_solicitante_id
                LEFT JOIN funcionario  apr ON apr.id = r.usuario_aprovador_id
                LEFT JOIN funcionario  ate ON ate.id = r.usuario_atendente_id
                WHERE r.id = ?";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([$reqId]);
        $row = $stmt->fetch();

        if (!$row) return null;

        $req = $this->fmtReq($row);

        $sqlItens = "SELECT ri.*, p.descricao_resumo, p.foto,
                            p.depto_id, d.descricao AS depto_descricao
                     FROM requisicao_item ri
                     INNER JOIN produto p ON p.id = ri.produto_id
                     LEFT JOIN departamento d ON d.id = p.depto_id
                     WHERE ri.request_id = ?";
        
        $stmt = $db->prepare($sqlItens);
        $stmt->execute([$reqId]);
        $itensRaw = $stmt->fetchAll();

        $req['itens'] = [];
        foreach ($itensRaw as $item) {
            $deptoId = $item['depto_id'] ?? null;
            $deptoDesc = $item['depto_descricao'] ?? null;
            unset($item['depto_id'], $item['depto_descricao']);

            $req['itens'][] = [
                "id" => (int)$item['id'],
                "request_id" => (int)$item['request_id'],
                "produto_id" => (int)$item['produto_id'],
                "qtde" => (int)$item['qtde'],
                "status" => (int)$item['status'],
                "valor_produto" => (float)($item['valor_produto'] ?? 0),
                "produto" => [
                    "id" => (int)$item['produto_id'],
                    "descricao_resumo" => $item['descricao_resumo'],
                    "foto" => $item['foto'] ?? null,
                    "depto" => [
                        "id" => $deptoId ? (int)$deptoId : null,
                        "descricao" => $deptoDesc
                    ]
                ]
            ];
        }

        return $req;
    }

    public function listar() {
        $currentUser = Security::getCurrentUser();
        Security::checkAccess($currentUser, ["CM24", "CM21", "CM22"]);

        $data_ini = $_GET['data_ini'] ?? null;
        $data_fim = $_GET['data_fim'] ?? null;
        $status = $_GET['status'] ?? "0,1,3";

        $now = new DateTime();
        $d1 = $data_ini ? $this->parseDate($data_ini) : $now->format('Y-m') . '-01';
        $d2 = ($data_fim ? $this->parseDate($data_fim) : $now->format('Y-m-d')) . ' 23:59:59';

        $statusList = array_map('trim', explode(',', $status));
        $placeholders = implode(',', array_fill(0, count($statusList), '?'));

        $extra = "";
        $params = array_merge([$d1, $d2], $statusList);

        $db = Config::getDb();

        if ($currentUser['type'] === 'funcionario') {
            $stmt = $db->prepare("SELECT admin_estoque, depto_id FROM funcionario WHERE id = ?");
            $stmt->execute([$currentUser['id']]);
            $func = $stmt->fetch();
            if ($func && !$func['admin_estoque']) {
                $extra = " AND r.depto_destino_id = ?";
                $params[] = (int)$func['depto_id'];
            }
        }

        $sql = "SELECT r.*,
                       dd.descricao AS depto_destino_descricao,
                       mot.descricao AS motivo_descricao,
                       sol.nome AS solicitante_nome, sol.id AS solicitante_id,
                       apr.nome AS aprovador_nome,  apr.id AS aprovador_id,
                       ate.nome AS atendente_nome,  ate.id AS atendente_id
                FROM requisicao r
                LEFT JOIN departamento dd  ON dd.id  = r.depto_destino_id
                LEFT JOIN motivo       mot ON mot.id = r.motivo_id
                LEFT JOIN funcionario  sol ON sol.id = r.usuario_solicitante_id
                LEFT JOIN funcionario  apr ON apr.id = r.usuario_aprovador_id
                LEFT JOIN funcionario  ate ON ate.id = r.usuario_atendente_id
                WHERE r.data_pedido BETWEEN ? AND ?
                  AND r.status IN ($placeholders)
                  $extra
                ORDER BY r.data_pedido DESC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $result = [];
        $sqlItens = "SELECT ri.*, p.descricao_resumo FROM requisicao_item ri INNER JOIN produto p ON p.id = ri.produto_id WHERE ri.request_id = ?";
        $stmtItens = $db->prepare($sqlItens);

        foreach ($rows as $row) {
            $req = $this->fmtReq($row);
            
            $stmtItens->execute([$req['id']]);
            $itens = $stmtItens->fetchAll();
            
            $req['itens'] = [];
            foreach ($itens as $i) {
                $req['itens'][] = [
                    "id" => (int)$i['id'],
                    "request_id" => (int)$i['request_id'],
                    "produto_id" => (int)$i['produto_id'],
                    "qtde" => (int)$i['qtde'],
                    "status" => (int)$i['status'],
                    "valor_produto" => (float)($i['valor_produto'] ?? 0)
                ];
            }
            $result[] = $req;
        }

        return $result;
    }

    public function criar() {
        $currentUser = Security::getCurrentUser();
        Security::checkAccess($currentUser, ["CM21", "CM22"]);

        if ($currentUser['type'] !== 'funcionario') {
            throw new Exception("Apenas Funcionários podem criar Requisições", 403);
        }

        $d = getJsonBody();
        $db = Config::getDb();

        $stmt = $db->prepare("SELECT id, depto_id FROM funcionario WHERE id = ?");
        $stmt->execute([$currentUser['id']]);
        $func = $stmt->fetch();

        $stmt = $db->query("SELECT id FROM parametros LIMIT 1");
        $entidade = $stmt->fetch();
        $entidadeId = $entidade ? (int)$entidade['id'] : null;

        $hash = md5(uniqid(rand(), true));
        $now = date('Y-m-d H:i:s');

        $sql = "INSERT INTO requisicao (descricao, tag, numero_nf, data_pedido, hash, " .
               "motivo_id, depto_destino_id, depto_origem_id, usuario_solicitante_id, entidade_id) " .
               "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([
            $d['descricao'] ?? null,
            $d['tag'] ?? null,
            $d['numero_nf'] ?? null,
            $now,
            $hash,
            isset($d['motivo_id']) ? (int)$d['motivo_id'] : 2,
            (int)$func['depto_id'],
            (int)$func['depto_id'],
            (int)$func['id'],
            $entidadeId
        ]);

        $id = $db->lastInsertId();
        return $this->getReqWithItens($db, $id);
    }

    public function pendentes() {
        $currentUser = Security::getCurrentUser();
        Security::checkAccess($currentUser, ["CM25"]);

        $now = new DateTime();
        $d1 = $now->format('Y') . '-01-01';
        $d2 = $now->format('Y-m-d') . ' 23:59:59';

        $db = Config::getDb();
        $sql = "SELECT r.*,
                       dd.descricao AS depto_destino_descricao,
                       mot.descricao AS motivo_descricao,
                       sol.nome AS solicitante_nome, sol.id AS solicitante_id,
                       apr.nome AS aprovador_nome,  apr.id AS aprovador_id,
                       ate.nome AS atendente_nome,  ate.id AS atendente_id
                FROM requisicao r
                LEFT JOIN departamento dd  ON dd.id  = r.depto_destino_id
                LEFT JOIN motivo       mot ON mot.id = r.motivo_id
                LEFT JOIN funcionario  sol ON sol.id = r.usuario_solicitante_id
                LEFT JOIN funcionario  apr ON apr.id = r.usuario_aprovador_id
                LEFT JOIN funcionario  ate ON ate.id = r.usuario_atendente_id
                WHERE r.data_pedido BETWEEN ? AND ?
                  AND r.status = 1
                ORDER BY r.data_pedido DESC";

        $stmt = $db->prepare($sql);
        $stmt->execute([$d1, $d2]);
        $rows = $stmt->fetchAll();

        $result = [];
        $sqlItens = "SELECT ri.* FROM requisicao_item ri WHERE ri.request_id = ?";
        $stmtItens = $db->prepare($sqlItens);

        foreach ($rows as $row) {
            $req = $this->fmtReq($row);
            
            $stmtItens->execute([$req['id']]);
            $itens = $stmtItens->fetchAll();

            $req['itens'] = [];
            foreach ($itens as $i) {
                $req['itens'][] = [
                    "id" => (int)$i['id'],
                    "request_id" => (int)$i['request_id'],
                    "produto_id" => (int)$i['produto_id'],
                    "qtde" => (int)$i['qtde'],
                    "status" => (int)$i['status'],
                    "valor_produto" => (float)($i['valor_produto'] ?? 0)
                ];
            }
            $result[] = $req;
        }

        return $result;
    }

    public function detalhe($id) {
        $currentUser = Security::getCurrentUser();
        Security::checkAccess($currentUser, ["CM21", "CM22", "CM24", "CM25"]);

        $db = Config::getDb();
        $req = $this->getReqWithItens($db, $id);
        if (!$req) {
            throw new Exception("Requisição não encontrada", 404);
        }

        return $req;
    }

    public function atualizar($id) {
        $currentUser = Security::getCurrentUser();
        Security::checkAccess($currentUser, ["CM21", "CM22"]);

        $d = getJsonBody();
        $db = Config::getDb();

        $stmt = $db->prepare("SELECT id FROM requisicao WHERE id = ?");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            throw new Exception("Requisição não encontrada", 404);
        }

        $stmt = $db->prepare("UPDATE requisicao SET descricao = ?, tag = ?, numero_nf = ? WHERE id = ?");
        $stmt->execute([
            $d['descricao'] ?? null,
            $d['tag'] ?? null,
            $d['numero_nf'] ?? null,
            $id
        ]);

        return $this->getReqWithItens($db, $id);
    }

    public function deletar($id) {
        $currentUser = Security::getCurrentUser();
        Security::checkAccess($currentUser, ["CM21", "CM22"]);

        $db = Config::getDb();
        $stmt = $db->prepare("SELECT id FROM requisicao WHERE id = ?");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            throw new Exception("Requisição não encontrada", 404);
        }

        try {
            $stmt = $db->prepare("DELETE FROM requisicao WHERE id = ?");
            $stmt->execute([$id]);
            return ["ok" => true];
        } catch (Exception $e) {
            throw new Exception($e->getMessage(), 400);
        }
    }

    public function adicionarItem($id) {
        $currentUser = Security::getCurrentUser();
        Security::checkAccess($currentUser, ["CM21", "CM22"]);

        $d = getJsonBody();
        $produto_id = $d['produto_id'] ?? null;
        $qtde = (int)($d['qtde'] ?? 1);

        $db = Config::getDb();
        $stmt = $db->prepare("SELECT id, motivo_id FROM requisicao WHERE id = ?");
        $stmt->execute([$id]);
        $req = $stmt->fetch();

        if (!$req) {
            throw new Exception("Requisição não encontrada", 404);
        }

        $stmt = $db->prepare("SELECT id, qtde_estoque, valor_compra FROM produto WHERE id = ?");
        $stmt->execute([$produto_id]);
        $produto = $stmt->fetch();

        if (!$produto) {
            throw new Exception("Produto não encontrado", 404);
        }

        if ((int)$req['motivo_id'] === 2 && (int)$produto['qtde_estoque'] < $qtde) {
            throw new Exception("Estoque insuficiente. Disponível: " . $produto['qtde_estoque'], 400);
        }

        $valor = isset($d['valor_produto']) ? (float)$d['valor_produto'] : (float)($produto['valor_compra'] ?? 0.0);

        // Corrigido bug de placeholders do Python (inserido status na lista de colunas)
        $stmt = $db->prepare(
            "INSERT INTO requisicao_item (request_id, produto_id, qtde, valor_produto, status) " .
            "VALUES (?, ?, ?, ?, 0)"
        );
        $stmt->execute([
            $id,
            $produto_id,
            $qtde,
            $valor
        ]);

        $itemId = $db->lastInsertId();

        $sqlItem = "SELECT ri.*, p.descricao_resumo 
                    FROM requisicao_item ri 
                    INNER JOIN produto p ON p.id = ri.produto_id 
                    WHERE ri.id = ?";
        $stmt = $db->prepare($sqlItem);
        $stmt->execute([$itemId]);
        $item = $stmt->fetch();

        return [
            "id" => (int)$item['id'],
            "request_id" => (int)$item['request_id'],
            "produto_id" => (int)$item['produto_id'],
            "qtde" => (int)$item['qtde'],
            "status" => (int)$item['status'],
            "valor_produto" => (float)($item['valor_produto'] ?? 0),
            "produto" => [
                "id" => (int)$produto_id,
                "descricao_resumo" => $item['descricao_resumo']
            ]
        ];
    }

    public function removerItem($id, $itemId) {
        $currentUser = Security::getCurrentUser();
        Security::checkAccess($currentUser, ["CM21", "CM22"]);

        $db = Config::getDb();
        $stmt = $db->prepare("SELECT id FROM requisicao_item WHERE id = ? AND request_id = ?");
        $stmt->execute([$itemId, $id]);
        $item = $stmt->fetch();

        if (!$item) {
            throw new Exception("Item não encontrado", 404);
        }

        $stmt = $db->prepare("DELETE FROM requisicao_item WHERE id = ?");
        $stmt->execute([$itemId]);

        return ["ok" => true];
    }

    public function aprovar($id) {
        $currentUser = Security::getCurrentUser();
        Security::checkAccess($currentUser, ["CM21", "CM22"]);

        $d = getJsonBody();
        $password = $d['password'] ?? '';

        $db = Config::getDb();

        $stmt = $db->prepare("SELECT id, login_ldap, nome FROM funcionario WHERE id = ?");
        $stmt->execute([$currentUser['id']]);
        $func = $stmt->fetch();

        if (!$func) {
            throw new Exception("Precisa ser Funcionário para aprovar", 403);
        }

        $stmt = $db->prepare(
            "SELECT r.id, dep.user_auth FROM requisicao r " .
            "LEFT JOIN departamento dep ON dep.id = r.depto_destino_id " .
            "WHERE r.id = ?"
        );
        $stmt->execute([$id]);
        $req = $stmt->fetch();

        if (!$req) {
            throw new Exception("Requisição não encontrada", 404);
        }

        // Verifica autorização no departamento
        $authIds = array_filter(array_map('trim', explode(',', $req['user_auth'] ?? '')));
        if (!in_array((string)$func['id'], $authIds)) {
            throw new Exception("Usuário não autorizado a aprovar", 403);
        }

        // Valida senha
        $pwOk = false;
        if (!empty($func['login_ldap'])) {
            $pwOk = Security::tryLdapAuth($func['login_ldap'], $password);
        }
        if (!$pwOk) {
            $stmt = $db->prepare("SELECT senha FROM usuario WHERE login = ?");
            $stmt->execute([$func['login_ldap'] ?? '']);
            $u = $stmt->fetch();
            if ($u) {
                $pwOk = Security::verificarSenha($password, $u['senha']);
            }
        }
        if (!$pwOk) {
            throw new Exception("Senha inválida", 400);
        }

        $stmt = $db->prepare("UPDATE requisicao SET status = 1, usuario_aprovador_id = ? WHERE id = ?");
        $stmt->execute([$func['id'], $id]);

        // Envia notificações de aprovação de saída de material
        try {
            $stmtCfg = $db->query("SELECT email_ativo, wa_ativo FROM parametros LIMIT 1");
            $cfg = $stmtCfg->fetch();
            
            if ($cfg) {
                $email_ativo = isset($cfg['email_ativo']) ? (int)$cfg['email_ativo'] : 0;
                $wa_ativo = isset($cfg['wa_ativo']) ? (int)$cfg['wa_ativo'] : 0;
                
                if ($email_ativo || $wa_ativo) {
                    $stmtSol = $db->prepare("
                        SELECT f.nome, f.email, f.telefone 
                        FROM funcionario f 
                        WHERE f.id = (SELECT usuario_solicitante_id FROM requisicao WHERE id = ?)
                    ");
                    $stmtSol->execute([$id]);
                    $sol = $stmtSol->fetch();
                    
                    if ($sol) {
                        // Envia Email
                        if ($email_ativo && !empty($sol['email'])) {
                            require_once __DIR__ . '/../SmtpEmail.php';
                            $subj = "[COMAT] Saída de Material Autorizada (Req #$id)";
                            $body = "
                            <div style='font-family: Arial, sans-serif; padding: 20px; background-color: #f8fafc;'>
                                <div style='max-width: 600px; margin: auto; background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 24px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);'>
                                    <h2 style='color: #0f766e; margin-top: 0;'>📦 Saída de Material Autorizada!</h2>
                                    <p style='color: #334155; font-size: 15px; line-height: 1.6;'>
                                        Olá, <strong>" . htmlspecialchars($sol['nome']) . "</strong>!<br><br>
                                        A sua requisição de material <strong>#$id</strong> foi devidamente <strong>autorizada e aprovada</strong> pelo gestor!
                                    </p>
                                    <div style='margin: 20px 0; padding: 16px; background-color: #eff6ff; border-radius: 6px; border-left: 4px solid #3b82f6;'>
                                        <strong>📍 Próximo Passo:</strong><br>
                                        <span style='font-size: 14px; color: #1e40af;'>
                                            Dirija-se ao Almoxarifado para realizar a retirada física dos materiais solicitados.
                                        </span>
                                    </div>
                                    <p style='font-size: 13px; color: #64748b; margin-bottom: 0;'>
                                        Sistema COMAT — Controle de Materiais
                                    </p>
                                </div>
                            </div>";
                            SmtpEmail::sendAsync($sol['email'], $subj, $body);
                        }
                        
                        // Envia WhatsApp
                        if ($wa_ativo && !empty($sol['telefone'])) {
                            require_once __DIR__ . '/../WhatsApp.php';
                            $msg = "Olá, *" . $sol['nome'] . "*! A sua requisição de material *#$id* foi devidamente *autorizada/aprovada*! 📦\n📍 Dirija-se ao Almoxarifado para realizar a retirada física dos seus materiais.";
                            WhatsApp::sendAsync($sol['telefone'], $msg);
                        }
                    }
                }
            }
        } catch (Exception $e) {
            // Silencia erros de notificação
        }

        return ["ok" => true, "status" => 1];
    }

    public function processar($id) {
        $currentUser = Security::getCurrentUser();
        Security::checkAccess($currentUser, ["CM25"]);

        $now = date('Y-m-d H:i:s');
        $db = Config::getDb();

        $stmt = $db->prepare("SELECT id, motivo_id FROM requisicao WHERE id = ? AND status = 1");
        $stmt->execute([$id]);
        $req = $stmt->fetch();

        if (!$req) {
            throw new Exception("Requisição não encontrada ou não aprovada", 404);
        }

        // Começa uma transação para garantir integridade do estoque
        $db->beginTransaction();
        try {
            $stmt = $db->prepare("SELECT * FROM requisicao_item WHERE request_id = ? AND status = 0");
            $stmt->execute([$id]);
            $itens = $stmt->fetchAll();

            foreach ($itens as $item) {
                $delta = (int)$req['motivo_id'] === 1 ? (int)$item['qtde'] : -(int)$item['qtde'];
                $hash = md5(uniqid(rand(), true));

                // Registra movimentação
                $stmtMov = $db->prepare(
                    "INSERT INTO movimento (data, qtde, valor_produto, produto_id, request_item_id, hash) " .
                    "VALUES (?, ?, ?, ?, ?, ?)"
                );
                $stmtMov->execute([
                    $now,
                    $delta,
                    $item['valor_produto'],
                    $item['produto_id'],
                    $item['id'],
                    $hash
                ]);

                // Atualiza estoque do produto
                $stmtProd = $db->prepare("UPDATE produto SET qtde_estoque = qtde_estoque + ? WHERE id = ?");
                $stmtProd->execute([$delta, $item['produto_id']]);

                // Entrada: atualiza valor de compra
                if ((int)$req['motivo_id'] === 1) {
                    $stmtPrice = $db->prepare("UPDATE produto SET valor_compra = ? WHERE id = ?");
                    $stmtPrice->execute([$item['valor_produto'], $item['produto_id']]);
                }

                // Atualiza item da requisição
                $stmtItemUpdate = $db->prepare("UPDATE requisicao_item SET status = 1 WHERE id = ?");
                $stmtItemUpdate->execute([$item['id']]);
            }

            $funcId = $currentUser['type'] === 'funcionario' ? $currentUser['id'] : null;

            $stmtReqUpdate = $db->prepare(
                "UPDATE requisicao SET status = 3, data_atendido = ?, usuario_atendente_id = ? WHERE id = ?"
            );
            $stmtReqUpdate->execute([$now, $funcId, $id]);

            $db->commit();

            // Envia notificações de entrega/atendimento de saída de material
            try {
                $stmtCfg = $db->query("SELECT email_ativo, wa_ativo FROM parametros LIMIT 1");
                $cfg = $stmtCfg->fetch();
                
                if ($cfg) {
                    $email_ativo = isset($cfg['email_ativo']) ? (int)$cfg['email_ativo'] : 0;
                    $wa_ativo = isset($cfg['wa_ativo']) ? (int)$cfg['wa_ativo'] : 0;
                    
                    if ($email_ativo || $wa_ativo) {
                        $stmtSol = $db->prepare("
                            SELECT f.nome, f.email, f.telefone 
                            FROM funcionario f 
                            WHERE f.id = (SELECT usuario_solicitante_id FROM requisicao WHERE id = ?)
                        ");
                        $stmtSol->execute([$id]);
                        $sol = $stmtSol->fetch();
                        
                        if ($sol) {
                            // Envia Email
                            if ($email_ativo && !empty($sol['email'])) {
                                require_once __DIR__ . '/../SmtpEmail.php';
                                $subj = "[COMAT] Requisição Atendida (Req #$id)";
                                $body = "
                                <div style='font-family: Arial, sans-serif; padding: 20px; background-color: #f8fafc;'>
                                    <div style='max-width: 600px; margin: auto; background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 24px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);'>
                                        <h2 style='color: #0f766e; margin-top: 0;'>✅ Requisição de Material Entregue!</h2>
                                        <p style='color: #334155; font-size: 15px; line-height: 1.6;'>
                                            Olá, <strong>" . htmlspecialchars($sol['nome']) . "</strong>!<br><br>
                                            Os itens da sua requisição de material <strong>#$id</strong> foram retirados e a requisição foi marcada como <strong>atendida/entregue</strong>!
                                        </p>
                                        <div style='margin: 20px 0; padding: 16px; background-color: #f0fdf4; border-radius: 6px; border-left: 4px solid #22c55e;'>
                                            <strong>📊 Status da Movimentação:</strong><br>
                                            <span style='font-size: 14px; color: #15803d;'>
                                                O estoque foi atualizado e os materiais foram devidamente retirados.
                                            </span>
                                        </div>
                                        <p style='font-size: 13px; color: #64748b; margin-bottom: 0;'>
                                            Sistema COMAT — Controle de Materiais
                                        </p>
                                    </div>
                                </div>";
                                SmtpEmail::sendAsync($sol['email'], $subj, $body);
                            }
                            
                            // Envia WhatsApp
                            if ($wa_ativo && !empty($sol['telefone'])) {
                                require_once __DIR__ . '/../WhatsApp.php';
                                $msg = "Olá, *" . $sol['nome'] . "*! A sua requisição de material *#$id* foi entregue e finalizada com sucesso! ✅📦";
                                WhatsApp::sendAsync($sol['telefone'], $msg);
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                // Silencia erros de notificação
            }

            return ["ok" => true, "status" => 3];
        } catch (Exception $e) {
            $db->rollBack();
            throw new Exception($e->getMessage(), 500);
        }
    }

    public function devolver($id) {
        $currentUser = Security::getCurrentUser();
        Security::checkAccess($currentUser, ["CM25"]);

        $db = Config::getDb();
        $stmt = $db->prepare("SELECT id FROM requisicao WHERE id = ? AND status = 1");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            throw new Exception("Requisição não encontrada", 404);
        }

        $stmt = $db->prepare("UPDATE requisicao SET status = 0, usuario_aprovador_id = NULL WHERE id = ?");
        $stmt->execute([$id]);

        return ["ok" => true, "status" => 0];
    }
}
