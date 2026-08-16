<?php
/**
 * DashboardController.php — Estatísticas agregadas para o painel com filtros avançados
 */

class DashboardController {

    private function parseDate($s, $default) {
        if (!$s) return $default;
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $s, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }
        return $s;
    }

    public function stats() {
        $currentUser = Security::getCurrentUser(); // Valida autenticação

        $db = Config::getDb();

        // 1. Captura de Filtros via $_GET
        $data_ini = $_GET['data_ini'] ?? null;
        $data_fim = $_GET['data_fim'] ?? null;
        $depto_id = isset($_GET['depto_id']) && $_GET['depto_id'] !== '' ? (int)$_GET['depto_id'] : null;
        $produto_id = isset($_GET['produto_id']) && $_GET['produto_id'] !== '' ? (int)$_GET['produto_id'] : null;
        $grupo_id = isset($_GET['grupo_id']) && $_GET['grupo_id'] !== '' ? (int)$_GET['grupo_id'] : null;
        $usuario_id = isset($_GET['usuario_id']) && $_GET['usuario_id'] !== '' ? (int)$_GET['usuario_id'] : null;

        $now = new DateTime();
        $default_ini = $now->format('Y') . '-01-01'; // 01 de janeiro do ano atual
        $default_fim = $now->format('Y-m-d'); // data presente

        $dIniStr = $this->parseDate($data_ini, $default_ini);
        $dFimStr = $this->parseDate($data_fim, $default_fim);
        $dFimTimeStr = $dFimStr . ' 23:59:59';

        $periodo_label = (new DateTime($dIniStr))->format('d/m/Y') . ' a ' . (new DateTime($dFimStr))->format('d/m/Y');

        // 2. Construção dinâmica das cláusulas WHERE
        // Cláusula para produtos
        $whereProd = " WHERE status = 1";
        $paramsProd = [];
        if ($depto_id) {
            $whereProd .= " AND depto_id = ?";
            $paramsProd[] = $depto_id;
        }
        if ($grupo_id) {
            $whereProd .= " AND grupo_id = ?";
            $paramsProd[] = $grupo_id;
        }
        if ($produto_id) {
            $whereProd .= " AND id = ?";
            $paramsProd[] = $produto_id;
        }

        // Cláusula para requisições
        $whereReq = " WHERE r.data_pedido BETWEEN ? AND ?";
        $paramsReq = [$dIniStr, $dFimTimeStr];
        if ($depto_id) {
            $whereReq .= " AND r.depto_destino_id = ?";
            $paramsReq[] = $depto_id;
        }
        if ($produto_id) {
            $whereReq .= " AND ri.produto_id = ?";
            $paramsReq[] = $produto_id;
        }
        if ($grupo_id) {
            $whereReq .= " AND p.grupo_id = ?";
            $paramsReq[] = $grupo_id;
        }
        if ($usuario_id) {
            $whereReq .= " AND r.usuario_solicitante_id = ?";
            $paramsReq[] = $usuario_id;
        }

        // Cláusula para movimentos
        $whereMov = " WHERE DATE(mov.data) BETWEEN ? AND ?";
        $paramsMov = [$dIniStr, $dFimStr];
        if ($depto_id) {
            $whereMov .= " AND r.depto_destino_id = ?";
            $paramsMov[] = $depto_id;
        }
        if ($produto_id) {
            $whereMov .= " AND mov.produto_id = ?";
            $paramsMov[] = $produto_id;
        }
        if ($grupo_id) {
            $whereMov .= " AND p.grupo_id = ?";
            $paramsMov[] = $grupo_id;
        }
        if ($usuario_id) {
            $whereMov .= " AND r.usuario_solicitante_id = ?";
            $paramsMov[] = $usuario_id;
        }

        // 3. Execução das queries de totais gerais
        // Total Produtos
        $sqlTotalProd = "SELECT COUNT(DISTINCT ri.produto_id) AS c
                         FROM requisicao_item ri
                         INNER JOIN requisicao r ON r.id = ri.request_id
                         INNER JOIN produto p ON p.id = ri.produto_id
                         $whereReq";
        $stmt = $db->prepare($sqlTotalProd);
        $stmt->execute($paramsReq);
        $total_produtos = (int)($stmt->fetch()['c'] ?? 0);

        // Total Requisições
        $sqlCount = "SELECT COUNT(DISTINCT r.id) AS c
                     FROM requisicao r
                     INNER JOIN requisicao_item ri ON ri.request_id = r.id
                     INNER JOIN produto p ON p.id = ri.produto_id
                     $whereReq";
        $stmt = $db->prepare($sqlCount);
        $stmt->execute($paramsReq);
        $total_req = (int)($stmt->fetch()['c'] ?? 0);

        // Aguardando
        $stmt = $db->prepare($sqlCount . " AND r.status = 0");
        $stmt->execute($paramsReq);
        $req_pendentes = (int)($stmt->fetch()['c'] ?? 0);

        // Aprovadas
        $stmt = $db->prepare($sqlCount . " AND r.status = 1");
        $stmt->execute($paramsReq);
        $req_aprovadas = (int)($stmt->fetch()['c'] ?? 0);

        // Atendidas
        $stmt = $db->prepare($sqlCount . " AND r.status = 3");
        $stmt->execute($paramsReq);
        $req_atendidas = (int)($stmt->fetch()['c'] ?? 0);

        // Valor Estoque
        $sqlValorEst = "SELECT COALESCE(SUM(p.qtde_estoque * p.valor_compra), 0) AS v
                        FROM produto p
                        WHERE p.id IN (
                            SELECT DISTINCT ri.produto_id
                            FROM requisicao_item ri
                            INNER JOIN requisicao r ON r.id = ri.request_id
                            INNER JOIN produto p ON p.id = ri.produto_id
                            $whereReq
                        )";
        $stmt = $db->prepare($sqlValorEst);
        $stmt->execute($paramsReq);
        $valor_est = (float)($stmt->fetch()['v'] ?? 0.0);


        // 4. Movimentação por dia (entradas e saídas no período)
        $sqlMov = "SELECT DATE(mov.data) AS dia,
                          SUM(CASE WHEN mov.qtde > 0 THEN mov.qtde ELSE 0 END) AS entradas,
                          SUM(CASE WHEN mov.qtde < 0 THEN ABS(mov.qtde) ELSE 0 END) AS saidas
                   FROM movimento mov
                   INNER JOIN requisicao_item ri ON ri.id = mov.request_item_id
                   INNER JOIN requisicao       r  ON r.id  = ri.request_id
                   INNER JOIN produto          p  ON p.id  = mov.produto_id
                   $whereMov
                   GROUP BY DATE(mov.data)
                   ORDER BY dia";
        $stmt = $db->prepare($sqlMov);
        $stmt->execute($paramsMov);
        $mov_raw = $stmt->fetchAll();

        $movimentacao_chart = [];
        foreach ($mov_raw as $row) {
            $diaObj = new DateTime($row['dia']);
            $movimentacao_chart[] = [
                "dia" => $diaObj->format('d/m'),
                "entradas" => (int)($row['entradas'] ?? 0),
                "saidas" => (int)($row['saidas'] ?? 0)
            ];
        }


                // 5. Top 10 produtos mais movimentados
        $sqlTop = "SELECT p.descricao_resumo AS produto,
                          COUNT(*) AS movimentacoes,
                          ABS(SUM(mov.qtde)) AS qtde,
                          SUM(ABS(mov.qtde) * mov.valor_produto) AS valor
                   FROM movimento mov
                   INNER JOIN requisicao_item ri ON ri.id = mov.request_item_id
                   INNER JOIN requisicao       r  ON r.id  = ri.request_id
                   INNER JOIN produto          p  ON p.id  = mov.produto_id
                   $whereMov
                   GROUP BY p.id, p.descricao_resumo
                   ORDER BY movimentacoes DESC
                   LIMIT 10";
        $stmt = $db->prepare($sqlTop);
        $stmt->execute($paramsMov);
        $top_raw = $stmt->fetchAll();

        $top_produtos = [];
        foreach ($top_raw as $row) {
            $top_produtos[] = [
                "produto" => $row['produto'],
                "movimentacoes" => (int)$row['movimentacoes'],
                "qtde" => (int)$row['qtde'],
                "valor" => (float)$row['valor']
            ];
        }


        // 6. Novo Gráfico: Saídas por Setor (motivo_id = 2)
        $sqlSaidasSetor = "SELECT COALESCE(dep.descricao, 'Não Informado') AS setor,
                                  ABS(SUM(mov.qtde)) AS qtde,
                                  SUM(ABS(mov.qtde) * mov.valor_produto) AS valor
                           FROM movimento mov
                           INNER JOIN requisicao_item ri ON ri.id = mov.request_item_id
                           INNER JOIN requisicao       r  ON r.id  = ri.request_id
                           INNER JOIN produto          p  ON p.id  = mov.produto_id
                           LEFT JOIN  departamento     dep ON dep.id = r.depto_destino_id
                           $whereMov AND r.motivo_id = 2
                           GROUP BY dep.id, dep.descricao
                           ORDER BY valor DESC";
        $stmt = $db->prepare($sqlSaidasSetor);
        $stmt->execute($paramsMov);
        $saidas_setor_raw = $stmt->fetchAll();
        
        $saidas_por_setor = [];
        foreach ($saidas_setor_raw as $row) {
            $saidas_por_setor[] = [
                "setor" => $row['setor'],
                "qtde" => (int)$row['qtde'],
                "valor" => (float)$row['valor']
            ];
        }


        // 7. Novo Gráfico: Entradas por Setor (motivo_id = 1)
        $sqlEntradasSetor = "SELECT COALESCE(dep.descricao, 'Não Informado') AS setor,
                                    SUM(mov.qtde) AS qtde,
                                    SUM(mov.qtde * mov.valor_produto) AS valor
                             FROM movimento mov
                             INNER JOIN requisicao_item ri ON ri.id = mov.request_item_id
                             INNER JOIN requisicao       r  ON r.id  = ri.request_id
                             INNER JOIN produto          p  ON p.id  = mov.produto_id
                             LEFT JOIN  departamento     dep ON dep.id = r.depto_destino_id
                             $whereMov AND r.motivo_id = 1
                             GROUP BY dep.id, dep.descricao
                             ORDER BY valor DESC";
        $stmt = $db->prepare($sqlEntradasSetor);
        $stmt->execute($paramsMov);
        $entradas_setor_raw = $stmt->fetchAll();
        
        $entradas_por_setor = [];
        foreach ($entradas_setor_raw as $row) {
            $entradas_por_setor[] = [
                "setor" => $row['setor'],
                "qtde" => (int)$row['qtde'],
                "valor" => (float)$row['valor']
            ];
        }


                // 8. Últimas 10 requisições
        $sqlUlt = "SELECT r.id, r.descricao, r.status, r.data_pedido,
                          COALESCE(SUM(ri.qtde * ri.valor_produto), 0) AS valor_total
                   FROM requisicao r
                   INNER JOIN requisicao_item ri ON ri.request_id = r.id
                   INNER JOIN produto          p  ON p.id  = ri.produto_id
                   $whereReq
                   GROUP BY r.id, r.descricao, r.status, r.data_pedido
                   ORDER BY r.data_pedido DESC
                   LIMIT 10";
        $stmt = $db->prepare($sqlUlt);
        $stmt->execute($paramsReq);
        $ult_raw = $stmt->fetchAll();

        $ultimas_requisicoes = [];
        foreach ($ult_raw as $row) {
            $dateStr = null;
            if (!empty($row['data_pedido'])) {
                $d = new DateTime($row['data_pedido']);
                $dateStr = $d->format('Y-m-d\TH:i:s');
            }
            $ultimas_requisicoes[] = [
                "id" => (int)$row['id'],
                "descricao" => $row['descricao'],
                "status" => (int)$row['status'],
                "data_pedido" => $dateStr,
                "valor_total" => (float)($row['valor_total'] ?? 0.0)
            ];
        }

        return [
            "total_produtos" => $total_produtos,
            "total_requisicoes" => $total_req,
            "requisicoes_pendentes" => $req_pendentes,
            "requisicoes_aprovadas" => $req_aprovadas,
            "requisicoes_atendidas" => $req_atendidas,
            "valor_estoque" => $valor_est,
            "periodo_grafico" => $periodo_label,
            "movimentacao_chart" => $movimentacao_chart,
            "top_produtos" => $top_produtos,
            "saidas_por_setor" => $saidas_por_setor,
            "entradas_por_setor" => $entradas_por_setor,
            "ultimas_requisicoes" => $ultimas_requisicoes
        ];
    }
}
