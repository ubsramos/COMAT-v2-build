# COMAT v2 — Guia e Instruções de Troca de Senha do Administrador

Este documento orienta os procedimentos para alteração ou recuperação de senha do usuário **admin** (ou qualquer outro operador local) no sistema **COMAT v2**.

---

## 🔒 Formato e Segurança das Senhas

O COMAT v2 utiliza o algoritmo **Bcrypt com custo adaptativo** (`PASSWORD_BCRYPT` via `password_hash()` do PHP) para armazenamento seguro no banco de dados. 

> [!NOTE]
> Não insira senhas em texto puro diretamente no banco via SQL (`UPDATE usuario SET senha = '123'`), pois o sistema rejeitará o login. Utilize sempre as instruções abaixo.

---

## 📋 Métodos Disponíveis

### Método 1: Script Automatizado Interativo *(Recomendado no Servidor)*

Na pasta da aplicação no servidor Linux, execute o script utilitário dedicado:

```bash
cd ~/DOCKER-DISTRIB
sudo bash trocar_senha_admin.sh
```

1. O script solicitará a nova senha de forma oculta (sem eco no terminal).
2. Solicitará a confirmação da nova senha.
3. Gerará o hash Bcrypt e aplicará instantaneamente no banco de dados.

*Para trocar a senha de outro usuário que não seja o `admin`, passe o login como argumento:*
```bash
sudo bash trocar_senha_admin.sh nome_do_usuario
```

---

### Método 2: Comando Direto via Container Docker (One-Liner)

Se preferir executar um único comando via Docker:

```bash
docker exec -it comat_v2_app php -r '
require "/var/www/html/backend/config.php";
$novaSenha = "SUA_NOVA_SENHA_AQUI";
$hash = password_hash($novaSenha, PASSWORD_BCRYPT);
$db = Config::getDb();
$stmt = $db->prepare("UPDATE usuario SET senha = ? WHERE login = ?");
$stmt->execute([$hash, "admin"]);
echo "\n[OK] Senha do usuário admin atualizada com sucesso!\n\n";
'
```

---

### Método 3: Reset para o Padrão de Fábrica (`admin123`)

Caso necessite restaurar a senha inicial padrão de instalação:

```bash
sudo mysql -u root -p -e "UPDATE comat_db.usuario SET senha = '\$2y\$10\$b2eBOXeaSBQlZ6YVPoJUv.vYRJhGrLW2KGngyJ2/LgTPnsQd1HS3y' WHERE login = 'admin';"
```

*Credenciais padrão restauradas:*
* **Usuário:** `admin`
* **Senha:** `admin123`

---

### Método 4: Pela Interface Web (Tela de Usuários)

1. Acesse o sistema no navegador e faça login com a conta `admin`.
2. No menu lateral, clique em **Administração ➔ Usuários**.
3. Na lista de usuários, localize o usuário `admin` e clique no botão **Editar** (ícone de lápis ✏️).
4. No campo **Senha**, digite a nova senha desejada.
5. Clique no botão **Salvar**.

---

## 🛠️ Resolução de Problemas

| Situação | Causa Provável | Solução |
| :--- | :--- | :--- |
| **"Usuário ou senha inválidos"** | Senha digitada incorretamente ou hash incompatível | Execute o `trocar_senha_admin.sh` ou o Reset padrão do Método 3. |
| **"Funcionários LDAP não possuem senha local"** | Tentativa de alterar senha de conta do Active Directory/LDAP | Senhas de funcionários LDAP devem ser alteradas no controlador de domínio Windows (AD). |
| **Erro de conexão ao rodar comando** | Container Docker parado ou MySQL inativo | Verifique com `docker ps` e `sudo systemctl status mysql`. |

---
*Documento gerado para a equipe de Infraestrutura e Administração do COMAT v2.*
