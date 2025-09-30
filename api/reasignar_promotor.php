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
    // ===== 🆕 FUNCIÓN HELPER PARA FORMATEAR NUMERO_TIENDA JSON =====
    function formatearNumeroTiendaJSON($numero_tienda) {
        if ($numero_tienda === null || $numero_tienda === '') {
            return [
                'original' => null,
                'display' => 'N/A',
                'parsed' => null,
                'is_json' => false,
                'is_legacy' => false,
                'type' => 'empty'
            ];
        }
        
        // Intentar parsear como JSON primero
        $parsed = json_decode($numero_tienda, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            // Es JSON válido
            if (is_numeric($parsed)) {
                return [
                    'original' => $numero_tienda,
                    'display' => (string)$parsed,
                    'parsed' => $parsed,
                    'is_json' => true,
                    'is_legacy' => false,
                    'type' => 'single_number'
                ];
            } elseif (is_array($parsed)) {
                return [
                    'original' => $numero_tienda,
                    'display' => implode(', ', $parsed),
                    'parsed' => $parsed,
                    'is_json' => true,
                    'is_legacy' => false,
                    'type' => 'array',
                    'count' => count($parsed)
                ];
            } else {
                return [
                    'original' => $numero_tienda,
                    'display' => is_string($parsed) ? $parsed : json_encode($parsed),
                    'parsed' => $parsed,
                    'is_json' => true,
                    'is_legacy' => false,
                    'type' => 'object'
                ];
            }
        } else {
            // No es JSON válido, asumir que es un entero legacy
            if (is_numeric($numero_tienda)) {
                return [
                    'original' => $numero_tienda,
                    'display' => (string)$numero_tienda,
                    'parsed' => (int)$numero_tienda,
                    'is_json' => false,
                    'is_legacy' => true,
                    'type' => 'legacy_integer'
                ];
            } else {
                return [
                    'original' => $numero_tienda,
                    'display' => (string)$numero_tienda,
                    'parsed' => $numero_tienda,
                    'is_json' => false,
                    'is_legacy' => true,
                    'type' => 'legacy_string'
                ];
            }
        }
    }

    // ===== LOG PARA DEBUGGING =====
    $debug_info = [
        'timestamp' => date('Y-m-d H:i:s'),
        'method' => $_SERVER['REQUEST_METHOD'],
        'content_type' => $_SERVER['CONTENT_TYPE'] ?? '',
        'session_status' => session_status(),
        'session_id' => session_id()
    ];
    error_log('REASIGNAR_PROMOTOR: Iniciando - ' . json_encode($debug_info));

    // ===== VERIFICAR SESIÓN BÁSICA =====
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        error_log('REASIGNAR_PROMOTOR: Sin sesión - user_id no encontrado');
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
        error_log('REASIGNAR_PROMOTOR: Rol incorrecto - ' . ($_SESSION['rol'] ?? 'NO_SET'));
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Sin permisos para reasignar promotores.',
            'error' => 'insufficient_permissions'
        ]);
        exit;
    }

    error_log('REASIGNAR_PROMOTOR: Sesión válida - Usuario: ' . ($_SESSION['username'] ?? 'NO_USERNAME') . ', Rol: ' . $_SESSION['rol']);

    // ===== INCLUIR CONEXIÓN DB =====
    $db_path = __DIR__ . '/../config/db_connect.php';
    if (!file_exists($db_path)) {
        error_log('REASIGNAR_PROMOTOR: Archivo db_connect.php no encontrado en: ' . $db_path);
        throw new Exception('Configuración de base de datos no encontrada');
    }

    require_once $db_path;

    // ===== VERIFICAR CLASE DATABASE =====
    if (!class_exists('Database')) {
        error_log('REASIGNAR_PROMOTOR: Clase Database no encontrada');
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
    $id_asignacion_actual = intval($input['id_asignacion'] ?? 0);
    $id_nuevo_promotor = intval($input['nuevo_promotor'] ?? $input['id_nuevo_promotor'] ?? 0);
    $fecha_cambio = trim($input['fecha_cambio'] ?? '');
    $motivo_cambio = trim($input['motivo_cambio'] ?? '');
    $motivo_nueva_asignacion = trim($input['motivo_nueva_asignacion'] ?? '');
    
    // Opcional: nueva tienda (si no se especifica, mantiene la misma)
    $id_nueva_tienda = intval($input['nueva_tienda'] ?? $input['id_nueva_tienda'] ?? 0);

    error_log('REASIGNAR_PROMOTOR: Asignación actual: ' . $id_asignacion_actual . ', Nuevo promotor: ' . $id_nuevo_promotor . ', Nueva tienda: ' . $id_nueva_tienda . ', Fecha: ' . $fecha_cambio);

    // ===== VALIDACIONES BÁSICAS =====
    if ($id_asignacion_actual <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'ID de asignación actual inválido'
        ]);
        exit;
    }

    if ($id_nuevo_promotor <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'ID de nuevo promotor inválido'
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
        $motivo_nueva_asignacion = "Reasignación de promotor";
    }

    // ===== VERIFICAR CONEXIÓN DB =====
    try {
        $test_connection = Database::connect();
        if (!$test_connection) {
            throw new Exception('No se pudo establecer conexión con la base de datos');
        }
        error_log('REASIGNAR_PROMOTOR: Conexión DB exitosa');
    } catch (Exception $conn_error) {
        error_log('REASIGNAR_PROMOTOR: Error de conexión DB - ' . $conn_error->getMessage());
        throw new Exception('Error de conexión a la base de datos: ' . $conn_error->getMessage());
    }

    // ===== 🆕 OBTENER DATOS DE LA ASIGNACIÓN ACTUAL - ACTUALIZADO =====
    $sql_asignacion_actual = "SELECT 
                                pta.id_asignacion,
                                pta.id_promotor,
                                pta.id_tienda,
                                pta.fecha_inicio,
                                pta.fecha_fin,
                                pta.activo,
                                
                                p.nombre as promotor_nombre,
                                p.apellido as promotor_apellido,
                                p.numero_tienda as promotor_numero_tienda,
                                p.region as promotor_region,
                                p.tipo_trabajo as promotor_tipo_trabajo,
                                p.clave_asistencia as promotor_clave_asistencia,
                                
                                t.cadena as tienda_cadena,
                                t.num_tienda as tienda_num,
                                t.nombre_tienda as tienda_nombre
                              FROM promotor_tienda_asignaciones pta
                              INNER JOIN promotores p ON pta.id_promotor = p.id_promotor
                              INNER JOIN tiendas t ON pta.id_tienda = t.id_tienda
                              WHERE pta.id_asignacion = :id_asignacion";

    $asignacion_actual = Database::selectOne($sql_asignacion_actual, [':id_asignacion' => $id_asignacion_actual]);

    if (!$asignacion_actual) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Asignación actual no encontrada'
        ]);
        exit;
    }

    // Verificar que la asignación esté activa
    if (!$asignacion_actual['activo'] || $asignacion_actual['fecha_fin']) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'La asignación actual no está activa o ya está finalizada'
        ]);
        exit;
    }

    // Si no se especifica nueva tienda, usar la misma de la asignación actual
    if ($id_nueva_tienda <= 0) {
        $id_nueva_tienda = $asignacion_actual['id_tienda'];
    }

    // ===== 🆕 VERIFICAR QUE EL NUEVO PROMOTOR EXISTE Y ESTÁ DISPONIBLE - ACTUALIZADO =====
    $sql_nuevo_promotor = "SELECT 
                             p.id_promotor,
                             p.nombre,
                             p.apellido,
                             p.estatus,
                             p.vacaciones,
                             p.numero_tienda,
                             p.region,
                             p.tipo_trabajo,
                             p.fecha_ingreso,
                             p.clave_asistencia,
                             pta.id_asignacion as asignacion_activa_id
                           FROM promotores p
                           LEFT JOIN promotor_tienda_asignaciones pta ON (
                               p.id_promotor = pta.id_promotor 
                               AND pta.activo = 1 
                               AND pta.fecha_fin IS NULL
                           )
                           WHERE p.id_promotor = :id_promotor AND p.estado = 1";

    $nuevo_promotor = Database::selectOne($sql_nuevo_promotor, [':id_promotor' => $id_nuevo_promotor]);

    if (!$nuevo_promotor) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'El nuevo promotor no existe o está inactivo'
        ]);
        exit;
    }

    if ($nuevo_promotor['estatus'] !== 'ACTIVO') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'El nuevo promotor no está activo'
        ]);
        exit;
    }

    if (intval($nuevo_promotor['vacaciones']) === 1) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'El nuevo promotor está en vacaciones'
        ]);
        exit;
    }

    if ($nuevo_promotor['asignacion_activa_id']) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'El nuevo promotor ya tiene una asignación activa'
        ]);
        exit;
    }

    // Verificar que no es el mismo promotor
    if ($id_nuevo_promotor == $asignacion_actual['id_promotor']) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'El nuevo promotor es el mismo que el actual'
        ]);
        exit;
    }

    // ===== VERIFICAR QUE LA NUEVA TIENDA EXISTE (SI ES DIFERENTE) =====
    if ($id_nueva_tienda != $asignacion_actual['id_tienda']) {
        $sql_nueva_tienda = "SELECT 
                               t.id_tienda,
                               t.cadena,
                               t.num_tienda,
                               t.nombre_tienda
                             FROM tiendas t
                             WHERE t.id_tienda = :id_tienda AND t.estado_reg = 1";

        $nueva_tienda = Database::selectOne($sql_nueva_tienda, [':id_tienda' => $id_nueva_tienda]);

        if (!$nueva_tienda) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'La nueva tienda no existe o está inactiva'
            ]);
            exit;
        }
    }

    // ===== VERIFICAR QUE NO SE ESTÉ DUPLICANDO LA MISMA ASIGNACIÓN =====
    if ($id_nueva_tienda == $asignacion_actual['id_tienda']) {
        // Mismo promotor y misma tienda - verificar que el nuevo promotor no esté ya asignado a esta tienda
        $sql_check_duplicado = "SELECT id_asignacion 
                                FROM promotor_tienda_asignaciones 
                                WHERE id_promotor = :id_promotor 
                                AND id_tienda = :id_tienda 
                                AND activo = 1 
                                AND fecha_fin IS NULL";
        
        $duplicado = Database::selectOne($sql_check_duplicado, [
            ':id_promotor' => $id_nuevo_promotor,
            ':id_tienda' => $id_nueva_tienda
        ]);

        if ($duplicado) {
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'message' => 'El nuevo promotor ya está asignado a esta tienda'
            ]);
            exit;
        }
    }

    // Validar que la fecha de cambio no sea anterior a la fecha de inicio de la asignación actual
    $fecha_inicio_obj = new DateTime($asignacion_actual['fecha_inicio']);
    if ($fecha_cambio_obj < $fecha_inicio_obj) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'La fecha de cambio no puede ser anterior a la fecha de inicio de la asignación actual (' . date('d/m/Y', strtotime($asignacion_actual['fecha_inicio'])) . ')'
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
            ':id_promotor' => $id_nuevo_promotor,
            ':id_tienda' => $id_nueva_tienda,
            ':fecha_inicio' => $fecha_cambio,
            ':motivo_asignacion' => $motivo_nueva_asignacion,
            ':usuario_asigno' => $_SESSION['user_id']
        ];

        $nueva_asignacion_id = Database::insert($sql_nueva, $params_nueva);

        if (!$nueva_asignacion_id) {
            throw new Exception('No se pudo crear la nueva asignación');
        }

        // ===== PASO 3: REGISTRAR EN LOG DE ACTIVIDADES =====
        $duracion_anterior = $fecha_cambio_obj->diff($fecha_inicio_obj)->days;
        
        $detalle_log = "Reasignación de promotor: De {$asignacion_actual['promotor_nombre']} {$asignacion_actual['promotor_apellido']} a {$nuevo_promotor['nombre']} {$nuevo_promotor['apellido']} en tienda {$asignacion_actual['tienda_cadena']} #{$asignacion_actual['tienda_num']} - {$asignacion_actual['tienda_nombre']}. Duración anterior: {$duracion_anterior} días. Motivo: {$motivo_cambio}";
        
        $sql_log = "INSERT INTO log_actividades (tabla, accion, id_registro, usuario_id, fecha, detalles) 
                    VALUES ('promotor_tienda_asignaciones', 'REASIGNACION_PROMOTOR', :id_registro, :usuario_id, NOW(), :detalles)";
        
        Database::insert($sql_log, [
            ':id_registro' => $nueva_asignacion_id,
            ':usuario_id' => $_SESSION['user_id'],
            ':detalles' => $detalle_log
        ]);

        // ===== CONFIRMAR TRANSACCIÓN =====
        $connection->commit();
        
        error_log("REASIGNACION_PROMOTOR_EXITOSA: " . $detalle_log . " - Usuario: " . ($_SESSION['username'] ?? 'NO_USERNAME'));

    } catch (Exception $transaction_error) {
        // Revertir transacción
        $connection->rollback();
        throw $transaction_error;
    }

    // ===== 🆕 OBTENER DATOS COMPLETOS DE LA NUEVA ASIGNACIÓN - ACTUALIZADO =====
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
                              p.numero_tienda as promotor_numero_tienda,
                              p.region as promotor_region,
                              p.tipo_trabajo as promotor_tipo_trabajo,
                              p.fecha_ingreso as promotor_fecha_ingreso,
                              p.clave_asistencia as promotor_clave_asistencia,
                              
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

    // ===== 🆕 PROCESAR INFORMACIÓN JSON DE LOS PROMOTORES =====
    
    // Procesar promotor anterior
    $promotor_anterior_numero_tienda_info = formatearNumeroTiendaJSON($asignacion_actual['promotor_numero_tienda']);
    $promotor_anterior_claves = [];
    if (!empty($asignacion_actual['promotor_clave_asistencia'])) {
        $parsed_claves = json_decode($asignacion_actual['promotor_clave_asistencia'], true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($parsed_claves)) {
            $promotor_anterior_claves = $parsed_claves;
        } else {
            $promotor_anterior_claves = [$asignacion_actual['promotor_clave_asistencia']];
        }
    }
    
    // Procesar nuevo promotor
    $nuevo_promotor_numero_tienda_info = formatearNumeroTiendaJSON($nueva_asignacion_completa['promotor_numero_tienda']);
    $nuevo_promotor_claves = [];
    if (!empty($nueva_asignacion_completa['promotor_clave_asistencia'])) {
        $parsed_claves = json_decode($nueva_asignacion_completa['promotor_clave_asistencia'], true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($parsed_claves)) {
            $nuevo_promotor_claves = $parsed_claves;
        } else {
            $nuevo_promotor_claves = [$nueva_asignacion_completa['promotor_clave_asistencia']];
        }
    }
    
    // Formatear tipos de trabajo
    $tipos_trabajo = [
        'fijo' => 'Fijo',
        'cubredescansos' => 'Cubre Descansos'
    ];

    // ===== 🆕 FORMATEAR RESPUESTA - MEJORADA CON INFORMACIÓN JSON =====
    $response_data = [
        'asignacion_finalizada' => [
            'id_asignacion' => intval($id_asignacion_actual),
            'promotor_anterior' => trim($asignacion_actual['promotor_nombre'] . ' ' . $asignacion_actual['promotor_apellido']),
            'tienda_identificador' => $asignacion_actual['tienda_cadena'] . ' #' . $asignacion_actual['tienda_num'] . ' - ' . $asignacion_actual['tienda_nombre'],
            'duracion_dias' => $duracion_anterior,
            'fecha_fin' => $fecha_cambio,
            'motivo_finalizacion' => $motivo_cambio,
            
            // ===== 🆕 INFORMACIÓN ADICIONAL DEL PROMOTOR ANTERIOR =====
            'promotor_anterior_info' => [
                'numero_tienda' => $promotor_anterior_numero_tienda_info['original'],
                'numero_tienda_display' => $promotor_anterior_numero_tienda_info['display'],
                'numero_tienda_parsed' => $promotor_anterior_numero_tienda_info['parsed'],
                'numero_tienda_info' => $promotor_anterior_numero_tienda_info,
                'region' => (int)$asignacion_actual['promotor_region'],
                'tipo_trabajo' => $asignacion_actual['promotor_tipo_trabajo'],
                'tipo_trabajo_formatted' => $tipos_trabajo[$asignacion_actual['promotor_tipo_trabajo']] ?? $asignacion_actual['promotor_tipo_trabajo'],
                'claves_asistencia' => $promotor_anterior_claves,
                'claves_texto' => implode(', ', $promotor_anterior_claves)
            ]
        ],
        'asignacion_nueva' => [
            'id_asignacion' => intval($nueva_asignacion_completa['id_asignacion']),
            'id_promotor' => intval($nueva_asignacion_completa['id_promotor']),
            'id_tienda' => intval($nueva_asignacion_completa['id_tienda']),
            
            'promotor_nombre_completo' => trim($nueva_asignacion_completa['promotor_nombre'] . ' ' . $nueva_asignacion_completa['promotor_apellido']),
            'promotor_telefono' => $nueva_asignacion_completa['promotor_telefono'],
            'promotor_correo' => $nueva_asignacion_completa['promotor_correo'],
            
            // ===== 🆕 INFORMACIÓN COMPLETA DEL NUEVO PROMOTOR =====
            'promotor_info' => [
                'numero_tienda' => $nuevo_promotor_numero_tienda_info['original'],
                'numero_tienda_display' => $nuevo_promotor_numero_tienda_info['display'],
                'numero_tienda_parsed' => $nuevo_promotor_numero_tienda_info['parsed'],
                'numero_tienda_info' => $nuevo_promotor_numero_tienda_info,
                'region' => (int)$nueva_asignacion_completa['promotor_region'],
                'tipo_trabajo' => $nueva_asignacion_completa['promotor_tipo_trabajo'],
                'tipo_trabajo_formatted' => $tipos_trabajo[$nueva_asignacion_completa['promotor_tipo_trabajo']] ?? $nueva_asignacion_completa['promotor_tipo_trabajo'],
                'fecha_ingreso' => $nueva_asignacion_completa['promotor_fecha_ingreso'],
                'fecha_ingreso_formatted' => $nueva_asignacion_completa['promotor_fecha_ingreso'] ? date('d/m/Y', strtotime($nueva_asignacion_completa['promotor_fecha_ingreso'])) : 'N/A',
                'claves_asistencia' => $nuevo_promotor_claves,
                'claves_texto' => implode(', ', $nuevo_promotor_claves),
                'total_claves' => count($nuevo_promotor_claves)
            ],
            
            'tienda_identificador' => $nueva_asignacion_completa['cadena'] . ' #' . $nueva_asignacion_completa['num_tienda'] . ' - ' . $nueva_asignacion_completa['nombre_tienda'],
            'tienda_region' => intval($nueva_asignacion_completa['region']),
            'tienda_cadena' => $nueva_asignacion_completa['cadena'],
            'tienda_num_tienda' => intval($nueva_asignacion_completa['num_tienda']),
            'tienda_nombre_tienda' => $nueva_asignacion_completa['nombre_tienda'],
            'tienda_ciudad' => $nueva_asignacion_completa['ciudad'],
            'tienda_estado' => $nueva_asignacion_completa['tienda_estado'],
            
            'fecha_inicio' => $nueva_asignacion_completa['fecha_inicio'],
            'motivo_asignacion' => $nueva_asignacion_completa['motivo_asignacion'],
            'activo' => 1,
            'estatus_texto' => 'Activo',
            
            'usuario_asigno' => trim($nueva_asignacion_completa['usuario_nombre'] . ' ' . $nueva_asignacion_completa['usuario_apellido']),
            
            'fecha_inicio_formatted' => date('d/m/Y', strtotime($nueva_asignacion_completa['fecha_inicio'])),
            'fecha_registro_formatted' => date('d/m/Y H:i', strtotime($nueva_asignacion_completa['fecha_registro']))
        ],
        'resumen' => [
            'fecha_cambio' => $fecha_cambio,
            'fecha_cambio_formateada' => $fecha_cambio_obj->format('d/m/Y'),
            'motivo_cambio' => $motivo_cambio,
            'duracion_asignacion_anterior' => $duracion_anterior,
            'tipo_reasignacion' => $id_nueva_tienda == $asignacion_actual['id_tienda'] ? 'solo_promotor' : 'promotor_y_tienda',
            'multiples_promotores_habilitado' => true,
            // ===== 🆕 INFORMACIÓN JSON =====
            'soporte_json' => [
                'numero_tienda' => true,
                'claves_asistencia' => true,
                'version' => '1.1 - JSON Support'
            ]
        ]
    ];

    // ===== RESPUESTA EXITOSA =====
    echo json_encode([
        'success' => true,
        'message' => 'Reasignación de promotor completada correctamente',
        'data' => $response_data
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    // Log del error
    error_log("Error en reasignar_promotor.php: " . $e->getMessage() . " - Usuario: " . ($_SESSION['username'] ?? 'desconocido') . " - IP: " . $_SERVER['REMOTE_ADDR']);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor: ' . $e->getMessage()
    ]);
}
?>