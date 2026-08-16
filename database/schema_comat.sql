-- ==============================================================================
-- SCHEMA OFICIAL COMPLETO DO BANCO DE DADOS — COMAT v2.0
-- MySQL 8.0+ / MariaDB 10.5+
-- ==============================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET NAMES utf8mb4;

-- 1. Tabela: usuario (Operadores e Administradores)
CREATE TABLE IF NOT EXISTS `usuario` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `login` VARCHAR(100) NOT NULL UNIQUE,
  `senha` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255) NULL,
  `nivel` INT NOT NULL DEFAULT 1,
  `ativo` TINYINT(1) NOT NULL DEFAULT 1,
  `acesso` TEXT NULL,
  `hash` VARCHAR(64) NULL,
  `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Tabela: departamento (Setores da Instituição)
CREATE TABLE IF NOT EXISTS `departamento` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `descricao` VARCHAR(255) NOT NULL,
  `user_auth` VARCHAR(100) NULL,
  `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Tabela: grupo (Categorias de Insumos e Materiais)
CREATE TABLE IF NOT EXISTS `grupo` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `descricao` VARCHAR(255) NOT NULL,
  `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Tabela: motivo (Motivos de Movimentação de Estoque)
CREATE TABLE IF NOT EXISTS `motivo` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `descricao` VARCHAR(255) NOT NULL,
  `tipo` VARCHAR(20) NOT NULL DEFAULT 'SAIDA', -- SAIDA, ENTRADA, DEVOLUCAO
  `ativo` TINYINT(1) NOT NULL DEFAULT 1,
  `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Tabela: funcionario (Colaboradores e Solicitantes)
CREATE TABLE IF NOT EXISTS `funcionario` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `nome` VARCHAR(255) NOT NULL,
  `login_ldap` VARCHAR(100) NULL,
  `depto_id` INT NULL,
  `status` TINYINT(1) NOT NULL DEFAULT 1,
  `acesso` TEXT NULL,
  `admin_estoque` TINYINT(1) NOT NULL DEFAULT 0,
  `email` VARCHAR(255) NULL,
  `telefone` VARCHAR(50) NULL,
  `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_func_depto` FOREIGN KEY (`depto_id`) REFERENCES `departamento` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Tabela: produto (Catálogo de Materiais e Estoque)
CREATE TABLE IF NOT EXISTS `produto` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `codigo` VARCHAR(50) NULL,
  `codigo_barra` VARCHAR(100) NULL,
  `codigo_interno` VARCHAR(100) NULL,
  `descricao_resumo` VARCHAR(255) NOT NULL,
  `descricao_completa` TEXT NULL,
  `depto_id` INT NULL,
  `grupo_id` INT NULL,
  `unidade` VARCHAR(20) NOT NULL DEFAULT 'UN',
  `qtde_estoque` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `qtde_reservado` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `qtde_min` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `qtde_max` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `valor_compra` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `custo_medio` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `foto` VARCHAR(255) NULL,
  `status` INT NOT NULL DEFAULT 1,
  `ativo` TINYINT(1) NOT NULL DEFAULT 1,
  `entidade_id` INT NULL,
  `hash` VARCHAR(64) NULL,
  `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_prod_depto` FOREIGN KEY (`depto_id`) REFERENCES `departamento` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_prod_grupo` FOREIGN KEY (`grupo_id`) REFERENCES `grupo` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Tabela: requisicao (Cabeçalho de Requisições)
CREATE TABLE IF NOT EXISTS `requisicao` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `numero` VARCHAR(50) NULL,
  `depto_destino_id` INT NULL,
  `depto_origem_id` INT NULL,
  `departamento_id` INT NULL,
  `usuario_solicitante_id` INT NULL,
  `usuario_aprovador_id` INT NULL,
  `usuario_atendente_id` INT NULL,
  `motivo_id` INT NULL,
  `data_solicitacao` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `data_aprovacao` DATETIME NULL,
  `data_processamento` DATETIME NULL,
  `status` INT NOT NULL DEFAULT 0, -- 0: Pendente, 1: Aprovada, 2: Processada/Fechada, 3: Devolvida/Cancelada
  `observacao` TEXT NULL,
  `tipo` VARCHAR(20) NOT NULL DEFAULT 'SAIDA',
  `entidade_id` INT NULL,
  `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_req_func` FOREIGN KEY (`usuario_solicitante_id`) REFERENCES `funcionario` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_req_depto_dest` FOREIGN KEY (`depto_destino_id`) REFERENCES `departamento` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_req_motivo` FOREIGN KEY (`motivo_id`) REFERENCES `motivo` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Tabela: requisicao_item (Itens da Requisição)
CREATE TABLE IF NOT EXISTS `requisicao_item` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `request_id` INT NOT NULL,
  `produto_id` INT NOT NULL,
  `qtde` DECIMAL(12,2) NOT NULL DEFAULT 1.00,
  `quantidade` DECIMAL(12,2) NOT NULL DEFAULT 1.00,
  `valor_produto` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `valor_unitario` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `status` INT NOT NULL DEFAULT 0, -- 0: Pendente, 1: Processado, 2: Devolvido
  `motivo_devolucao` VARCHAR(255) NULL,
  CONSTRAINT `fk_item_req` FOREIGN KEY (`request_id`) REFERENCES `requisicao` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_item_prod` FOREIGN KEY (`produto_id`) REFERENCES `produto` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Tabela: correspondencia_tipo (Tipos de Correspondência / Encomenda)
CREATE TABLE IF NOT EXISTS `correspondencia_tipo` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `descricao` VARCHAR(100) NOT NULL,
  `ativo` TINYINT(1) NOT NULL DEFAULT 1,
  `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Tabela: correspondencia (Gestão de Correspondências e Encomendas da Recepção)
CREATE TABLE IF NOT EXISTS `correspondencia` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `tipo_id` INT NULL,
  `remetente` VARCHAR(255) NOT NULL,
  `destinatario_id` INT NULL,
  `destinatario_manual` VARCHAR(255) NULL,
  `ponto_recepcao` VARCHAR(100) DEFAULT 'Recepção Central',
  `status` VARCHAR(50) NOT NULL DEFAULT 'PENDENTE', -- PENDENTE, RETIRADO, DEVOLVIDO
  `data_recebimento` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `data_retirada` DATETIME NULL,
  `retirado_por` VARCHAR(255) NULL,
  `observacao` TEXT NULL,
  `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_corresp_tipo` FOREIGN KEY (`tipo_id`) REFERENCES `correspondencia_tipo` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_corresp_dest` FOREIGN KEY (`destinatario_id`) REFERENCES `funcionario` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Tabela: parametros (Configurações Gerais, SMTP e WhatsApp)
CREATE TABLE IF NOT EXISTS `parametros` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `empresa_nome` VARCHAR(255) NOT NULL DEFAULT 'COMAT — Controle de Material',
  `email_ativo` TINYINT(1) NOT NULL DEFAULT 0,
  `smtp_host` VARCHAR(255) NULL,
  `smtp_porta` INT NULL DEFAULT 587,
  `smtp_user` VARCHAR(255) NULL,
  `smtp_pass` VARCHAR(255) NULL,
  `smtp_cripto` VARCHAR(50) NULL DEFAULT 'tls',
  `email_sistema` VARCHAR(255) NULL,
  `wa_ativo` TINYINT(1) NOT NULL DEFAULT 0,
  `wa_api_url` VARCHAR(512) NULL,
  `wa_token` VARCHAR(255) NULL,
  `wa_headers` TEXT NULL,
  `wa_payload` TEXT NULL,
  `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ==============================================================================
-- CARGA INICIAL DE DADOS PADRÃO (SEED)
-- ==============================================================================

-- Usuário Administrador Padrão (Login: admin / Senha: admin123)
-- Nível 1 (Superusuário Master) com todos os módulos liberados
INSERT INTO `usuario` (`id`, `login`, `senha`, `nivel`, `ativo`, `acesso`) VALUES
(1, 'admin', '$2y$10$b2eBOXeaSBQlZ6YVPoJUv.vYRJhGrLW2KGngyJ2/LgTPnsQd1HS3y', 1, 1, 'ALL,CM0,CM11,CM12,CM13,CM14,CM15,CM16,CM17,CM21,CM22,CM24,CM25,CM31,CM32,CM40')
ON DUPLICATE KEY UPDATE `senha` = '$2y$10$b2eBOXeaSBQlZ6YVPoJUv.vYRJhGrLW2KGngyJ2/LgTPnsQd1HS3y', `nivel` = 1, `acesso` = 'ALL,CM0,CM11,CM12,CM13,CM14,CM15,CM16,CM17,CM21,CM22,CM24,CM25,CM31,CM32,CM40', `ativo` = 1;

-- Departamentos Padrão
INSERT IGNORE INTO `departamento` (`id`, `descricao`, `user_auth`) VALUES
(1, 'ALMOXARIFADO / FARMÁCIA CENTRAL', 'admin'),
(2, 'ADMINISTRAÇÃO GERAL', 'admin'),
(3, 'ENGENHARIA CLÍNICA / TI', 'admin'),
(4, 'ENFERMAGEM E ASSISTENCIAL', 'admin'),
(5, 'RECEPÇÃO E ATENDIMENTO', 'admin');

-- Grupos de Insumos Padrão
INSERT IGNORE INTO `grupo` (`id`, `descricao`) VALUES
(1, 'MATERIAIS DE ESCRITÓRIO E EXPEDIENTE'),
(2, 'MATERIAIS MÉDICO-HOSPITALARES'),
(3, 'HIGIENE, LIMPEZA E DESCARTÁVEIS'),
(4, 'EQUIPAMENTOS E INFORMÁTICA'),
(5, 'MEDICAMENTOS E SOLUÇÕES');

-- Motivos de Movimentação Padrão
INSERT IGNORE INTO `motivo` (`id`, `descricao`, `tipo`, `ativo`) VALUES
(1, 'CONSUMO SETORIAL ROTINEIRO', 'SAIDA', 1),
(2, 'ENTRADA DE NOTA FISCAL / COMPRA', 'ENTRADA', 1),
(3, 'TRANSFERÊNCIA ENTRE UNIDADES', 'SAIDA', 1),
(4, 'DEVOLUÇÃO DE SOBRA DE MATERIAL', 'ENTRADA', 1),
(5, 'DESCARTE / AVARIA / VENCIMENTO', 'SAIDA', 1);

-- Tipos de Correspondência Padrão
INSERT IGNORE INTO `correspondencia_tipo` (`id`, `descricao`, `ativo`) VALUES
(1, 'ENCOMENDA / PACOTE', 1),
(2, 'CARTA / DOCUMENTO REGISTRADO', 1),
(3, 'MALOTE INTERNO', 1),
(4, 'SEDEX / TRANSPORTADORA', 1);

-- Parâmetros Iniciais do Sistema
INSERT IGNORE INTO `parametros` (`id`, `empresa_nome`, `email_ativo`, `wa_ativo`) VALUES
(1, 'COMAT v2 — Hospital & Gestão de Materiais', 0, 0);
