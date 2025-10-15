<?php
session_start();

// 🔐 DEFINIR CONSTANTE ANTES DE INCLUIR DB_CONNECT
define('APP_ACCESS', true);

// Incluir la API de base de datos
require_once __DIR__ . '/../config/db_connect.php';

// Headers de seguridad y CORS
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// Verificar que sea PUT o POST (para compatibilidad)
if (!in_array($_SERVER['REQUEST_METHOD'], ['PUT', 'POST'])) {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Método no permitido'
    ]);
    exit;
}

try {
    // ===== VERIFICAR SESIÓN Y ROL ROOT =====
    // ===== VERIFICAR ROL =====
$roles_permitidos = ['root', 'supervisor'];
if (!isset($_SESSION['rol']) || !in_array(strtolower($_SESSION['rol']), $roles_permitidos)) {
    error_log('UPDATE_TIENDA: Acceso denegado - Rol: ' . ($_SESSION['rol'] ?? 'NO_SET'));
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Acceso denegado. Se requiere rol ROOT o SUPERVISOR para editar tiendas.',
        'error' => 'insufficient_permissions'
    ]);
    exit;
}

    // ===== OBTENER DATOS =====
    $input = null;
    
    if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
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

    if (!$input) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'No se recibieron datos'
        ]);
        exit;
    }

    // ===== VALIDAR ID DE TIENDA =====
    if (!isset($input['id_tienda']) || empty($input['id_tienda'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'ID de tienda requerido'
        ]);
        exit;
    }

    $id_tienda = intval($input['id_tienda']);

    if ($id_tienda <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'ID de tienda inválido'
        ]);
        exit;
    }

    // ===== VERIFICAR QUE LA TIENDA EXISTE Y ESTÁ ACTIVA (INCLUYE NUEVOS CAMPOS) =====
    $sql_check = "SELECT 
                      id_tienda,
                      region,
                      cadena,
                      num_tienda,
                      nombre_tienda,
                      ciudad,
                      estado,
                      promotorio_ideal,
                      tipo,
                      categoria,
                      comision,
                      estado_reg
                  FROM tiendas 
                  WHERE id_tienda = :id_tienda 
                  LIMIT 1";
    
    $tienda_actual = Database::selectOne($sql_check, [':id_tienda' => $id_tienda]);

    if (!$tienda_actual) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Tienda no encontrada'
        ]);
        exit;
    }

    if ($tienda_actual['estado_reg'] == 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'No se puede actualizar una tienda eliminada'
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

    // ===== SANITIZAR Y VALIDAR DATOS (INCLUYE NUEVOS CAMPOS) =====
    $region = intval($input['region']);
    $cadena = Database::sanitize(trim($input['cadena']));
    $num_tienda = intval($input['num_tienda']);
    $nombre_tienda = Database::sanitize(trim($input['nombre_tienda']));
    $ciudad = Database::sanitize(trim($input['ciudad']));
    $estado = Database::sanitize(trim($input['estado']));
    
    // CAMPOS EXISTENTES (OPCIONALES)
    $tipo = Database::sanitize(trim($input['tipo'] ?? ''));
    $promotorio_ideal = !empty($input['promotorio_ideal']) ? intval($input['promotorio_ideal']) : null;
    
    // NUEVOS CAMPOS
    $categoria = Database::sanitize(trim($input['categoria'] ?? ''));
    $comision = isset($input['comision']) ? floatval($input['comision']) : 0.00;

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

    // Validaciones para campos existentes
    if (!empty($tipo) && strlen($tipo) > 100) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'El campo tipo no puede exceder 100 caracteres'
        ]);
        exit;
    }

    if ($promotorio_ideal !== null && ($promotorio_ideal < 1 || $promotorio_ideal > 20)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'El promotorio ideal debe ser un número entre 1 y 20'
        ]);
        exit;
    }

    // Validaciones para NUEVOS CAMPOS
    if (!empty($categoria) && strlen($categoria) > 100) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'El campo categoría no puede exceder 100 caracteres'
        ]);
        exit;
    }

    if ($comision < 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'La comisión no puede ser negativa'
        ]);
        exit;
    }

    // ===== VERIFICAR DUPLICADOS (EXCLUYENDO LA TIENDA ACTUAL) =====
    $sql_duplicate = "SELECT id_tienda 
                      FROM tiendas 
                      WHERE num_tienda = :num_tienda 
                      AND cadena = :cadena 
                      AND estado_reg = 1 
                      AND id_tienda != :id_tienda
                      LIMIT 1";
    
    $duplicate = Database::selectOne($sql_duplicate, [
        ':num_tienda' => $num_tienda,
        ':cadena' => $cadena,
        ':id_tienda' => $id_tienda
    ]);

    if ($duplicate) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => "Ya existe otra tienda con el número {$num_tienda} en la cadena {$cadena}"
        ]);
        exit;
    }

    // ===== ACTUALIZAR TIENDA (INCLUYE NUEVOS CAMPOS) =====
    $sql_update = "UPDATE tiendas SET
                       region = :region,
                       cadena = :cadena,
                       num_tienda = :num_tienda,
                       nombre_tienda = :nombre_tienda,
                       ciudad = :ciudad,
                       estado = :estado,
                       promotorio_ideal = :promotorio_ideal,
                       tipo = :tipo,
                       categoria = :categoria,
                       comision = :comision,
                       fecha_modificacion = NOW()
                   WHERE id_tienda = :id_tienda 
                   AND estado_reg = 1";
    
    $params = [
        ':region' => $region,
        ':cadena' => $cadena,
        ':num_tienda' => $num_tienda,
        ':nombre_tienda' => $nombre_tienda,
        ':ciudad' => $ciudad,
        ':estado' => $estado,
        ':promotorio_ideal' => $promotorio_ideal,
        ':tipo' => !empty($tipo) ? $tipo : null,
        ':categoria' => !empty($categoria) ? $categoria : null,
        ':comision' => $comision,
        ':id_tienda' => $id_tienda
    ];

    $affected_rows = Database::execute($sql_update, $params);

    if ($affected_rows === 0) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'No se pudo actualizar la tienda'
        ]);
        exit;
    }

    // ===== OBTENER LA TIENDA ACTUALIZADA (INCLUYE NUEVOS CAMPOS) =====
    $sql_get = "SELECT 
                    id_tienda,
                    region,
                    cadena,
                    num_tienda,
                    nombre_tienda,
                    ciudad,
                    estado,
                    promotorio_ideal,
                    tipo,
                    categoria,
                    comision,
                    fecha_alta,
                    fecha_modificacion
                FROM tiendas 
                WHERE id_tienda = :id_tienda";
    
    $tienda_actualizada = Database::selectOne($sql_get, [':id_tienda' => $id_tienda]);

    // Formatear fechas y datos
    if ($tienda_actualizada['fecha_alta']) {
        $tienda_actualizada['fecha_alta_formatted'] = date('d/m/Y H:i', strtotime($tienda_actualizada['fecha_alta']));
    }
    if ($tienda_actualizada['fecha_modificacion']) {
        $tienda_actualizada['fecha_modificacion_formatted'] = date('d/m/Y H:i', strtotime($tienda_actualizada['fecha_modificacion']));
    }
    if ($tienda_actualizada['comision'] !== null) {
        $tienda_actualizada['comision_formatted'] = number_format($tienda_actualizada['comision'], 2);
    }

    // ===== LOG DE AUDITORÍA =====
    error_log("Tienda actualizada - ID: {$id_tienda} - Nombre: {$nombre_tienda} - Cadena: {$cadena} - Tipo: " . ($tipo ?: 'N/A') . " - Promotorio: " . ($promotorio_ideal ?: 'N/A') . " - Categoría: " . ($categoria ?: 'N/A') . " - Comisión: {$comision} - Usuario: " . $_SESSION['username'] . " - IP: " . $_SERVER['REMOTE_ADDR']);

    // ===== RESPUESTA EXITOSA (INCLUYE NUEVOS CAMPOS EN CHANGES) =====
    echo json_encode([
        'success' => true,
        'message' => 'Tienda actualizada correctamente',
        'data' => $tienda_actualizada,
        'changes' => [
            'region' => $tienda_actual['region'] !== $region,
            'cadena' => $tienda_actual['cadena'] !== $cadena,
            'num_tienda' => $tienda_actual['num_tienda'] !== $num_tienda,
            'nombre_tienda' => $tienda_actual['nombre_tienda'] !== $nombre_tienda,
            'ciudad' => $tienda_actual['ciudad'] !== $ciudad,
            'estado' => $tienda_actual['estado'] !== $estado,
            'promotorio_ideal' => $tienda_actual['promotorio_ideal'] !== $promotorio_ideal,
            'tipo' => $tienda_actual['tipo'] !== (!empty($tipo) ? $tipo : null),
            'categoria' => $tienda_actual['categoria'] !== (!empty($categoria) ? $categoria : null),
            'comision' => abs($tienda_actual['comision'] - $comision) > 0.001 // Comparación de decimales
        ]
    ]);

} catch (Exception $e) {
    // Log del error
    error_log("Error en update_tienda.php: " . $e->getMessage() . " - Usuario: " . ($_SESSION['username'] ?? 'desconocido') . " - IP: " . $_SERVER['REMOTE_ADDR']);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor'
    ]);
}
?>