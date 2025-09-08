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
    error_log('UPDATE_ASIGNACION: Iniciando - ' . json_encode($debug_info));

    // ===== VERIFICAR SESIÓN BÁSICA =====
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        error_log('UPDATE_ASIGNACION: Sin sesión - user_id no encontrado');
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
        error_log('UPDATE_ASIGNACION: Rol incorrecto - ' . ($_SESSION['rol'] ?? 'NO_SET'));
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Sin permisos para reasignar.',
            'error' => 'insufficient_permissions'
        ]);
        exit;
    }

    error_log('UPDATE_ASIGNACION: Sesión válida - Usuario: ' . ($_SESSION['username'] ?? 'NO_USERNAME') . ', Rol: ' . $_SESSION['rol']);

    // ===== INCLUIR CONEXIÓN DB =====
    $db_path = __DIR__ . '/../config/db_connect.php';
    if (!file_exists($db_path)) {
        error_log('UPDATE_ASIGNACION: Archivo db_connect.php no encontrado en: ' . $db_path);
        throw new Exception('Configuración de base de datos no encontrada');
    }

    require_once $db_path;

    // ===== VERIFICAR CLASE DATABASE =====
    if (!class_exists('Database')) {
        error_log('UPDATE_ASIGNACION: Clase Database no encontrada');
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
    $id_asignacion_actual = intval($input['id_asignacion_actual'] ?? 0);
    $id_tienda_nueva = intval($input['id_tienda_nueva'] ?? 0);
    $fecha_cambio = trim($input['fecha_cambio'] ?? '');
    $motivo_cambio = trim($input['motivo_cambio'] ?? '');
    $motivo_nueva_asignacion = trim($input['motivo_nueva_asignacion'] ?? '');

    error_log('UPDATE_ASIGNACION: Asignación actual: ' . $id_asignacion_actual . ', Nueva tienda: ' . $id_tienda_nueva . ', Fecha: ' . $fecha_cambio);

    // ===== VALIDACIONES BÁSICAS =====
    if ($id_asignacion_actual <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'ID de asignación actual inválido'
        ]);
        exit;
    }

    if ($id_tienda_nueva <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'ID de nueva tienda inválido'
        ]);
        exit;
    }

    if (empty($fecha_cambio)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'La fecha de cambio es requerida'
        ]);
        exit;
    }

    // Validar formato de fecha
    $fecha_cambio_obj = DateTime::createFromFormat('Y-m-d', $fecha_cambio);
    if (!$fecha_cambio_obj) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Formato de fecha inválido (usar YYYY-MM-DD)'
        ]);
        exit;
    }

    if (empty($motivo_cambio)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'El motivo del cambio es requerido'
        ]);
        exit;
    }

    if (empty($motivo_nueva_asignacion)) {
        $motivo_nueva_asignacion = "Reasignación desde otra tienda";
    }

    // ===== VERIFICAR CONEXIÓN DB =====
    try {
        $test_connection = Database::connect();
        if (!$test_connection) {
            throw new Exception('No se pudo establecer conexión con la base de datos');
        }
        error_log('UPDATE_ASIGNACION: Conexión DB exitosa');
    } catch (Exception $conn_error) {
        error_log('UPDATE_ASIGNACION: Error de conexión DB - ' . $conn_error->getMessage());
        throw new Exception('Error de conexión a la base de datos: ' . $conn_error->getMessage());
    }

    // ===== VERIFICAR ASIGNACIÓN ACTUAL =====
    $sql_asignacion_actual = "SELECT 
                                 pta.id_asignacion,
                                 pta.id_promotor,
                                 pta.id_tienda,
                                 pta.fecha_inicio,
                                 pta.fecha_fin,
                                 pta.activo,
                                 
                                 p.nombre as promotor_nombre,
                                 p.apellido as promotor_apellido,
                                 p.estatus as promotor_estatus,
                                 
                                 t.cadena as tienda_actual_cadena,
                                 t.num_tienda as tienda_actual_num,
                                 t.nombre_tienda as tienda_actual_nombre
                              FROM promotor_tienda_asignaciones pta
                              INNER JOIN promotores p ON pta.id_promotor = p.id_promotor
                              INNER JOIN tiendas t ON pta.id_tienda = t.id_tienda
                              WHERE pta.id_asignacion = :id_asignacion
                              AND p.estado = 1 AND t.estado_reg = 1";
    
    $asignacion_actual = Database::selectOne($sql_asignacion_actual, [':id_asignacion' => $id_asignacion_actual]);

    if (!$asignacion_actual) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Asignación actual no encontrada'
        ]);
        exit;
    }

    // Verificar que esté activa
    if (!$asignacion_actual['activo'] || $asignacion_actual['fecha_fin']) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'La asignación actual no está activa'
        ]);
        exit;
    }

    // Verificar que no sea la misma tienda
    if ($asignacion_actual['id_tienda'] == $id_tienda_nueva) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'El promotor ya está asignado a esa tienda'
        ]);
        exit;
    }

    // Verificar fecha no anterior al inicio
    $fecha_inicio_obj = new DateTime($asignacion_actual['fecha_inicio']);
    if ($fecha_cambio_obj < $fecha_inicio_obj) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'La fecha de cambio no puede ser anterior a la fecha de inicio de la asignación actual'
        ]);
        exit;
    }

    // ===== VERIFICAR NUEVA TIENDA =====
    $sql_tienda_nueva = "SELECT id_tienda, region, cadena, num_tienda, nombre_tienda, ciudad, estado 
                         FROM tiendas 
                         WHERE id_tienda = :id_tienda AND estado_reg = 1";
    
    $tienda_nueva = Database::selectOne($sql_tienda_nueva, [':id_tienda' => $id_tienda_nueva]);

    if (!$tienda_nueva) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Nueva tienda no encontrada o inactiva'
        ]);
        exit;
    }

    // ===== VERIFICAR QUE EL PROMOTOR NO ESTÉ YA ASIGNADO A LA NUEVA TIENDA =====
    $sql_check_duplicado = "SELECT id_asignacion 
                            FROM promotor_tienda_asignaciones 
                            WHERE id_promotor = :id_promotor 
                            AND id_tienda = :id_tienda 
                            AND activo = 1 
                            AND fecha_fin IS NULL";
    
    $duplicado = Database::selectOne($sql_check_duplicado, [
        ':id_promotor' => $asignacion_actual['id_promotor'],
        ':id_tienda' => $id_tienda_nueva
    ]);

    if ($duplicado) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'Este promotor ya está asignado a la nueva tienda'
        ]);
        exit;
    }

    // ===== INICIAR TRANSACCIÓN =====
    $connection = Database::connect();
    $connection->beginTransaction();

    try {
        // ===== PASO 1: FINALIZAR ASIGNACIÓN ACTUAL =====
        $sql_finalizar = "UPDATE promotor_tienda_asignaciones 
                          SET fecha_fin = :fecha_fin,
                              motivo_cambio = :motivo_cambio,
                              usuario_cambio = :usuario_cambio,
                              fecha_modificacion = NOW()
                          WHERE id_asignacion = :id_asignacion";
        
        $params_finalizar = [
            ':fecha_fin' => $fecha_cambio,
            ':motivo_cambio' => $motivo_cambio,
            ':usuario_cambio' => $_SESSION['user_id'],
            ':id_asignacion' => $id_asignacion_actual
        ];

        $affected_finalizar = Database::execute($sql_finalizar, $params_finalizar);

        if ($affected_finalizar === 0) {
            throw new Exception('No se pudo finalizar la asignación actual');
        }

        // ===== PASO 2: CREAR NUEVA ASIGNACIÓN =====
        $sql_nueva = "INSERT INTO promotor_tienda_asignaciones (
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
        
        $params_nueva = [
            ':id_promotor' => $asignacion_actual['id_promotor'],
            ':id_tienda' => $id_tienda_nueva,
            ':fecha_inicio' => $fecha_cambio,
            ':motivo_asignacion' => $motivo_nueva_asignacion,
            ':usuario_asigno' => $_SESSION['user_id']
        ];

        $nueva_asignacion_id = Database::insert($sql_nueva, $params_nueva);

        if (!$nueva_asignacion_id) {
            throw new Exception('No se pudo crear la nueva asignación');
        }

        // ===== PASO 3: REGISTRAR EN LOG DE ACTIVIDADES =====
        $detalle_log = "Reasignación de tienda: Promotor {$asignacion_actual['promotor_nombre']} {$asignacion_actual['promotor_apellido']} movido de {$asignacion_actual['tienda_actual_cadena']} #{$asignacion_actual['tienda_actual_num']} a {$tienda_nueva['cadena']} #{$tienda_nueva['num_tienda']}. Motivo: {$motivo_cambio}";
        
        $sql_log = "INSERT INTO log_actividades (tabla, accion, id_registro, usuario_id, fecha, detalles) 
                    VALUES ('promotor_tienda_asignaciones', 'REASIGNACION', :id_registro, :usuario_id, NOW(), :detalles)";
        
        Database::insert($sql_log, [
            ':id_registro' => $nueva_asignacion_id,
            ':usuario_id' => $_SESSION['user_id'],
            ':detalles' => $detalle_log
        ]);

        // ===== CONFIRMAR TRANSACCIÓN =====
        $connection->commit();
        
        error_log("REASIGNACION_EXITOSA: " . $detalle_log . " - Usuario: " . $_SESSION['username']);

    } catch (Exception $transaction_error) {
        // Revertir transacción
        $connection->rollback();
        throw $transaction_error;
    }

    // ===== OBTENER DATOS COMPLETOS DE LA NUEVA ASIGNACIÓN =====
    $sql_nueva_completa = "SELECT 
                              pta.id_asignacion,
                              pta.id_promotor,
                              pta.id_tienda,
                              pta.fecha_inicio,
                              pta.motivo_asignacion,
                              pta.fecha_registro,
                              
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
    
    $nueva_asignacion_completa = Database::selectOne($sql_nueva_completa, [':id_asignacion' => $nueva_asignacion_id]);

    // ===== CALCULAR DURACIÓN DE LA ASIGNACIÓN ANTERIOR =====
    $duracion_anterior = $fecha_cambio_obj->diff($fecha_inicio_obj)->days;

    // ===== FORMATEAR RESPUESTA =====
    $response_data = [
        'asignacion_finalizada' => [
            'id_asignacion' => intval($id_asignacion_actual),
            'tienda_anterior_identificador' => $asignacion_actual['tienda_actual_cadena'] . ' #' . $asignacion_actual['tienda_actual_num'] . ' - ' . $asignacion_actual['tienda_actual_nombre'],
            'duracion_dias' => $duracion_anterior,
            'fecha_fin' => $fecha_cambio,
            'motivo_finalizacion' => $motivo_cambio
        ],
        'asignacion_nueva' => [
            'id_asignacion' => intval($nueva_asignacion_completa['id_asignacion']),
            'promotor_nombre_completo' => trim($nueva_asignacion_completa['promotor_nombre'] . ' ' . $nueva_asignacion_completa['promotor_apellido']),
            'promotor_telefono' => $nueva_asignacion_completa['promotor_telefono'],
            'promotor_correo' => $nueva_asignacion_completa['promotor_correo'],
            'tienda_identificador' => $nueva_asignacion_completa['cadena'] . ' #' . $nueva_asignacion_completa['num_tienda'] . ' - ' . $nueva_asignacion_completa['nombre_tienda'],
            'tienda_region' => intval($nueva_asignacion_completa['region']),
            'tienda_cadena' => $nueva_asignacion_completa['cadena'],
            'tienda_num_tienda' => intval($nueva_asignacion_completa['num_tienda']),
            'tienda_nombre_tienda' => $nueva_asignacion_completa['nombre_tienda'],
            'tienda_ciudad' => $nueva_asignacion_completa['ciudad'],
            'fecha_inicio' => $nueva_asignacion_completa['fecha_inicio'],
            'motivo_asignacion' => $nueva_asignacion_completa['motivo_asignacion'],
            'usuario_asigno' => trim($nueva_asignacion_completa['usuario_nombre'] . ' ' . $nueva_asignacion_completa['usuario_apellido'])
        ],
        'resumen' => [
            'fecha_cambio' => $fecha_cambio,
            'fecha_cambio_formateada' => $fecha_cambio_obj->format('d/m/Y'),
            'motivo_cambio' => $motivo_cambio,
            'duracion_asignacion_anterior' => $duracion_anterior,
            'tipo_operacion' => 'cambio_tienda',
            'multiples_promotores_habilitado' => true
        ]
    ];

    // ===== RESPUESTA EXITOSA =====
    echo json_encode([
        'success' => true,
        'message' => 'Reasignación completada correctamente',
        'data' => $response_data
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    // Log del error
    error_log("Error en update_asignacion.php: " . $e->getMessage() . " - Usuario: " . ($_SESSION['username'] ?? 'desconocido') . " - IP: " . $_SERVER['REMOTE_ADDR']);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor: ' . $e->getMessage()
    ]);
}
?>