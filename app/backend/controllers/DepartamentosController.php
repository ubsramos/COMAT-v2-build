<?php
/**
 * DepartamentosController.php — CRUD de departamentos
 */

class DepartamentosController {

    public function listar() {
        $currentUser = Security::getCurrentUser(); // Valida autenticação genérica

        $db = Config::getDb();
        $stmt = $db->query("
            SELECT d.*,
                   (SELECT COUNT(*) FROM departamento_email de WHERE de.depto_id = d.id) AS total_emails
            FROM departamento d
            ORDER BY d.descricao
        ");
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

    // ─── E-mails do Departamento ─────────────────────────────────────────────

    public function listarEmails($id) {
        $currentUser = Security::getCurrentUser();
        $db = Config::getDb();

        $stmt = $db->prepare("SELECT * FROM departamento_email WHERE depto_id = ? ORDER BY id ASC");
        $stmt->execute([$id]);
        return $stmt->fetchAll();
    }

    public function adicionarEmail($id) {
        $currentUser = Security::getCurrentUser();
        Security::checkAccess($currentUser, ["CM13"]);

        $d = getJsonBody();
        $email = trim($d['email'] ?? '');
        $nome = trim($d['nome'] ?? '');

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("E-mail inválido ou não informado", 400);
        }

        $db = Config::getDb();

        // Verifica existência do depto
        $chk = $db->prepare("SELECT id FROM departamento WHERE id = ?");
        $chk->execute([$id]);
        if (!$chk->fetch()) {
            throw new Exception("Departamento não encontrado", 404);
        }

        // Evita duplicidade no mesmo departamento
        $dup = $db->prepare("SELECT id FROM departamento_email WHERE depto_id = ? AND LOWER(email) = LOWER(?)");
        $dup->execute([$id, $email]);
        if ($dup->fetch()) {
            throw new Exception("Este e-mail já está cadastrado para este departamento", 400);
        }

        $stmt = $db->prepare("INSERT INTO departamento_email (depto_id, email, nome) VALUES (?, ?, ?)");
        $stmt->execute([$id, $email, $nome ?: null]);

        $emailId = $db->lastInsertId();
        $res = $db->prepare("SELECT * FROM departamento_email WHERE id = ?");
        $res->execute([$emailId]);
        return $res->fetch();
    }

    public function removerEmail($id, $emailId) {
        $currentUser = Security::getCurrentUser();
        Security::checkAccess($currentUser, ["CM13"]);

        $db = Config::getDb();
        $stmt = $db->prepare("DELETE FROM departamento_email WHERE id = ? AND depto_id = ?");
        $stmt->execute([$emailId, $id]);

        return ["ok" => true];
    }
}
