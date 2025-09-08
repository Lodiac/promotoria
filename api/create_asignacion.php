<?php

// Habilitar logging de errores para debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// 🔑 DEFINIR CONSTANTE ANTES DE INCLUIR DB_CONNECT
define('APP_ACCESS', true);

// Headers de seguridad y CORS
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// Solo permitir POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Método no permitido'
    ]);
    exit;
}

try {
    // ===== LOG PARA DEBUGGING =====
    $debug_info = [
        'timestamp' => date('Y-m-d H:i:s'),
        'method' => $_SERVER['REQUEST_METHOD'],
        'content_type' => $_SERVER['CONTENT_TYPE'] ?? '',
        'session_status' => session_status(),
        'session_id' => session_id()
    ];
    error_log('CREATE_ASIGNACION: Iniciando - ' . json_encode($debug_info));

    // ===== VERIFICAR SESIÓN BÁSICA =====
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        error_log('CREATE_ASIGNACION: Sin sesión - user_id no encontrado');
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'No hay sesión activa',
            'error' => 'no_session'
        ]);
        exit;
    }

    // ===== VERIFICAR ROL =====
    if (!isset($_SESSION['rol']) || !in_array($_SESSION['rol'], ['supervisor', 'root'])) {
        error_log('CREATE_ASIGNACION: Rol incorrecto - ' . ($_SESSION['rol'] ?? 'NO_SET'));
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Sin permisos para crear asignaciones.',
            'error' => 'insufficient_permissions'
        ]);
        exit;
    }

    error_log('CREATE_ASIGNACION: Sesión válida - Usuario: ' . ($_SESSION['username'] ?? 'NO_USERNAME') . ', Rol: ' . $_SESSION['rol']);

    // ===== INCLUIR CONEXIÓN DB =====
    $db_path = __DIR__ . '/../config/db_connect.php';
    if (!file_exists($db_path)) {
        error_log('CREATE_ASIGNACION: Archivo db_connect.php no encontrado en: ' . $db_path);
        throw new Exception('Configuración de base de datos no encontrada');
    }

    require_once $db_path;

    // ===== VERIFICAR CLASE DATABASE =====
    if (!class_exists('Database')) {
        error_log('CREATE_ASIGNACION: Clase Database no encontrada');
        throw new Exception('Clase Database no disponible');
    }

    // ===== OBTENER DATOS DEL POST =====
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

    // Validar datos requeridos
    $id_promotor = intval($input['id_promotor'] ?? 0);
    $id_tienda = intval($input['id_tienda'] ?? 0);
    $fecha_inicio = trim($input['fecha_inicio'] ?? '');
    $motivo_asignacion = trim($input['motivo_asignacion'] ?? '');

    error_log('CREATE_ASIGNACION: Datos recibidos - Promotor: ' . $id_promotor . ', Tienda: ' . $id_tienda . ', Fecha: ' . $fecha_inicio);

    // ===== VALIDACIONES BÁSICAS =====
    if ($id_promotor <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'ID de promotor inválido'
        ]);
        exit;
    }

    if ($id_tienda <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'ID de tienda inválido'
        ]);
        exit;
    }

    if (empty($fecha_inicio)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'La fecha de inicio es requerida'
        ]);
        exit;
    }

    // Validar formato de fecha
    $fecha_inicio_obj = DateTime::createFromFormat('Y-m-d', $fecha_inicio);
    if (!$fecha_inicio_obj) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Formato de fecha inválido (usar YYYY-MM-DD)'
        ]);
        exit;
    }

    if (empty($motivo_asignacion)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'El motivo de asignación es requerido'
        ]);
        exit;
    }

    if (strlen($motivo_asignacion) > 255) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'El motivo no puede exceder 255 caracteres'
        ]);
        exit;
    }

    // ===== VERIFICAR CONEXIÓN DB =====
    try {
        $test_connection = Database::connect();
        if (!$test_connection) {
            throw new Exception('No se pudo establecer conexión con la base de datos');
        }
        error_log('CREATE_ASIGNACION: Conexión DB exitosa');
    } catch (Exception $conn_error) {
        error_log('CREATE_ASIGNACION: Error de conexión DB - ' . $conn_error->getMessage());
        throw new Exception('Error de conexión a la base de datos: ' . $conn_error->getMessage());
    }

    // ===== VERIFICAR QUE EL PROMOTOR EXISTE Y ESTÁ ACTIVO =====
    $sql_promotor = "SELECT id_promotor, nombre, apellido, estatus 
                     FROM promotores 
                     WHERE id_promotor = :id_promotor AND estado = 1";
    
    $promotor = Database::selectOne($sql_promotor, [':id_promotor' => $id_promotor]);

    if (!$promotor) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Promotor no encontrado o inactivo'
        ]);
        exit;
    }

    if ($promotor['estatus'] !== 'ACTIVO') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'El promotor debe estar en estatus ACTIVO para ser asignado'
        ]);
        exit;
    }

    // ===== VERIFICAR QUE LA TIENDA EXISTE Y ESTÁ ACTIVA =====
    $sql_tienda = "SELECT id_tienda, region, cadena, num_tienda, nombre_tienda, ciudad, estado 
                   FROM tiendas 
                   WHERE id_tienda = :id_tienda AND estado_reg = 1";
    
    $tienda = Database::selectOne($sql_tienda, [':id_tienda' => $id_tienda]);

    if (!$tienda) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Tienda no encontrada o inactiva'
        ]);
        exit;
    }

    // ===== VERIFICAR QUE EL PROMOTOR NO TENGA ASIGNACIÓN ACTIVA =====
    $sql_check_promotor = "SELECT id_asignacion, id_tienda, fecha_inicio 
                           FROM promotor_tienda_asignaciones 
                           WHERE id_promotor = :id_promotor 
                           AND activo = 1 
                           AND fecha_fin IS NULL";
    
    $asignacion_activa_promotor = Database::selectOne($sql_check_promotor, [':id_promotor' => $id_promotor]);

    if ($asignacion_activa_promotor) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'El promotor ya tiene una asignación activa. Debe finalizar la asignación actual antes de crear una nueva.',
            'asignacion_activa' => $asignacion_activa_promotor
        ]);
        exit;
    }

    // ===== VERIFICAR SI EL PROMOTOR YA ESTÁ ASIGNADO A ESTA MISMA TIENDA =====
    $sql_check_duplicado = "SELECT id_asignacion 
                            FROM promotor_tienda_asignaciones 
                            WHERE id_promotor = :id_promotor 
                            AND id_tienda = :id_tienda 
                            AND activo = 1 
                            AND fecha_fin IS NULL";
    
    $duplicado = Database::selectOne($sql_check_duplicado, [
        ':id_promotor' => $id_promotor,
        ':id_tienda' => $id_tienda
    ]);

    if ($duplicado) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'Este promotor ya está asignado a esta tienda'
        ]);
        exit;
    }

    // ===== CREAR LA NUEVA ASIGNACIÓN =====
    $sql_insert = "INSERT INTO promotor_tienda_asignaciones (
                        id_promotor,
                        id_tienda,
                        fecha_inicio,
                        fecha_fin,
                        motivo_asignacion,
                        motivo_cambio,
                        usuario_asigno,
                        usuario_cambio,
                        fecha_registro,
                        fecha_modificacion,
                        activo
                   ) VALUES (
                        :id_promotor,
                        :id_tienda,
                        :fecha_inicio,
                        NULL,
                        :motivo_asignacion,
                        NULL,
                        :usuario_asigno,
                        NULL,
                        NOW(),
                        NOW(),
                        1
                   )";
    
    $params_insert = [
        ':id_promotor' => $id_promotor,
        ':id_tienda' => $id_tienda,
        ':fecha_inicio' => $fecha_inicio,
        ':motivo_asignacion' => $motivo_asignacion,
        ':usuario_asigno' => $_SESSION['user_id']
    ];

    $new_id = Database::insert($sql_insert, $params_insert);

    if (!$new_id) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'No se pudo crear la asignación'
        ]);
        exit;
    }

    // ===== REGISTRAR EN LOG DE ACTIVIDADES =====
    try {
        $detalle_log = "Asignación creada: Promotor {$promotor['nombre']} {$promotor['apellido']} asignado a tienda {$tienda['cadena']} #{$tienda['num_tienda']} - {$tienda['nombre_tienda']}. Motivo: {$motivo_asignacion}";
        
        $sql_log = "INSERT INTO log_actividades (tabla, accion, id_registro, usuario_id, fecha, detalles) 
                    VALUES ('promotor_tienda_asignaciones', 'CREATE', :id_registro, :usuario_id, NOW(), :detalles)";
        
        Database::insert($sql_log, [
            ':id_registro' => $new_id,
            ':usuario_id' => $_SESSION['user_id'],
            ':detalles' => $detalle_log
        ]);
        
        error_log("LOG_ASIGNACION: " . $detalle_log . " - Usuario: " . $_SESSION['username']);
    } catch (Exception $log_error) {
        // Log del error pero no fallar la operación principal
        error_log("Error registrando en log_actividades: " . $log_error->getMessage());
    }

    // ===== OBTENER LA ASIGNACIÓN CREADA CON TODOS LOS DATOS =====
    $sql_get_created = "SELECT 
                            pta.id_asignacion,
                            pta.id_promotor,
                            pta.id_tienda,
                            pta.fecha_inicio,
                            pta.fecha_fin,
                            pta.motivo_asignacion,
                            pta.activo,
                            pta.fecha_registro,
                            pta.fecha_modificacion,
                            
                            p.nombre as promotor_nombre,
                            p.apellido as promotor_apellido,
                            p.telefono as promotor_telefono,
                            p.correo as promotor_correo,
                            
                            t.region,
                            t.cadena,
                            t.num_tienda,
                            t.nombre_tienda,
                            t.ciudad,
                            t.estado as tienda_estado,
                            
                            u.nombre as usuario_nombre,
                            u.apellido as usuario_apellido
                        FROM promotor_tienda_asignaciones pta
                        INNER JOIN promotores p ON pta.id_promotor = p.id_promotor
                        INNER JOIN tiendas t ON pta.id_tienda = t.id_tienda
                        INNER JOIN usuarios u ON pta.usuario_asigno = u.id
                        WHERE pta.id_asignacion = :id_asignacion";
    
    $asignacion_creada = Database::selectOne($sql_get_created, [':id_asignacion' => $new_id]);

    // Formatear respuesta
    $response_data = [
        'id_asignacion' => intval($asignacion_creada['id_asignacion']),
        'promotor_nombre_completo' => trim($asignacion_creada['promotor_nombre'] . ' ' . $asignacion_creada['promotor_apellido']),
        'promotor_telefono' => $asignacion_creada['promotor_telefono'],
        'promotor_correo' => $asignacion_creada['promotor_correo'],
        'tienda_identificador' => $asignacion_creada['cadena'] . ' #' . $asignacion_creada['num_tienda'] . ' - ' . $asignacion_creada['nombre_tienda'],
        'tienda_region' => intval($asignacion_creada['region']),
        'tienda_cadena' => $asignacion_creada['cadena'],
        'tienda_num_tienda' => intval($asignacion_creada['num_tienda']),
        'tienda_nombre_tienda' => $asignacion_creada['nombre_tienda'],
        'tienda_ciudad' => $asignacion_creada['ciudad'],
        'tienda_estado' => $asignacion_creada['tienda_estado'],
        'fecha_inicio' => $asignacion_creada['fecha_inicio'],
        'motivo_asignacion' => $asignacion_creada['motivo_asignacion'],
        'activo' => intval($asignacion_creada['activo']),
        'fecha_registro' => $asignacion_creada['fecha_registro'],
        'usuario_asigno' => trim($asignacion_creada['usuario_nombre'] . ' ' . $asignacion_creada['usuario_apellido'])
    ];

    // ===== RESPUESTA EXITOSA =====
    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Asignación creada correctamente',
        'data' => $response_data
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    // Log del error
    error_log("Error en create_asignacion.php: " . $e->getMessage() . " - Usuario: " . ($_SESSION['username'] ?? 'desconocido') . " - IP: " . $_SERVER['REMOTE_ADDR']);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor'
    ]);
}
?>