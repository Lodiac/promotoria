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

// Verificar que sea POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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
    
    // Detectar si es JSON o form-data
    $content_type = $_SERVER['CONTENT_TYPE'] ?? '';
    if (strpos($content_type, 'application/json') !== false) {
        $input = json_decode(file_get_contents('php://input'), true);
    } else {
        $input = $_POST;
    }

    if (!$input) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'No se recibieron datos'
        ]);
        exit;
    }

    // ===== VALIDAR CAMPOS REQUERIDOS =====
    $required_fields = ['region', 'cadena', 'num_tienda', 'nombre_tienda', 'ciudad', 'estado'];
    $errors = [];

    foreach ($required_fields as $field) {
        if (!isset($input[$field]) || trim($input[$field]) === '') {
            $errors[] = "El campo '{$field}' es requerido";
        }
    }

    if (!empty($errors)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Campos requeridos faltantes',
            'errors' => $errors
        ]);
        exit;
    }

    // ===== SANITIZAR Y VALIDAR DATOS =====
    $region = intval($input['region']);
    $cadena = Database::sanitize(trim($input['cadena']));
    $num_tienda = intval($input['num_tienda']);
    $nombre_tienda = Database::sanitize(trim($input['nombre_tienda']));
    $ciudad = Database::sanitize(trim($input['ciudad']));
    $estado = Database::sanitize(trim($input['estado']));

    // Validaciones básicas
    if ($region <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'La región debe ser un número mayor a 0'
        ]);
        exit;
    }

    if ($num_tienda <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'El número de tienda debe ser mayor a 0'
        ]);
        exit;
    }

    if (strlen($cadena) > 100 || strlen($nombre_tienda) > 100 || strlen($ciudad) > 100 || strlen($estado) > 100) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Los campos de texto no pueden exceder 100 caracteres'
        ]);
        exit;
    }

    // ===== VERIFICAR DUPLICADOS =====
    $sql_check = "SELECT id_tienda 
                  FROM tiendas 
                  WHERE num_tienda = :num_tienda 
                  AND cadena = :cadena 
                  AND estado_reg = 1 
                  LIMIT 1";
    
    $duplicate = Database::selectOne($sql_check, [
        ':num_tienda' => $num_tienda,
        ':cadena' => $cadena
    ]);

    if ($duplicate) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => "Ya existe una tienda con el número {$num_tienda} en la cadena {$cadena}"
        ]);
        exit;
    }

    // ===== INSERTAR NUEVA TIENDA =====
    $sql_insert = "INSERT INTO tiendas (
                        region, 
                        cadena, 
                        num_tienda, 
                        nombre_tienda, 
                        ciudad, 
                        estado, 
                        estado_reg,
                        fecha_alta,
                        fecha_modificacion
                   ) VALUES (
                        :region,
                        :cadena,
                        :num_tienda,
                        :nombre_tienda,
                        :ciudad,
                        :estado,
                        1,
                        NOW(),
                        NOW()
                   )";
    
    $params = [
        ':region' => $region,
        ':cadena' => $cadena,
        ':num_tienda' => $num_tienda,
        ':nombre_tienda' => $nombre_tienda,
        ':ciudad' => $ciudad,
        ':estado' => $estado
    ];

    $new_id = Database::insert($sql_insert, $params);

    if (!$new_id) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'No se pudo crear la tienda'
        ]);
        exit;
    }

    // ===== OBTENER LA TIENDA CREADA =====
    $sql_get = "SELECT 
                    id_tienda,
                    region,
                    cadena,
                    num_tienda,
                    nombre_tienda,
                    ciudad,
                    estado,
                    fecha_alta,
                    fecha_modificacion
                FROM tiendas 
                WHERE id_tienda = :id_tienda";
    
    $nueva_tienda = Database::selectOne($sql_get, [':id_tienda' => $new_id]);

    // Formatear fechas
    if ($nueva_tienda['fecha_alta']) {
        $nueva_tienda['fecha_alta_formatted'] = date('d/m/Y H:i', strtotime($nueva_tienda['fecha_alta']));
    }
    if ($nueva_tienda['fecha_modificacion']) {
        $nueva_tienda['fecha_modificacion_formatted'] = date('d/m/Y H:i', strtotime($nueva_tienda['fecha_modificacion']));
    }

    // ===== LOG DE AUDITORÍA =====
    error_log("Tienda creada - ID: {$new_id} - Nombre: {$nombre_tienda} - Cadena: {$cadena} - Usuario: " . $_SESSION['username'] . " - IP: " . $_SERVER['REMOTE_ADDR']);

    // ===== RESPUESTA EXITOSA =====
    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Tienda creada correctamente',
        'data' => $nueva_tienda
    ]);

} catch (Exception $e) {
    // Log del error
    error_log("Error en create_tienda.php: " . $e->getMessage() . " - Usuario: " . ($_SESSION['username'] ?? 'desconocido') . " - IP: " . $_SERVER['REMOTE_ADDR']);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor'
    ]);
}
?>