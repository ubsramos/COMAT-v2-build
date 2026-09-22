<?php
/**
 * AuthController.php — Login local + LDAP
 */

class AuthController {

    public function login() {
        $data = getJsonBody();
        $username = trim($data['username'] ?? '');
        $password = $data['password'] ?? '';

        if (empty($username) || empty($password)) {
            throw new Exception("Informe usuário e senha", 400);
        }

        $db = Config::getDb();

        // 1. Tenta Usuário local
        $stmt = $db->prepare("SELECT id, login, senha, nivel, ativo, acesso FROM usuario WHERE login = ? AND ativo = 1");
        $stmt->execute([$username]);
        $usuario = $stmt->fetch();

        if ($usuario && Security::verificarSenha($password, $usuario['senha'])) {
            $token = Security::gerarToken($usuario['id'], 'usuario');
            $info = Security::getUserDeptosAndName($db, (int)$usuario['id'], 'usuario', $usuario['login'], (int)$usuario['nivel'], $usuario['acesso'] ?? '');
            return [
                "access_token" => $token,
                "token_type" => "bearer",
                "user" => [
                    "id"            => (int)$usuario['id'],
                    "login"         => $usuario['login'],
                    "nome"          => $info['nome'],
                    "nivel"         => (int)$usuario['nivel'],
                    "type"          => "usuario",
                    "acesso"        => $usuario['acesso'] ?: "",
                    "todos_deptos"  => $info['todos_deptos'],
                    "departamentos" => $info['departamentos'],
                    "deptos_nomes"  => $info['deptos_nomes'],
                ]
            ];
        }

        // 2. Tenta Funcionário via LDAP
        $stmt = $db->prepare(
            "SELECT id, nome, login_ldap, acesso, admin_estoque, depto_id " .
            "FROM funcionario WHERE login_ldap = ? AND status = 1"
        );
        $stmt->execute([$username]);
        $func = $stmt->fetch();

        if ($func && Security::tryLdapAuth($username, $password)) {
            $token = Security::gerarToken($func['id'], 'funcionario');
            $info = Security::getUserDeptosAndName($db, (int)$func['id'], 'funcionario', $func['nome'], 9, $func['acesso'] ?? '', $func['depto_id'], $func['nome']);
            return [
                "access_token" => $token,
                "token_type" => "bearer",
                "user" => [
                    "id"            => (int)$func['id'],
                    "login"         => $func['nome'],
                    "nome"          => $func['nome'],
                    "nivel"         => 9,
                    "type"          => "funcionario",
                    "acesso"        => $func['acesso'] ?: "",
                    "depto_id"      => $func['depto_id'] ? (int)$func['depto_id'] : null,
                    "admin_estoque" => (int)($func['admin_estoque'] ?? 0),
                    "todos_deptos"  => $info['todos_deptos'],
                    "departamentos" => $info['departamentos'],
                    "deptos_nomes"  => $info['deptos_nomes'],
                ]
            ];
        }

        throw new Exception("Usuário ou senha inválidos", 401);
    }

    public function me() {
        $currentUser = Security::getCurrentUser();
        return $currentUser;
    }

    public function trocarSenha() {
        $currentUser = Security::getCurrentUser();
        $data = getJsonBody();
        $senhaAtual = $data['senha_atual'] ?? '';
        $senhaNova = $data['senha_nova'] ?? '';
        $senhaConf = $data['senha_conf'] ?? '';

        if (empty($senhaAtual) || empty($senhaNova)) {
            throw new Exception("Informe a senha atual e a nova senha", 400);
        }
        if ($senhaNova !== $senhaConf) {
            throw new Exception("Nova senha e confirmação não coincidem", 400);
        }
        if (strlen($senhaNova) < 4) {
            throw new Exception("A nova senha deve ter ao menos 4 caracteres", 400);
        }

        $userId = $currentUser['id'];
        $userType = $currentUser['type'] ?? 'usuario';

        if ($userType !== 'usuario') {
            throw new Exception("Funcionários LDAP não possuem senha local para alterar", 400);
        }

        $db = Config::getDb();
        $stmt = $db->prepare("SELECT senha FROM usuario WHERE id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        if (!$row || !Security::verificarSenha($senhaAtual, $row['senha'])) {
            throw new Exception("Senha atual incorreta", 401);
        }

        $hashed = Security::hashSenha($senhaNova);
        $stmt = $db->prepare("UPDATE usuario SET senha = ? WHERE id = ?");
        $stmt->execute([$hashed, $userId]);

        return ["detail" => "Senha alterada com sucesso!"];
    }
}
