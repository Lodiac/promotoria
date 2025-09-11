<?php

// Habilitar logging de errores para debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// 🔒 DEFINIR CONSTANTE ANTES DE INCLUIR DB_CONNECT
define('APP_ACCESS', true);

// Headers de seguridad y CORS
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// Verificar que sea GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
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
        'query_string' => $_SERVER['QUERY_STRING'] ?? '',
        'session_status' => session_status(),
        'session_id' => session_id()
    ];
    error_log('GET_HISTORIAL_PROMOTOR: Iniciando - ' . json_encode($debug_info));

    // ===== VERIFICAR SESIÓN BÁSICA =====
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        error_log('GET_HISTORIAL_PROMOTOR: Sin sesión - user_id no encontrado');
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'No hay sesión activa',
            'error' => 'no_session'
        ]);
        exit;
    }

    // ===== VERIFICAR ROL =====
    if (!isset($_SESSION['rol']) || !in_array($_SESSION['rol'], ['usuario', 'supervisor', 'root'])) {
        error_log('GET_HISTORIAL_PROMOTOR: Rol incorrecto - ' . ($_SESSION['rol'] ?? 'NO_SET'));
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Sin permisos para ver historial.',
            'error' => 'insufficient_permissions'
        ]);
        exit;
    }

    error_log('GET_HISTORIAL_PROMOTOR: Sesión válida - Usuario: ' . ($_SESSION['username'] ?? 'NO_USERNAME') . ', Rol: ' . $_SESSION['rol']);

    // ===== INCLUIR CONEXIÓN DB =====
    $db_path = __DIR__ . '/../config/db_connect.php';
    if (!file_exists($db_path)) {
        error_log('GET_HISTORIAL_PROMOTOR: Archivo db_connect.php no encontrado en: ' . $db_path);
        throw new Exception('Configuración de base de datos no encontrada');
    }

    require_once $db_path;

    // ===== VERIFICAR CLASE DATABASE =====
    if (!class_exists('Database')) {
        error_log('GET_HISTORIAL_PROMOTOR: Clase Database no encontrada');
        throw new Exception('Clase Database no disponible');
    }

    // ===== OBTENER PARÁMETROS =====
    $id_promotor = intval($_GET['id_promotor'] ?? 0);
    $incluir_inactivos = ($_GET['incluir_inactivos'] ?? 'false') === 'true';

    error_log('GET_HISTORIAL_PROMOTOR: ID Promotor: ' . $id_promotor . ', Incluir inactivos: ' . ($incluir_inactivos ? 'true' : 'false'));

    // ===== VALIDACIONES BÁSICAS =====
    if ($id_promotor <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'ID de promotor requerido y válido'
        ]);
        exit;
    }

    // ===== VERIFICAR CONEXIÓN DB =====
    try {
        $test_connection = Database::connect();
        if (!$test_connection) {
            throw new Exception('No se pudo establecer conexión con la base de datos');
        }
        error_log('GET_HISTORIAL_PROMOTOR: Conexión DB exitosa');
    } catch (Exception $conn_error) {
        error_log('GET_HISTORIAL_PROMOTOR: Error de conexión DB - ' . $conn_error->getMessage());
        throw new Exception('Error de conexión a la base de datos: ' . $conn_error->getMessage());
    }

    // ===== VERIFICAR QUE EL PROMOTOR EXISTE (CON NUEVOS CAMPOS) =====
    $sql_promotor = "SELECT 
                        id_promotor, 
                        nombre, 
                        apellido, 
                        telefono, 
                        correo, 
                        rfc, 
                        estatus, 
                        vacaciones,
                        incidencias,
                        fecha_ingreso,
                        tipo_trabajo,
                        estado,
                        fecha_alta
                     FROM promotores 
                     WHERE id_promotor = :id_promotor";
    
    $promotor = Database::selectOne($sql_promotor, [':id_promotor' => $id_promotor]);

    if (!$promotor) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Promotor no encontrado'
        ]);
        exit;
    }

    // ===== CONSTRUIR CONSULTA DEL HISTORIAL =====
    $sql_historial = "SELECT 
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
                      INNER JOIN tiendas t ON pta.id_tienda = t.id_tienda
                      LEFT JOIN usuarios u1 ON pta.usuario_asigno = u1.id
                      LEFT JOIN usuarios u2 ON pta.usuario_cambio = u2.id
                      WHERE pta.id_promotor = :id_promotor
                      AND t.estado_reg = 1";

    $params = [':id_promotor' => $id_promotor];

    // Filtrar inactivos si se requiere
    if (!$incluir_inactivos) {
        $sql_historial .= " AND pta.activo = 1";
    }

    $sql_historial .= " ORDER BY pta.fecha_inicio DESC, pta.fecha_registro DESC";

    error_log('GET_HISTORIAL_PROMOTOR: Query - ' . $sql_historial);

    // ===== EJECUTAR CONSULTA =====
    $historial = Database::select($sql_historial, $params);

    error_log('GET_HISTORIAL_PROMOTOR: ' . count($historial) . ' registros encontrados');

    // ===== CALCULAR ESTADÍSTICAS =====
    $total_asignaciones = count($historial);
    $asignaciones_activas = 0;
    $asignaciones_finalizadas = 0;
    $total_dias_asignado = 0;
    $tiendas_distintas = [];
    $cadenas_distintas = [];
    $primera_asignacion = null;
    $ultima_asignacion = null;

    foreach ($historial as $asignacion) {
        // Contar por estatus
        if ($asignacion['activo'] && !$asignacion['fecha_fin']) {
            $asignaciones_activas++;
        } else {
            $asignaciones_finalizadas++;
        }

        // Calcular días
        $fecha_inicio = new DateTime($asignacion['fecha_inicio']);
        $fecha_fin = $asignacion['fecha_fin'] ? new DateTime($asignacion['fecha_fin']) : new DateTime();
        $dias = $fecha_fin->diff($fecha_inicio)->days;
        $total_dias_asignado += $dias;

        // Tiendas y cadenas únicas
        $tienda_key = $asignacion['cadena'] . '_' . $asignacion['num_tienda'];
        $tiendas_distintas[$tienda_key] = [
            'cadena' => $asignacion['cadena'],
            'num_tienda' => $asignacion['num_tienda'],
            'nombre_tienda' => $asignacion['nombre_tienda']
        ];
        $cadenas_distintas[$asignacion['cadena']] = $asignacion['cadena'];

        // Primera y última asignación
        if (!$primera_asignacion || $asignacion['fecha_inicio'] < $primera_asignacion['fecha_inicio']) {
            $primera_asignacion = $asignacion;
        }
        if (!$ultima_asignacion || $asignacion['fecha_inicio'] > $ultima_asignacion['fecha_inicio']) {
            $ultima_asignacion = $asignacion;
        }
    }

    // ===== FORMATEAR HISTORIAL =====
    $historial_formateado = [];
    foreach ($historial as $asignacion) {
        $fecha_inicio = new DateTime($asignacion['fecha_inicio']);
        $fecha_fin = $asignacion['fecha_fin'] ? new DateTime($asignacion['fecha_fin']) : null;
        $dias_asignado = $fecha_fin ? $fecha_fin->diff($fecha_inicio)->days : (new DateTime())->diff($fecha_inicio)->days;

        $item = [
            'id_asignacion' => intval($asignacion['id_asignacion']),
            'id_tienda' => intval($asignacion['id_tienda']),
            'tienda_region' => intval($asignacion['region']),
            'tienda_cadena' => $asignacion['cadena'],
            'tienda_num_tienda' => intval($asignacion['num_tienda']),
            'tienda_nombre_tienda' => $asignacion['nombre_tienda'],
            'tienda_ciudad' => $asignacion['ciudad'],
            'tienda_estado' => $asignacion['tienda_estado'],
            'tienda_identificador' => $asignacion['cadena'] . ' #' . $asignacion['num_tienda'] . ' - ' . $asignacion['nombre_tienda'],
            
            'fecha_inicio' => $asignacion['fecha_inicio'],
            'fecha_fin' => $asignacion['fecha_fin'],
            'dias_asignado' => $dias_asignado,
            'actualmente_asignado' => $asignacion['activo'] && !$asignacion['fecha_fin'],
            
            'motivo_asignacion' => $asignacion['motivo_asignacion'],
            'motivo_cambio' => $asignacion['motivo_cambio'],
            
            'usuario_asigno' => $asignacion['usuario_asigno_nombre'] ? 
                trim($asignacion['usuario_asigno_nombre'] . ' ' . $asignacion['usuario_asigno_apellido']) : 'N/A',
            'usuario_cambio' => $asignacion['usuario_cambio_nombre'] ? 
                trim($asignacion['usuario_cambio_nombre'] . ' ' . $asignacion['usuario_cambio_apellido']) : null,
            
            'fecha_inicio_formatted' => $fecha_inicio->format('d/m/Y'),
            'fecha_fin_formatted' => $fecha_fin ? $fecha_fin->format('d/m/Y') : null,
            'fecha_registro_formatted' => date('d/m/Y H:i', strtotime($asignacion['fecha_registro'])),
            
            'activo' => intval($asignacion['activo']),
            'estatus_texto' => $asignacion['fecha_fin'] ? 'Finalizado' : ($asignacion['activo'] ? 'Activo' : 'Inactivo'),
            'estatus_color' => $asignacion['fecha_fin'] ? 'secondary' : ($asignacion['activo'] ? 'success' : 'warning')
        ];

        $historial_formateado[] = $item;
    }

    // ===== OBTENER ASIGNACIÓN ACTUAL (SI EXISTE) =====
    $asignacion_actual = null;
    foreach ($historial_formateado as $asignacion) {
        if ($asignacion['actualmente_asignado']) {
            $asignacion_actual = $asignacion;
            break;
        }
    }

    // ===== FORMATEAR DATOS DEL PROMOTOR (CON NUEVOS CAMPOS) =====
    $tipos_trabajo = [
        'fijo' => 'Fijo',
        'cubredescansos' => 'Cubre Descansos'
    ];

    // ===== PREPARAR RESPUESTA =====
    $response = [
        'success' => true,
        'promotor' => [
            'id' => intval($promotor['id_promotor']),
            'nombre_completo' => trim($promotor['nombre'] . ' ' . $promotor['apellido']),
            'nombre' => $promotor['nombre'],
            'apellido' => $promotor['apellido'],
            'telefono' => $promotor['telefono'],
            'correo' => $promotor['correo'],
            'rfc' => $promotor['rfc'],
            'estatus' => $promotor['estatus'],
            'vacaciones' => (bool)$promotor['vacaciones'],
            'incidencias' => (bool)$promotor['incidencias'],
            'fecha_ingreso' => $promotor['fecha_ingreso'],
            'fecha_ingreso_formatted' => $promotor['fecha_ingreso'] ? date('d/m/Y', strtotime($promotor['fecha_ingreso'])) : 'N/A',
            'tipo_trabajo' => $promotor['tipo_trabajo'],
            'tipo_trabajo_formatted' => $tipos_trabajo[$promotor['tipo_trabajo']] ?? $promotor['tipo_trabajo'],
            'estado' => intval($promotor['estado']),
            'fecha_alta' => $promotor['fecha_alta'],
            'fecha_alta_formatted' => date('d/m/Y', strtotime($promotor['fecha_alta']))
        ],
        'asignacion_actual' => $asignacion_actual,
        'historial' => $historial_formateado,
        'estadisticas' => [
            'total_asignaciones' => $total_asignaciones,
            'asignaciones_activas' => $asignaciones_activas,
            'asignaciones_finalizadas' => $asignaciones_finalizadas,
            'total_dias_asignado' => $total_dias_asignado,
            'promedio_dias_por_asignacion' => $total_asignaciones > 0 ? round($total_dias_asignado / $total_asignaciones, 1) : 0,
            'tiendas_distintas' => count($tiendas_distintas),
            'cadenas_distintas' => count($cadenas_distintas),
            'lista_cadenas' => array_values($cadenas_distintas)
        ],
        'resumen_cronologico' => [
            'primera_asignacion' => $primera_asignacion ? [
                'fecha' => $primera_asignacion['fecha_inicio'],
                'tienda' => $primera_asignacion['cadena'] . ' #' . $primera_asignacion['num_tienda']
            ] : null,
            'ultima_asignacion' => $ultima_asignacion ? [
                'fecha' => $ultima_asignacion['fecha_inicio'],
                'tienda' => $ultima_asignacion['cadena'] . ' #' . $ultima_asignacion['num_tienda']
            ] : null
        ]
    ];

    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    // Log del error
    error_log("Error en get_historial_promotor.php: " . $e->getMessage() . " - Usuario: " . ($_SESSION['username'] ?? 'desconocido') . " - IP: " . $_SERVER['REMOTE_ADDR']);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor'
    ]);
}
?>