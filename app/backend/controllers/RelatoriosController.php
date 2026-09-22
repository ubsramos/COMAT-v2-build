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
                    COALESCE(mot.descricao, mov.tipo, 'Movimentação') AS motivo,
                    p.qtde_estoque AS estoque_atual,
                    mov.data,
                    mov.qtde,
                    mov.valor_produto,
                    COALESCE(mov.usuario_nome, 'Sistema') AS usuario
                FROM movimento mov
                LEFT JOIN requisicao_item ri  ON ri.id  = mov.request_item_id
                LEFT JOIN requisicao      r   ON r.id   = ri.request_id
                INNER JOIN produto        p   ON p.id   = mov.produto_id
                LEFT JOIN  motivo         mot ON mot.id = r.motivo_id
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
            $row['requisicao'] = $row['requisicao'] ? (int)$row['requisicao'] : null;
            $row['estoque_atual'] = (float)$row['estoque_atual'];
            $row['qtde'] = (float)$row['qtde'];
            $row['valor_produto'] = (float)($row['valor_produto'] ?? 0);
            $result[] = $row;
        }

        return $result;
    }

    public function situacaoEstoque() {
        $currentUser = Security::getCurrentUser();
        Security::checkAccess($currentUser, ["CM32", "CM26"]);

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
                    p.qtde_estoque,
                    p.valor_compra,

                    /* Entradas no período selecionado (qtde > 0) */
                    COALESCE((
                        SELECT SUM(m.qtde)
                        FROM movimento m
                        WHERE m.produto_id = p.id AND m.qtde > 0 AND m.data >= ? AND m.data <= ?
                    ), 0) AS qtde_entrada,

                    COALESCE((
                        SELECT SUM(m.qtde * COALESCE(NULLIF(m.valor_produto, 0), p.valor_compra, 0))
                        FROM movimento m
                        WHERE m.produto_id = p.id AND m.qtde > 0 AND m.data >= ? AND m.data <= ?
                    ), 0) AS valor_entrada,

                    /* Saídas no período selecionado (módulo de qtde < 0) */
                    COALESCE((
                        SELECT ABS(SUM(m.qtde))
                        FROM movimento m
                        WHERE m.produto_id = p.id AND m.qtde < 0 AND m.data >= ? AND m.data <= ?
                    ), 0) AS qtde_saida,

                    COALESCE((
                        SELECT SUM(ABS(m.qtde) * COALESCE(NULLIF(m.valor_produto, 0), p.valor_compra, 0))
                        FROM movimento m
                        WHERE m.produto_id = p.id AND m.qtde < 0 AND m.data >= ? AND m.data <= ?
                    ), 0) AS valor_saida,

                    /* Movimentos posteriores a d2 (do fim do período até agora) */
                    COALESCE((
                        SELECT SUM(m.qtde)
                        FROM movimento m
                        WHERE m.produto_id = p.id AND m.data > ?
                    ), 0) AS mov_pos

                FROM produto p
                LEFT JOIN departamento dep ON dep.id = p.depto_id
                WHERE p.status = 1 $deptoFilter
                ORDER BY dep.descricao, p.descricao_resumo";

        $params = [$d1, $d2, $d1, $d2, $d1, $d2, $d1, $d2, $d2];
        if ($depto_id) {
            $params[] = $depto_id;
        }

        $db = Config::getDb();
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $result = [];
        foreach ($rows as $row) {
            $estoqueAtualFisico = (float)($row['qtde_estoque'] ?? 0);
            $movPos = (float)($row['mov_pos'] ?? 0);
            $qEntrada = (float)($row['qtde_entrada'] ?? 0);
            $qSaida = (float)($row['qtde_saida'] ?? 0);
            $vUnit = (float)($row['valor_compra'] ?? 0);

            // Saldo no final da data limite selecionada (d2)
            $saldoFinal = $estoqueAtualFisico - $movPos;

            // Saldo anterior antes do início do período (d1)
            $saldoAnt = $saldoFinal - $qEntrada + $qSaida;

            $vAnt = round($saldoAnt * $vUnit, 2);
            $vFinal = round($saldoFinal * $vUnit, 2);

            $result[] = [
                'depto'         => $row['depto'] ?? 'Sem Departamento',
                'produto'       => $row['produto'] ?? '—',
                'qtde_ant'      => $saldoAnt,
                'valor_ant'     => $vAnt,
                'qtde_entrada'  => $qEntrada,
                'valor_entrada' => (float)$row['valor_entrada'],
                'qtde_saida'    => $qSaida,
                'valor_saida'   => (float)$row['valor_saida'],
                'saldo_atual'   => $saldoFinal,
                'valor_atual'   => $vFinal,
                'valor_unitario'=> $vUnit,
            ];
        }

        return $result;
    }
}
