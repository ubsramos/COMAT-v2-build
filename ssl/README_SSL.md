# Gerenciamento de Certificado SSL / HTTPS — COMAT v2

Esta pasta e o local oficial para colocar os certificados de seguranca SSL/TLS do servidor.

---

## Estrutura de Arquivos Esperada

Quando o parametro `USAR_SSL="sim"` estiver ativado no script de instalacao, o Nginx utilizara os seguintes arquivos:

- **`comat.crt`**: Certificado publico (ou `fullchain.pem` emitido por sua Autoridade Certificadora / Let's Encrypt / DigiCert).
- **`comat.key`**: Chave privada correspondente (ou `privkey.pem`).

---

## Como Obter / Configurar o Certificado

### Opcao 1: Certificado Oficial (Let's Encrypt / Dominio Proprio)
Se você possui um dominio registrado (ex: `comat.minhaempresa.com.br`):
1. Gere seu certificado via Certbot / Let's Encrypt ou adquira um certificado comercial.
2. Copie o arquivo de certificado para `DOCKER-DISTRIB/ssl/comat.crt`.
3. Copie o arquivo da chave privada para `DOCKER-DISTRIB/ssl/comat.key`.
4. No arquivo `install_server.sh`, configure `USAR_SSL="sim"`.

---

### Opcao 2: Gerar Certificado Autoassinado (Apenas para Testes Internos)
Se voce estiver em ambiente de rede local/homologacao sem dominio publico e quiser testar HTTPS:
Execute no terminal dentro desta pasta `ssl/`:

```bash
openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
  -keyout comat.key \
  -out comat.crt \
  -subj "/C=BR/ST=SP/L=SaoPaulo/O=COMAT/CN=192.168.15.4"
```

---

## Status Atual
Por padrao de fábrica, o sistema vem configurado com **`USAR_SSL="nao"`** (atendendo em HTTP porta 80).
Ao alterar para **`USAR_SSL="sim"`**, o Nginx redirecionara automaticamente todo o trafego HTTP (porta 80) para HTTPS seguro (porta 443).
