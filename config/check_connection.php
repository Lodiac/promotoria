<?php
/**
 * Verificador simple de conexión
 * No requiere APP_ACCESS para permitir verificaciones AJAX
 */

// Headers para evitar cache
header('Cache-Control: no-cache, must-revalidate');
header('Content-Type: application/json');

// Definir la constante antes de incluir db_connect
define('APP_ACCESS', true);

try {
    // Incluir la conexión
    require_once __DIR__ . '/db_connect.php';
    
    // Intentar conectar
    $pdo = Database::connect();
    
    // Hacer una consulta simple para verificar
    $stmt = $pdo->query("SELECT 1 as test");
    $result = $stmt->fetch();
    
    if ($result && $result['test'] == 1) {
        // Conexión exitosa
        echo json_encode([
            'status' => 'online',
            'message' => 'Conexión activa',
            'timestamp' => time()
        ]);
    } else {
        throw new Exception('Test query failed');
    }
    
} catch (Exception $e) {
    // Error de conexión
    http_response_code(503); // Service Unavailable
    echo json_encode([
        'status' => 'offline',
        'message' => 'Error de conexión',
        'error' => 'Database connection failed',
        'timestamp' => time()
    ]);
}
?>