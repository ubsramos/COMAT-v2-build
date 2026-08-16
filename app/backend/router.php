<?php
/**
 * router.php — Roteador para o servidor embutido do PHP (php -S)
 * Permite servir arquivos estáticos (como uploads/fotos) de forma nativa
 * e redireciona todas as requisições dinâmicas de API para o index.php.
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $uri;

// Se o arquivo estático existir fisicamente e não for um diretório, serve diretamente
if (file_exists($file) && !is_dir($file)) {
    return false; // Retorna false para o servidor embutido do PHP tratá-lo
}

// Caso contrário, encaminha a requisição para o front controller da API
require_once __DIR__ . '/api/index.php';
