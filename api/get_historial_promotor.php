<?php

// ===== DESHABILITAR ERRORES HTML EN PRODUCCIÓN =====
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

// ===== INICIAR OUTPUT BUFFERING PARA CAPTURAR CUALQUIER OUTPUT NO DESEADO =====
ob_start();

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
    ob_end_clean(); // Limpiar buffer
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
                'is_legacy' => false
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
        'query_string' => $_SERVER['QUERY_STRING'] ?? '',
        'session_status' => session_status(),
        'session_id' => session_id()
    ];
    error_log('GET_HISTORIAL_PROMOTOR: Iniciando - ' . json_encode($debug_info));

    // ===== VERIFICAR SESIÓN BÁSICA =====
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        error_log('GET_HISTORIAL_PROMOTOR: Sin sesión - user_id no encontrado');
        ob_end_clean();
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
        ob_end_clean();
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
        ob_end_clean();
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

    // ===== OBTENER INFORMACIÓN DEL PROMOTOR CON DÍA DE DESCANSO =====
    $sql_promotor = "SELECT 
                        p.*,
                        CONCAT(p.nombre, ' ', p.apellido) as nombre_completo,
                        DATE_FORMAT(p.fecha_ingreso, '%d/%m/%Y') as fecha_ingreso_formatted,
                        CASE 
                            WHEN p.tipo_trabajo = 'fijo' THEN 'Promotor Fijo'
                            WHEN p.tipo_trabajo = 'cubredescansos' THEN 'Cubre Descansos'
                            ELSE p.tipo_trabajo
                        END as tipo_trabajo_formatted,
                        DATE_FORMAT(p.fecha_alta, '%d/%m/%Y %H:%i') as fecha_alta_formatted,
                        DATE_FORMAT(p.fecha_modificacion, '%d/%m/%Y %H:%i') as fecha_modificacion_formatted
                     FROM promotores p 
                     WHERE p.id_promotor = :id_promotor AND p.estado = 1";
    
    $promotor = Database::selectOne($sql_promotor, [':id_promotor' => $id_promotor]);

    if (!$promotor) {
        ob_end_clean();
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Promotor no encontrado'
        ]);
        exit;
    }

    // ===== ✅ FORMATEAR DÍA DE DESCANSO =====
    if ($promotor['dia_descanso']) {
        $dias_semana = [
            '1' => 'Lunes',
            '2' => 'Martes',
            '3' => 'Miércoles',
            '4' => 'Jueves',
            '5' => 'Viernes',
            '6' => 'Sábado',
            '7' => 'Domingo'
        ];
        $promotor['dia_descanso_formatted'] = $dias_semana[$promotor['dia_descanso']] ?? 'N/A';
    } else {
        $promotor['dia_descanso_formatted'] = 'No especificado';
    }

    // ===== OBTENER CLAVES ASIGNADAS ACTUALMENTE (NUEVA FUNCIONALIDAD) =====
    $sql_claves_actuales = "SELECT 
                                ct.id_clave,
                                ct.codigo_clave,
                                ct.numero_tienda as clave_tienda,
                                ct.region as clave_region,
                                ct.en_uso,
                                ct.fecha_asignacion
                            FROM claves_tienda ct
                            WHERE ct.id_promotor_actual = :id_promotor
                            AND ct.activa = 1
                            ORDER BY ct.codigo_clave";
    
    $claves_actuales = Database::select($sql_claves_actuales, [':id_promotor' => $id_promotor]);

    error_log('GET_HISTORIAL_PROMOTOR: ' . count($claves_actuales) . ' claves actuales encontradas');

    // ===== 🔧 CORRECCIÓN: CONSULTA DE HISTORIAL CON DETECCIÓN DE REACTIVACIONES =====
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
                        
                        t.cadena,
                        t.num_tienda,
                        t.nombre_tienda,
                        t.ciudad,
                        t.region,
                        
                        CONCAT(t.cadena, ' #', t.num_tienda, ' - ', t.nombre_tienda) as tienda_identificador,
                        DATE_FORMAT(pta.fecha_inicio, '%d/%m/%Y') as fecha_inicio_formatted,
                        DATE_FORMAT(pta.fecha_fin, '%d/%m/%Y') as fecha_fin_formatted,
                        
                        -- ✅ CORRECCIÓN: Detectar correctamente asignaciones activas (incluyendo reactivadas)
                        CASE 
                            WHEN pta.fecha_fin IS NULL AND pta.activo = 1 THEN 1
                            ELSE 0
                        END as actualmente_asignado,
                        
                        -- Calcular días asignado
                        CASE 
                            WHEN pta.fecha_fin IS NOT NULL THEN 
                                DATEDIFF(pta.fecha_fin, pta.fecha_inicio) + 1
                            WHEN pta.activo = 1 THEN 
                                DATEDIFF(CURDATE(), pta.fecha_inicio) + 1
                            ELSE 0
                        END as dias_asignado,
                        
                        u1.nombre as usuario_asigno_nombre,
                        u1.apellido as usuario_asigno_apellido,
                        CONCAT(u1.nombre, ' ', u1.apellido) as usuario_asigno,
                        
                        u2.nombre as usuario_cambio_nombre,
                        u2.apellido as usuario_cambio_apellido,
                        CONCAT(u2.nombre, ' ', u2.apellido) as usuario_cambio
                        
                      FROM promotor_tienda_asignaciones pta
                      INNER JOIN tiendas t ON pta.id_tienda = t.id_tienda
                      LEFT JOIN usuarios u1 ON pta.usuario_asigno = u1.id
                      LEFT JOIN usuarios u2 ON pta.usuario_cambio = u2.id
                      
                      WHERE pta.id_promotor = :id_promotor";

    $params = [':id_promotor' => $id_promotor];

    // Filtrar inactivos si se requiere
    if (!$incluir_inactivos) {
        $sql_historial .= " AND pta.activo = 1";
    }

    $sql_historial .= " ORDER BY pta.fecha_inicio DESC, pta.id_asignacion DESC";

    error_log('GET_HISTORIAL_PROMOTOR: Ejecutando consulta de historial');

    // ===== EJECUTAR CONSULTA =====
    $historial = Database::select($sql_historial, $params);

    error_log('GET_HISTORIAL_PROMOTOR: ' . count($historial) . ' registros encontrados en historial');

    // ===== CALCULAR ESTADÍSTICAS (MEJORADO CON TODAS LAS FUNCIONALIDADES) =====
    $total_asignaciones = count($historial);
    $asignaciones_activas = 0;
    $asignaciones_finalizadas = 0;
    $total_dias_asignado = 0;
    $tiendas_distintas = [];
    $cadenas_distintas = [];
    $primera_asignacion = null;
    $ultima_asignacion = null;
    
    $tiendas_visitadas = [];

    foreach ($historial as &$item) {
        // 🔧 CORRECCIÓN: Convertir a entero para comparación segura
        $actualmente_asignado_bool = intval($item['actualmente_asignado']) === 1;
        $activo_bool = intval($item['activo']) === 1;
        
        // Log detallado para debugging
        error_log("GET_HISTORIAL: Asignación ID {$item['id_asignacion']} - " . 
                  "Tienda: {$item['tienda_identificador']}, " .
                  "fecha_fin: " . ($item['fecha_fin'] ?? 'NULL') . ", " .
                  "activo: {$item['activo']}, " .
                  "actualmente_asignado: {$item['actualmente_asignado']}");
        
        // Contar por estatus
        if ($actualmente_asignado_bool) {
            $asignaciones_activas++;
            error_log("GET_HISTORIAL: ✅ ASIGNACIÓN ACTIVA DETECTADA - ID: {$item['id_asignacion']}");
        } else {
            $asignaciones_finalizadas++;
        }

        // Sumar días
        $total_dias_asignado += intval($item['dias_asignado']);

        // Contar tiendas distintas
        if (!in_array($item['id_tienda'], $tiendas_visitadas)) {
            $tiendas_visitadas[] = $item['id_tienda'];
        }

        // Tiendas y cadenas únicas
        $tienda_key = $item['cadena'] . '_' . $item['num_tienda'];
        $tiendas_distintas[$tienda_key] = [
            'cadena' => $item['cadena'],
            'num_tienda' => $item['num_tienda'],
            'nombre_tienda' => $item['nombre_tienda']
        ];
        $cadenas_distintas[$item['cadena']] = $item['cadena'];

        // Primera y última asignación
        if (!$primera_asignacion || $item['fecha_inicio'] < $primera_asignacion['fecha_inicio']) {
            $primera_asignacion = $item;
        }
        if (!$ultima_asignacion || $item['fecha_inicio'] > $ultima_asignacion['fecha_inicio']) {
            $ultima_asignacion = $item;
        }
        
        // 🔧 CORRECCIÓN: Formatear estado usando las variables booleanas
        if ($item['fecha_fin']) {
            $item['periodo_completo'] = $item['fecha_inicio_formatted'] . ' hasta ' . $item['fecha_fin_formatted'];
            $item['estado_asignacion'] = 'Finalizada';
        } else if ($activo_bool) {
            $item['periodo_completo'] = 'Desde: ' . $item['fecha_inicio_formatted'];
            $item['estado_asignacion'] = 'Activa';
        } else {
            $item['periodo_completo'] = 'Inicio: ' . $item['fecha_inicio_formatted'];
            $item['estado_asignacion'] = 'Eliminada';
        }
    }
    
    error_log("GET_HISTORIAL: RESUMEN - Total: {$total_asignaciones}, Activas: {$asignaciones_activas}, Finalizadas: {$asignaciones_finalizadas}");
    
    // ===== ESTADÍSTICAS COMBINADAS (ORIGINAL + AVANZADAS) =====
    $estadisticas = [
        // Estadísticas originales
        'total_asignaciones' => $total_asignaciones,
        'asignaciones_activas' => $asignaciones_activas,
        'total_dias_asignado' => $total_dias_asignado,
        'tiendas_distintas' => count($tiendas_visitadas),
        
        // Nuevas estadísticas avanzadas
        'asignaciones_finalizadas' => $asignaciones_finalizadas,
        'promedio_dias_por_asignacion' => $total_asignaciones > 0 ? round($total_dias_asignado / $total_asignaciones, 1) : 0,
        'cadenas_distintas' => count($cadenas_distintas),
        'lista_cadenas' => array_values($cadenas_distintas),
        
        // Estadísticas de claves
        'claves' => [
            'total_claves_asignadas' => count($claves_actuales),
            'claves_activas' => count(array_filter($claves_actuales, function($c) { return $c['en_uso']; })),
            'tiendas_con_claves' => count(array_unique(array_column($claves_actuales, 'clave_tienda'))),
            'regiones_con_claves' => count(array_unique(array_column($claves_actuales, 'clave_region')))
        ]
    ];

    // ===== PROCESAMIENTO AVANZADO DE CLAVES MÚLTIPLES =====
    $clave_asistencia_parsed = [];
    $claves_desde_json = [];
    $claves_desde_tabla = [];
    
    // Procesar claves desde el campo JSON
    if ($promotor['clave_asistencia']) {
        $clave_asistencia_parsed = json_decode($promotor['clave_asistencia'], true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($clave_asistencia_parsed)) {
            $claves_desde_json = $clave_asistencia_parsed;
        } else {
            // Si no es JSON válido, podría ser una clave única antigua
            $claves_desde_json = [$promotor['clave_asistencia']];
        }
    }
    
    // Procesar claves desde la tabla claves_tienda (datos más actualizados)
    foreach ($claves_actuales as $clave) {
        $claves_desde_tabla[] = [
            'id_clave' => intval($clave['id_clave']),
            'codigo' => $clave['codigo_clave'],
            'numero_tienda' => intval($clave['clave_tienda']),
            'region' => intval($clave['clave_region']),
            'en_uso' => (bool)$clave['en_uso'],
            'fecha_asignacion' => $clave['fecha_asignacion'],
            'fecha_asignacion_formatted' => $clave['fecha_asignacion'] ? date('d/m/Y H:i', strtotime($clave['fecha_asignacion'])) : 'N/A'
        ];
    }
    
    // Determinar qué claves mostrar (priorizar tabla real sobre JSON)
    $claves_a_mostrar = count($claves_desde_tabla) > 0 ? 
        array_column($claves_desde_tabla, 'codigo') : 
        $claves_desde_json;

    // ===== PROCESAMIENTO AVANZADO DE NUMERO_TIENDA JSON =====
    $numero_tienda_info = formatearNumeroTiendaJSON($promotor['numero_tienda']);
    error_log('GET_HISTORIAL_PROMOTOR: numero_tienda procesado - Tipo: ' . $numero_tienda_info['type'] . ', Display: ' . $numero_tienda_info['display']);

    // ===== LIMPIAR BUFFER ANTES DE ENVIAR JSON =====
    ob_end_clean();

    // ===== RESPUESTA FINAL CON DÍA DE DESCANSO =====
    $response = [
        'success' => true,
        // Mantener estructura original del promotor pero con mejoras
        'promotor' => array_merge($promotor, [
            // Campos originales formateados mantenidos
            'nombre_completo' => $promotor['nombre_completo'],
            'fecha_ingreso_formatted' => $promotor['fecha_ingreso_formatted'],
            'tipo_trabajo_formatted' => $promotor['tipo_trabajo_formatted'],
            'fecha_alta_formatted' => $promotor['fecha_alta_formatted'],
            'fecha_modificacion_formatted' => $promotor['fecha_modificacion_formatted'],
            
            // ✅ DÍA DE DESCANSO AGREGADO
            'dia_descanso' => $promotor['dia_descanso'],
            'dia_descanso_formatted' => $promotor['dia_descanso_formatted'],
            
            // Nuevas funcionalidades avanzadas
            'region' => (int)$promotor['region'],
            'numero_tienda_display' => $numero_tienda_info['display'],
            'numero_tienda_parsed' => $numero_tienda_info['parsed'],
            'numero_tienda_info' => $numero_tienda_info,
            'clave_asistencia_parsed' => $clave_asistencia_parsed,
            'claves_desde_json' => $claves_desde_json,
            'claves_actuales' => $claves_desde_tabla,
            'claves_codigos' => $claves_a_mostrar,
            'claves_texto' => implode(', ', $claves_a_mostrar),
            'vacaciones' => (bool)$promotor['vacaciones'],
            'incidencias' => (bool)$promotor['incidencias'],
            'estado' => intval($promotor['estado'])
        ]),
        
        // Mantener historial original
        'historial' => $historial,
        
        // Estadísticas combinadas (original + avanzadas)
        'estadisticas' => $estadisticas
    ];

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    // ===== MANEJO DE ERRORES QUE SIEMPRE DEVUELVE JSON =====
    ob_end_clean(); // Limpiar cualquier output anterior
    
    error_log("Error en get_historial_promotor.php: " . $e->getMessage() . " - Usuario: " . ($_SESSION['username'] ?? 'desconocido') . " - IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor',
        'debug' => [
            'error' => $e->getMessage(),
            'file' => basename($e->getFile()),
            'line' => $e->getLine()
        ]
    ]);
}
?>