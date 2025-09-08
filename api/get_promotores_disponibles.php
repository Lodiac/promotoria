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
    error_log('GET_PROMOTORES_DISPONIBLES: Iniciando - ' . json_encode($debug_info));

    // ===== VERIFICAR SESIÓN BÁSICA =====
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        error_log('GET_PROMOTORES_DISPONIBLES: Sin sesión - user_id no encontrado');
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
        error_log('GET_PROMOTORES_DISPONIBLES: Rol incorrecto - ' . ($_SESSION['rol'] ?? 'NO_SET'));
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Sin permisos para ver promotores.',
            'error' => 'insufficient_permissions'
        ]);
        exit;
    }

    error_log('GET_PROMOTORES_DISPONIBLES: Sesión válida - Usuario: ' . ($_SESSION['username'] ?? 'NO_USERNAME') . ', Rol: ' . $_SESSION['rol']);

    // ===== INCLUIR CONEXIÓN DB =====
    $db_path = __DIR__ . '/../config/db_connect.php';
    if (!file_exists($db_path)) {
        error_log('GET_PROMOTORES_DISPONIBLES: Archivo db_connect.php no encontrado en: ' . $db_path);
        throw new Exception('Configuración de base de datos no encontrada');
    }

    require_once $db_path;

    // ===== VERIFICAR CLASE DATABASE =====
    if (!class_exists('Database')) {
        error_log('GET_PROMOTORES_DISPONIBLES: Clase Database no encontrada');
        throw new Exception('Clase Database no disponible');
    }

    // ===== OBTENER PARÁMETROS =====
    $incluir_asignados = ($_GET['incluir_asignados'] ?? 'false') === 'true';
    $solo_activos = ($_GET['solo_activos'] ?? 'true') === 'true';
    $search = trim($_GET['search'] ?? '');

    error_log('GET_PROMOTORES_DISPONIBLES: Incluir asignados: ' . ($incluir_asignados ? 'true' : 'false') . ', Solo activos: ' . ($solo_activos ? 'true' : 'false') . ', Search: ' . $search);

    // ===== VERIFICAR CONEXIÓN DB =====
    try {
        $test_connection = Database::connect();
        if (!$test_connection) {
            throw new Exception('No se pudo establecer conexión con la base de datos');
        }
        error_log('GET_PROMOTORES_DISPONIBLES: Conexión DB exitosa');
    } catch (Exception $conn_error) {
        error_log('GET_PROMOTORES_DISPONIBLES: Error de conexión DB - ' . $conn_error->getMessage());
        throw new Exception('Error de conexión a la base de datos: ' . $conn_error->getMessage());
    }

    // ===== VERIFICAR SI EXISTEN LAS TABLAS =====
    try {
        $table_check = Database::selectOne("SHOW TABLES LIKE 'promotores'");
        if (!$table_check) {
            error_log('GET_PROMOTORES_DISPONIBLES: Tabla promotores no existe');
            throw new Exception('La tabla de promotores no existe en la base de datos');
        }
        error_log('GET_PROMOTORES_DISPONIBLES: Tabla promotores verificada');
    } catch (Exception $table_error) {
        error_log('GET_PROMOTORES_DISPONIBLES: Error verificando tabla - ' . $table_error->getMessage());
        throw new Exception('Error verificando tabla promotores: ' . $table_error->getMessage());
    }

    // ===== CONSTRUIR CONSULTA BASE =====
    $sql = "SELECT 
                p.id_promotor,
                p.nombre,
                p.apellido,
                p.telefono,
                p.correo,
                p.rfc,
                p.estatus,
                p.vacaciones,
                
                pta.id_asignacion as asignacion_activa_id,
                pta.fecha_inicio as asignacion_fecha_inicio,
                t.cadena as asignacion_cadena,
                t.num_tienda as asignacion_num_tienda,
                t.nombre_tienda as asignacion_nombre_tienda
            FROM promotores p
            LEFT JOIN promotor_tienda_asignaciones pta ON (
                p.id_promotor = pta.id_promotor 
                AND pta.activo = 1 
                AND pta.fecha_fin IS NULL
            )
            LEFT JOIN tiendas t ON pta.id_tienda = t.id_tienda
            WHERE p.estado = 1";

    $params = [];

    // ===== FILTROS =====
    
    // Solo promotores activos
    if ($solo_activos) {
        $sql .= " AND p.estatus = 'ACTIVO'";
    }

    // Excluir promotores con asignación activa (comportamiento por defecto)
    if (!$incluir_asignados) {
        $sql .= " AND pta.id_asignacion IS NULL";
    }

    // Filtro de búsqueda
    if (!empty($search)) {
        $sql .= " AND (
                    p.nombre LIKE :search 
                    OR p.apellido LIKE :search 
                    OR CONCAT(p.nombre, ' ', p.apellido) LIKE :search
                    OR p.correo LIKE :search
                    OR p.rfc LIKE :search
                  )";
        $params[':search'] = '%' . $search . '%';
    }

    $sql .= " ORDER BY p.nombre ASC, p.apellido ASC";

    error_log('GET_PROMOTORES_DISPONIBLES: Query - ' . $sql);

    // ===== EJECUTAR CONSULTA =====
    $promotores = Database::select($sql, $params);

    error_log('GET_PROMOTORES_DISPONIBLES: ' . count($promotores) . ' promotores encontrados');

    // ===== FORMATEAR DATOS =====
    $promotores_formateados = [];
    $estadisticas = [
        'total' => 0,
        'disponibles' => 0,
        'asignados' => 0,
        'en_vacaciones' => 0,
        'inactivos' => 0
    ];

    foreach ($promotores as $promotor) {
        $estadisticas['total']++;

        // Determinar disponibilidad
        $tiene_asignacion = !empty($promotor['asignacion_activa_id']);
        $esta_en_vacaciones = intval($promotor['vacaciones']) === 1;
        $esta_activo = $promotor['estatus'] === 'ACTIVO';

        if ($tiene_asignacion) {
            $estadisticas['asignados']++;
        } else {
            $estadisticas['disponibles']++;
        }

        if ($esta_en_vacaciones) {
            $estadisticas['en_vacaciones']++;
        }

        if (!$esta_activo) {
            $estadisticas['inactivos']++;
        }

        $item = [
            'id_promotor' => intval($promotor['id_promotor']),
            'nombre' => $promotor['nombre'],
            'apellido' => $promotor['apellido'],
            'nombre_completo' => trim($promotor['nombre'] . ' ' . $promotor['apellido']),
            'telefono' => $promotor['telefono'],
            'correo' => $promotor['correo'],
            'rfc' => $promotor['rfc'],
            'estatus' => $promotor['estatus'],
            'vacaciones' => intval($promotor['vacaciones']) === 1,
            'disponible' => !$tiene_asignacion && $esta_activo && !$esta_en_vacaciones,
            'tiene_asignacion' => $tiene_asignacion,
            'en_vacaciones' => $esta_en_vacaciones,
            'activo' => $esta_activo,
            'puede_asignar' => !$tiene_asignacion && $esta_activo
        ];

        // Información de asignación actual si existe
        if ($tiene_asignacion) {
            $item['asignacion_actual'] = [
                'id_asignacion' => intval($promotor['asignacion_activa_id']),
                'fecha_inicio' => $promotor['asignacion_fecha_inicio'],
                'tienda_cadena' => $promotor['asignacion_cadena'],
                'tienda_num_tienda' => intval($promotor['asignacion_num_tienda']),
                'tienda_nombre_tienda' => $promotor['asignacion_nombre_tienda'],
                'tienda_identificador' => $promotor['asignacion_cadena'] . ' #' . $promotor['asignacion_num_tienda'] . ' - ' . $promotor['asignacion_nombre_tienda'],
                'dias_asignado' => (new DateTime())->diff(new DateTime($promotor['asignacion_fecha_inicio']))->days
            ];
        } else {
            $item['asignacion_actual'] = null;
        }

        // Motivos de no disponibilidad
        $motivos_no_disponible = [];
        if ($tiene_asignacion) {
            $motivos_no_disponible[] = 'Ya tiene asignación activa';
        }
        if (!$esta_activo) {
            $motivos_no_disponible[] = 'Estatus inactivo';
        }
        if ($esta_en_vacaciones) {
            $motivos_no_disponible[] = 'En vacaciones';
        }
        $item['motivo_no_disponible'] = $motivos_no_disponible;

        $promotores_formateados[] = $item;
    }

    // ===== PREPARAR RESPUESTA =====
    $response = [
        'success' => true,
        'data' => $promotores_formateados,
        'estadisticas' => $estadisticas,
        'filtros' => [
            'incluir_asignados' => $incluir_asignados,
            'solo_activos' => $solo_activos,
            'search' => $search
        ],
        'metadata' => [
            'total_encontrados' => count($promotores_formateados),
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ];

    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    // Log del error
    error_log("Error en get_promotores_disponibles.php: " . $e->getMessage() . " - Usuario: " . ($_SESSION['username'] ?? 'desconocido') . " - IP: " . $_SERVER['REMOTE_ADDR']);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor'
    ]);
}
?>