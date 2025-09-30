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
    error_log('GET_PROMOTORES_DISPONIBLES_V2: Iniciando - ' . json_encode($debug_info));

    // ===== VERIFICAR SESIÓN BÁSICA =====
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        error_log('GET_PROMOTORES_DISPONIBLES_V2: Sin sesión - user_id no encontrado');
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
        error_log('GET_PROMOTORES_DISPONIBLES_V2: Rol incorrecto - ' . ($_SESSION['rol'] ?? 'NO_SET'));
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Sin permisos para ver promotores.',
            'error' => 'insufficient_permissions'
        ]);
        exit;
    }

    error_log('GET_PROMOTORES_DISPONIBLES_V2: Sesión válida - Usuario: ' . ($_SESSION['username'] ?? 'NO_USERNAME') . ', Rol: ' . $_SESSION['rol']);

    // ===== INCLUIR CONEXIÓN DB =====
    $db_path = __DIR__ . '/../config/db_connect.php';
    if (!file_exists($db_path)) {
        error_log('GET_PROMOTORES_DISPONIBLES_V2: Archivo db_connect.php no encontrado en: ' . $db_path);
        throw new Exception('Configuración de base de datos no encontrada');
    }

    require_once $db_path;

    // ===== VERIFICAR CLASE DATABASE =====
    if (!class_exists('Database')) {
        error_log('GET_PROMOTORES_DISPONIBLES_V2: Clase Database no encontrada');
        throw new Exception('Clase Database no disponible');
    }

    // ===== OBTENER PARÁMETROS =====
    $solo_activos = ($_GET['solo_activos'] ?? 'true') === 'true';
    $search = trim($_GET['search'] ?? '');
    $excluir_tienda = intval($_GET['excluir_tienda'] ?? 0); // Para evitar mostrar promotores ya en esa tienda

    error_log('GET_PROMOTORES_DISPONIBLES_V2: Solo activos: ' . ($solo_activos ? 'true' : 'false') . ', Search: ' . $search . ', Excluir tienda: ' . $excluir_tienda);

    // ===== VERIFICAR CONEXIÓN DB =====
    try {
        $test_connection = Database::connect();
        if (!$test_connection) {
            throw new Exception('No se pudo establecer conexión con la base de datos');
        }
        error_log('GET_PROMOTORES_DISPONIBLES_V2: Conexión DB exitosa');
    } catch (Exception $conn_error) {
        error_log('GET_PROMOTORES_DISPONIBLES_V2: Error de conexión DB - ' . $conn_error->getMessage());
        throw new Exception('Error de conexión a la base de datos: ' . $conn_error->getMessage());
    }

    // ===== VERIFICAR SI EXISTEN LAS TABLAS =====
    try {
        $table_check = Database::selectOne("SHOW TABLES LIKE 'promotores'");
        if (!$table_check) {
            error_log('GET_PROMOTORES_DISPONIBLES_V2: Tabla promotores no existe');
            throw new Exception('La tabla de promotores no existe en la base de datos');
        }
        error_log('GET_PROMOTORES_DISPONIBLES_V2: Tabla promotores verificada');
    } catch (Exception $table_error) {
        error_log('GET_PROMOTORES_DISPONIBLES_V2: Error verificando tabla - ' . $table_error->getMessage());
        throw new Exception('Error verificando tabla promotores: ' . $table_error->getMessage());
    }

    // ===== 🆕 CONSTRUIR CONSULTA BASE - ACTUALIZADA CON NUMERO_TIENDA Y DIA_DESCANSO =====
    $sql = "SELECT 
                p.id_promotor,
                p.nombre,
                p.apellido,
                p.telefono,
                p.correo,
                p.rfc,
                p.estatus,
                p.vacaciones,
                p.numero_tienda,
                p.region,
                p.tipo_trabajo,
                p.fecha_ingreso,
                p.clave_asistencia,
                p.dia_descanso,
                
                COUNT(pta.id_asignacion) as total_asignaciones_activas,
                GROUP_CONCAT(
                    CONCAT(t.cadena, ' #', t.num_tienda, ' - ', t.nombre_tienda)
                    ORDER BY pta.fecha_inicio DESC
                    SEPARATOR '; '
                ) as tiendas_asignadas,
                GROUP_CONCAT(
                    DISTINCT t.id_tienda
                    ORDER BY pta.fecha_inicio DESC
                ) as ids_tiendas_asignadas,
                MAX(pta.fecha_inicio) as fecha_ultima_asignacion
            FROM promotores p
            LEFT JOIN promotor_tienda_asignaciones pta ON (
                p.id_promotor = pta.id_promotor 
                AND pta.activo = 1 
                AND pta.fecha_fin IS NULL
            )
            LEFT JOIN tiendas t ON (
                pta.id_tienda = t.id_tienda
                AND t.estado_reg = 1
            )
            WHERE p.estado = 1";

    $params = [];

    // ===== FILTROS =====
    
    // Solo promotores activos
    if ($solo_activos) {
        $sql .= " AND p.estatus = 'ACTIVO'";
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

    $sql .= " GROUP BY p.id_promotor, p.nombre, p.apellido, p.telefono, p.correo, p.rfc, p.estatus, p.vacaciones, p.numero_tienda, p.region, p.tipo_trabajo, p.fecha_ingreso, p.clave_asistencia, p.dia_descanso";

    // 🆕 FILTRO PARA EXCLUIR PROMOTORES YA ASIGNADOS A UNA TIENDA ESPECÍFICA
    if ($excluir_tienda > 0) {
        $sql .= " HAVING (
                    total_asignaciones_activas = 0 
                    OR ids_tiendas_asignadas NOT LIKE :excluir_tienda_param
                  )";
        $params[':excluir_tienda_param'] = '%' . $excluir_tienda . '%';
    }

    $sql .= " ORDER BY p.nombre ASC, p.apellido ASC";

    error_log('GET_PROMOTORES_DISPONIBLES_V2: Query - ' . $sql);

    // ===== EJECUTAR CONSULTA =====
    $promotores = Database::select($sql, $params);

    error_log('GET_PROMOTORES_DISPONIBLES_V2: ' . count($promotores) . ' promotores encontrados');

    // ===== FORMATEAR DATOS =====
    $promotores_formateados = [];
    $estadisticas = [
        'total' => 0,
        'activos_sin_asignacion' => 0,
        'activos_con_asignaciones' => 0,
        'en_vacaciones' => 0,
        'inactivos' => 0,
        'total_asignaciones_sistema' => 0,
        // ===== 🆕 ESTADÍSTICAS DE NUMERO_TIENDA JSON =====
        'con_numero_tienda' => 0,
        'sin_numero_tienda' => 0,
        'numero_tienda_json' => 0,
        'numero_tienda_legacy' => 0,
        // ===== 🆕 ESTADÍSTICAS DE DÍA DE DESCANSO =====
        'con_dia_descanso' => 0,
        'sin_dia_descanso' => 0
    ];

    // ===== 🆕 PROCESAR TIPOS DE TRABAJO =====
    $tipos_trabajo = [
        'fijo' => 'Fijo',
        'cubredescansos' => 'Cubre Descansos'
    ];

    // ===== 🆕 MAPEO DE DÍAS DE LA SEMANA =====
    $dias_semana = [
        '1' => 'Lunes',
        '2' => 'Martes',
        '3' => 'Miércoles',
        '4' => 'Jueves',
        '5' => 'Viernes',
        '6' => 'Sábado',
        '7' => 'Domingo'
    ];

    foreach ($promotores as $promotor) {
        $estadisticas['total']++;

        // Determinar estado
        $total_asignaciones = intval($promotor['total_asignaciones_activas']);
        $esta_en_vacaciones = intval($promotor['vacaciones']) === 1;
        $esta_activo = $promotor['estatus'] === 'ACTIVO';

        // ===== 🆕 PROCESAR NUMERO_TIENDA CON SOPORTE JSON =====
        $numero_tienda_info = formatearNumeroTiendaJSON($promotor['numero_tienda']);
        
        // Actualizar estadísticas de numero_tienda
        if ($numero_tienda_info['parsed'] !== null) {
            $estadisticas['con_numero_tienda']++;
            if ($numero_tienda_info['is_json']) {
                $estadisticas['numero_tienda_json']++;
            } else {
                $estadisticas['numero_tienda_legacy']++;
            }
        } else {
            $estadisticas['sin_numero_tienda']++;
        }

        // ===== 🆕 PROCESAR DÍA DE DESCANSO =====
        $dia_descanso = $promotor['dia_descanso'];
        $dia_descanso_formatted = 'No asignado';
        $tiene_dia_descanso = false;
        
        if (!empty($dia_descanso) && isset($dias_semana[$dia_descanso])) {
            $dia_descanso_formatted = $dias_semana[$dia_descanso];
            $tiene_dia_descanso = true;
            $estadisticas['con_dia_descanso']++;
        } else {
            $estadisticas['sin_dia_descanso']++;
        }

        // ===== 🆕 PROCESAR CLAVES DE ASISTENCIA =====
        $claves_asistencia = [];
        if (!empty($promotor['clave_asistencia'])) {
            $parsed_claves = json_decode($promotor['clave_asistencia'], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($parsed_claves)) {
                $claves_asistencia = $parsed_claves;
            } else {
                $claves_asistencia = [$promotor['clave_asistencia']];
            }
        }

        // Estadísticas existentes
        if ($esta_activo) {
            if ($total_asignaciones === 0) {
                $estadisticas['activos_sin_asignacion']++;
            } else {
                $estadisticas['activos_con_asignaciones']++;
                $estadisticas['total_asignaciones_sistema'] += $total_asignaciones;
            }
        } else {
            $estadisticas['inactivos']++;
        }

        if ($esta_en_vacaciones) {
            $estadisticas['en_vacaciones']++;
        }

        // 🆕 PROCESAR LISTA DE TIENDAS ASIGNADAS
        $tiendas_asignadas_lista = [];
        $ids_tiendas_lista = [];
        
        if ($total_asignaciones > 0 && !empty($promotor['tiendas_asignadas'])) {
            $tiendas_asignadas_lista = explode('; ', $promotor['tiendas_asignadas']);
            $ids_tiendas_lista = array_map('intval', explode(',', $promotor['ids_tiendas_asignadas'] ?? ''));
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
            'vacaciones' => $esta_en_vacaciones,
            'activo' => $esta_activo,
            
            // ===== 🆕 INFORMACIÓN ADICIONAL DEL PROMOTOR =====
            'region' => (int)$promotor['region'],
            'tipo_trabajo' => $promotor['tipo_trabajo'],
            'tipo_trabajo_formatted' => $tipos_trabajo[$promotor['tipo_trabajo']] ?? $promotor['tipo_trabajo'],
            'fecha_ingreso' => $promotor['fecha_ingreso'],
            'fecha_ingreso_formatted' => $promotor['fecha_ingreso'] ? date('d/m/Y', strtotime($promotor['fecha_ingreso'])) : 'N/A',
            
            // ===== 🆕 NÚMERO DE TIENDA CON SOPORTE JSON =====
            'numero_tienda' => $numero_tienda_info['original'],
            'numero_tienda_display' => $numero_tienda_info['display'],
            'numero_tienda_parsed' => $numero_tienda_info['parsed'],
            'numero_tienda_info' => $numero_tienda_info,
            
            // ===== 🆕 DÍA DE DESCANSO =====
            'dia_descanso' => $dia_descanso,
            'dia_descanso_formatted' => $dia_descanso_formatted,
            'tiene_dia_descanso' => $tiene_dia_descanso,
            
            // ===== 🆕 CLAVES DE ASISTENCIA PROCESADAS =====
            'clave_asistencia' => $promotor['clave_asistencia'],
            'claves_asistencia_parsed' => $claves_asistencia,
            'claves_texto' => implode(', ', $claves_asistencia),
            'total_claves' => count($claves_asistencia),
            
            // 🆕 NUEVA INFORMACIÓN DE ASIGNACIONES MÚLTIPLES
            'total_asignaciones_activas' => $total_asignaciones,
            'tiene_asignaciones' => $total_asignaciones > 0,
            'puede_asignar_mas' => $esta_activo, // Ahora siempre puede asignar más si está activo
            'disponible_para_asignacion' => $esta_activo && !$esta_en_vacaciones, // Nuevo criterio de disponibilidad
            
            'tiendas_asignadas' => $tiendas_asignadas_lista,
            'ids_tiendas_asignadas' => $ids_tiendas_lista,
            'fecha_ultima_asignacion' => $promotor['fecha_ultima_asignacion'],
            'fecha_ultima_asignacion_formatted' => $promotor['fecha_ultima_asignacion'] ? 
                date('d/m/Y', strtotime($promotor['fecha_ultima_asignacion'])) : null
        ];

        // Estado y mensajes descriptivos
        if (!$esta_activo) {
            $item['estado_descripcion'] = 'Promotor inactivo';
            $item['estado_color'] = 'secondary';
        } elseif ($esta_en_vacaciones) {
            $item['estado_descripcion'] = 'En vacaciones';
            $item['estado_color'] = 'warning';
        } elseif ($total_asignaciones === 0) {
            $item['estado_descripcion'] = 'Disponible - Sin asignaciones';
            $item['estado_color'] = 'success';
        } else {
            $item['estado_descripcion'] = 'Asignado a ' . $total_asignaciones . ' tienda' . ($total_asignaciones > 1 ? 's' : '');
            $item['estado_color'] = 'info';
        }

        // Razones por las que no se puede asignar (si aplica)
        $motivos_no_disponible = [];
        if (!$esta_activo) {
            $motivos_no_disponible[] = 'Estatus inactivo';
        }
        if ($esta_en_vacaciones) {
            $motivos_no_disponible[] = 'En vacaciones';
        }
        $item['motivos_no_disponible'] = $motivos_no_disponible;

        $promotores_formateados[] = $item;
    }

    // ===== PREPARAR RESPUESTA =====
    $response = [
        'success' => true,
        'data' => $promotores_formateados,
        'estadisticas' => $estadisticas,
        'configuracion' => [
            'permite_asignaciones_multiples' => true,
            'criterio_disponibilidad' => 'promotor_activo',
            'incluye_promotores_con_asignaciones' => true,
            // ===== 🆕 CONFIGURACIÓN JSON =====
            'soporta_numero_tienda_json' => true,
            'soporta_claves_multiples' => true,
            'incluye_dia_descanso' => true
        ],
        'filtros' => [
            'solo_activos' => $solo_activos,
            'search' => $search,
            'excluir_tienda' => $excluir_tienda
        ],
        'metadata' => [
            'total_encontrados' => count($promotores_formateados),
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '2.2 - Asignaciones Múltiples + JSON Support + Día Descanso',
            // ===== 🆕 METADATOS JSON =====
            'campos_json' => [
                'numero_tienda' => 'Soporta JSON array, object, y valores legacy',
                'clave_asistencia' => 'Soporta JSON array de claves múltiples'
            ],
            'campos_nuevos' => [
                'dia_descanso' => 'Día de descanso del promotor (1-7, siendo 1=Lunes, 7=Domingo)'
            ]
        ]
    ];

    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    // Log del error
    error_log("Error en get_promotores_disponibles_v2.php: " . $e->getMessage() . " - Usuario: " . ($_SESSION['username'] ?? 'desconocido') . " - IP: " . $_SERVER['REMOTE_ADDR']);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor'
    ]);
}
?>