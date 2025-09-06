<?php
session_start();

// 🔑 DEFINIR CONSTANTE ANTES DE INCLUIR DB_CONNECT
define('APP_ACCESS', true);

// Incluir la API de base de datos
require_once __DIR__ . '/../config/db_connect.php';

// Headers de seguridad y CORS
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// Verificar que sea DELETE o POST (para compatibilidad)
if (!in_array($_SERVER['REQUEST_METHOD'], ['DELETE', 'POST'])) {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Método no permitido'
    ]);
    exit;
}

try {
    // ===== VERIFICAR SESIÓN Y ROL ROOT =====
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['rol']) || $_SESSION['rol'] !== 'root') {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Acceso denegado. Se requiere rol ROOT.'
        ]);
        exit;
    }

    // ===== OBTENER DATOS =====
    $input = null;
    
    if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        $input = json_decode(file_get_contents('php://input'), true);
    } else {
        $input = $_POST;
    }

    // Validar que se recibió el ID
    if (!isset($input['id_tienda']) || empty($input['id_tienda'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'ID de tienda requerido'
        ]);
        exit;
    }

    $id_tienda = intval($input['id_tienda']);

    // Validar que el ID sea válido
    if ($id_tienda <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'ID de tienda inválido'
        ]);
        exit;
    }

    // ===== VERIFICAR QUE LA TIENDA EXISTE Y ESTÁ ACTIVA =====
    $sql_check = "SELECT id_tienda, nombre_tienda, cadena, estado_reg 
                  FROM tiendas 
                  WHERE id_tienda = :id_tienda 
                  LIMIT 1";
    
    $tienda = Database::selectOne($sql_check, [':id_tienda' => $id_tienda]);

    if (!$tienda) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Tienda no encontrada'
        ]);
        exit;
    }

    // Verificar si ya está eliminada (soft delete)
    if ($tienda['estado_reg'] == 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'La tienda ya está eliminada'
        ]);
        exit;
    }

    // ===== REALIZAR SOFT DELETE =====
    $sql_delete = "UPDATE tiendas 
                   SET estado_reg = 0,
                       fecha_modificacion = NOW()
                   WHERE id_tienda = :id_tienda 
                   AND estado_reg = 1";
    
    $affected_rows = Database::execute($sql_delete, [':id_tienda' => $id_tienda]);

    if ($affected_rows === 0) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'No se pudo eliminar la tienda'
        ]);
        exit;
    }

    // ===== LOG DE AUDITORÍA =====
    error_log("Tienda eliminada (soft delete) - ID: {$id_tienda} - Nombre: {$tienda['nombre_tienda']} - Usuario: " . $_SESSION['username'] . " - IP: " . $_SERVER['REMOTE_ADDR']);

    // ===== RESPUESTA EXITOSA =====
    echo json_encode([
        'success' => true,
        'message' => 'Tienda eliminada correctamente',
        'data' => [
            'id_tienda' => $id_tienda,
            'nombre_tienda' => $tienda['nombre_tienda'],
            'cadena' => $tienda['cadena']
        ]
    ]);

} catch (Exception $e) {
    // Log del error
    error_log("Error en delete_tienda.php: " . $e->getMessage() . " - Usuario: " . ($_SESSION['username'] ?? 'desconocido') . " - IP: " . $_SERVER['REMOTE_ADDR']);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor'
    ]);
}
?>