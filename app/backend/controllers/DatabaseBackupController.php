<?php
/**
 * DatabaseBackupController.php — Controlador RESTful para Backup e Restauração do Banco
 * Exige autorização de Administrador (permissão CM11).
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../Security.php';
require_once __DIR__ . '/../DatabaseBackupService.php';

class DatabaseBackupController {

    private function checkAdmin() {
        $currentUser = Security::getCurrentUser();
        // Permissão de parâmetros / administração institucional
        Security::checkAccess($currentUser, ["CM11"]);
        return $currentUser;
    }

    /**
     * Lista os backups de banco disponíveis no servidor
     */
    public function listar() {
        $this->checkAdmin();
        $service = new DatabaseBackupService();
        return $service->listarBackups();
    }

    /**
     * Gera um novo backup completo (.sql.gz) agora
     */
    public function criar() {
        $this->checkAdmin();
        $service = new DatabaseBackupService();
        return $service->criarBackup('manual');
    }

    /**
     * Restaura o banco de dados a partir de um arquivo existente no servidor ou upload direto
     */
    public function restaurar() {
        $this->checkAdmin();
        $service = new DatabaseBackupService();

        // 1. Se enviou arquivo por upload (multipart/form-data)
        if (!empty($_FILES['file']['tmp_name'])) {
            $tmpPath = $_FILES['file']['tmp_name'];
            $res = $service->restaurarBackup($tmpPath);
            return $res;
        }

        // 2. Se informou o nome de um arquivo já salvo no servidor (JSON body)
        $body = getJsonBody();
        $filename = trim($body['filename'] ?? '');

        if (empty($filename)) {
            throw new Exception("Nome do arquivo de backup não informado para restauração.", 400);
        }

        $filepath = $service->obterCaminhoBackup($filename);
        $res = $service->restaurarBackup($filepath);
        return $res;
    }

    /**
     * Faz download seguro do arquivo de backup compactado
     */
    public function download($filename) {
        $this->checkAdmin();
        $service = new DatabaseBackupService();
        $filepath = $service->obterCaminhoBackup($filename);

        if (!file_exists($filepath)) {
            throw new Exception("Arquivo de backup não encontrado.", 404);
        }

        if (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Description: File Transfer');
        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . basename($filepath) . '"');
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filepath));

        readfile($filepath);
        exit;
    }

    /**
     * Exclui um arquivo de backup do servidor
     */
    public function excluir($filename) {
        $this->checkAdmin();
        $service = new DatabaseBackupService();
        $service->excluirBackup($filename);
        return ['sucesso' => true, 'mensagem' => 'Backup removido com sucesso.'];
    }
}
