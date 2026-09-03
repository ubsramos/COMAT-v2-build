<?php
/**
 * ExcelReader.php — Leitor nativo de planilhas Excel (.xlsx) do zero
 * Utiliza as extensões padrão ZipArchive e SimpleXMLElement do PHP.
 * Totalmente autônomo, livre de pacotes e extremamente performático.
 */

class ExcelReader {

    /**
     * Lê um arquivo .xlsx e retorna um array bidimensional contendo as linhas e colunas.
     */
    public static function read($filePath) {
        if (!file_exists($filePath)) {
            throw new Exception("Arquivo de planilha não encontrado.");
        }

        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new Exception("Falha ao abrir o arquivo .xlsx como arquivo ZIP.");
        }

        // 1. Carrega o Shared Strings (Strings Compartilhadas) para traduzir índices de células textuais
        $sharedStrings = [];
        $sharedStringsData = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedStringsData !== false) {
            $xml = @simplexml_load_string($sharedStringsData);
            if ($xml && isset($xml->si)) {
                foreach ($xml->si as $si) {
                    // Células ricas podem ter múltiplos elementos <t> dentro de <r>
                    if (isset($si->t)) {
                        $sharedStrings[] = (string)$si->t;
                    } elseif (isset($si->r)) {
                        $textParts = [];
                        foreach ($si->r as $r) {
                            if (isset($r->t)) {
                                $textParts[] = (string)$r->t;
                            }
                        }
                        $sharedStrings[] = implode('', $textParts);
                    } else {
                        $sharedStrings[] = '';
                    }
                }
            }
        }

        // 2. Carrega a primeira planilha (sheet1.xml)
        $sheetData = $zip->getFromName('xl/worksheets/sheet1.xml');
        if ($sheetData === false) {
            $zip->close();
            throw new Exception("Falha ao ler os dados da planilha 'sheet1.xml'.");
        }

        $xml = @simplexml_load_string($sheetData);
        if (!$xml) {
            $zip->close();
            throw new Exception("Dados XML da planilha corrompidos ou inválidos.");
        }

        $rows = [];
        
        // Percorre as linhas (<row>)
        foreach ($xml->sheetData->row as $row) {
            $rowIndex = (int)$row['r'];
            $rowData = [];

            // Inicializa colunas vazias
            $maxColIndex = 0;

            // Percorre as células (<c>) de cada linha
            foreach ($row->c as $c) {
                $cellRef = (string)$c['r']; // Ex: A1, B1, C1
                $colIndex = self::colRefToNum($cellRef);

                // Preenche células vazias intermediárias se houver
                while ($maxColIndex < $colIndex - 1) {
                    $rowData[$maxColIndex] = null;
                    $maxColIndex++;
                }

                $value = isset($c->v) ? (string)$c->v : null;
                $type = isset($c['t']) ? (string)$c['t'] : '';

                // Se o tipo for 's' (shared string), busca o valor real no Shared Strings pelo índice
                if ($type === 's' && $value !== null) {
                    $idx = (int)$value;
                    $value = isset($sharedStrings[$idx]) ? $sharedStrings[$idx] : '';
                }

                $rowData[$colIndex - 1] = $value;
                $maxColIndex = $colIndex;
            }

            $rows[$rowIndex] = $rowData;
        }

        $zip->close();

        // Reindexa as linhas para começar do 0 sequencialmente
        return array_values($rows);
    }

    /**
     * Converte uma referência de coluna Excel (ex: A, B, C, AA, AB) para um número (1-indexed)
     */
    private static function colRefToNum($cellRef) {
        // Remove os números da referência (ex: "B23" -> "B")
        $colRef = preg_replace('/[0-9]/', '', $cellRef);
        $len = strlen($colRef);
        $num = 0;

        for ($i = 0; $i < $len; $i++) {
            $num = $num * 26 + (ord($colRef[$i]) - 64);
        }

        return $num;
    }
}
