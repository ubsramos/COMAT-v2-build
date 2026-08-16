<?php
/**
 * UsuariosController.php — CRUD de usuários locais
 */

class UsuariosController {

    public function listar() {
        $currentUser = Security::getCurrentUser();
        Security::checkAccess($currentUser, ["CM12"]);

        $db = Config::getDb();
        $stmt = $db->query("SELECT id, login, email, nivel, ativo, acesso FROM usuario ORDER BY login");
        return $stmt->fetchAll();
    }

    public function criar() {
        $currentUser = Security::getCurrentUser();
        Security::checkAccess($currentUser, ["CM12"]);

        $d = getJsonBody();
        $login = trim($d['login'] ?? '');
        $senha = $d['senha'] ?? '';

        if (empty($login) || empty($senha)) {
            throw new Exception("login e senha são obrigatórios", 400);
        }

        $db = Config::getDb();
        $stmt = $db->prepare("SELECT id FROM usuario WHERE login = ?");
        $stmt->execute([$login]);
        if ($stmt->fetch()) {
            throw new Exception("Login já existe", 400);
        }

        $email = $d['email'] ?? null;
        $nivel = isset($d['nivel']) ? (int)$d['nivel'] : 2;
        $ativo = isset($d['ativo']) ? (int)$d['ativo'] : 1;
        $acesso = $d['acesso'] ?? "";
        $hash = md5(uniqid(rand(), true));

        $stmt = $db->prepare(
            "INSERT INTO usuario (login, senha, email, nivel, ativo, acesso, hash) " .
            "VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $login,
            Security::hashSenha($senha),
            $email,
            $nivel,
            $ativo,
            $acesso,
            $hash
        ]);

        $id = $db->lastInsertId();

        $stmt = $db->prepare("SELECT id, login, email, nivel, ativo, acesso FROM usuario WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    public function detalhe($id) {
        $currentUser = Security::getCurrentUser();
        Security::checkAccess($currentUser, ["CM12"]);

        $db = Config::getDb();
        $stmt = $db->prepare("SELECT id, login, email, nivel, ativo, acesso FROM usuario WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        if (!$row) {
            throw new Exception("Usuário não encontrado", 404);
        }

        return $row;
    }

    public function atualizar($id) {
        $currentUser = Security::getCurrentUser();
        Security::checkAccess($currentUser, ["CM12"]);

        $d = getJsonBody();
        $db = Config::getDb();

        $stmt = $db->prepare("SELECT id FROM usuario WHERE id = ?");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            throw new Exception("Usuário não encontrado", 404);
        }

        $login = $d['login'] ?? null;
        $email = $d['email'] ?? null;
        $nivel = isset($d['nivel']) ? (int)$d['nivel'] : 2;
        $ativo = isset($d['ativo']) ? (int)$d['ativo'] : 1;
        $acesso = $d['acesso'] ?? "";
        $senha = $d['senha'] ?? null;

        if (!empty($senha)) {
            $stmt = $db->prepare(
                "UPDATE usuario SET login = ?, email = ?, nivel = ?, ativo = ?, acesso = ?, senha = ? WHERE id = ?"
            );
            $stmt->execute([
                $login,
                $email,
                $nivel,
                $ativo,
                $acesso,
                Security::hashSenha($senha),
                $id
            ]);
        } else {
            $stmt = $db->prepare(
                "UPDATE usuario SET login = ?, email = ?, nivel = ?, ativo = ?, acesso = ? WHERE id = ?"
            );
            $stmt->execute([
                $login,
                $email,
                $nivel,
                $ativo,
                $acesso,
                $id
            ]);
        }

        $stmt = $db->prepare("SELECT id, login, email, nivel, ativo, acesso FROM usuario WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    public function deletar($id) {
        $currentUser = Security::getCurrentUser();
        Security::checkAccess($currentUser, ["CM12"]);

        $db = Config::getDb();
        $stmt = $db->prepare("SELECT id FROM usuario WHERE id = ?");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            throw new Exception("Usuário não encontrado", 404);
        }

        try {
            $stmt = $db->prepare("DELETE FROM usuario WHERE id = ?");
            $stmt->execute([$id]);
            return ["ok" => true];
        } catch (Exception $e) {
            throw new Exception($e->getMessage(), 400);
        }
    }
}
