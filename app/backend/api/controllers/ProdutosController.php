<?php
/**
 * ProdutosController.php — CRUD de produtos, upload de foto e importador XLSX nativo
 */

require_once __DIR__ . '/../ExcelReader.php';

class ProdutosController {

    private $access = ["CM17", "CM21", "CM22", "CM24", "CM25"];

    private function fmt($row) {
        if (!$row) return $row;
        
        $row['id'] = (int)$row['id'];
        $row['depto_id'] = $row['depto_id'] ? (int)$row['depto_id'] : null;
        $row['grupo_id'] = $row['grupo_id'] ? (int)$row['grupo_id'] : null;
        $row['qtde_estoque'] = isset($row['qtde_estoque']) ? (int)$row['qtde_estoque'] : 0;
        $row['qtde_reservado'] = isset($row['qtde_reservado']) ? (int)$row['qtde_reservado'] : 0;
        $row['status'] = isset($row['status']) ? (int)$row['status'] : 1;
        $row['entidade_id'] = $row['entidade_id'] ? (int)$row['entidade_id'] : null;
        $row['valor_compra'] = (float)($row['valor_compra'] ?? 0);
        $row['custo_medio'] = (float)($row['custo_medio'] ?? 0);

        $deptoDesc = $row['depto_descricao'] ?? null;
        unset($row['depto_descricao']);
        $row['depto'] = [
            'id' => $row['depto_id'],
            'descricao' => $deptoDesc
        ];

        $grupoDesc = $row['grupo_descricao'] ?? null;
        unset($row['grupo_descricao']);
        $row['grupo'] = [
            'id' => $row['grupo_id'],
            'descricao' => $grupoDesc
        ];

        return $row;
    }

    public function listar() {
        $currentUser = Security::getCurrentUser();
        Security::checkAccess($currentUser, $this->access);

        $depto_id = isset($_GET['depto_id']) && $_GET['depto_id'] !== '' ? (int)$_GET['depto_id'] : null;
        $grupo_id = isset($_GET['grupo_id']) && $_GET['grupo_id'] !== '' ? (int)$_GET['grupo_id'] : null;
        $search = isset($_GET['search']) ? trim($_GET['search']) : null;

        $whereParts = [];
        $params = [];

        if ($depto_id !== null) {
            $whereParts[] = "p.depto_id = ?";
            $params[] = $depto_id;
        }
        if ($grupo_id !== null) {
            $whereParts[] = "p.grupo_id = ?";
            $params[] = $grupo_id;
        }
        if ($search !== null && $search !== '') {
            $whereParts[] = "(p.descricao_resumo LIKE ? OR p.descricao_completa LIKE ? OR p.codigo_interno LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        $where = !empty($whereParts) ? "WHERE " . implode(" AND ", $whereParts) : "";

        $db = Config::getDb();
        $sql = "SELECT p.*,
                       d.descricao AS depto_descricao,
                       g.descricao AS grupo_descricao
                FROM produto p
                LEFT JOIN departamento d ON d.id = p.depto_id
                LEFT JOIN grupo        g ON g.id = p.grupo_id
                $where
                ORDER BY p.descricao_resumo";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $result = [];
        foreach ($rows as $row) {
            $result[] = $this->fmt($row);
        }
        return $result;
    }

    public function criar() {
        $currentUser = Security::getCurrentUser();
        Security::checkAccess($currentUser, ["CM17"]);

        $d = getJsonBody();
        $descResumo = trim($d['descricao_resumo'] ?? '');
        $descCompleta = trim($d['descricao_completa'] ?? '');

        if (empty($descResumo) || empty($descCompleta)) {
            throw new Exception("descricao_resumo e descricao_completa são obrigatórias", 400);
        }

        $db = Config::getDb();
        
        // Busca entidade padrão
        $stmt = $db->query("SELECT id FROM parametros LIMIT 1");
        $entidade = $stmt->fetch();
        $entidadeId = $entidade ? (int)$entidade['id'] : null;

        $hash = md5(uniqid(rand(), true));

        // Corrigido bug do Python: adicionada a coluna 'status' e garantido os 13 placeholders correspondentes
        $sql = "INSERT INTO produto (descricao_resumo, descricao_completa, qtde_estoque, qtde_reservado, " .
               "valor_compra, custo_medio, codigo_barra, codigo_interno, status, depto_id, grupo_id, entidade_id, hash) " .
               "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([
            $descResumo,
            $descCompleta,
            isset($d['qtde_estoque']) ? (int)$d['qtde_estoque'] : 0,
            isset($d['qtde_reservado']) ? (int)$d['qtde_reservado'] : 0,
            isset($d['valor_compra']) ? (float)$d['valor_compra'] : 0.0,
            isset($d['custo_medio']) ? (float)$d['custo_medio'] : 0.0,
            $d['codigo_barra'] ?? null,
            $d['codigo_interno'] ?? null,
            isset($d['status']) ? (int)$d['status'] : 1,
            $d['depto_id'] ?? null,
            $d['grupo_id'] ?? null,
            $entidadeId,
            $hash
        ]);

        $id = $db->lastInsertId();

        $sqlOne = "SELECT p.*,
                          d.descricao AS depto_descricao,
                          g.descricao AS grupo_descricao
                   FROM produto p
                   LEFT JOIN departamento d ON d.id = p.depto_id
                   LEFT JOIN grupo        g ON g.id = p.grupo_id
                   WHERE p.id = ?";
        $stmt = $db->prepare($sqlOne);
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $this->fmt($row);
    }

    public function detalhe($id) {
        $currentUser = Security::getCurrentUser();
        Security::checkAccess($currentUser, $this->access);

        $db = Config::getDb();
        $sqlOne = "SELECT p.*,
                          d.descricao AS depto_descricao,
                          g.descricao AS grupo_descricao
                   FROM produto p
                   LEFT JOIN departamento d ON d.id = p.depto_id
                   LEFT JOIN grupo        g ON g.id = p.grupo_id
                   WHERE p.id = ?";
        $stmt = $db->prepare($sqlOne);
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        if (!$row) {
            throw new Exception("Produto não encontrado", 404);
        }

        return $this->fmt($row);
    }

    public function atualizar($id) {
        $currentUser = Security::getCurrentUser();
        Security::checkAccess($currentUser, ["CM17"]);

        $d = getJsonBody();
        $db = Config::getDb();

        $stmt = $db->prepare("SELECT id FROM produto WHERE id = ?");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            throw new Exception("Produto não encontrado", 404);
        }

        $sql = "UPDATE produto SET descricao_resumo = ?, descricao_completa = ?, qtde_estoque = ?, " .
               "valor_compra = ?, custo_medio = ?, codigo_barra = ?, codigo_interno = ?, " .
               "status = ?, depto_id = ?, grupo_id = ? WHERE id = ?";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([
            $d['descricao_resumo'] ?? null,
            $d['descricao_completa'] ?? null,
            isset($d['qtde_estoque']) ? (int)$d['qtde_estoque'] : 0,
            isset($d['valor_compra']) ? (float)$d['valor_compra'] : 0.0,
            isset($d['custo_medio']) ? (float)$d['custo_medio'] : 0.0,
            $d['codigo_barra'] ?? null,
            $d['codigo_interno'] ?? null,
            isset($d['status']) ? (int)$d['status'] : 1,
            $d['depto_id'] ?? null,
            $d['grupo_id'] ?? null,
            $id
        ]);

        $sqlOne = "SELECT p.*,
                          d.descricao AS depto_descricao,
                          g.descricao AS grupo_descricao
                   FROM produto p
                   LEFT JOIN departamento d ON d.id = p.depto_id
                   LEFT JOIN grupo        g ON g.id = p.grupo_id
                   WHERE p.id = ?";
        $stmt = $db->prepare($sqlOne);
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $this->fmt($row);
    }

    public function uploadFoto($id) {
        $currentUser = Security::getCurrentUser();
        Security::checkAccess($currentUser, ["CM17"]);

        $db = Config::getDb();
        $stmt = $db->prepare("SELECT id FROM produto WHERE id = ?");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            throw new Exception("Produto não encontrado", 404);
        }

        if (empty($_FILES['foto']['tmp_name'])) {
            throw new Exception("Arquivo de foto não enviado.", 400);
        }

        $uploadDir = __DIR__ . '/../../uploads/produto';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $origName = $_FILES['foto']['name'] ?? 'foto.jpg';
        $ext = pathinfo($origName, PATHINFO_EXTENSION);
        if (empty($ext)) {
            $ext = 'jpg';
        }
        
        $fname = md5(uniqid(rand(), true)) . '.' . $ext;
        $path = $uploadDir . '/' . $fname;

        if (!move_uploaded_file($_FILES['foto']['tmp_name'], $path)) {
            throw new Exception("Falha ao salvar o arquivo de foto.", 500);
        }

        $fotoUrl = "produto/" . $fname;

        $stmt = $db->prepare("UPDATE produto SET foto = ? WHERE id = ?");
        $stmt->execute([$fotoUrl, $id]);

        return ["foto" => $fotoUrl];
    }

    public function deletar($id) {
        $currentUser = Security::getCurrentUser();
        Security::checkAccess($currentUser, ["CM17"]);

        $db = Config::getDb();
        $stmt = $db->prepare("SELECT id FROM produto WHERE id = ?");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            throw new Exception("Produto não encontrado", 404);
        }

        try {
            $stmt = $db->prepare("DELETE FROM produto WHERE id = ?");
            $stmt->execute([$id]);
            return ["ok" => true];
        } catch (Exception $e) {
            throw new Exception($e->getMessage(), 400);
        }
    }

    public function importarXlsx() {
        $currentUser = Security::getCurrentUser();
        Security::checkAccess($currentUser, ["CM17"]);

        if (empty($_FILES['file']['tmp_name'])) {
            throw new Exception("Nenhum arquivo enviado.", 400);
        }

        $filePath = $_FILES['file']['tmp_name'];
        $rows = ExcelReader::read($filePath);

        $db = Config::getDb();

        // Busca entidade padrão
        $stmt = $db->query("SELECT id FROM parametros LIMIT 1");
        $entidade = $stmt->fetch();
        $entidadeId = $entidade ? (int)$entidade['id'] : null;

        $count = 0;
        $errors = [];
        $first = true;

        foreach ($rows as $index => $row) {
            // Pula o cabeçalho (primeira linha)
            if ($first) {
                $first = false;
                continue;
            }

            if (empty($row[0])) {
                continue;
            }

            try {
                $descricao = trim($row[0]);
                $depto_nome = isset($row[1]) ? trim($row[1]) : "";
                $grupo_nome = isset($row[2]) ? trim($row[2]) : "";
                $qtde = isset($row[3]) ? (int)$row[3] : 0;
                $valor = isset($row[4]) ? (float)str_replace(",", ".", (string)$row[4]) : 0.0;

                // 1. Resolve ou cria departamento
                $depId = null;
                if ($depto_nome !== '') {
                    $stmt = $db->prepare("SELECT id FROM departamento WHERE descricao = ?");
                    $stmt->execute([$depto_nome]);
                    $dep = $stmt->fetch();
                    if ($dep) {
                        $depId = (int)$dep['id'];
                    } else {
                        $stmt = $db->prepare("INSERT INTO departamento (descricao) VALUES (?)");
                        $stmt->execute([$depto_nome]);
                        $depId = (int)$db->lastInsertId();
                    }
                }

                // 2. Resolve ou cria grupo
                $grpId = null;
                if ($grupo_nome !== '') {
                    $stmt = $db->prepare("SELECT id FROM grupo WHERE descricao = ?");
                    $stmt->execute([$grupo_nome]);
                    $grp = $stmt->fetch();
                    if ($grp) {
                        $grpId = (int)$grp['id'];
                    } else {
                        $stmt = $db->prepare("INSERT INTO grupo (descricao) VALUES (?)");
                        $stmt->execute([$grupo_nome]);
                        $grpId = (int)$db->lastInsertId();
                    }
                }

                $hash = md5(uniqid(rand(), true));

                // Corrigido bug crítico de placeholders e colunas do Python
                $sql = "INSERT INTO produto (descricao_resumo, descricao_completa, qtde_estoque, qtde_reservado, " .
                       "valor_compra, status, depto_id, grupo_id, entidade_id, hash) " .
                       "VALUES (?, ?, ?, 0, ?, 1, ?, ?, ?, ?)";
                
                $stmt = $db->prepare($sql);
                $stmt->execute([
                    $descricao,
                    $descricao,
                    $qtde,
                    $valor,
                    $depId,
                    $grpId,
                    $entidadeId,
                    $hash
                ]);

                $count++;
            } catch (Exception $e) {
                $lineNumber = $index + 1;
                $errors[] = "Linha {$lineNumber}: " . $e->getMessage();
            }
        }

        return [
            "importados" => $count,
            "erros" => $errors
        ];
    }
}
