<?php
/**
 * FuncionariosController.php — CRUD de funcionários
 */

class FuncionariosController {

    private function rowWithDepto($row, $db = null) {
        if (!$row) return $row;
        if (!$db) $db = Config::getDb();

        $deptoId = $row['depto_id'] ?? null;
        $deptoDesc = $row['depto_descricao'] ?? null;
        unset($row['depto_descricao']);
        
        $row['depto'] = [
            'id' => $deptoId ? (int)$deptoId : null,
            'descricao' => $deptoDesc
        ];

        // Buscar login associado na tabela usuario se não estiver preenchido
        $loginUser = $row['login_ldap'] ?? '';
        if (!empty($row['usuario_id'])) {
            try {
                $stU = $db->prepare("SELECT login FROM usuario WHERE id = ?");
                $stU->execute([$row['usuario_id']]);
                $uRow = $stU->fetch();
                if ($uRow && !empty($uRow['login'])) {
                    $loginUser = $uRow['login'];
                }
            } catch (Exception $e) {}
        }
        $row['login'] = $loginUser;

        // Buscar departamentos associados em funcionario_depto
        $deptos = [];
        try {
            $stmtFD = $db->prepare("SELECT d.id, d.descricao 
                                    FROM departamento d 
                                    INNER JOIN funcionario_depto fd ON fd.depto_id = d.id 
                                    WHERE fd.funcionario_id = ? 
                                    ORDER BY d.descricao");
            $stmtFD->execute([$row['id']]);
            $deptos = $stmtFD->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {}

        // Se depto principal não estiver na lista, adiciona
        if ($deptoId) {
            $found = false;
            foreach ($deptos as $dp) {
                if ((int)$dp['id'] === (int)$deptoId) { $found = true; break; }
            }
            if (!$found) {
                $deptos[] = ['id' => (int)$deptoId, 'descricao' => $deptoDesc ?: 'Principal'];
            }
        }

        $row['deptos'] = $deptos;
        $row['deptos_ids'] = array_values(array_unique(array_map('intval', array_column($deptos, 'id'))));

        // Casts de tipos
        $row['id'] = (int)$row['id'];
        $row['status'] = isset($row['status']) ? (int)$row['status'] : null;
        $row['admin_estoque'] = isset($row['admin_estoque']) ? (int)$row['admin_estoque'] : null;
        $row['depto_id'] = $deptoId ? (int)$deptoId : null;
        $row['funcao'] = isset($row['funcao']) ? (int)$row['funcao'] : null;
        
        return $row;
    }

    public function listar() {
        $currentUser = Security::getCurrentUser();
        Security::checkAccess($currentUser, ["CM14"]);

        $db = Config::getDb();
        $sql = "SELECT f.*, d.descricao as depto_descricao 
                FROM funcionario f 
                LEFT JOIN departamento d ON d.id = f.depto_id 
                ORDER BY f.nome";
        $stmt = $db->query($sql);
        $rows = $stmt->fetchAll();

        $result = [];
        foreach ($rows as $row) {
            $result[] = $this->rowWithDepto($row, $db);
        }
        return $result;
    }

    public function criar() {
        $currentUser = Security::getCurrentUser();
        Security::checkAccess($currentUser, ["CM14"]);

        $d = getJsonBody();
        $nome = trim($d['nome'] ?? '');

        if (empty($nome)) {
            throw new Exception("nome é obrigatório", 400);
        }

        $db = Config::getDb();
        $hash = md5(uniqid(rand(), true));

        // Login e Senha para criação automática de usuário no sistema
        $login = trim($d['login'] ?? $d['login_ldap'] ?? '');
        if (empty($login)) {
            if (!empty($d['email'])) {
                $login = explode('@', $d['email'])[0];
            } else {
                $login = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $nome)));
            }
        }
        $senha = trim($d['senha'] ?? 'comat123');

        // Cria ou sincroniza registro correspondente na tabela usuario
        $usuarioId = null;
        try {
            $checkU = $db->prepare("SELECT id FROM usuario WHERE login = ?");
            $checkU->execute([$login]);
            $existingU = $checkU->fetch();

            if ($existingU) {
                $usuarioId = (int)$existingU['id'];
                if (!empty($d['senha'])) {
                    $upU = $db->prepare("UPDATE usuario SET senha = ?, email = ?, ativo = ?, acesso = ? WHERE id = ?");
                    $upU->execute([
                        Security::hashSenha($senha),
                        $d['email'] ?? null,
                        isset($d['status']) ? (int)$d['status'] : 1,
                        $d['acesso'] ?? '',
                        $usuarioId
                    ]);
                }
            } else {
                $insU = $db->prepare("INSERT INTO usuario (login, senha, email, nivel, ativo, acesso, hash) VALUES (?, ?, ?, 2, ?, ?, ?)");
                $insU->execute([
                    $login,
                    Security::hashSenha($senha),
                    $d['email'] ?? null,
                    isset($d['status']) ? (int)$d['status'] : 1,
                    $d['acesso'] ?? '',
                    md5(uniqid(rand(), true))
                ]);
                $usuarioId = (int)$db->lastInsertId();
            }
        } catch (Exception $e) {}

        // Determina departamento principal e departamentos adicionais
        $deptosIds = isset($d['deptos_ids']) && is_array($d['deptos_ids']) ? array_map('intval', $d['deptos_ids']) : [];
        $deptoPrincipal = !empty($d['depto_id']) ? (int)$d['depto_id'] : (!empty($deptosIds) ? $deptosIds[0] : null);

        $sql = "INSERT INTO funcionario (nome, email, login_ldap, status, admin_estoque, depto_id, acesso, funcao, hash, telefone, usuario_id) " .
               "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            $nome,
            $d['email'] ?? null,
            $login,
            isset($d['status']) ? (int)$d['status'] : 1,
            isset($d['admin_estoque']) ? (int)$d['admin_estoque'] : 0,
            $deptoPrincipal,
            $d['acesso'] ?? "",
            isset($d['funcao']) ? (int)$d['funcao'] : 1,
            $hash,
            $d['telefone'] ?? null,
            $usuarioId
        ]);

        $id = (int)$db->lastInsertId();

        // Vincula múltiplos departamentos na tabela associativa funcionario_depto
        if (!empty($deptoPrincipal) && !in_array($deptoPrincipal, $deptosIds)) {
            $deptosIds[] = $deptoPrincipal;
        }
        foreach ($deptosIds as $depId) {
            if ($depId > 0) {
                try {
                    $stFD = $db->prepare("INSERT IGNORE INTO funcionario_depto (funcionario_id, depto_id) VALUES (?, ?)");
                    $stFD->execute([$id, $depId]);
                } catch (Exception $e) {}
            }
        }

        $sqlOne = "SELECT f.*, d.descricao as depto_descricao 
                   FROM funcionario f 
                   LEFT JOIN departamento d ON d.id = f.depto_id 
                   WHERE f.id = ?";
        $stmt = $db->prepare($sqlOne);
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $this->rowWithDepto($row, $db);
    }

    public function detalhe($id) {
        $currentUser = Security::getCurrentUser();
        Security::checkAccess($currentUser, ["CM14"]);

        $db = Config::getDb();
        $sqlOne = "SELECT f.*, d.descricao as depto_descricao 
                   FROM funcionario f 
                   LEFT JOIN departamento d ON d.id = f.depto_id 
                   WHERE f.id = ?";
        $stmt = $db->prepare($sqlOne);
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        if (!$row) {
            throw new Exception("Funcionário não encontrado", 404);
        }

        return $this->rowWithDepto($row, $db);
    }

    public function atualizar($id) {
        $currentUser = Security::getCurrentUser();
        Security::checkAccess($currentUser, ["CM14"]);

        $d = getJsonBody();
        $db = Config::getDb();

        $stmt = $db->prepare("SELECT id, usuario_id, login_ldap FROM funcionario WHERE id = ?");
        $stmt->execute([$id]);
        $currentFunc = $stmt->fetch();
        if (!$currentFunc) {
            throw new Exception("Funcionário não encontrado", 404);
        }

        $login = trim($d['login'] ?? $d['login_ldap'] ?? $currentFunc['login_ldap'] ?? '');
        $usuarioId = $currentFunc['usuario_id'];

        // Atualiza ou cria usuário correspondente
        if (!empty($login)) {
            try {
                if ($usuarioId) {
                    $sqlUpU = "UPDATE usuario SET login = ?, email = ?, ativo = ?, acesso = ?" . (!empty($d['senha']) ? ", senha = ?" : "") . " WHERE id = ?";
                    $paramsUpU = [$login, $d['email'] ?? null, isset($d['status']) ? (int)$d['status'] : 1, $d['acesso'] ?? ''];
                    if (!empty($d['senha'])) {
                        $paramsUpU[] = Security::hashSenha($d['senha']);
                    }
                    $paramsUpU[] = $usuarioId;
                    $db->prepare($sqlUpU)->execute($paramsUpU);
                } else {
                    $checkU = $db->prepare("SELECT id FROM usuario WHERE login = ?");
                    $checkU->execute([$login]);
                    $rowU = $checkU->fetch();
                    if ($rowU) {
                        $usuarioId = (int)$rowU['id'];
                    } else if (!empty($d['senha'])) {
                        $insU = $db->prepare("INSERT INTO usuario (login, senha, email, nivel, ativo, acesso, hash) VALUES (?, ?, ?, 2, ?, ?, ?)");
                        $insU->execute([$login, Security::hashSenha($d['senha']), $d['email'] ?? null, isset($d['status']) ? (int)$d['status'] : 1, $d['acesso'] ?? '', md5(uniqid(rand(), true))]);
                        $usuarioId = (int)$db->lastInsertId();
                    }
                }
            } catch (Exception $e) {}
        }

        // Departamentos
        $deptosIds = isset($d['deptos_ids']) && is_array($d['deptos_ids']) ? array_map('intval', $d['deptos_ids']) : [];
        $deptoPrincipal = !empty($d['depto_id']) ? (int)$d['depto_id'] : (!empty($deptosIds) ? $deptosIds[0] : null);

        $sql = "UPDATE funcionario SET nome = ?, email = ?, login_ldap = ?, status = ?, " .
               "admin_estoque = ?, depto_id = ?, acesso = ?, funcao = ?, telefone = ?, usuario_id = ? WHERE id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            $d['nome'] ?? null,
            $d['email'] ?? null,
            $login,
            isset($d['status']) ? (int)$d['status'] : 1,
            isset($d['admin_estoque']) ? (int)$d['admin_estoque'] : 0,
            $deptoPrincipal,
            $d['acesso'] ?? "",
            isset($d['funcao']) ? (int)$d['funcao'] : 1,
            $d['telefone'] ?? null,
            $usuarioId,
            $id
        ]);

        // Sincroniza departamentos associados em funcionario_depto
        if (isset($d['deptos_ids']) || !empty($deptoPrincipal)) {
            if (!empty($deptoPrincipal) && !in_array($deptoPrincipal, $deptosIds)) {
                $deptosIds[] = $deptoPrincipal;
            }
            try {
                $delFD = $db->prepare("DELETE FROM funcionario_depto WHERE funcionario_id = ?");
                $delFD->execute([$id]);
                foreach ($deptosIds as $depId) {
                    if ($depId > 0) {
                        $insFD = $db->prepare("INSERT IGNORE INTO funcionario_depto (funcionario_id, depto_id) VALUES (?, ?)");
                        $insFD->execute([$id, $depId]);
                    }
                }
            } catch (Exception $e) {}
        }

        $sqlOne = "SELECT f.*, d.descricao as depto_descricao 
                   FROM funcionario f 
                   LEFT JOIN departamento d ON d.id = f.depto_id 
                   WHERE f.id = ?";
        $stmt = $db->prepare($sqlOne);
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $this->rowWithDepto($row, $db);
    }

    public function deletar($id) {
        $currentUser = Security::getCurrentUser();
        Security::checkAccess($currentUser, ["CM14"]);

        $db = Config::getDb();
        $stmt = $db->prepare("SELECT id, usuario_id FROM funcionario WHERE id = ?");
        $stmt->execute([$id]);
        $func = $stmt->fetch();
        if (!$func) {
            throw new Exception("Funcionário não encontrado", 404);
        }

        try {
            // Remove associações em funcionario_depto
            $db->prepare("DELETE FROM funcionario_depto WHERE funcionario_id = ?")->execute([$id]);
            
            $stmt = $db->prepare("DELETE FROM funcionario WHERE id = ?");
            $stmt->execute([$id]);
            return ["ok" => true];
        } catch (Exception $e) {
            throw new Exception($e->getMessage(), 400);
        }
    }

    public function atualizarAcesso($id) {
        $currentUser = Security::getCurrentUser();
        Security::checkAccess($currentUser, ["CM099"]);

        $d = getJsonBody();
        $db = Config::getDb();

        $stmt = $db->prepare("UPDATE funcionario SET acesso = ? WHERE id = ?");
        $stmt->execute([
            $d['acesso'] ?? "",
            $id
        ]);

        return ["ok" => true];
    }
}
