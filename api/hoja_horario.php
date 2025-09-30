<?php

// Habilitar logging de errores para debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// 🔒 DEFINIR CONSTANTE ANTES DE INCLUIR DB_CONNECT
define('APP_ACCESS', true);
// Incluir la API de base de datos
require_once __DIR__ . '/../config/db_connect.php';

// Headers de seguridad y CORS 
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// Headers CORS para el módulo de horas
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Manejar preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    // ===== LOG PARA DEBUGGING =====
    $debug_info = [
        'timestamp' => date('Y-m-d H:i:s'),
        'method' => $_SERVER['REQUEST_METHOD'],
        'uri' => $_SERVER['REQUEST_URI'],
        'get_params' => $_GET,
        'content_type' => $_SERVER['CONTENT_TYPE'] ?? '',
        'session_status' => session_status(),
        'session_id' => session_id()
    ];
    error_log('HORAS_API: Iniciando - ' . json_encode($debug_info));

    // ===== VERIFICAR CLASE DATABASE =====
    if (!class_exists('Database')) {
        error_log('HORAS_API: Clase Database no encontrada');
        throw new Exception('Clase Database no disponible');
    }

    // ===== VERIFICAR CONEXIÓN DB =====
    try {
        $test_connection = Database::connect();
        if (!$test_connection) {
            throw new Exception('No se pudo establecer conexión con la base de datos');
        }
        error_log('HORAS_API: Conexión DB exitosa');
    } catch (Exception $conn_error) {
        error_log('HORAS_API: Error de conexión DB - ' . $conn_error->getMessage());
        throw new Exception('Error de conexión a la base de datos: ' . $conn_error->getMessage());
    }

    // ===== ENRUTAMIENTO BASADO EN PARÁMETROS GET =====
    $resource = $_GET['resource'] ?? '';
    $id = isset($_GET['id']) ? intval($_GET['id']) : null;
    $method = $_SERVER['REQUEST_METHOD'];

    error_log("HORAS_API: Recurso: {$resource}, ID: {$id}, Método: {$method}");

    // ===== ENRUTADOR PRINCIPAL =====
    switch ($resource) {
        case 'asignaciones':
            handleAsignaciones($method, $id);
            break;
            
        case 'claves':
            handleClaves($method, $id);
            break;
            
        case 'promotores':
            handlePromotores($method, $id);
            break;
            
        case 'tiendas':
            handleTiendas($method, $id);
            break;
            
        default:
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Recurso no encontrado: ' . $resource,
                'available_resources' => ['asignaciones', 'claves', 'promotores', 'tiendas']
            ]);
            exit;
    }

} catch (Exception $e) {
    // Log del error
    error_log("Error en horas API: " . $e->getMessage() . " - Usuario: " . ($_SESSION['username'] ?? 'desconocido') . " - IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor: ' . $e->getMessage()
    ]);
}

/**
 * ===== MANEJAR OPERACIONES DE ASIGNACIONES =====
 */
function handleAsignaciones($method, $id) {
    switch ($method) {
        case 'GET':
            if ($id) {
                getAsignacionById($id);
            } else {
                getAllAsignaciones();
            }
            break;
            
        case 'POST':
            createAsignacion();
            break;
            
        case 'PUT':
            if (!$id) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'ID requerido para actualizar']);
                exit;
            }
            updateAsignacion($id);
            break;
            
        case 'DELETE':
            if (!$id) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'ID requerido para eliminar']);
                exit;
            }
            deleteAsignacion($id);
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    }
}

/**
 * ===== OBTENER TODAS LAS ASIGNACIONES =====
 */
function getAllAsignaciones() {
    try {
        error_log('HORAS_API: Obteniendo todas las asignaciones');
        
        $sql = "SELECT 
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
                    p.tipo_trabajo,
                    
                    t.region,
                    t.cadena,
                    t.num_tienda,
                    t.nombre_tienda,
                    t.ciudad,
                    t.estado as tienda_estado,
                    
                    u.nombre as usuario_asigno_nombre,
                    u.apellido as usuario_asigno_apellido
                FROM promotor_tienda_asignaciones pta
                LEFT JOIN promotores p ON pta.id_promotor = p.id_promotor
                LEFT JOIN tiendas t ON pta.id_tienda = t.id_tienda
                LEFT JOIN usuarios u ON pta.usuario_asigno = u.id
                WHERE pta.activo = 1
                ORDER BY pta.fecha_inicio DESC, pta.id_asignacion DESC";
        
        $asignaciones = Database::select($sql);
        
        // Formatear datos para el frontend
        $result = [];
        foreach ($asignaciones as $asignacion) {
            $result[] = [
                'id_asignacion' => intval($asignacion['id_asignacion']),
                'id_promotor' => intval($asignacion['id_promotor']),
                'id_tienda' => intval($asignacion['id_tienda']),
                'fecha_inicio' => $asignacion['fecha_inicio'],
                'fecha_fin' => $asignacion['fecha_fin'],
                'motivo_asignacion' => $asignacion['motivo_asignacion'],
                'motivo_cambio' => $asignacion['motivo_cambio'],
                'activo' => intval($asignacion['activo']),
                'nombre_promotor' => trim(($asignacion['promotor_nombre'] ?? '') . ' ' . ($asignacion['promotor_apellido'] ?? '')),
                'promotor_telefono' => $asignacion['promotor_telefono'],
                'promotor_correo' => $asignacion['promotor_correo'],
                'tipo_trabajo' => $asignacion['tipo_trabajo'],
                'nombre_tienda' => $asignacion['nombre_tienda'],
                'numero_tienda' => intval($asignacion['num_tienda']),
                'cadena' => $asignacion['cadena'],
                'region' => intval($asignacion['region']),
                'ciudad' => $asignacion['ciudad'],
                'tienda_estado' => $asignacion['tienda_estado'],
                'usuario_asigno_nombre' => trim(($asignacion['usuario_asigno_nombre'] ?? '') . ' ' . ($asignacion['usuario_asigno_apellido'] ?? '')),
                'fecha_registro' => $asignacion['fecha_registro'],
                'fecha_modificacion' => $asignacion['fecha_modificacion']
            ];
        }
        
        error_log("HORAS_API: {" . count($result) . "} asignaciones obtenidas exitosamente");
        
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'data' => $result,
            'count' => count($result),
            'message' => count($result) . ' asignaciones encontradas'
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        error_log("HORAS_API: Error obteniendo asignaciones - " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error obteniendo asignaciones: ' . $e->getMessage()
        ]);
    }
}

/**
 * ===== OBTENER ASIGNACIÓN POR ID =====
 */
function getAsignacionById($id) {
    try {
        error_log("HORAS_API: Obteniendo asignación ID: {$id}");
        
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'ID de asignación inválido'
            ]);
            return;
        }
        
        $sql = "SELECT 
                    pta.*,
                    p.nombre as promotor_nombre,
                    p.apellido as promotor_apellido,
                    t.cadena,
                    t.num_tienda,
                    t.nombre_tienda
                FROM promotor_tienda_asignaciones pta
                LEFT JOIN promotores p ON pta.id_promotor = p.id_promotor
                LEFT JOIN tiendas t ON pta.id_tienda = t.id_tienda
                WHERE pta.id_asignacion = :id AND pta.activo = 1";
        
        $asignacion = Database::selectOne($sql, [':id' => $id]);
        
        if (!$asignacion) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Asignación no encontrada'
            ]);
            return;
        }
        
        error_log("HORAS_API: Asignación {$id} obtenida exitosamente");
        
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'data' => $asignacion
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        error_log("HORAS_API: Error obteniendo asignación {$id} - " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error obteniendo asignación: ' . $e->getMessage()
        ]);
    }
}

/**
 * ===== CREAR NUEVA ASIGNACIÓN CON CIERRE AUTOMÁTICO DE ASIGNACIONES ANTERIORES =====
 */
function createAsignacion() {
    try {
        error_log('HORAS_API: === INICIANDO CREACIÓN DE ASIGNACIÓN CON CIERRE AUTOMÁTICO ===');
        
        // ===== DEBUGGING: LOG DE INPUT RAW =====
        $raw_input = file_get_contents('php://input');
        error_log('HORAS_API: Raw input recibido: ' . $raw_input);
        error_log('HORAS_API: Content-Type: ' . ($_SERVER['CONTENT_TYPE'] ?? 'No definido'));
        error_log('HORAS_API: Método: ' . $_SERVER['REQUEST_METHOD']);
        
        // ===== VERIFICAR AUTENTICACIÓN =====
        $usuario_asigno = 1; // Default usuario del sistema
        if (isset($_SESSION['user_id']) && $_SESSION['user_id']) {
            $usuario_asigno = $_SESSION['user_id'];
            error_log('HORAS_API: Usuario de sesión: ' . $usuario_asigno);
        } else {
            error_log('HORAS_API: Usando usuario por defecto: ' . $usuario_asigno);
        }
        
        // Obtener datos del request
        $content_type = $_SERVER['CONTENT_TYPE'] ?? '';
        $input = null;
        
        if (strpos($content_type, 'application/json') !== false) {
            if (empty($raw_input)) {
                error_log('HORAS_API: ERROR - Input JSON vacío');
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'No se recibieron datos JSON'
                ]);
                return;
            }
            
            $input = json_decode($raw_input, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log('HORAS_API: ERROR - Error parseando JSON: ' . json_last_error_msg());
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'Error en formato JSON: ' . json_last_error_msg()
                ]);
                return;
            }
            
            error_log('HORAS_API: JSON parseado exitosamente: ' . json_encode($input));
        } else {
            $input = $_POST;
            error_log('HORAS_API: Usando $_POST: ' . json_encode($input));
        }
        
        if (!$input || !is_array($input)) {
            error_log('HORAS_API: ERROR - No se recibieron datos válidos');
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'No se recibieron datos válidos'
            ]);
            return;
        }
        
        // Validar datos requeridos
        $id_promotor = intval($input['id_promotor'] ?? 0);
        $id_tienda = intval($input['id_tienda'] ?? 0);
        $fecha_inicio = trim($input['fecha_inicio'] ?? '');
        $motivo_asignacion = trim($input['motivo_asignacion'] ?? 'Asignación desde calendario de horas');
        
        error_log("HORAS_API: Datos extraídos - Promotor: {$id_promotor}, Tienda: {$id_tienda}, Fecha: '{$fecha_inicio}', Motivo: '{$motivo_asignacion}'");
        
        // Detectar tipo de asignación
        $tipo_asignacion = 'Individual'; // Por defecto
        if (isset($input['tipo_asignacion'])) {
            $tipo_asignacion = trim($input['tipo_asignacion']);
        } elseif (stripos($motivo_asignacion, 'Principal') !== false || 
                  stripos($motivo_asignacion, 'Fijo') !== false ||
                  stripos($motivo_asignacion, 'Permanente') !== false) {
            $tipo_asignacion = 'Principal';
        }
        
        error_log("HORAS_API: Tipo de asignación determinado: {$tipo_asignacion}");
        
        // ===== VALIDACIONES BÁSICAS =====
        if ($id_promotor <= 0) {
            error_log('HORAS_API: ERROR - ID de promotor inválido: ' . $id_promotor);
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'ID de promotor inválido'
            ]);
            return;
        }
        
        if ($id_tienda <= 0) {
            error_log('HORAS_API: ERROR - ID de tienda inválido: ' . $id_tienda);
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'ID de tienda inválido'
            ]);
            return;
        }
        
        if (empty($fecha_inicio)) {
            error_log('HORAS_API: ERROR - Fecha de inicio vacía');
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'La fecha de inicio es requerida'
            ]);
            return;
        }
        
        // Validar formato de fecha
        $fecha_inicio_obj = DateTime::createFromFormat('Y-m-d', $fecha_inicio);
        if (!$fecha_inicio_obj) {
            error_log('HORAS_API: ERROR - Formato de fecha inválido: ' . $fecha_inicio);
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Formato de fecha inválido (usar YYYY-MM-DD)'
            ]);
            return;
        }
        
        error_log('HORAS_API: Validaciones básicas pasadas exitosamente');
        
        // ===== VERIFICAR QUE EL PROMOTOR EXISTE Y ESTÁ ACTIVO =====
        error_log('HORAS_API: Verificando existencia del promotor...');
        
        $sql_promotor = "SELECT id_promotor, nombre, apellido, estatus 
                         FROM promotores 
                         WHERE id_promotor = :id_promotor AND estado = 1";
        
        try {
            $promotor = Database::selectOne($sql_promotor, [':id_promotor' => $id_promotor]);
            error_log('HORAS_API: Resultado consulta promotor: ' . json_encode($promotor));
        } catch (Exception $db_error) {
            error_log('HORAS_API: ERROR BD consultando promotor: ' . $db_error->getMessage());
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Error consultando promotor en base de datos: ' . $db_error->getMessage()
            ]);
            return;
        }
        
        if (!$promotor) {
            error_log('HORAS_API: ERROR - Promotor no encontrado o inactivo: ' . $id_promotor);
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Promotor no encontrado o inactivo'
            ]);
            return;
        }
        
        if ($promotor['estatus'] !== 'ACTIVO') {
            error_log('HORAS_API: ERROR - Promotor no está activo: ' . $promotor['estatus']);
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'El promotor debe estar en estatus ACTIVO para ser asignado'
            ]);
            return;
        }
        
        error_log('HORAS_API: Promotor verificado exitosamente: ' . $promotor['nombre'] . ' ' . $promotor['apellido']);
        
        // ===== VERIFICAR QUE LA TIENDA EXISTE Y ESTÁ ACTIVA =====
        error_log('HORAS_API: Verificando existencia de la tienda...');
        
        $sql_tienda = "SELECT id_tienda, region, cadena, num_tienda, nombre_tienda, ciudad, estado 
                       FROM tiendas 
                       WHERE id_tienda = :id_tienda AND estado_reg = 1";
        
        try {
            $tienda = Database::selectOne($sql_tienda, [':id_tienda' => $id_tienda]);
            error_log('HORAS_API: Resultado consulta tienda: ' . json_encode($tienda));
        } catch (Exception $db_error) {
            error_log('HORAS_API: ERROR BD consultando tienda: ' . $db_error->getMessage());
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Error consultando tienda en base de datos: ' . $db_error->getMessage()
            ]);
            return;
        }
        
        if (!$tienda) {
            error_log('HORAS_API: ERROR - Tienda no encontrada o inactiva: ' . $id_tienda);
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Tienda no encontrada o inactiva'
            ]);
            return;
        }
        
        error_log('HORAS_API: Tienda verificada exitosamente: ' . $tienda['cadena'] . ' #' . $tienda['num_tienda']);
        
        // ===== NUEVA FUNCIONALIDAD: CERRAR AUTOMÁTICAMENTE ASIGNACIONES ANTERIORES ACTIVAS =====
        error_log('HORAS_API: === CERRANDO ASIGNACIONES ANTERIORES AUTOMÁTICAMENTE ===');
        
        // Calcular fecha de fin para asignaciones anteriores (un día antes de la nueva fecha)
        $fecha_fin_anterior = date('Y-m-d', strtotime($fecha_inicio . ' -1 day'));
        
        try {
            // Buscar asignaciones activas del promotor (sin fecha_fin y activas)
            $sql_asignaciones_activas = "SELECT 
                                            pta.id_asignacion,
                                            pta.id_tienda,
                                            pta.fecha_inicio,
                                            t.cadena,
                                            t.num_tienda,
                                            t.nombre_tienda
                                         FROM promotor_tienda_asignaciones pta
                                         INNER JOIN tiendas t ON pta.id_tienda = t.id_tienda
                                         WHERE pta.id_promotor = :id_promotor 
                                         AND pta.activo = 1 
                                         AND pta.fecha_fin IS NULL";
            
            $asignaciones_activas = Database::select($sql_asignaciones_activas, [':id_promotor' => $id_promotor]);
            
            if (count($asignaciones_activas) > 0) {
                error_log("HORAS_API: Encontradas " . count($asignaciones_activas) . " asignaciones activas para cerrar");
                
                foreach ($asignaciones_activas as $asignacion_activa) {
                    // Cerrar cada asignación activa
                    $sql_cerrar = "UPDATE promotor_tienda_asignaciones 
                                   SET fecha_fin = :fecha_fin, 
                                       motivo_cambio = :motivo_cambio,
                                       usuario_cambio = :usuario_cambio,
                                       fecha_modificacion = NOW()
                                   WHERE id_asignacion = :id_asignacion";
                    
                    $motivo_cambio = "Reasignado a {$tienda['cadena']} #{$tienda['num_tienda']} a partir del {$fecha_inicio}";
                    
                    $params_cerrar = [
                        ':fecha_fin' => $fecha_fin_anterior,
                        ':motivo_cambio' => $motivo_cambio,
                        ':usuario_cambio' => $usuario_asigno,
                        ':id_asignacion' => $asignacion_activa['id_asignacion']
                    ];
                    
                    Database::execute($sql_cerrar, $params_cerrar);
                    
                    error_log("HORAS_API: Asignación {$asignacion_activa['id_asignacion']} cerrada - Tienda anterior: {$asignacion_activa['cadena']} #{$asignacion_activa['num_tienda']}, Fecha fin: {$fecha_fin_anterior}");
                    
                    // Registrar en log de actividades
                    try {
                        $sql_check_log_table = "SHOW TABLES LIKE 'log_actividades'";
                        $table_exists = Database::selectOne($sql_check_log_table);
                        
                        if ($table_exists) {
                            $detalle_log = "Asignación cerrada automáticamente: Promotor {$promotor['nombre']} {$promotor['apellido']} dejó de trabajar en {$asignacion_activa['cadena']} #{$asignacion_activa['num_tienda']} el {$fecha_fin_anterior} por reasignación a nueva tienda";
                            
                            $sql_log = "INSERT INTO log_actividades (tabla, accion, id_registro, usuario_id, fecha, detalles) 
                                        VALUES ('promotor_tienda_asignaciones', 'AUTO_CLOSE', :id_registro, :usuario_id, NOW(), :detalles)";
                            
                            Database::insert($sql_log, [
                                ':id_registro' => $asignacion_activa['id_asignacion'],
                                ':usuario_id' => $usuario_asigno,
                                ':detalles' => $detalle_log
                            ]);
                        }
                    } catch (Exception $log_error) {
                        error_log("HORAS_API: Error registrando cierre en log (no crítico): " . $log_error->getMessage());
                    }
                }
                
                error_log("HORAS_API: ✅ Todas las asignaciones anteriores fueron cerradas automáticamente");
            } else {
                error_log("HORAS_API: No se encontraron asignaciones activas previas para cerrar");
            }
            
        } catch (Exception $close_error) {
            error_log("HORAS_API: ERROR cerrando asignaciones anteriores: " . $close_error->getMessage());
            // No es crítico, continuar con la creación de la nueva asignación
        }
        
        // ===== VERIFICACIÓN DE DUPLICADOS - ACTUALIZADA =====
        error_log('HORAS_API: Verificando duplicados para la nueva asignación...');
        
        $sql_check_duplicado = "SELECT 
                                    pta.id_asignacion, 
                                    pta.fecha_inicio,
                                    pta.motivo_asignacion,
                                    t.cadena,
                                    t.num_tienda,
                                    t.nombre_tienda
                                 FROM promotor_tienda_asignaciones pta
                                 INNER JOIN tiendas t ON pta.id_tienda = t.id_tienda
                                 WHERE pta.id_promotor = :id_promotor 
                                 AND pta.id_tienda = :id_tienda 
                                 AND pta.fecha_inicio = :fecha_inicio
                                 AND pta.activo = 1";
        
        $asignacion_duplicada = Database::selectOne($sql_check_duplicado, [
            ':id_promotor' => $id_promotor,
            ':id_tienda' => $id_tienda,
            ':fecha_inicio' => $fecha_inicio
        ]);
        
        if ($asignacion_duplicada) {
            error_log('HORAS_API: ERROR - Asignación duplicada encontrada');
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'message' => 'Ya existe una asignación para este promotor en esta tienda en la fecha especificada'
            ]);
            return;
        }
        
        error_log('HORAS_API: Verificación de duplicados pasada exitosamente');
        
        // ===== CREAR LA NUEVA ASIGNACIÓN =====
        error_log('HORAS_API: Procediendo a crear la nueva asignación...');
        
        // Para asignaciones individuales, establecer fecha_fin al final del día
        $fecha_fin = null;
        if ($tipo_asignacion === 'Individual') {
            $fecha_fin = $fecha_inicio; // Mismo día para asignaciones individuales
        }
        
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
                            :fecha_fin,
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
            ':fecha_fin' => $fecha_fin,
            ':motivo_asignacion' => $motivo_asignacion,
            ':usuario_asigno' => $usuario_asigno
        ];
        
        error_log('HORAS_API: SQL Insert: ' . $sql_insert);
        error_log('HORAS_API: Parámetros Insert: ' . json_encode($params_insert));
        
        try {
            $new_id = Database::insert($sql_insert, $params_insert);
            error_log('HORAS_API: Insert ejecutado, nuevo ID: ' . $new_id);
        } catch (Exception $insert_error) {
            error_log('HORAS_API: ERROR CRÍTICO en insert: ' . $insert_error->getMessage());
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Error ejecutando insert en base de datos: ' . $insert_error->getMessage()
            ]);
            return;
        }
        
        if (!$new_id || $new_id <= 0) {
            error_log('HORAS_API: ERROR - Insert no devolvió un ID válido: ' . var_export($new_id, true));
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'No se pudo crear la asignación - ID inválido devuelto'
            ]);
            return;
        }
        
        error_log('HORAS_API: Asignación creada exitosamente con ID: ' . $new_id);
        
        // ===== REGISTRAR EN LOG DE ACTIVIDADES =====
        try {
            $detalle_log = "Nueva asignación creada con cierre automático: Promotor {$promotor['nombre']} {$promotor['apellido']} asignado a {$tienda['cadena']} #{$tienda['num_tienda']} a partir del {$fecha_inicio}. Asignaciones anteriores cerradas automáticamente.";
            
            $sql_check_log_table = "SHOW TABLES LIKE 'log_actividades'";
            $table_exists = Database::selectOne($sql_check_log_table);
            
            if ($table_exists) {
                $sql_log = "INSERT INTO log_actividades (tabla, accion, id_registro, usuario_id, fecha, detalles) 
                            VALUES ('promotor_tienda_asignaciones', 'CREATE_WITH_AUTO_CLOSE', :id_registro, :usuario_id, NOW(), :detalles)";
                
                Database::insert($sql_log, [
                    ':id_registro' => $new_id,
                    ':usuario_id' => $usuario_asigno,
                    ':detalles' => $detalle_log
                ]);
                
                error_log('HORAS_API: Log de actividad registrado');
            }
            
            error_log("HORAS_LOG_ASIGNACION: " . $detalle_log);
        } catch (Exception $log_error) {
            error_log("HORAS_API: Error registrando en log_actividades (no crítico): " . $log_error->getMessage());
        }
        
        // ===== OBTENER LA ASIGNACIÓN CREADA CON TODOS LOS DATOS =====
        error_log('HORAS_API: Obteniendo datos completos de la asignación creada...');
        
        $sql_get_created = "SELECT 
                                pta.*,
                                p.nombre as promotor_nombre,
                                p.apellido as promotor_apellido,
                                t.cadena,
                                t.num_tienda,
                                t.nombre_tienda,
                                t.ciudad,
                                t.estado as tienda_estado
                            FROM promotor_tienda_asignaciones pta
                            INNER JOIN promotores p ON pta.id_promotor = p.id_promotor
                            INNER JOIN tiendas t ON pta.id_tienda = t.id_tienda
                            WHERE pta.id_asignacion = :id_asignacion";
        
        try {
            $asignacion_creada = Database::selectOne($sql_get_created, [':id_asignacion' => $new_id]);
            error_log('HORAS_API: Datos completos obtenidos: ' . json_encode($asignacion_creada));
        } catch (Exception $get_error) {
            error_log('HORAS_API: ERROR obteniendo datos completos: ' . $get_error->getMessage());
            $asignacion_creada = null;
        }
        
        error_log("HORAS_API: === ASIGNACIÓN CREADA EXITOSAMENTE CON CIERRE AUTOMÁTICO ===");
        error_log("HORAS_API: ID: {$new_id}, Tipo: {$tipo_asignacion}");
        
        // ===== RESPUESTA EXITOSA =====
        $response_data = [
            'success' => true,
            'message' => 'Asignación creada correctamente. Las asignaciones anteriores fueron cerradas automáticamente.',
            'id' => intval($new_id),
            'tipo' => $tipo_asignacion,
            'data' => $asignacion_creada,
            'asignaciones_cerradas' => count($asignaciones_activas ?? []),
            'fecha_fin_anterior' => $fecha_fin_anterior
        ];
        
        error_log('HORAS_API: Enviando respuesta exitosa: ' . json_encode($response_data));
        
        http_response_code(201);
        echo json_encode($response_data, JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        error_log("HORAS_API: ERROR CRÍTICO GENERAL en createAsignacion - " . $e->getMessage());
        error_log("HORAS_API: Stack trace: " . $e->getTraceAsString());
        
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error interno del servidor: ' . $e->getMessage()
        ]);
    }
}

/**
 * ===== ACTUALIZAR ASIGNACIÓN CON MANEJO AUTOMÁTICO DE HISTORIAL DE CAMBIOS DE TIENDA =====
 */
function updateAsignacion($id) {
    try {
        error_log("HORAS_API: Actualizando asignación ID: {$id}");
        
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'ID de asignación inválido'
            ]);
            return;
        }
        
        // Obtener datos del request
        $input = null;
        $content_type = $_SERVER['CONTENT_TYPE'] ?? '';
        
        if (strpos($content_type, 'application/json') !== false) {
            $raw_input = file_get_contents('php://input');
            error_log("HORAS_API: Raw JSON input: " . $raw_input);
            
            if (!empty($raw_input)) {
                $input = json_decode($raw_input, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    error_log("HORAS_API: Error decodificando JSON: " . json_last_error_msg());
                    http_response_code(400);
                    echo json_encode([
                        'success' => false,
                        'message' => 'Error en formato JSON: ' . json_last_error_msg()
                    ]);
                    return;
                }
            }
        } else {
            $input = $_POST;
        }
        
        if (empty($input) || !is_array($input)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'No se recibieron datos para actualizar'
            ]);
            return;
        }
        
        error_log("HORAS_API: Datos recibidos para actualizar: " . json_encode($input));
        
        // Verificar que la asignación existe
        $sql_check = "SELECT id_asignacion, id_promotor, id_tienda, fecha_inicio 
                      FROM promotor_tienda_asignaciones 
                      WHERE id_asignacion = :id AND activo = 1";
        $existing = Database::selectOne($sql_check, [':id' => $id]);
        
        if (!$existing) {
            error_log("HORAS_API: Asignación {$id} no encontrada");
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Asignación no encontrada o ya eliminada'
            ]);
            return;
        }
        
        error_log("HORAS_API: Asignación encontrada: " . json_encode($existing));
        
        // ===== MANEJO AUTOMÁTICO DE CAMBIO DE TIENDA =====
        $cambio_tienda = false;
        if (isset($input['manejar_cambio_tienda']) && $input['manejar_cambio_tienda'] === true) {
            
            // Verificar si realmente cambió la tienda
            if (isset($input['id_tienda_nueva']) && $input['id_tienda_nueva'] != $existing['id_tienda']) {
                
                error_log("HORAS_API: Detectado cambio de tienda - Anterior: {$existing['id_tienda']}, Nueva: {$input['id_tienda_nueva']}");
                
                // Obtener información de las tiendas para el log
                $sql_tienda_anterior = "SELECT cadena, num_tienda, nombre_tienda FROM tiendas WHERE id_tienda = :id";
                $tienda_anterior = Database::selectOne($sql_tienda_anterior, [':id' => $existing['id_tienda']]);
                
                $sql_tienda_nueva = "SELECT cadena, num_tienda, nombre_tienda FROM tiendas WHERE id_tienda = :id";
                $tienda_nueva = Database::selectOne($sql_tienda_nueva, [':id' => $input['id_tienda_nueva']]);
                
                // Obtener información del promotor
                $sql_promotor = "SELECT nombre, apellido FROM promotores WHERE id_promotor = :id";
                $promotor = Database::selectOne($sql_promotor, [':id' => $existing['id_promotor']]);
                
                // Calcular fecha de fin para asignación anterior (un día antes de la nueva fecha)
                $fecha_fin_anterior = date('Y-m-d', strtotime($input['fecha_inicio'] . ' -1 day'));
                
                // 1. CERRAR asignación actual
                $sql_cerrar = "UPDATE promotor_tienda_asignaciones 
                               SET fecha_fin = :fecha_fin, 
                                   motivo_cambio = :motivo_cambio,
                                   fecha_modificacion = NOW()";
                
                $motivo_cambio = "Reasignado a {$tienda_nueva['cadena']} #{$tienda_nueva['num_tienda']} desde calendario";
                $params_cerrar = [
                    ':id' => $id,
                    ':fecha_fin' => $fecha_fin_anterior,
                    ':motivo_cambio' => $motivo_cambio
                ];
                
                // Solo agregar usuario_cambio si hay sesión válida
                if (isset($_SESSION['user_id']) && $_SESSION['user_id'] > 0) {
                    $sql_cerrar .= ", usuario_cambio = :usuario_cambio";
                    $params_cerrar[':usuario_cambio'] = $_SESSION['user_id'];
                }
                
                $sql_cerrar .= " WHERE id_asignacion = :id";
                
                Database::execute($sql_cerrar, $params_cerrar);
                
                // 2. CREAR nueva asignación para la nueva tienda
                $sql_nueva = "INSERT INTO promotor_tienda_asignaciones (
                                id_promotor, id_tienda, fecha_inicio, motivo_asignacion, 
                                usuario_asigno, activo, fecha_registro, fecha_modificacion
                              ) VALUES (
                                :id_promotor, :id_tienda_nueva, :fecha_inicio, 
                                'Reasignación desde calendario de horas',
                                :usuario_asigno, 1, NOW(), NOW()
                              )";
                
                $usuario_asigno = $_SESSION['user_id'] ?? 1;
                
                $new_asignacion_id = Database::insert($sql_nueva, [
                    ':id_promotor' => $existing['id_promotor'],
                    ':id_tienda_nueva' => $input['id_tienda_nueva'],
                    ':fecha_inicio' => $input['fecha_inicio'],
                    ':usuario_asigno' => $usuario_asigno
                ]);
                
                $cambio_tienda = true;
                
                // Registrar en log de actividades si existe la tabla
                try {
                    $sql_check_log_table = "SHOW TABLES LIKE 'log_actividades'";
                    $table_exists = Database::selectOne($sql_check_log_table);
                    
                    if ($table_exists && $usuario_asigno) {
                        $detalle_log = "Cambio de tienda desde calendario: Promotor {$promotor['nombre']} {$promotor['apellido']} dejó {$tienda_anterior['cadena']} #{$tienda_anterior['num_tienda']} el {$fecha_fin_anterior} y fue reasignado a {$tienda_nueva['cadena']} #{$tienda_nueva['num_tienda']} desde {$input['fecha_inicio']}";
                        
                        $sql_log = "INSERT INTO log_actividades (tabla, accion, id_registro, usuario_id, fecha, detalles) 
                                    VALUES ('promotor_tienda_asignaciones', 'CAMBIO_TIENDA_CALENDARIO', :id_registro, :usuario_id, NOW(), :detalles)";
                        
                        Database::insert($sql_log, [
                            ':id_registro' => $new_asignacion_id,
                            ':usuario_id' => $usuario_asigno,
                            ':detalles' => $detalle_log
                        ]);
                    }
                } catch (Exception $log_error) {
                    error_log("Error registrando cambio de tienda en log (no crítico): " . $log_error->getMessage());
                }
                
                error_log("HORAS_API: Historial de cambio de tienda registrado exitosamente - Nueva asignación ID: {$new_asignacion_id}");
                
                // Respuesta inmediata para cambio de tienda
                http_response_code(200);
                echo json_encode([
                    'success' => true,
                    'message' => 'Cambio de tienda registrado en historial con fechas de fin automáticas',
                    'cambio_tienda' => true,
                    'nueva_asignacion_id' => $new_asignacion_id,
                    'tienda_anterior' => $tienda_anterior['cadena'] . ' #' . $tienda_anterior['num_tienda'],
                    'tienda_nueva' => $tienda_nueva['cadena'] . ' #' . $tienda_nueva['num_tienda'],
                    'fecha_fin_anterior' => $fecha_fin_anterior,
                    'fecha_inicio_nueva' => $input['fecha_inicio']
                ], JSON_UNESCAPED_UNICODE);
                return;
            }
        }
        
        // Construir query de actualización dinámico (resto del código original)
        $allowed_fields = [
            'id_promotor' => 'int',
            'id_tienda' => 'int',
            'fecha_inicio' => 'string',
            'fecha_fin' => 'string',
            'motivo_asignacion' => 'string',
            'motivo_cambio' => 'string'
        ];
        
        $set_parts = [];
        $params = [':id' => $id];
        
        foreach ($allowed_fields as $field => $type) {
            if (array_key_exists($field, $input)) {
                $set_parts[] = "`$field` = :$field";
                if ($type === 'int') {
                    $params[":$field"] = intval($input[$field]);
                } else {
                    $value = trim($input[$field] ?? '');
                    $params[":$field"] = $value === '' ? null : $value;
                }
            }
        }
        
        if (empty($set_parts)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'No hay campos válidos para actualizar'
            ]);
            return;
        }
        
        // Agregar fecha de modificación (SIEMPRE)
        $set_parts[] = "`fecha_modificacion` = NOW()";
        
        // Agregar usuario de modificación solo si hay sesión válida
        $usuario_cambio = null;
        if (isset($_SESSION['user_id']) && $_SESSION['user_id'] > 0) {
            $usuario_cambio = $_SESSION['user_id'];
            $set_parts[] = "`usuario_cambio` = :usuario_cambio";
            $params[':usuario_cambio'] = $usuario_cambio;
        }
        
        // Construir y ejecutar query de actualización
        $sql_update = "UPDATE promotor_tienda_asignaciones 
                       SET " . implode(', ', $set_parts) . "
                       WHERE id_asignacion = :id AND activo = 1";
        
        error_log("HORAS_API: SQL Update: " . $sql_update);
        error_log("HORAS_API: Parámetros: " . json_encode($params));
        
        $rows_affected = Database::execute($sql_update, $params);
        
        if ($rows_affected > 0) {
            error_log("HORAS_API: Asignación {$id} actualizada exitosamente");
            
            // REGISTRAR EN LOG DE ACTIVIDADES
            try {
                $campos_actualizados = array_keys(array_intersect_key($input, $allowed_fields));
                $detalle_log = "Asignación de horas actualizada - ID: {$id}. Campos: " . implode(', ', $campos_actualizados);
                
                $sql_check_log_table = "SHOW TABLES LIKE 'log_actividades'";
                $table_exists = Database::selectOne($sql_check_log_table);
                
                if ($table_exists && $usuario_cambio) {
                    $sql_log = "INSERT INTO log_actividades (tabla, accion, id_registro, usuario_id, fecha, detalles) 
                                VALUES ('promotor_tienda_asignaciones', 'UPDATE_HORAS', :id_registro, :usuario_id, NOW(), :detalles)";
                    
                    Database::insert($sql_log, [
                        ':id_registro' => $id,
                        ':usuario_id' => $usuario_cambio,
                        ':detalles' => $detalle_log
                    ]);
                    
                    error_log("HORAS_API: Log registrado exitosamente");
                }
            } catch (Exception $log_error) {
                error_log("HORAS_API: Error registrando en log (no crítico): " . $log_error->getMessage());
            }
            
            // Respuesta exitosa
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'Asignación actualizada correctamente',
                'rows_affected' => $rows_affected
            ], JSON_UNESCAPED_UNICODE);
            
        } else {
            error_log("HORAS_API: No se actualizó ninguna fila para ID {$id}");
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'No se pudo actualizar la asignación - posiblemente ya fue modificada'
            ]);
        }
        
    } catch (Exception $e) {
        error_log("HORAS_API: Error crítico actualizando asignación {$id} - " . $e->getMessage());
        error_log("HORAS_API: Stack trace: " . $e->getTraceAsString());
        
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error interno actualizando asignación: ' . $e->getMessage()
        ]);
    }
}

/**
 * ===== ELIMINAR ASIGNACIÓN (SOFT DELETE) =====
 */
function deleteAsignacion($id) {
    try {
        error_log("HORAS_API: Eliminando asignación ID: {$id}");
        
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'ID de asignación inválido'
            ]);
            return;
        }
        
        // Obtener datos antes de eliminar para el log
        $sql_get_before = "SELECT 
                              pta.*,
                              p.nombre as promotor_nombre,
                              p.apellido as promotor_apellido,
                              t.cadena,
                              t.num_tienda,
                              t.nombre_tienda
                           FROM promotor_tienda_asignaciones pta
                           LEFT JOIN promotores p ON pta.id_promotor = p.id_promotor
                           LEFT JOIN tiendas t ON pta.id_tienda = t.id_tienda
                           WHERE pta.id_asignacion = :id AND pta.activo = 1";
        
        $asignacion_antes = Database::selectOne($sql_get_before, [':id' => $id]);
        
        if (!$asignacion_antes) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Asignación no encontrada o ya eliminada'
            ]);
            return;
        }
        
        // Realizar soft delete
        $sql_delete = "UPDATE promotor_tienda_asignaciones 
                       SET `activo` = 0, 
                           `fecha_modificacion` = NOW()";
        
        $params_delete = [':id' => $id];
        
        // Solo agregar usuario_cambio si hay sesión válida
        if (isset($_SESSION['user_id']) && $_SESSION['user_id'] > 0) {
            $sql_delete .= ", `usuario_cambio` = :usuario_cambio";
            $params_delete[':usuario_cambio'] = $_SESSION['user_id'];
        }
        
        $sql_delete .= " WHERE id_asignacion = :id AND activo = 1";
        
        $rows_affected = Database::execute($sql_delete, $params_delete);
        
        if ($rows_affected > 0) {
            error_log("HORAS_API: Asignación {$id} eliminada exitosamente (soft delete)");
            
            // Registrar en log
            try {
                $detalle_log = "Asignación de horas eliminada - Promotor: {$asignacion_antes['promotor_nombre']} {$asignacion_antes['promotor_apellido']}, Tienda: {$asignacion_antes['cadena']} #{$asignacion_antes['num_tienda']} - {$asignacion_antes['nombre_tienda']}";
                
                $sql_check_log_table = "SHOW TABLES LIKE 'log_actividades'";
                $table_exists = Database::selectOne($sql_check_log_table);
                
                $usuario_log = $_SESSION['user_id'] ?? null;
                if ($table_exists && $usuario_log) {
                    $sql_log = "INSERT INTO log_actividades (tabla, accion, id_registro, usuario_id, fecha, detalles) 
                                VALUES ('promotor_tienda_asignaciones', 'DELETE_HORAS', :id_registro, :usuario_id, NOW(), :detalles)";
                    
                    Database::insert($sql_log, [
                        ':id_registro' => $id,
                        ':usuario_id' => $usuario_log,
                        ':detalles' => $detalle_log
                    ]);
                }
            } catch (Exception $log_error) {
                error_log("Error registrando eliminación en log (no crítico): " . $log_error->getMessage());
            }
            
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'Asignación eliminada correctamente'
            ], JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'No se pudo eliminar la asignación - posiblemente ya fue eliminada'
            ]);
        }
        
    } catch (Exception $e) {
        error_log("HORAS_API: Error eliminando asignación {$id} - " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error eliminando asignación: ' . $e->getMessage()
        ]);
    }
}

/**
 * ===== MANEJAR OPERACIONES DE CLAVES =====
 */
function handleClaves($method, $id) {
    switch ($method) {
        case 'GET':
            getAllClaves();
            break;
            
        case 'POST':
            assignClave();
            break;
            
        case 'PUT':
            if (!$id) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'ID requerido']);
                exit;
            }
            updateClave($id);
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    }
}

/**
 * ===== OBTENER TODAS LAS CLAVES =====
 */
function getAllClaves() {
    try {
        error_log('HORAS_API: Obteniendo todas las claves');
        
        $sql = "SELECT 
                    ct.*,
                    t.nombre_tienda,
                    t.ciudad,
                    t.estado,
                    p.nombre as promotor_nombre,
                    p.apellido as promotor_apellido
                FROM claves_tienda ct
                LEFT JOIN tiendas t ON ct.numero_tienda = t.num_tienda
                LEFT JOIN promotores p ON ct.id_promotor_actual = p.id_promotor
                WHERE ct.activa = 1
                ORDER BY ct.numero_tienda, ct.en_uso, ct.codigo_clave";
        
        $claves = Database::select($sql);
        
        // Agrupar por tienda
        $result = [];
        foreach ($claves as $clave) {
            $tienda = $clave['numero_tienda'];
            if (!isset($result[$tienda])) {
                $result[$tienda] = [
                    'numero_tienda' => intval($tienda),
                    'nombre_tienda' => $clave['nombre_tienda'],
                    'ciudad' => $clave['ciudad'],
                    'estado' => $clave['estado'],
                    'claves' => []
                ];
            }
            
            $result[$tienda]['claves'][] = [
                'id_clave' => intval($clave['id_clave']),
                'codigo_clave' => $clave['codigo_clave'],
                'en_uso' => intval($clave['en_uso']),
                'id_promotor_actual' => $clave['id_promotor_actual'] ? intval($clave['id_promotor_actual']) : null,
                'promotor_actual' => $clave['id_promotor_actual'] ? 
                    trim(($clave['promotor_nombre'] ?? '') . ' ' . ($clave['promotor_apellido'] ?? '')) : null,
                'fecha_asignacion' => $clave['fecha_asignacion'],
                'fecha_liberacion' => $clave['fecha_liberacion'],
                'fecha_registro' => $clave['fecha_registro']
            ];
        }
        
        error_log("HORAS_API: {" . count($result) . "} tiendas con claves obtenidas");
        
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'data' => array_values($result),
            'tiendas_count' => count($result)
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        error_log("HORAS_API: Error obteniendo claves - " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error obteniendo claves: ' . $e->getMessage()
        ]);
    }
}

/**
 * ===== ASIGNAR CLAVE A PROMOTOR =====
 */
function assignClave() {
    try {
        error_log('HORAS_API: Asignando clave a promotor');
        
        $content_type = $_SERVER['CONTENT_TYPE'] ?? '';
        if (strpos($content_type, 'application/json') !== false) {
            $input = json_decode(file_get_contents('php://input'), true);
        } else {
            $input = $_POST;
        }
        
        if (!$input) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'No se recibieron datos']);
            return;
        }
        
        $numero_tienda = intval($input['numero_tienda'] ?? 0);
        $id_promotor = intval($input['id_promotor'] ?? 0);
        $usuario_asigno = null;
        if (isset($_SESSION['user_id']) && $_SESSION['user_id']) {
            $usuario_asigno = $_SESSION['user_id'];
        }
        
        if ($numero_tienda <= 0 || $id_promotor <= 0) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Número de tienda e ID de promotor son requeridos'
            ]);
            return;
        }
        
        // Buscar clave disponible
        $sql_clave = "SELECT id_clave, codigo_clave 
                      FROM claves_tienda 
                      WHERE numero_tienda = :numero_tienda AND en_uso = 0 AND activa = 1 
                      LIMIT 1";
        
        $clave = Database::selectOne($sql_clave, [':numero_tienda' => $numero_tienda]);
        
        if (!$clave) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'No hay claves disponibles para esta tienda'
            ]);
            return;
        }
        
        // Asignar la clave
        $sql_assign = "UPDATE claves_tienda 
                       SET en_uso = 1, 
                           id_promotor_actual = :id_promotor, 
                           fecha_asignacion = NOW()";
        
        $params_assign = [
            ':id_promotor' => $id_promotor,
            ':id_clave' => $clave['id_clave']
        ];
        
        // Solo agregar usuario_asigno si hay sesión válida
        if ($usuario_asigno) {
            $sql_assign .= ", usuario_asigno = :usuario_asigno";
            $params_assign[':usuario_asigno'] = $usuario_asigno;
        }
        
        $sql_assign .= " WHERE id_clave = :id_clave";
        
        $rows_affected = Database::execute($sql_assign, $params_assign);
        
        if ($rows_affected > 0) {
            error_log("HORAS_API: Clave {$clave['codigo_clave']} asignada a promotor {$id_promotor}");
            
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'message' => 'Clave asignada exitosamente',
                'clave' => [
                    'id_clave' => intval($clave['id_clave']),
                    'codigo_clave' => $clave['codigo_clave']
                ]
            ], JSON_UNESCAPED_UNICODE);
        } else {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Error asignando clave'
            ]);
        }
        
    } catch (Exception $e) {
        error_log("HORAS_API: Error asignando clave - " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error asignando clave: ' . $e->getMessage()
        ]);
    }
}

/**
 * ===== ACTUALIZAR/LIBERAR CLAVE =====
 */
function updateClave($id) {
    try {
        error_log("HORAS_API: Actualizando clave ID: {$id}");
        
        $content_type = $_SERVER['CONTENT_TYPE'] ?? '';
        if (strpos($content_type, 'application/json') !== false) {
            $input = json_decode(file_get_contents('php://input'), true);
        } else {
            $input = $_POST;
        }
        
        if (isset($input['liberar']) && $input['liberar'] === true) {
            $sql = "UPDATE claves_tienda 
                    SET en_uso = 0, 
                        id_promotor_actual = NULL, 
                        fecha_liberacion = NOW()
                    WHERE id_clave = :id";
            
            $rows_affected = Database::execute($sql, [':id' => $id]);
            
            if ($rows_affected > 0) {
                error_log("HORAS_API: Clave {$id} liberada exitosamente");
                
                http_response_code(200);
                echo json_encode([
                    'success' => true,
                    'message' => 'Clave liberada exitosamente'
                ], JSON_UNESCAPED_UNICODE);
            } else {
                http_response_code(404);
                echo json_encode([
                    'success' => false,
                    'message' => 'Clave no encontrada'
                ]);
            }
        } else {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Operación no válida'
            ]);
        }
        
    } catch (Exception $e) {
        error_log("HORAS_API: Error actualizando clave {$id} - " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error actualizando clave: ' . $e->getMessage()
        ]);
    }
}

/**
 * ===== OBTENER PROMOTORES CON FECHA DE INGRESO =====
 */
function handlePromotores($method, $id) {
    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Método no permitido']);
        return;  
    }
    
    try {
        error_log('HORAS_API: Obteniendo promotores con fecha de ingreso');
        
        $sql = "SELECT id_promotor, nombre, apellido, telefono, correo, estatus, rfc, tipo_trabajo, fecha_ingreso
                FROM promotores 
                WHERE estado = 1 AND estatus = 'ACTIVO'
                ORDER BY tipo_trabajo DESC, nombre, apellido";
        
        $promotores = Database::select($sql);
        
        error_log("HORAS_API: {" . count($promotores) . "} promotores obtenidos con fechas de ingreso");
        
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'data' => $promotores
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        error_log("HORAS_API: Error obteniendo promotores - " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error obteniendo promotores: ' . $e->getMessage()
        ]);
    }
}

/**
 * ===== OBTENER TIENDAS (HELPER ENDPOINT) =====
 */
function handleTiendas($method, $id) {
    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Método no permitido']);
        return;
    }
    
    try {
        error_log('HORAS_API: Obteniendo tiendas');
        
        $sql = "SELECT id_tienda, num_tienda, cadena, nombre_tienda, region, ciudad, estado
                FROM tiendas 
                WHERE estado_reg = 1
                ORDER BY cadena, num_tienda";
        
        $tiendas = Database::select($sql);
        
        error_log("HORAS_API: {" . count($tiendas) . "} tiendas obtenidas");
        
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'data' => $tiendas
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        error_log("HORAS_API: Error obteniendo tiendas - " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error obteniendo tiendas: ' . $e->getMessage()
        ]);
    }
}

?>