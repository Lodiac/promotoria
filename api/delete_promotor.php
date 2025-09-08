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
    // ===== VERIFICAR SESIÓN Y ROL =====
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['rol'])) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'No hay sesión activa'
        ]);
        exit;
    }

    // Verificar permisos (solo root puede eliminar promotores)
    if ($_SESSION['rol'] !== 'root') {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Solo usuarios ROOT pueden eliminar promotores'
        ]);
        exit;
    }

    // ===== OBTENER DATOS =====
    $input = null;
    
    if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        $input = json_decode(file_get_contents('php://input'), true);
    } else {
        // Detectar si es JSON o form-data en POST
        $content_type = $_SERVER['CONTENT_TYPE'] ?? '';
        if (strpos($content_type, 'application/json') !== false) {
            $input = json_decode(file_get_contents('php://input'), true);
        } else {
            $input = $_POST;
        }
    }

    // Validar que se recibió el ID (compatible con ambos formatos)
    $id_promotor = null;
    if (isset($input['id_promotor'])) {
        $id_promotor = $input['id_promotor'];
    } elseif (isset($input['id'])) {
        $id_promotor = $input['id'];
    }

    if (!$id_promotor || empty($id_promotor)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'ID de promotor requerido'
        ]);
        exit;
    }

    $id_promotor = intval($id_promotor);

    // Validar que el ID sea válido
    if ($id_promotor <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'ID de promotor inválido'
        ]);
        exit;
    }

    // ===== VERIFICAR QUE EL PROMOTOR EXISTE Y ESTÁ ACTIVO =====
    $sql_check = "SELECT 
                      id_promotor, 
                      nombre, 
                      apellido, 
                      correo,
                      estatus,
                      estado 
                  FROM promotores 
                  WHERE id_promotor = :id_promotor 
                  LIMIT 1";
    
    $promotor = Database::selectOne($sql_check, [':id_promotor' => $id_promotor]);

    if (!$promotor) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Promotor no encontrado'
        ]);
        exit;
    }

    // Verificar si ya está eliminado (soft delete)
    if ($promotor['estado'] == 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'El promotor ya está eliminado'
        ]);
        exit;
    }

    // ===== REALIZAR SOFT DELETE =====
    $sql_delete = "UPDATE promotores 
                   SET estado = 0,
                       fecha_modificacion = NOW()
                   WHERE id_promotor = :id_promotor 
                   AND estado = 1";
    
    $affected_rows = Database::execute($sql_delete, [':id_promotor' => $id_promotor]);

    if ($affected_rows === 0) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'No se pudo eliminar el promotor'
        ]);
        exit;
    }

    // ===== LOG DE AUDITORÍA =====
    error_log("Promotor eliminado (soft delete) - ID: {$id_promotor} - Nombre: {$promotor['nombre']} {$promotor['apellido']} - Usuario: " . $_SESSION['username'] . " - IP: " . $_SERVER['REMOTE_ADDR']);

    // ===== RESPUESTA EXITOSA =====
    echo json_encode([
        'success' => true,
        'message' => 'Promotor eliminado correctamente',
        'data' => [
            'id_promotor' => $id_promotor,
            'nombre' => $promotor['nombre'],
            'apellido' => $promotor['apellido'],
            'correo' => $promotor['correo']
        ]
    ]);

} catch (Exception $e) {
    // Log del error
    error_log("Error en delete_promotor.php: " . $e->getMessage() . " - Usuario: " . ($_SESSION['username'] ?? 'desconocido') . " - IP: " . $_SERVER['REMOTE_ADDR']);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor'
    ]);
}
?>