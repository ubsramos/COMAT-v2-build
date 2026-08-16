<?php
/**
 * index.php — Front Controller e Roteador RESTful
 * Gerencia todo o ciclo de vida da API PHP nativa.
 * Suporta roteamento com parâmetros dinâmicos (ex: /usuarios/{id}).
 */

// Trata cabeçalhos globais e CORS de forma profissional
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS, PATCH");
header("Access-Control-Allow-Headers: Authorization, Content-Type, Accept, Origin, X-Requested-With");
header("Content-Type: application/json; charset=utf-8");

// Trata preflight CORS (método OPTIONS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Inicializa configurações
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Security.php';

// Registra classes dos controladores sob demanda (Autoload simples)
spl_autoload_register(function ($class) {
    $file = __DIR__ . '/controllers/' . $class . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// Helper de JSON Request Body
function getJsonBody() {
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?: [];
}

// ─── Tabela de Rotas ─────────────────────────────────────────────────────────

$routes = [
    // Autenticação
    ['POST', '/auth/login',                           'AuthController@login'],
    ['GET',  '/auth/me',                              'AuthController@me'],
    ['POST', '/auth/trocar-senha',                    'AuthController@trocarSenha'],

    // Usuários
    ['GET',    '/usuarios',                           'UsuariosController@listar'],
    ['POST',   '/usuarios',                           'UsuariosController@criar'],
    ['GET',    '/usuarios/{id}',                      'UsuariosController@detalhe'],
    ['PUT',    '/usuarios/{id}',                      'UsuariosController@atualizar'],
    ['DELETE', '/usuarios/{id}',                      'UsuariosController@deletar'],

    // Departamentos
    ['GET',    '/departamentos',                      'DepartamentosController@listar'],
    ['POST',   '/departamentos',                      'DepartamentosController@criar'],
    ['GET',    '/departamentos/{id}',                 'DepartamentosController@detalhe'],
    ['PUT',    '/departamentos/{id}',                 'DepartamentosController@atualizar'],
    ['DELETE', '/departamentos/{id}',                 'DepartamentosController@deletar'],

    // Funcionários
    ['GET',    '/funcionarios',                       'FuncionariosController@listar'],
    ['POST',   '/funcionarios',                       'FuncionariosController@criar'],
    ['GET',    '/funcionarios/{id}',                  'FuncionariosController@detalhe'],
    ['PUT',    '/funcionarios/{id}',                  'FuncionariosController@atualizar'],
    ['DELETE', '/funcionarios/{id}',                  'FuncionariosController@deletar'],
    ['PUT',    '/funcionarios/{id}/acesso',           'FuncionariosController@atualizarAcesso'],

    // Produtos
    ['GET',    '/produtos',                           'ProdutosController@listar'],
    ['POST',   '/produtos',                           'ProdutosController@criar'],
    ['GET',    '/produtos/{id}',                      'ProdutosController@detalhe'],
    ['PUT',    '/produtos/{id}',                      'ProdutosController@atualizar'],
    ['DELETE', '/produtos/{id}',                      'ProdutosController@deletar'],
    ['POST',   '/produtos/{id}/foto',                 'ProdutosController@uploadFoto'],
    ['POST',   '/produtos/importar',                  'ProdutosController@importarXlsx'],

    // Requisições
    ['GET',    '/requisicoes',                        'RequisicoesController@listar'],
    ['POST',   '/requisicoes',                        'RequisicoesController@criar'],
    ['GET',    '/requisicoes/process/pendentes',      'RequisicoesController@pendentes'],
    ['GET',    '/requisicoes/{id}',                   'RequisicoesController@detalhe'],
    ['PUT',    '/requisicoes/{id}',                   'RequisicoesController@atualizar'],
    ['DELETE', '/requisicoes/{id}',                   'RequisicoesController@deletar'],
    ['POST',   '/requisicoes/{id}/itens',             'RequisicoesController@adicionarItem'],
    ['DELETE', '/requisicoes/{id}/itens/{item_id}',   'RequisicoesController@removerItem'],
    ['POST',   '/requisicoes/{id}/aprovar',           'RequisicoesController@aprovar'],
    ['POST',   '/requisicoes/{id}/processar',         'RequisicoesController@processar'],
    ['POST',   '/requisicoes/{id}/devolver',          'RequisicoesController@devolver'],

    // Dashboard
    ['GET',    '/dashboard/stats',                    'DashboardController@stats'],

    // Correspondências
    ['GET',    '/correspondencias/tipos',             'CorrespondenciasController@listarTipos'],
    ['GET',    '/correspondencias/ponto-recepcao',    'CorrespondenciasController@pontoRecepcao'],
    ['GET',    '/correspondencias',                   'CorrespondenciasController@listar'],
    ['GET',    '/correspondencias/stats',             'CorrespondenciasController@stats'],
    ['GET',    '/correspondencias/{id}',              'CorrespondenciasController@detalhe'],
    ['POST',   '/correspondencias',                   'CorrespondenciasController@criar'],
    ['PUT',    '/correspondencias/{id}',              'CorrespondenciasController@atualizar'],
    ['PUT',    '/correspondencias/{id}/retirada',     'CorrespondenciasController@registrarRetirada'],
    ['PUT',    '/correspondencias/{id}/devolver',     'CorrespondenciasController@registrarDevolucao'],
    ['DELETE', '/correspondencias/{id}',              'CorrespondenciasController@deletar'],

    // Motivos
    ['GET',    '/motivos',                            'MotivosController@listar'],
    ['POST',   '/motivos',                            'MotivosController@criar'],
    ['GET',    '/motivos/{id}',                       'MotivosController@detalhe'],
    ['PUT',    '/motivos/{id}',                       'MotivosController@atualizar'],
    ['DELETE', '/motivos/{id}',                       'MotivosController@deletar'],

    // Grupos
    ['GET',    '/grupos',                             'GruposController@listar'],
    ['POST',   '/grupos',                             'GruposController@criar'],
    ['GET',    '/grupos/{id}',                        'GruposController@detalhe'],
    ['PUT',    '/grupos/{id}',                        'GruposController@atualizar'],
    ['DELETE', '/grupos/{id}',                        'GruposController@deletar'],

    // Parâmetros
    ['GET',    '/parametros',                         'ParametrosController@getParametros'],
    ['PUT',    '/parametros/{id}',                    'ParametrosController@atualizar'],
    ['GET',    '/parametros/tags',                    'ParametrosController@listarTags'],
    ['POST',   '/parametros/test-email',              'ParametrosController@testarEmail'],
    ['POST',   '/parametros/test-whatsapp',           'ParametrosController@testarWhatsapp'],

    // Relatórios
    ['GET',    '/relatorios/movimentacao',            'RelatoriosController@movimentacao'],
    ['GET',    '/relatorios/situacao-estoque',        'RelatoriosController@situacaoEstoque'],
];

// ─── Processamento do Request URI e Roteamento ───────────────────────────────

$requestMethod = $_SERVER['REQUEST_METHOD'];
$requestUri = $_SERVER['REQUEST_URI'];

// Remove query strings da URL (ex: ?depto_id=3 -> depto_id está em $_GET)
$parsedUrl = parse_url($requestUri);
$path = $parsedUrl['path'] ?? '/';

// Normaliza o path: remove barra final e trata prefixos do subdomínio (/backend/api/v1/...)
$path = rtrim($path, '/');
if (empty($path)) {
    $path = '/';
}

// Remove prefixos conhecidos para rotas limpas
// Ex: /backend/api/v1/auth/login -> /auth/login
$prefixes = ['/backend/api/v1', '/backend', '/api/v1', '/api'];
foreach ($prefixes as $prefix) {
    if (strpos($path, $prefix) === 0) {
        $path = substr($path, strlen($prefix));
        break;
    }
}
$path = rtrim($path, '/');
if (empty($path)) {
    $path = '/';
}

// Rota raiz "/" (Health Check)
if ($path === '/') {
    try {
        $db = Config::getDb();
        $stmt = $db->query("SELECT 1");
        $dbStatus = "ok";
    } catch (Exception $e) {
        $dbStatus = "error: " . $e->getMessage();
    }
    
    echo json_encode([
        "app" => Config::get('APP_NAME', 'COMAT'),
        "version" => Config::get('APP_VERSION', '2.0'),
        "db" => $dbStatus,
        "status" => "online"
    ]);
    exit;
}

// Faz o matching das rotas
$routeMatched = false;
$methodMatched = false;

foreach ($routes as $route) {
    list($m, $pattern, $handler) = $route;
    
    // Transforma padrões dinâmicos {id} e {item_id} em expressões regulares
    // ex: /usuarios/{id} -> ^/usuarios/([A-Za-z0-9_-]+)$
    $regex = preg_replace('/\{\w+\}/', '([A-Za-z0-9_-]+)', $pattern);
    $regex = '#^' . $regex . '$#';
    
    if (preg_match($regex, $path, $matches)) {
        $routeMatched = true;
        
        if ($requestMethod === $m) {
            $methodMatched = true;
            
            // Remove o primeiro elemento que contém a string inteira casada
            array_shift($matches);
            
            // Separa controlador e método
            list($controllerName, $action) = explode('@', $handler);
            
            try {
                // Instancia o controlador de forma dinâmica
                if (!class_exists($controllerName)) {
                    throw new Exception("Controlador '$controllerName' não existe.", 500);
                }
                
                $controller = new $controllerName();
                
                if (!method_exists($controller, $action)) {
                    throw new Exception("Método '$action' não encontrado no controlador '$controllerName'.", 500);
                }
                
                // Invoca o método passando os parâmetros extraídos do path (ex: $id)
                $response = call_user_func_array([$controller, $action], $matches);
                
                // Renderiza resposta JSON
                if ($response !== null) {
                    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
                
            } catch (Exception $e) {
                $code = $e->getCode();
                if ($code < 400 || $code > 599) {
                    $code = 500;
                }
                http_response_code($code);
                echo json_encode(['detail' => $e->getMessage()]);
            }
            exit;
        }
    }
}

// Retorna erros HTTP caso não encontre a rota
if ($routeMatched && !$methodMatched) {
    http_response_code(405);
    echo json_encode(['detail' => 'Method Not Allowed']);
} else {
    http_response_code(404);
    echo json_encode(['detail' => 'Not Found']);
}
