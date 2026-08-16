<?php
/**
 * RelatoriosController.php — Relatórios de movimentação e situação de estoque (SQL puro)
 */

class RelatoriosController {

    private function parseDate($s, $default) {
        if (!$s) return $default;
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $s, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }
        return $s;
    }

    public function movimentacao() {
        $currentUser = Security::getCurrentUser();
        Security::checkAccess($currentUser, ["CM23"]);

        $data_ini = $_GET['data_ini'] ?? null;
        $data_fim = $_GET['data_fim'] ?? null;

        $now = new DateTime();
        $d1 = $this->parseDate($data_ini, $now->format('Y-m') . '-01');
        $d2 = $this->parseDate($data_fim, $now->format('Y-m-d')) . ' 23:59:59';

        $db = Config::getDb();
        $sql = "SELECT
                    p.descricao_resumo,
                    r.id AS requisicao,
                    mot.descricao AS motivo,
                    p.qtde_estoque AS estoque_atual,
                    mov.data,
                    mov.qtde
                FROM movimento mov
                INNER JOIN requisicao_item  ri  ON ri.id  = mov.request_item_id
                INNER JOIN requisicao       r   ON r.id   = ri.request_id
                INNER JOIN produto          p   ON p.id   = mov.produto_id
                LEFT JOIN  motivo           mot ON mot.id = r.motivo_id
                WHERE mov.data BETWEEN ? AND ?
                ORDER BY mov.data DESC, p.descricao_resumo";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([$d1, $d2]);
        $rows = $stmt->fetchAll();

        $result = [];
        foreach ($rows as $row) {
            $dateStr = null;
            if (!empty($row['data'])) {
                $dObj = new DateTime($row['data']);
                $dateStr = $dObj->format('Y-m-d\TH:i:s');
            }
            $row['data'] = $dateStr;
            $row['requisicao'] = (int)$row['requisicao'];
            $row['estoque_atual'] = (int)$row['estoque_atual'];
            $row['qtde'] = (int)$row['qtde'];
            $result[] = $row;
        }

        return $result;
    }

    public function situacaoEstoque() {
        $currentUser = Security::getCurrentUser();
        Security::checkAccess($currentUser, ["CM26"]);

        $data_ini = $_GET['data_ini'] ?? null;
        $data_fim = $_GET['data_fim'] ?? null;
        $depto_id = isset($_GET['depto_id']) && $_GET['depto_id'] !== '' ? (int)$_GET['depto_id'] : null;

        $now = new DateTime();
        $d1 = $this->parseDate($data_ini, $now->format('Y-m') . '-01');
        $d2 = $this->parseDate($data_fim, $now->format('Y-m-d')) . ' 23:59:59';

        $deptoFilter = $depto_id ? "AND p.depto_id = ?" : "";

        $sql = "SELECT
                    dep.descricao AS depto,
                    p.descricao_resumo AS produto,

                    /* Saldo anterior */
                    COALESCE((
                        SELECT SUM(m2.qtde)
                        FROM movimento m2
                        INNER JOIN requisicao_item ri2 ON ri2.id = m2.request_item_id
                        INNER JOIN requisicao r2 ON r2.id = ri2.request_id
                        WHERE m2.produto_id = p.id AND m2.data < ?
                    ), 0) AS qtde_ant,

                    COALESCE((
                        SELECT SUM(m2.qtde * m2.valor_produto)
                        FROM movimento m2
                        INNER JOIN requisicao_item ri2 ON ri2.id = m2.request_item_id
                        INNER JOIN requisicao r2 ON r2.id = ri2.request_id
                        WHERE m2.produto_id = p.id AND r2.motivo_id = 1 AND m2.data < ?
                    ), 0) AS valor_ant,

                    /* Entradas no período */
                    COALESCE((
                        SELECT SUM(m2.qtde)
                        FROM movimento m2
                        INNER JOIN requisicao_item ri2 ON ri2.id = m2.request_item_id
                        INNER JOIN requisicao r2 ON r2.id = ri2.request_id
                        WHERE m2.produto_id = p.id AND r2.motivo_id = 1
                          AND m2.data BETWEEN ? AND ?
                    ), 0) AS qtde_entrada,

                    COALESCE((
                        SELECT SUM(m2.qtde * m2.valor_produto)
                        FROM movimento m2
                        INNER JOIN requisicao_item ri2 ON ri2.id = m2.request_item_id
                        INNER JOIN requisicao r2 ON r2.id = ri2.request_id
                        WHERE m2.produto_id = p.id AND r2.motivo_id = 1
                          AND m2.data BETWEEN ? AND ?
                    ), 0) AS valor_entrada,

                    /* Saídas no período */
                    COALESCE((
                        SELECT ABS(SUM(m2.qtde))
                        FROM movimento m2
                        INNER JOIN requisicao_item ri2 ON ri2.id = m2.request_item_id
                        INNER JOIN requisicao r2 ON r2.id = ri2.request_id
                        WHERE m2.produto_id = p.id AND r2.motivo_id = 2
                          AND m2.data BETWEEN ? AND ?
                    ), 0) AS qtde_saida,

                    COALESCE((
                        SELECT SUM(ABS(m2.qtde) * m2.valor_produto)
                        FROM movimento m2
                        INNER JOIN requisicao_item ri2 ON ri2.id = m2.request_item_id
                        INNER JOIN requisicao r2 ON r2.id = ri2.request_id
                        WHERE m2.produto_id = p.id AND r2.motivo_id = 2
                          AND m2.data BETWEEN ? AND ?
                    ), 0) AS valor_saida

                FROM produto p
                LEFT JOIN departamento dep ON dep.id = p.depto_id
                WHERE p.status = 1 $deptoFilter
                ORDER BY dep.descricao, p.descricao_resumo";

        $params = [$d1, $d1, $d1, $d2, $d1, $d2, $d1, $d2, $d1, $d2];
        if ($depto_id) {
            $params[] = $depto_id;
        }

        $db = Config::getDb();
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $result = [];
        foreach ($rows as $row) {
            $row['qtde_ant'] = (int)$row['qtde_ant'];
            $row['valor_ant'] = (float)$row['valor_ant'];
            $row['qtde_entrada'] = (int)$row['qtde_entrada'];
            $row['valor_entrada'] = (float)$row['valor_entrada'];
            $row['qtde_saida'] = (int)$row['qtde_saida'];
            $row['valor_saida'] = (float)$row['valor_saida'];
            $result[] = $row;
        }

        return $result;
    }
}
