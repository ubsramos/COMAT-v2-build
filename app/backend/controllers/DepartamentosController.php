<?php
/**
 * DepartamentosController.php — CRUD de departamentos
 */

class DepartamentosController {

    public function listar() {
        $currentUser = Security::getCurrentUser(); // Valida autenticação genérica

        $db = Config::getDb();
        $stmt = $db->query("SELECT * FROM departamento ORDER BY descricao");
        return $stmt->fetchAll();
    }

    public function criar() {
        $currentUser = Security::getCurrentUser();
        Security::checkAccess($currentUser, ["CM13"]);

        $d = getJsonBody();
        $descricao = trim($d['descricao'] ?? '');

        if (empty($descricao)) {
            throw new Exception("descricao é obrigatória", 400);
        }

        $db = Config::getDb();
        $stmt = $db->prepare(
            "INSERT INTO departamento (descricao, descricao_completa, conta, sub_conta, centro_custo, user_auth) " .
            "VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $descricao,
            $d['descricao_completa'] ?? null,
            $d['conta'] ?? null,
            $d['sub_conta'] ?? null,
            $d['centro_custo'] ?? null,
            $d['user_auth'] ?? null
        ]);

        $id = $db->lastInsertId();

        $stmt = $db->prepare("SELECT * FROM departamento WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    public function detalhe($id) {
        $currentUser = Security::getCurrentUser(); // Valida autenticação genérica

        $db = Config::getDb();
        $stmt = $db->prepare("SELECT * FROM departamento WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        if (!$row) {
            throw new Exception("Departamento não encontrado", 404);
        }

        return $row;
    }

    public function atualizar($id) {
        $currentUser = Security::getCurrentUser();
        Security::checkAccess($currentUser, ["CM13"]);

        $d = getJsonBody();
        $db = Config::getDb();

        $stmt = $db->prepare("SELECT id FROM departamento WHERE id = ?");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            throw new Exception("Departamento não encontrado", 404);
        }

        $stmt = $db->prepare(
            "UPDATE departamento SET descricao = ?, descricao_completa = ?, conta = ?, " .
            "sub_conta = ?, centro_custo = ?, user_auth = ? WHERE id = ?"
        );
        $stmt->execute([
            $d['descricao'] ?? null,
            $d['descricao_completa'] ?? null,
            $d['conta'] ?? null,
            $d['sub_conta'] ?? null,
            $d['centro_custo'] ?? null,
            $d['user_auth'] ?? null,
            $id
        ]);

        $stmt = $db->prepare("SELECT * FROM departamento WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    public function deletar($id) {
        $currentUser = Security::getCurrentUser();
        Security::checkAccess($currentUser, ["CM13"]);

        $db = Config::getDb();
        $stmt = $db->prepare("SELECT id FROM departamento WHERE id = ?");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            throw new Exception("Departamento não encontrado", 404);
        }

        try {
            $stmt = $db->prepare("DELETE FROM departamento WHERE id = ?");
            $stmt->execute([$id]);
            return ["ok" => true];
        } catch (Exception $e) {
            throw new Exception($e->getMessage(), 400);
        }
    }
}
