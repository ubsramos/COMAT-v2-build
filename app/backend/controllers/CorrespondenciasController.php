<?php
/**
 * CorrespondenciasController.php — CRUD de recepção de correspondências e encomendas
 */

require_once __DIR__ . '/../SmtpEmail.php';

class CorrespondenciasController {

    private const TIPOS_PADRAO = [
        "Carta", "Pacote", "Celular", "Documento", "Encomenda",
        "Periódico / Revista", "Malote", "Outro"
    ];

    private function fmt($row) {
        if (!$row) return $row;

        $row['id'] = (int)$row['id'];
        $row['func_destino_id'] = $row['func_destino_id'] ? (int)$row['func_destino_id'] : null;
        $row['depto_destino_id'] = $row['depto_destino_id'] ? (int)$row['depto_destino_id'] : null;
        $row['recebedor_id'] = $row['recebedor_id'] ? (int)$row['recebedor_id'] : null;
        $row['func_retirada_id'] = $row['func_retirada_id'] ? (int)$row['func_retirada_id'] : null;
        $row['email_enviado'] = (int)$row['email_enviado'];

        foreach (['data_chegada', 'data_retirada', 'created_at', 'updated_at'] as $field) {
            if (!empty($row[$field])) {
                $d = new DateTime($row[$field]);
                $row[$field] = $d->format('Y-m-d\TH:i:s');
            }
        }
        return $row;
    }

    private function getPontoRecepcao() {
        $dow = (int)date('w'); // 0 (Domingo) a 6 (Sábado)
        // No Python: 0=Seg ... 6=Dom. dow >= 5 significa Sábado e Domingo.
        // No PHP: date('w') retorna 0=Dom, 6=Sáb. Então Sábado (6) e Domingo (0) são os finais de semana.
        return ($dow === 0 || $dow === 6) ? "portaria" : "almoxarifado";
    }

    private function resolveEmailDestino($db, $funcId, $deptoId, $ldapDomain = "") {
        if ($funcId) {
            $stmt = $db->prepare("SELECT email, login_ldap FROM funcionario WHERE id = ?");
            $stmt->execute([$funcId]);
            $row = $stmt->fetch();
            if ($row) {
                if (!empty($row['email'])) {
                    return $row['email'];
                }
                if (!empty($row['login_ldap']) && !empty($ldapDomain)) {
                    return $row['login_ldap'] . '@' . $ldapDomain;
                }
            }
        } elseif ($deptoId) {
            $stmt = $db->prepare("
                SELECT f.email, f.login_ldap
                FROM funcionario f
                JOIN departamento d ON d.user_auth = f.id
                WHERE d.id = ? LIMIT 1
            ");
            $stmt->execute([$deptoId]);
            $row = $stmt->fetch();
            if ($row && !empty($row['email'])) {
                return $row['email'];
            }
        }
        return "";
    }

    private function resolveTelefoneDestino($db, $funcId, $deptoId) {
        if ($funcId) {
            try {
                $stmt = $db->prepare("SELECT telefone FROM funcionario WHERE id = ?");
                $stmt->execute([$funcId]);
                $row = $stmt->fetch();
                if ($row && !empty($row['telefone'])) {
                    return $row['telefone'];
                }
            } catch (Exception $e) {
                // Silencia se a coluna não existir ainda no banco de dados do cliente
            }
        }
        return "";
    }

    public function listarTipos() {
        Security::getCurrentUser(); // Valida autenticação genérica
        
        $result = [];
        foreach (self::TIPOS_PADRAO as $t) {
            $result[] = ["label" => $t, "value" => $t];
        }
        return $result;
    }

    public function pontoRecepcao() {
        Security::getCurrentUser(); // Valida autenticação genérica
        return ["ponto" => $this->getPontoRecepcao()];
    }

    public function listar() {
        $currentUser = Security::getCurrentUser();
        Security::checkAccess($currentUser, ["CM40"]);

        $data_ini = $_GET['data_ini'] ?? null;
        $data_fim = $_GET['data_fim'] ?? null;
        $status = $_GET['status'] ?? null;

        $hoje = date('Y-m-d');
        $ini = $data_ini ?: "{$hoje} 00:00:00";
        $fim = $data_fim ?: "{$hoje} 23:59:59";

        if (strlen($ini) === 10) $ini .= " 00:00:00";
        if (strlen($fim) === 10) $fim .= " 23:59:59";

        $extra = "";
        $params = [$ini, $fim];

        if ($status && $status !== 'todos') {
            $extra = " AND c.status = ?";
            $params[] = $status;
        }

        $db = Config::getDb();
        $sql = "SELECT c.*,
                       f_dest.nome       AS func_destino_nome,
                       f_dest.email      AS func_destino_email,
                       f_dest.telefone   AS func_destino_telefone,
                       d_dest.descricao  AS depto_destino_nome,
                       f_rec.nome        AS recebedor_nome,
                       f_ret.nome        AS func_retirada_nome
                FROM correspondencia c
                LEFT JOIN funcionario  f_dest ON f_dest.id = c.func_destino_id
                LEFT JOIN departamento d_dest ON d_dest.id = c.depto_destino_id
                LEFT JOIN funcionario  f_rec  ON f_rec.id  = c.recebedor_id
                LEFT JOIN funcionario  f_ret  ON f_ret.id  = c.func_retirada_id
                WHERE c.data_chegada BETWEEN ? AND ?
                $extra
                ORDER BY c.data_chegada DESC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $result = [];
        foreach ($rows as $row) {
            $result[] = $this->fmt($row);
        }
        return $result;
    }

    public function stats() {
        $currentUser = Security::getCurrentUser();
        Security::checkAccess($currentUser, ["CM40"]);

        $hoje = date('Y-m-d');
        $db = Config::getDb();

        $stmt = $db->query("SELECT COUNT(*) as n FROM correspondencia WHERE status = 'aguardando'");
        $aguardando = (int)($stmt->fetch()['n'] ?? 0);

        $stmt = $db->prepare("SELECT COUNT(*) as n FROM correspondencia WHERE status = 'retirado' AND DATE(data_retirada) = ?");
        $stmt->execute([$hoje]);
        $retirados_hoje = (int)($stmt->fetch()['n'] ?? 0);

        $stmt = $db->prepare("SELECT COUNT(*) as n FROM correspondencia WHERE DATE(data_chegada) = ?");
        $stmt->execute([$hoje]);
        $total_hoje = (int)($stmt->fetch()['n'] ?? 0);

        return [
            "aguardando" => $aguardando,
            "retirados_hoje" => $retirados_hoje,
            "total_hoje" => $total_hoje
        ];
    }

    public function detalhe($id) {
        $currentUser = Security::getCurrentUser();
        Security::checkAccess($currentUser, ["CM40"]);

        $db = Config::getDb();
        $sql = "SELECT c.*,
                       f_dest.nome       AS func_destino_nome,
                       f_dest.email      AS func_destino_email,
                       d_dest.descricao  AS depto_destino_nome,
                       f_rec.nome        AS recebedor_nome,
                       f_ret.nome        AS func_retirada_nome
                FROM correspondencia c
                LEFT JOIN funcionario  f_dest ON f_dest.id = c.func_destino_id
                LEFT JOIN departamento d_dest ON d_dest.id = c.depto_destino_id
                LEFT JOIN funcionario  f_rec  ON f_rec.id  = c.recebedor_id
                LEFT JOIN funcionario  f_ret  ON f_ret.id  = c.func_retirada_id
                WHERE c.id = ?";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        if (!$row) {
            throw new Exception("Correspondência não encontrada", 404);
        }

        return $this->fmt($row);
    }

    public function criar() {
        $currentUser = Security::getCurrentUser();
        Security::checkAccess($currentUser, ["CM40"]);

        $d = getJsonBody();
        $tipo = trim($d['tipo'] ?? '');
        $recebedor_id = $d['recebedor_id'] ?? null;
        $func_id = $d['func_destino_id'] ?: null;
        $depto_id = $d['depto_destino_id'] ?: null;

        if (empty($tipo)) {
            throw new Exception("Tipo de correspondência é obrigatório", 400);
        }
        if (!$recebedor_id) {
            throw new Exception("Informe quem recebeu o objeto", 400);
        }
        if (!$func_id && !$depto_id) {
            throw new Exception("Informe o destinatário (funcionário ou departamento)", 400);
        }

        $data_chegada_raw = $d['data_chegada'] ?? null;
        if ($data_chegada_raw) {
            try {
                $data_chegada = new DateTime($data_chegada_raw);
            } catch (Exception $e) {
                $data_chegada = new DateTime();
            }
        } else {
            $data_chegada = new DateTime();
        }

        $dataChegadaStr = $data_chegada->format('Y-m-d H:i:s');
        $ponto = $d['recebedor_tipo'] ?: $this->getPontoRecepcao();

        $db = Config::getDb();

        // Resolve e-mail de destino e status dos disparos
        $stmt = $db->query("SELECT ldap_dominio_email, email_ativo, wa_ativo FROM parametros LIMIT 1");
        $param_cfg = $stmt->fetch() ?: [];
        $ldap_domain = $param_cfg['ldap_dominio_email'] ?? "";
        $email_ativo = isset($param_cfg['email_ativo']) ? (int)$param_cfg['email_ativo'] : 0;
        $wa_ativo = isset($param_cfg['wa_ativo']) ? (int)$param_cfg['wa_ativo'] : 0;

        $email_destino = $d['email_destino'] ?: $this->resolveEmailDestino($db, $func_id, $depto_id, $ldap_domain);
        $telefone_destino = !empty($d['telefone_destino']) ? $d['telefone_destino'] : $this->resolveTelefoneDestino($db, $func_id, $depto_id);

        // Se foi enviado um telefone no payload e o destino é um funcionário, atualiza o cadastro dele
        if ($func_id && !empty($d['telefone_destino'])) {
            $stmtTel = $db->prepare("SELECT telefone FROM funcionario WHERE id = ?");
            $stmtTel->execute([$func_id]);
            $telRow = $stmtTel->fetch();
            if (!$telRow || $telRow['telefone'] !== $d['telefone_destino']) {
                $stmtUpTel = $db->prepare("UPDATE funcionario SET telefone = ? WHERE id = ?");
                $stmtUpTel->execute([$d['telefone_destino'], $func_id]);
            }
        }

        $sql = "INSERT INTO correspondencia
                   (data_chegada, tipo, remetente, rastreio, descricao,
                    func_destino_id, depto_destino_id, email_destino,
                    recebedor_tipo, recebedor_id, status, email_enviado)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'aguardando', 0)";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([
            $dataChegadaStr,
            $tipo,
            $d['remetente'] ?? null,
            $d['rastreio'] ?? null,
            $d['descricao'] ?? null,
            $func_id,
            $depto_id,
            $email_destino,
            $ponto,
            $recebedor_id
        ]);

        $novoId = $db->lastInsertId();

        $sqlOne = "SELECT c.*,
                          f_dest.nome       AS func_destino_nome,
                          f_dest.email      AS func_destino_email,
                          f_dest.telefone   AS func_destino_telefone,
                          d_dest.descricao  AS depto_destino_nome,
                          f_rec.nome        AS recebedor_nome,
                          f_ret.nome        AS func_retirada_nome
                   FROM correspondencia c
                   LEFT JOIN funcionario  f_dest ON f_dest.id = c.func_destino_id
                   LEFT JOIN departamento d_dest ON d_dest.id = c.depto_destino_id
                   LEFT JOIN funcionario  f_rec  ON f_rec.id  = c.recebedor_id
                   LEFT JOIN funcionario  f_ret  ON f_ret.id  = c.func_retirada_id
                   WHERE c.id = ?";
        
        $stmt = $db->prepare($sqlOne);
        $stmt->execute([$novoId]);
        $row = $stmt->fetch();

        // Dispara e-mail de notificação
        if ($email_ativo && !empty($email_destino) && $row) {
            $dadosEmail = array_merge($row, [
                "data_chegada" => $data_chegada->format("d/m/Y às H:i"),
            ]);
            $htmlBody = SmtpEmail::buildEmailCorrespondencia($dadosEmail);
            $subject = "[COMAT] {$tipo} aguardando sua retirada";

            SmtpEmail::sendAsync($email_destino, $subject, $htmlBody, function($ok, $err) use ($db, $novoId) {
                $stmt = $db->prepare("UPDATE correspondencia SET email_enviado = ?, email_erro = ? WHERE id = ?");
                $stmt->execute([
                    $ok ? 1 : 0,
                    $err ?: null,
                    $novoId
                ]);
            });
        }

        // Dispara WhatsApp de notificação se ativo
        if ($wa_ativo && !empty($telefone_destino) && $row) {
            $local = ($row['recebedor_tipo'] ?? '') === 'portaria' ? 'Portaria' : 'Almoxarifado';
            $msg = "Olá! Uma nova correspondência do tipo *{$tipo}* (Remetente: " . ($row['remetente'] ?: 'Não informado') . ") chegou e está aguardando sua retirada no *{$local}* do COMAT.";
            
            require_once __DIR__ . '/../WhatsApp.php';
            WhatsApp::sendAsync($telefone_destino, $msg);
        }

        return $this->fmt($row);
    }

    public function atualizar($id) {
        $currentUser = Security::getCurrentUser();
        Security::checkAccess($currentUser, ["CM40"]);

        $d = getJsonBody();
        $db = Config::getDb();

        $stmt = $db->prepare("SELECT id, status FROM correspondencia WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        if (!$row) {
            throw new Exception("Correspondência não encontrada", 404);
        }
        if ($row['status'] !== 'aguardando') {
            throw new Exception("Só é possível editar correspondências aguardando", 400);
        }

        $func_id = $d['func_destino_id'] ?: null;

        // Se foi enviado um telefone no payload e o destino é um funcionário, atualiza o cadastro dele
        if ($func_id && !empty($d['telefone_destino'])) {
            $stmtTel = $db->prepare("SELECT telefone FROM funcionario WHERE id = ?");
            $stmtTel->execute([$func_id]);
            $telRow = $stmtTel->fetch();
            if (!$telRow || $telRow['telefone'] !== $d['telefone_destino']) {
                $stmtUpTel = $db->prepare("UPDATE funcionario SET telefone = ? WHERE id = ?");
                $stmtUpTel->execute([$d['telefone_destino'], $func_id]);
            }
        }

        $sql = "UPDATE correspondencia
                SET tipo = ?, remetente = ?, rastreio = ?, descricao = ?,
                    func_destino_id = ?, depto_destino_id = ?, email_destino = ?,
                    recebedor_tipo = ?, recebedor_id = ?
                WHERE id = ?";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([
            $d['tipo'] ?? null,
            $d['remetente'] ?? null,
            $d['rastreio'] ?? null,
            $d['descricao'] ?? null,
            $func_id,
            $d['depto_destino_id'] ?: null,
            $d['email_destino'] ?? null,
            $d['recebedor_tipo'] ?? null,
            $d['recebedor_id'] ?? null,
            $id
        ]);

        $sqlOne = "SELECT c.*,
                          f_dest.nome       AS func_destino_nome,
                          f_dest.email      AS func_destino_email,
                          f_dest.telefone   AS func_destino_telefone,
                          d_dest.descricao  AS depto_destino_nome,
                          f_rec.nome        AS recebedor_nome,
                          f_ret.nome        AS func_retirada_nome
                   FROM correspondencia c
                   LEFT JOIN funcionario  f_dest ON f_dest.id = c.func_destino_id
                   LEFT JOIN departamento d_dest ON d_dest.id = c.depto_destino_id
                   LEFT JOIN funcionario  f_rec  ON f_rec.id  = c.recebedor_id
                   LEFT JOIN funcionario  f_ret  ON f_ret.id  = c.func_retirada_id
                   WHERE c.id = ?";
        
        $stmt = $db->prepare($sqlOne);
        $stmt->execute([$id]);
        $updated = $stmt->fetch();

        return $this->fmt($updated);
    }

    public function registrarRetirada($id) {
        $currentUser = Security::getCurrentUser();
        Security::checkAccess($currentUser, ["CM40"]);

        $d = getJsonBody();
        $func_retirada_id = $d['func_retirada_id'] ?? null;
        if (!$func_retirada_id) {
            throw new Exception("Informe quem retirou o objeto", 400);
        }

        $db = Config::getDb();
        $stmt = $db->prepare("SELECT id, status FROM correspondencia WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        if (!$row) {
            throw new Exception("Correspondência não encontrada", 404);
        }
        if ($row['status'] !== 'aguardando') {
            throw new Exception("Esta correspondência já foi retirada ou devolvida", 400);
        }

        $now = date('Y-m-d H:i:s');
        $sql = "UPDATE correspondencia
                SET status = 'retirado', data_retirada = ?,
                    func_retirada_id = ?, obs_retirada = ?
                WHERE id = ?";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([
            $now,
            $func_retirada_id,
            $d['obs_retirada'] ?? null,
            $id
        ]);

        $sqlOne = "SELECT c.*,
                          f_dest.nome       AS func_destino_nome,
                          f_dest.email      AS func_destino_email,
                          d_dest.descricao  AS depto_destino_nome,
                          f_rec.nome        AS recebedor_nome,
                          f_ret.nome        AS func_retirada_nome
                   FROM correspondencia c
                   LEFT JOIN funcionario  f_dest ON f_dest.id = c.func_destino_id
                   LEFT JOIN departamento d_dest ON d_dest.id = c.depto_destino_id
                   LEFT JOIN funcionario  f_rec  ON f_rec.id  = c.recebedor_id
                   LEFT JOIN funcionario  f_ret  ON f_ret.id  = c.func_retirada_id
                   WHERE c.id = ?";
        
        $stmt = $db->prepare($sqlOne);
        $stmt->execute([$id]);
        $updated = $stmt->fetch();

        return $this->fmt($updated);
    }

    public function registrarDevolucao($id) {
        $currentUser = Security::getCurrentUser();
        Security::checkAccess($currentUser, ["CM40"]);

        $d = getJsonBody();
        $db = Config::getDb();

        $stmt = $db->prepare("SELECT id, status FROM correspondencia WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        if (!$row) {
            throw new Exception("Correspondência não encontrada", 404);
        }

        $sql = "UPDATE correspondencia SET status = 'devolvido', obs_retirada = ? WHERE id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            $d['obs_retirada'] ?? null,
            $id
        ]);

        $sqlOne = "SELECT c.*,
                          f_dest.nome       AS func_destino_nome,
                          f_dest.email      AS func_destino_email,
                          d_dest.descricao  AS depto_destino_nome,
                          f_rec.nome        AS recebedor_nome,
                          f_ret.nome        AS func_retirada_nome
                   FROM correspondencia c
                   LEFT JOIN funcionario  f_dest ON f_dest.id = c.func_destino_id
                   LEFT JOIN departamento d_dest ON d_dest.id = c.depto_destino_id
                   LEFT JOIN funcionario  f_rec  ON f_rec.id  = c.recebedor_id
                   LEFT JOIN funcionario  f_ret  ON f_ret.id  = c.func_retirada_id
                   WHERE c.id = ?";
        
        $stmt = $db->prepare($sqlOne);
        $stmt->execute([$id]);
        $updated = $stmt->fetch();

        return $this->fmt($updated);
    }

    public function deletar($id) {
        $currentUser = Security::getCurrentUser();
        Security::checkAccess($currentUser, ["CM41"]);

        $db = Config::getDb();
        $stmt = $db->prepare("SELECT id FROM correspondencia WHERE id = ?");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            throw new Exception("Correspondência não encontrada", 404);
        }

        $stmt = $db->prepare("DELETE FROM correspondencia WHERE id = ?");
        $stmt->execute([$id]);

        return ["ok" => true];
    }
}
