<?php
/**
 * MotivosController.php — CRUD de motivos
 */

class MotivosController {

    public function listar() {
        Security::getCurrentUser(); // Valida autenticação genérica

        $db = Config::getDb();
        $stmt = $db->query("SELECT * FROM motivo ORDER BY descricao");
        return $stmt->fetchAll();
    }

    public function criar() {
        $currentUser = Security::getCurrentUser();
        Security::checkAccess($currentUser, ["CM16"]);

        $d = getJsonBody();
        $descricao = trim($d['descricao'] ?? '');

        if (empty($descricao)) {
            throw new Exception("descricao é obrigatória", 400);
        }

        $db = Config::getDb();
        $stmt = $db->prepare("INSERT INTO motivo (descricao) VALUES (?)");
        $stmt->execute([$descricao]);

        $id = $db->lastInsertId();

        $stmt = $db->prepare("SELECT * FROM motivo WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    public function detalhe($id) {
        Security::getCurrentUser(); // Valida autenticação genérica

        $db = Config::getDb();
        $stmt = $db->prepare("SELECT * FROM motivo WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        if (!$row) {
            throw new Exception("Motivo não encontrado", 404);
        }

        return $row;
    }

    public function atualizar($id) {
        $currentUser = Security::getCurrentUser();
        Security::checkAccess($currentUser, ["CM16"]);

        $d = getJsonBody();
        $db = Config::getDb();

        $stmt = $db->prepare("SELECT id FROM motivo WHERE id = ?");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            throw new Exception("Motivo não encontrado", 404);
        }

        $stmt = $db->prepare("UPDATE motivo SET descricao = ? WHERE id = ?");
        $stmt->execute([$d['descricao'] ?? null, $id]);

        $stmt = $db->prepare("SELECT * FROM motivo WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    public function deletar($id) {
        $currentUser = Security::getCurrentUser();
        Security::checkAccess($currentUser, ["CM16"]);

        $db = Config::getDb();
        $stmt = $db->prepare("SELECT id FROM motivo WHERE id = ?");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            throw new Exception("Motivo não encontrado", 404);
        }

        try {
            $stmt = $db->prepare("DELETE FROM motivo WHERE id = ?");
            $stmt->execute([$id]);
            return ["ok" => true];
        } catch (Exception $e) {
            throw new Exception($e->getMessage(), 400);
        }
    }
}
