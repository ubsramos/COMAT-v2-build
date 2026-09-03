<?php
/**
 * FuncionariosController.php — CRUD de funcionários
 */

class FuncionariosController {

    private function rowWithDepto($row) {
        if (!$row) return $row;
        $deptoId = $row['depto_id'] ?? null;
        $deptoDesc = $row['depto_descricao'] ?? null;
        unset($row['depto_descricao']);
        
        $row['depto'] = [
            'id' => $deptoId ? (int)$deptoId : null,
            'descricao' => $deptoDesc
        ];

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
            $result[] = $this->rowWithDepto($row);
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

        // Adicionada a coluna 'telefone' e mapeamento de placeholders correspondentes
        $sql = "INSERT INTO funcionario (nome, email, login_ldap, status, admin_estoque, depto_id, acesso, funcao, hash, telefone) " .
               "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            $nome,
            $d['email'] ?? null,
            $d['login_ldap'] ?? null,
            isset($d['status']) ? (int)$d['status'] : 1,
            isset($d['admin_estoque']) ? (int)$d['admin_estoque'] : 0,
            $d['depto_id'] ?? null,
            $d['acesso'] ?? "",
            isset($d['funcao']) ? (int)$d['funcao'] : 1,
            $hash,
            $d['telefone'] ?? null
        ]);

        $id = $db->lastInsertId();

        $sqlOne = "SELECT f.*, d.descricao as depto_descricao 
                   FROM funcionario f 
                   LEFT JOIN departamento d ON d.id = f.depto_id 
                   WHERE f.id = ?";
        $stmt = $db->prepare($sqlOne);
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $this->rowWithDepto($row);
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

        return $this->rowWithDepto($row);
    }

    public function atualizar($id) {
        $currentUser = Security::getCurrentUser();
        Security::checkAccess($currentUser, ["CM14"]);

        $d = getJsonBody();
        $db = Config::getDb();

        $stmt = $db->prepare("SELECT id FROM funcionario WHERE id = ?");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            throw new Exception("Funcionário não encontrado", 404);
        }

        // Adicionada a coluna 'telefone' e mapeamento de placeholders correspondentes
        $sql = "UPDATE funcionario SET nome = ?, email = ?, login_ldap = ?, status = ?, " .
               "admin_estoque = ?, depto_id = ?, acesso = ?, funcao = ?, telefone = ? WHERE id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            $d['nome'] ?? null,
            $d['email'] ?? null,
            $d['login_ldap'] ?? null,
            isset($d['status']) ? (int)$d['status'] : 1,
            isset($d['admin_estoque']) ? (int)$d['admin_estoque'] : 0,
            $d['depto_id'] ?? null,
            $d['acesso'] ?? "",
            isset($d['funcao']) ? (int)$d['funcao'] : 1,
            $d['telefone'] ?? null,
            $id
        ]);

        $sqlOne = "SELECT f.*, d.descricao as depto_descricao 
                   FROM funcionario f 
                   LEFT JOIN departamento d ON d.id = f.depto_id 
                   WHERE f.id = ?";
        $stmt = $db->prepare($sqlOne);
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $this->rowWithDepto($row);
    }

    public function deletar($id) {
        $currentUser = Security::getCurrentUser();
        Security::checkAccess($currentUser, ["CM14"]);

        $db = Config::getDb();
        $stmt = $db->prepare("SELECT id FROM funcionario WHERE id = ?");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            throw new Exception("Funcionário não encontrado", 404);
        }

        try {
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
