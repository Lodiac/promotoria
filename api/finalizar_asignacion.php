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
    error_log('FINALIZAR_ASIGNACION: Iniciando - ' . json_encode($debug_info));

    // ===== VERIFICAR SESIÓN BÁSICA =====
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        error_log('FINALIZAR_ASIGNACION: Sin sesión - user_id no encontrado');
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
        error_log('FINALIZAR_ASIGNACION: Rol incorrecto - ' . ($_SESSION['rol'] ?? 'NO_SET'));
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Sin permisos para finalizar asignaciones.',
            'error' => 'insufficient_permissions'
        ]);
        exit;
    }

    error_log('FINALIZAR_ASIGNACION: Sesión válida - Usuario: ' . ($_SESSION['username'] ?? 'NO_USERNAME') . ', Rol: ' . $_SESSION['rol']);

    // ===== INCLUIR CONEXIÓN DB =====
    $db_path = __DIR__ . '/../config/db_connect.php';
    if (!file_exists($db_path)) {
        error_log('FINALIZAR_ASIGNACION: Archivo db_connect.php no encontrado en: ' . $db_path);
        throw new Exception('Configuración de base de datos no encontrada');
    }

    require_once $db_path;

    // ===== VERIFICAR CLASE DATABASE =====
    if (!class_exists('Database')) {
        error_log('FINALIZAR_ASIGNACION: Clase Database no encontrada');
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
    $id_asignacion = intval($input['id_asignacion'] ?? 0);
    $fecha_fin = trim($input['fecha_fin'] ?? '');
    $motivo_cambio = trim($input['motivo_cambio'] ?? '');
    $desactivar = ($input['desactivar'] ?? 'true') === 'true'; // Por defecto desactivar

    error_log('FINALIZAR_ASIGNACION: ID: ' . $id_asignacion . ', Fecha fin: ' . $fecha_fin . ', Desactivar: ' . ($desactivar ? 'true' : 'false'));

    // ===== VALIDACIONES BÁSICAS =====
    if ($id_asignacion <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'ID de asignación inválido'
        ]);
        exit;
    }

    if (empty($fecha_fin)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'La fecha de finalización es requerida'
        ]);
        exit;
    }

    // Validar formato de fecha
    $fecha_fin_obj = DateTime::createFromFormat('Y-m-d', $fecha_fin);
    if (!$fecha_fin_obj) {
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
            'message' => 'El motivo de finalización es requerido'
        ]);
        exit;
    }

    if (strlen($motivo_cambio) > 255) {
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
        error_log('FINALIZAR_ASIGNACION: Conexión DB exitosa');
    } catch (Exception $conn_error) {
        error_log('FINALIZAR_ASIGNACION: Error de conexión DB - ' . $conn_error->getMessage());
        throw new Exception('Error de conexión a la base de datos: ' . $conn_error->getMessage());
    }

    // ===== VERIFICAR QUE LA ASIGNACIÓN EXISTE Y ESTÁ ACTIVA =====
    $sql_asignacion = "SELECT 
                            pta.id_asignacion,
                            pta.id_promotor,
                            pta.id_tienda,
                            pta.fecha_inicio,
                            pta.fecha_fin,
                            pta.motivo_asignacion,
                            pta.activo,
                            pta.usuario_asigno,
                            
                            p.nombre as promotor_nombre,
                            p.apellido as promotor_apellido,
                            p.estatus as promotor_estatus,
                            
                            t.region,
                            t.cadena,
                            t.num_tienda,
                            t.nombre_tienda,
                            t.ciudad
                       FROM promotor_tienda_asignaciones pta
                       INNER JOIN promotores p ON pta.id_promotor = p.id_promotor
                       INNER JOIN tiendas t ON pta.id_tienda = t.id_tienda
                       WHERE pta.id_asignacion = :id_asignacion
                       AND p.estado = 1 
                       AND t.estado_reg = 1";
    
    $asignacion = Database::selectOne($sql_asignacion, [':id_asignacion' => $id_asignacion]);

    if (!$asignacion) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Asignación no encontrada o inválida'
        ]);
        exit;
    }

    // Verificar que no esté ya finalizada
    if ($asignacion['fecha_fin']) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'La asignación ya está finalizada con fecha: ' . date('d/m/Y', strtotime($asignacion['fecha_fin']))
        ]);
        exit;
    }

    // Verificar que la fecha de fin no sea anterior a la fecha de inicio
    $fecha_inicio_obj = new DateTime($asignacion['fecha_inicio']);
    if ($fecha_fin_obj < $fecha_inicio_obj) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'La fecha de finalización no puede ser anterior a la fecha de inicio (' . date('d/m/Y', strtotime($asignacion['fecha_inicio'])) . ')'
        ]);
        exit;
    }

    // ===== ACTUALIZAR LA ASIGNACIÓN =====
    $sql_update = "UPDATE promotor_tienda_asignaciones 
                   SET fecha_fin = :fecha_fin,
                       motivo_cambio = :motivo_cambio,
                       usuario_cambio = :usuario_cambio,
                       fecha_modificacion = NOW()";
    
    $params_update = [
        ':fecha_fin' => $fecha_fin,
        ':motivo_cambio' => $motivo_cambio,
        ':usuario_cambio' => $_SESSION['user_id'],
        ':id_asignacion' => $id_asignacion
    ];

    // Opcionalmente desactivar la asignación
    if ($desactivar) {
        $sql_update .= ", activo = 0";
    }

    $sql_update .= " WHERE id_asignacion = :id_asignacion";

    $affected_rows = Database::execute($sql_update, $params_update);

    if ($affected_rows === 0) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'No se pudo actualizar la asignación'
        ]);
        exit;
    }

    // ===== CALCULAR DURACIÓN =====
    $duracion_dias = $fecha_fin_obj->diff($fecha_inicio_obj)->days;

    // ===== REGISTRAR EN LOG DE ACTIVIDADES =====
    try {
        $detalle_log = "Asignación finalizada: Promotor {$asignacion['promotor_nombre']} {$asignacion['promotor_apellido']} finalizó asignación en tienda {$asignacion['cadena']} #{$asignacion['num_tienda']} - {$asignacion['nombre_tienda']}. Duración: {$duracion_dias} días. Motivo: {$motivo_cambio}";
        
        $sql_log = "INSERT INTO log_actividades (tabla, accion, id_registro, usuario_id, fecha, detalles) 
                    VALUES ('promotor_tienda_asignaciones', 'UPDATE', :id_registro, :usuario_id, NOW(), :detalles)";
        
        Database::insert($sql_log, [
            ':id_registro' => $id_asignacion,
            ':usuario_id' => $_SESSION['user_id'],
            ':detalles' => $detalle_log
        ]);
        
        error_log("LOG_FINALIZACION: " . $detalle_log . " - Usuario: " . $_SESSION['username']);
    } catch (Exception $log_error) {
        // Log del error pero no fallar la operación principal
        error_log("Error registrando en log_actividades: " . $log_error->getMessage());
    }

    // ===== OBTENER LA ASIGNACIÓN ACTUALIZADA =====
    $sql_get_updated = "SELECT 
                            pta.id_asignacion,
                            pta.id_promotor,
                            pta.id_tienda,
                            pta.fecha_inicio,
                            pta.fecha_fin,
                            pta.motivo_asignacion,
                            pta.motivo_cambio,
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
                            
                            u1.nombre as usuario_asigno_nombre,
                            u1.apellido as usuario_asigno_apellido,
                            u2.nombre as usuario_cambio_nombre,
                            u2.apellido as usuario_cambio_apellido
                        FROM promotor_tienda_asignaciones pta
                        INNER JOIN promotores p ON pta.id_promotor = p.id_promotor
                        INNER JOIN tiendas t ON pta.id_tienda = t.id_tienda
                        LEFT JOIN usuarios u1 ON pta.usuario_asigno = u1.id
                        LEFT JOIN usuarios u2 ON pta.usuario_cambio = u2.id
                        WHERE pta.id_asignacion = :id_asignacion";
    
    $asignacion_actualizada = Database::selectOne($sql_get_updated, [':id_asignacion' => $id_asignacion]);

    // ===== FORMATEAR RESPUESTA =====
    $response_data = [
        'id_asignacion' => intval($asignacion_actualizada['id_asignacion']),
        'promotor_nombre_completo' => trim($asignacion_actualizada['promotor_nombre'] . ' ' . $asignacion_actualizada['promotor_apellido']),
        'promotor_telefono' => $asignacion_actualizada['promotor_telefono'],
        'promotor_correo' => $asignacion_actualizada['promotor_correo'],
        'tienda_identificador' => $asignacion_actualizada['cadena'] . ' #' . $asignacion_actualizada['num_tienda'] . ' - ' . $asignacion_actualizada['nombre_tienda'],
        'tienda_region' => intval($asignacion_actualizada['region']),
        'tienda_cadena' => $asignacion_actualizada['cadena'],
        'tienda_num_tienda' => intval($asignacion_actualizada['num_tienda']),
        'tienda_nombre_tienda' => $asignacion_actualizada['nombre_tienda'],
        'tienda_ciudad' => $asignacion_actualizada['ciudad'],
        'tienda_estado' => $asignacion_actualizada['tienda_estado'],
        'fecha_inicio' => $asignacion_actualizada['fecha_inicio'],
        'fecha_fin' => $asignacion_actualizada['fecha_fin'],
        'duracion_dias' => $duracion_dias,
        'motivo_asignacion' => $asignacion_actualizada['motivo_asignacion'],
        'motivo_finalizacion' => $asignacion_actualizada['motivo_cambio'],
        'usuario_asigno' => trim($asignacion_actualizada['usuario_asigno_nombre'] . ' ' . $asignacion_actualizada['usuario_asigno_apellido']),
        'usuario_finalizo' => trim($asignacion_actualizada['usuario_cambio_nombre'] . ' ' . $asignacion_actualizada['usuario_cambio_apellido']),
        'fecha_inicio_formatted' => date('d/m/Y', strtotime($asignacion_actualizada['fecha_inicio'])),
        'fecha_fin_formatted' => date('d/m/Y', strtotime($asignacion_actualizada['fecha_fin'])),
        'fecha_finalizacion_formatted' => date('d/m/Y H:i', strtotime($asignacion_actualizada['fecha_modificacion'])),
        'activo' => intval($asignacion_actualizada['activo']),
        'finalizado' => true,
        'estatus_texto' => 'Finalizado'
    ];

    // ===== RESPUESTA EXITOSA =====
    echo json_encode([
        'success' => true,
        'message' => 'Asignación finalizada correctamente',
        'data' => $response_data
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    // Log del error
    error_log("Error en finalizar_asignacion.php: " . $e->getMessage() . " - Usuario: " . ($_SESSION['username'] ?? 'desconocido') . " - IP: " . $_SERVER['REMOTE_ADDR']);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor'
    ]);
}
?>