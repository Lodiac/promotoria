<?php
session_start();

// // 🔍 DEBUGGING - Comentar en producción
// error_log("========================================");
// error_log("=== ZONAS API REQUEST ===");
// error_log("Method: " . $_SERVER['REQUEST_METHOD']);
// error_log("URI: " . $_SERVER['REQUEST_URI']);
// error_log("Query String: " . ($_SERVER['QUERY_STRING'] ?? 'N/A'));
// error_log("Session ID: " . session_id());
// error_log("Session User: " . ($_SESSION['username'] ?? 'NO_SESSION'));
// error_log("Session Rol: " . ($_SESSION['rol'] ?? 'NO_ROL'));
// error_log("========================================");

error_log("========================================");
error_log("🔍 VERIFICANDO SESIÓN");
error_log("ID: " . ($_SESSION['user_id'] ?? 'NO DEFINIDO'));
error_log("Username: " . ($_SESSION['username'] ?? 'NO DEFINIDO'));
error_log("Rol: " . ($_SESSION['rol'] ?? 'NO DEFINIDO'));
error_log("========================================");

// 🔐 DEFINIR CONSTANTE ANTES DE INCLUIR DB_CONNECT
define('APP_ACCESS', true);

// Incluir la API de base de datos
require_once __DIR__ . '/../config/db_connect.php';

// Headers de seguridad y CORS
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// ===== VERIFICAR SESIÓN Y ROL =====
$roles_permitidos = ['root', 'supervisor'];
if (!isset($_SESSION['rol']) || !in_array(strtolower($_SESSION['rol']), $roles_permitidos)) {
    error_log('❌ ZONAS_API: Acceso denegado - Rol: ' . ($_SESSION['rol'] ?? 'NO_SET'));
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Acceso denegado. Se requiere rol ROOT o SUPERVISOR.',
        'error' => 'insufficient_permissions',
        'debug' => [
            'session_exists' => isset($_SESSION['rol']),
            'session_rol' => $_SESSION['rol'] ?? null,
            'roles_permitidos' => $roles_permitidos
        ]
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    
    // Obtener datos de entrada
    $input = null;
    $content_type = $_SERVER['CONTENT_TYPE'] ?? '';

    if (strpos($content_type, 'application/json') !== false) {
        $raw_input = file_get_contents('php://input');
        error_log("📥 Raw JSON Input: " . $raw_input);
    
        // Solo intentar decodificar si hay contenido
        if (!empty($raw_input)) {
            $input = json_decode($raw_input, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('JSON inválido: ' . json_last_error_msg());
            }
        } else {
            $input = [];
        }
    } else {
        $input = [];
    }

    // Mezclar con parámetros GET y POST
    $input = array_merge($input, $_GET, $_POST);
    

    // Determinar la acción
    $action = $input['action'] ?? '';
    error_log("🎯 Action: " . $action);
    error_log("📦 Input Data: " . print_r($input, true));

    // ===== ROUTER DE ACCIONES =====
    switch ($method) {
        case 'GET':
            if ($action === 'get_one' && !empty($input['id'])) {
                getZonaById($input['id']);
            } elseif ($action === 'get_supervisores') {
                getSupervisores();
            } elseif ($action === 'get_tiendas') {
                getTiendas();
            } elseif ($action === 'get_regiones') {
                getRegiones();
            }elseif ($action === 'get_promotores_tiendas') {
                // Recibir IDs de tiendas como array
                $tiendas_ids = isset($input['tiendas']) ? json_decode($input['tiendas'], true) : [];
                getPromotoresPorTiendas($tiendas_ids); 
            }else {
                // Por defecto, listar zonas
                getZonas($input);
            }
            break;

        case 'POST':
            if ($action === 'create') {
                createZona($input);
            } else {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'Acción no especificada para POST'
                ], JSON_UNESCAPED_UNICODE);
            }
            break;

        case 'PUT':
            if ($action === 'update') {
                updateZona($input);
            } else {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'Acción no especificada para PUT'
                ], JSON_UNESCAPED_UNICODE);
            }
            break;

        case 'DELETE':
            if (!empty($input['id'])) {
                deleteZona($input['id']);
            } else {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'ID no especificado para DELETE'
                ], JSON_UNESCAPED_UNICODE);
            }
            break;

        default:
            http_response_code(405);
            echo json_encode([
                'success' => false,
                'message' => 'Método no permitido'
            ], JSON_UNESCAPED_UNICODE);
            break;
    }

} catch (Exception $e) {
    error_log("❌❌❌ ERROR EN ZONAS.PHP ❌❌❌");
    error_log("Error: " . $e->getMessage());
    error_log("File: " . $e->getFile());
    error_log("Line: " . $e->getLine());
    error_log("Trace: " . $e->getTraceAsString());
    error_log("Usuario: " . ($_SESSION['username'] ?? 'desconocido'));
    error_log("IP: " . $_SERVER['REMOTE_ADDR']);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor',
        'error' => $e->getMessage(),
        'debug' => [
            'file' => basename($e->getFile()),
            'line' => $e->getLine()
        ]
    ], JSON_UNESCAPED_UNICODE);
}

// ===== FUNCIÓN: OBTENER LISTA DE ZONAS CON PAGINACIÓN Y FILTROS =====
function getZonas($params) {
    try {
        error_log("📋 Ejecutando getZonas()");
        
        // 🆕 OBTENER ROL DEL USUARIO
        $rol = strtolower($_SESSION['rol'] ?? 'usuario');
        $usuario_id = $_SESSION['user_id'] ?? null;
        
        error_log("👤 Usuario ID: $usuario_id");
        error_log("👤 Rol: $rol");
        
        // Parámetros de paginación
        $page = isset($params['page']) ? max(1, intval($params['page'])) : 1;
        $limit = isset($params['limit']) ? max(1, min(100, intval($params['limit']))) : 10;
        $offset = ($page - 1) * $limit;

        // Construir WHERE dinámico
        $where_conditions = ["z.activa = 1"];
        $where_params = [];

        // 🆕 SI ES SUPERVISOR, FILTRAR SOLO SUS ZONAS
        if ($rol === 'supervisor') {
            if (!$usuario_id) {
                error_log("❌ ERROR: Usuario ID no definido en sesión");
                throw new Exception("Usuario no identificado en la sesión");
            }
            
            $where_conditions[] = "EXISTS (
                SELECT 1 FROM zona_supervisor zs
                WHERE zs.id_zona = z.id_zona
                AND zs.id_supervisor = :usuario_id
                AND zs.activa = 1
            )";
            $where_params[':usuario_id'] = $usuario_id;
            error_log("🔒 Filtro de supervisor aplicado para usuario ID: $usuario_id");
            
            // 🆕 DEBUG: Verificar si el supervisor tiene zonas asignadas
            $debug_sql = "SELECT COUNT(*) as total FROM zona_supervisor WHERE id_supervisor = :id AND activa = 1";
            $debug_result = Database::selectOne($debug_sql, [':id' => $usuario_id]);
            error_log("📊 DEBUG: El supervisor tiene " . ($debug_result['total'] ?? 0) . " zonas asignadas en zona_supervisor");
        }

        $where_clause = implode(' AND ', $where_conditions);

        // Contar total
        $sql_count = "SELECT COUNT(DISTINCT z.id_zona) as total
                      FROM zonas z
                      LEFT JOIN regiones r ON z.id_region = r.id_region
                      WHERE $where_clause";

        error_log("🔍 SQL COUNT: $sql_count");
        error_log("🔍 Params: " . json_encode($where_params));

        $count_result = Database::selectOne($sql_count, $where_params);
        $total = $count_result['total'] ?? 0;
        error_log("✅ Total de zonas encontradas: " . $total);

        // Si no hay zonas, dar más información
        if ($total === 0 && $rol === 'supervisor') {
            error_log("⚠️ ATENCIÓN: No se encontraron zonas para el supervisor");
            error_log("⚠️ Verifica que la tabla zona_supervisor tenga registros para id_supervisor = $usuario_id");
        }

        // Obtener zonas
        $sql = "SELECT 
                    z.id_zona,
                    z.nombre_zona,
                    z.descripcion,
                    z.activa,
                    z.fecha_creacion,
                    z.fecha_modificacion,
                    r.id_region,
                    r.numero_region,
                    r.nombre_region,
                    (SELECT COUNT(*) FROM zona_supervisor WHERE id_zona = z.id_zona AND activa = 1) as total_supervisores,
                    (SELECT COUNT(*) FROM tiendas WHERE id_zona = z.id_zona AND estado_reg = 1) as total_tiendas,
                    (SELECT COUNT(DISTINCT pta.id_promotor) 
                     FROM promotor_tienda_asignaciones pta
                     INNER JOIN tiendas t ON pta.id_tienda = t.id_tienda
                     WHERE t.id_zona = z.id_zona 
                     AND pta.activo = 1
                     AND pta.fecha_inicio <= CURDATE()
                     AND (pta.fecha_fin IS NULL OR pta.fecha_fin >= CURDATE())) as total_promotores
                FROM zonas z
                LEFT JOIN regiones r ON z.id_region = r.id_region
                WHERE $where_clause
                ORDER BY z.fecha_creacion DESC
                LIMIT :limit OFFSET :offset";

        $params_final = array_merge($where_params, [
            ':limit' => $limit,
            ':offset' => $offset
        ]);

        $zonas = Database::select($sql, $params_final);
        error_log("✅ Zonas obtenidas: " . count($zonas));

        // Formatear fechas
        foreach ($zonas as &$zona) {
            if ($zona['fecha_creacion']) {
                $zona['fecha_creacion_formatted'] = date('d/m/Y', strtotime($zona['fecha_creacion']));
            }
            if ($zona['fecha_modificacion']) {
                $zona['fecha_modificacion_formatted'] = date('d/m/Y H:i', strtotime($zona['fecha_modificacion']));
            }
        }

        $response = [
            'success' => true,
            'data' => $zonas,
            'pagination' => [
                'total' => (int)$total,
                'page' => (int)$page,
                'limit' => (int)$limit,
                'total_pages' => (int)ceil($total / $limit)
            ],
            'user_rol' => $rol // 🆕 Enviar el rol al frontend
        ];

        http_response_code(200);
        echo json_encode($response, JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        error_log("❌ Error en getZonas: " . $e->getMessage());
        error_log("❌ Stack trace: " . $e->getTraceAsString());
        
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error al obtener zonas',
            'error' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
}

// ===== FUNCIÓN: OBTENER UNA ZONA POR ID CON RELACIONES =====
function getZonaById($id) {
    try {
        error_log("👁️ getZonaById: " . $id);
        $id = intval($id);

        // Obtener zona
        $sql_zona = "SELECT 
                        z.id_zona,
                        z.nombre_zona,
                        z.descripcion,
                        z.activa,
                        z.fecha_creacion,
                        z.fecha_modificacion,
                        r.id_region,
                        r.numero_region,
                        r.nombre_region
                    FROM zonas z
                    LEFT JOIN regiones r ON z.id_region = r.id_region
                    WHERE z.id_zona = :id";

        $zona = Database::selectOne($sql_zona, [':id' => $id]);

        if (!$zona) {
            error_log("❌ Zona no encontrada: " . $id);
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Zona no encontrada'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        // Obtener supervisores asignados
        $sql_supervisores = "SELECT 
                                u.id,
                                u.nombre,
                                u.apellido,
                                u.email,
                                zs.fecha_asignacion
                            FROM zona_supervisor zs
                            INNER JOIN usuarios u ON zs.id_supervisor = u.id
                            WHERE zs.id_zona = :id AND zs.activa = 1
                            ORDER BY u.nombre, u.apellido";

        $supervisores = Database::select($sql_supervisores, [':id' => $id]);

        // Obtener tiendas asignadas
        $sql_tiendas = "SELECT 
                            id_tienda,
                            nombre_tienda,
                            cadena,
                            num_tienda,
                            ciudad,
                            estado
                        FROM tiendas
                        WHERE id_zona = :id AND estado_reg = 1
                        ORDER BY nombre_tienda";

        $tiendas = Database::select($sql_tiendas, [':id' => $id]);

        // Formatear fechas
        if ($zona['fecha_creacion']) {
            $zona['fecha_creacion_formatted'] = date('d/m/Y H:i', strtotime($zona['fecha_creacion']));
        }
        if ($zona['fecha_modificacion']) {
            $zona['fecha_modificacion_formatted'] = date('d/m/Y H:i', strtotime($zona['fecha_modificacion']));
        }

        $zona['supervisores'] = $supervisores;
        $zona['tiendas'] = $tiendas;

        error_log("✅ Zona cargada con " . count($supervisores) . " supervisores y " . count($tiendas) . " tiendas");

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'data' => $zona
        ], JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        error_log("❌ Error en getZonaById: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error al obtener zona',
            'error' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
}

// ===== FUNCIÓN: CREAR NUEVA ZONA =====
function createZona($input) {
    try {
        error_log("========================================");
        error_log("🆕 INICIO createZona");
        error_log("Input recibido: " . print_r($input, true));

        // Validar campos requeridos
        $required_fields = ['nombre_zona', 'id_region'];
        $errors = [];

        foreach ($required_fields as $field) {
            if (!isset($input[$field]) || trim($input[$field]) === '') {
                $errors[] = "El campo '{$field}' es requerido";
            }
        }

        if (!empty($errors)) {
            error_log("❌ Validación fallida: " . print_r($errors, true));
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Campos requeridos faltantes',
                'errors' => $errors
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        // Sanitizar datos
        $nombre_zona = Database::sanitize(trim($input['nombre_zona']));
        $id_region = intval($input['id_region']);
        $descripcion = Database::sanitize(trim($input['descripcion'] ?? ''));
        $activa = isset($input['activa']) ? intval($input['activa']) : 1;
        $usuario_id = $_SESSION['user_id'] ?? null;

        error_log("✅ Datos sanitizados:");
        error_log("  - nombre_zona: $nombre_zona");
        error_log("  - id_region: $id_region");
        error_log("  - descripcion: $descripcion");
        error_log("  - activa: $activa");
        error_log("  - usuario_id: $usuario_id");

        // 🆕 VALIDAR QUE LA REGIÓN EXISTE O CREARLA AUTOMÁTICAMENTE
        error_log("🔍 Verificando región...");
        $sql_check_region = "SELECT id_region FROM regiones WHERE id_region = :id";
        $region_exists = Database::selectOne($sql_check_region, [':id' => $id_region]);

        if (!$region_exists) {
            error_log("⚠️ Región $id_region no existe, creándola automáticamente...");
            
            // Crear la región automáticamente
            $sql_create_region = "INSERT INTO regiones (id_region, numero_region, nombre_region, descripcion, activa, usuario_creacion) 
                                  VALUES (:id_region, :numero_region, :nombre_region, NULL, 1, :usuario_creacion)";
            
            try {
                Database::insert($sql_create_region, [
                    ':id_region' => $id_region,
                    ':numero_region' => $id_region,
                    ':nombre_region' => "Región $id_region",
                    ':usuario_creacion' => $usuario_id
                ]);
                error_log("✅ Región $id_region creada automáticamente");
            } catch (Exception $e) {
                error_log("❌ Error al crear región: " . $e->getMessage());
                // Continuar de todos modos, puede que otro proceso la haya creado
            }
        } else {
            error_log("✅ Región $id_region ya existe");
        }

        // Validaciones
        if (strlen($nombre_zona) > 100) {
            error_log("❌ Nombre muy largo: " . strlen($nombre_zona) . " caracteres");
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'El nombre de la zona no puede exceder 100 caracteres'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        // Verificar duplicados
        error_log("🔍 Verificando duplicados...");
        $sql_check = "SELECT id_zona FROM zonas WHERE nombre_zona = :nombre AND activa = 1 LIMIT 1";
        $duplicate = Database::selectOne($sql_check, [':nombre' => $nombre_zona]);

        if ($duplicate) {
            error_log("❌ Duplicado encontrado: " . print_r($duplicate, true));
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'message' => "Ya existe una zona con el nombre '{$nombre_zona}'"
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        error_log("✅ No hay duplicados");

        // Insertar zona
        error_log("💾 Insertando zona...");
        $sql_insert = "INSERT INTO zonas (
                            nombre_zona,
                            id_region,
                            descripcion,
                            activa,
                            usuario_creacion,
                            fecha_creacion,
                            fecha_modificacion
                       ) VALUES (
                            :nombre_zona,
                            :id_region,
                            :descripcion,
                            :activa,
                            :usuario_creacion,
                            NOW(),
                            NOW()
                       )";

        $params = [
            ':nombre_zona' => $nombre_zona,
            ':id_region' => $id_region,
            ':descripcion' => !empty($descripcion) ? $descripcion : null,
            ':activa' => $activa,
            ':usuario_creacion' => $usuario_id
        ];

        error_log("SQL Insert: " . $sql_insert);
        error_log("Params: " . print_r($params, true));

        try {
            $new_id = Database::insert($sql_insert, $params);
            error_log("✅ Zona insertada con ID: " . $new_id);
        } catch (Exception $e) {
            error_log("❌❌❌ ERROR AL INSERTAR ZONA ❌❌❌");
            error_log("Error: " . $e->getMessage());
            error_log("Trace: " . $e->getTraceAsString());
            throw $e;
        }

        if (!$new_id) {
            error_log("❌ Insert no devolvió ID");
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'No se pudo crear la zona'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        // Asignar supervisores si se proporcionaron
        if (isset($input['supervisores']) && is_array($input['supervisores']) && count($input['supervisores']) > 0) {
            error_log("👥 Asignando " . count($input['supervisores']) . " supervisores");
            
            foreach ($input['supervisores'] as $supervisor_id) {
                try {
                    $supervisor_id = intval($supervisor_id);
                    error_log("👤 Asignando supervisor ID: $supervisor_id");
                    
                    $sql_supervisor = "INSERT INTO zona_supervisor (
                                            id_zona,
                                            id_supervisor,
                                            usuario_asigno,
                                            fecha_asignacion,
                                            activa
                                       ) VALUES (
                                            :id_zona,
                                            :id_supervisor,
                                            :usuario_asigno,
                                            NOW(),
                                            1
                                       )";
                    
                    $result = Database::insert($sql_supervisor, [
                        ':id_zona' => $new_id,
                        ':id_supervisor' => $supervisor_id,
                        ':usuario_asigno' => $usuario_id
                    ]);
                    
                    error_log("✅ Supervisor $supervisor_id asignado correctamente (Insert ID: $result)");
                } catch (Exception $e) {
                    error_log("❌ Error al asignar supervisor $supervisor_id: " . $e->getMessage());
                    error_log("Trace: " . $e->getTraceAsString());
                }
            }
        } else {
            error_log("⚠️ No se proporcionaron supervisores para asignar");
        }

        // Asignar tiendas si se proporcionaron
        if (isset($input['tiendas']) && is_array($input['tiendas']) && count($input['tiendas']) > 0) {
            error_log("🏪 Asignando " . count($input['tiendas']) . " tiendas");
            
            foreach ($input['tiendas'] as $tienda_id) {
                try {
                    $tienda_id = intval($tienda_id);
                    error_log("🏪 Asignando tienda ID: $tienda_id");
                    
                    $sql_tienda = "UPDATE tiendas SET id_zona = :id_zona WHERE id_tienda = :id_tienda";
                    Database::execute($sql_tienda, [
                        ':id_zona' => $new_id,
                        ':id_tienda' => $tienda_id
                    ]);
                    
                    error_log("✅ Tienda $tienda_id asignada correctamente");
                } catch (Exception $e) {
                    error_log("❌ Error al asignar tienda $tienda_id: " . $e->getMessage());
                    error_log("Trace: " . $e->getTraceAsString());
                }
            }
        } else {
            error_log("⚠️ No se proporcionaron tiendas para asignar");
        }

        // Obtener la zona creada con relaciones
        error_log("📥 Devolviendo zona creada con ID: $new_id");
        error_log("========================================");
        getZonaById($new_id);

        // Log de auditoría
        error_log("✅ Zona creada exitosamente - ID: {$new_id} - Nombre: {$nombre_zona} - Usuario: " . ($_SESSION['username'] ?? 'desconocido') . " - IP: " . $_SERVER['REMOTE_ADDR']);

    } catch (Exception $e) {
        error_log("❌❌❌ ERROR EN createZona ❌❌❌");
        error_log("Error: " . $e->getMessage());
        error_log("File: " . $e->getFile());
        error_log("Line: " . $e->getLine());
        error_log("Trace: " . $e->getTraceAsString());
        error_log("========================================");
        
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error al crear zona',
            'error' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
}

// ===== FUNCIÓN: ACTUALIZAR ZONA =====
function updateZona($input) {
    try {
        error_log("✏️ Actualizando zona");
        error_log("Input: " . print_r($input, true));

        if (!isset($input['id_zona'])) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'ID de zona no especificado'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        $id_zona = intval($input['id_zona']);
        $usuario_id = $_SESSION['user_id'] ?? null;

        // Verificar que la zona existe
        $sql_check = "SELECT id_zona FROM zonas WHERE id_zona = :id";
        $zona_exists = Database::selectOne($sql_check, [':id' => $id_zona]);

        if (!$zona_exists) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Zona no encontrada'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        // Sanitizar datos
        $nombre_zona = Database::sanitize(trim($input['nombre_zona'] ?? ''));
        $id_region = isset($input['id_region']) ? intval($input['id_region']) : null;
        $descripcion = Database::sanitize(trim($input['descripcion'] ?? ''));
        $activa = isset($input['activa']) ? intval($input['activa']) : null;

        // Construir UPDATE dinámico
        $update_fields = [];
        $update_params = [':id' => $id_zona];

        if (!empty($nombre_zona)) {
            $update_fields[] = "nombre_zona = :nombre_zona";
            $update_params[':nombre_zona'] = $nombre_zona;
        }

        // if ($id_region !== null) {
        //     // Verificar que la región existe
        //     $sql_check_region = "SELECT id_region FROM regiones WHERE id_region = :id AND activa = 1";
        //     $region_exists = Database::selectOne($sql_check_region, [':id' => $id_region]);

        //     if (!$region_exists) {
        //         http_response_code(400);
        //         echo json_encode([
        //             'success' => false,
        //             'message' => 'La región especificada no existe o está inactiva'
        //         ], JSON_UNESCAPED_UNICODE);
        //         return;
        //     }

        //     $update_fields[] = "id_region = :id_region";
        //     $update_params[':id_region'] = $id_region;
        // }
        if ($id_region !== null) {
        // 🆕 YA NO VALIDAMOS - ES LIBRE
            $update_fields[] = "id_region = :id_region";
            $update_params[':id_region'] = $id_region;
        }

        if ($descripcion !== '') {
            $update_fields[] = "descripcion = :descripcion";
            $update_params[':descripcion'] = $descripcion;
        }

        if ($activa !== null) {
            $update_fields[] = "activa = :activa";
            $update_params[':activa'] = $activa;
        }

        // Actualizar zona
        if (!empty($update_fields)) {
            $sql_update = "UPDATE zonas SET " . implode(', ', $update_fields) . ", fecha_modificacion = NOW() WHERE id_zona = :id";
            error_log("SQL Update: " . $sql_update);
            error_log("Params: " . print_r($update_params, true));
            
            Database::execute($sql_update, $update_params);
        }

        // Actualizar supervisores
        if (isset($input['supervisores']) && is_array($input['supervisores'])) {
            error_log("👥 Actualizando supervisores");
            
            // Desactivar asignaciones actuales
            $sql_deactivate = "UPDATE zona_supervisor SET activa = 0 WHERE id_zona = :id_zona";
            Database::execute($sql_deactivate, [':id_zona' => $id_zona]);

            // Insertar nuevas asignaciones
            foreach ($input['supervisores'] as $supervisor_id) {
                // Verificar si ya existe la asignación
                $sql_check_asig = "SELECT id_asignacion FROM zona_supervisor 
                                   WHERE id_zona = :id_zona AND id_supervisor = :id_supervisor";
                $asig_exists = Database::selectOne($sql_check_asig, [
                    ':id_zona' => $id_zona,
                    ':id_supervisor' => intval($supervisor_id)
                ]);

                if ($asig_exists) {
                    // Reactivar
                    $sql_reactivate = "UPDATE zona_supervisor SET activa = 1, fecha_modificacion = NOW() 
                                       WHERE id_asignacion = :id_asignacion";
                    Database::execute($sql_reactivate, [':id_asignacion' => $asig_exists['id_asignacion']]);
                } else {
                    // Crear nueva
                    $sql_supervisor = "INSERT INTO zona_supervisor (
                                            id_zona,
                                            id_supervisor,
                                            usuario_asigno,
                                            fecha_asignacion,
                                            activa
                                       ) VALUES (
                                            :id_zona,
                                            :id_supervisor,
                                            :usuario_asigno,
                                            NOW(),
                                            1
                                       )";
                    
                    Database::insert($sql_supervisor, [
                        ':id_zona' => $id_zona,
                        ':id_supervisor' => intval($supervisor_id),
                        ':usuario_asigno' => $usuario_id
                    ]);
                }
            }
        }

        // Actualizar tiendas
        if (isset($input['tiendas']) && is_array($input['tiendas'])) {
            error_log("🏪 Actualizando tiendas");
            
            // Desasignar tiendas actuales
            $sql_unassign = "UPDATE tiendas SET id_zona = NULL WHERE id_zona = :id_zona";
            Database::execute($sql_unassign, [':id_zona' => $id_zona]);

            // Asignar nuevas tiendas
            foreach ($input['tiendas'] as $tienda_id) {
                $sql_tienda = "UPDATE tiendas SET id_zona = :id_zona WHERE id_tienda = :id_tienda";
                Database::execute($sql_tienda, [
                    ':id_zona' => $id_zona,
                    ':id_tienda' => intval($tienda_id)
                ]);
            }
        }

        // Obtener zona actualizada
        error_log("📥 Devolviendo zona actualizada");
        getZonaById($id_zona);

        // Log de auditoría
        error_log("✅ Zona actualizada - ID: {$id_zona} - Usuario: " . ($_SESSION['username'] ?? 'desconocido') . " - IP: " . $_SERVER['REMOTE_ADDR']);

    } catch (Exception $e) {
        error_log("❌ Error en updateZona: " . $e->getMessage());
        error_log("Stack: " . $e->getTraceAsString());
        
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error al actualizar zona',
            'error' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
}

// ===== FUNCIÓN: ELIMINAR ZONA (SOFT DELETE) =====
function deleteZona($id) {
    try {
        error_log("🗑️ Eliminando zona: " . $id);
        $id = intval($id);

        // Verificar que la zona existe
        $sql_check = "SELECT id_zona FROM zonas WHERE id_zona = :id";
        $zona_exists = Database::selectOne($sql_check, [':id' => $id]);

        if (!$zona_exists) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Zona no encontrada'
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        // Soft delete - cambiar activa a 0
        $sql_delete = "UPDATE zonas SET activa = 0, fecha_modificacion = NOW() WHERE id_zona = :id";
        Database::execute($sql_delete, [':id' => $id]);

        // Desactivar asignaciones de supervisores
        $sql_deactivate_sup = "UPDATE zona_supervisor SET activa = 0 WHERE id_zona = :id";
        Database::execute($sql_deactivate_sup, [':id' => $id]);

        // Desasignar tiendas
        $sql_unassign_tiendas = "UPDATE tiendas SET id_zona = NULL WHERE id_zona = :id";
        Database::execute($sql_unassign_tiendas, [':id' => $id]);

        // Desasignar de promotores (si existe esa tabla)
        try {
            $sql_unassign_promotores = "UPDATE promotores SET id_zona_principal = NULL WHERE id_zona_principal = :id";
            Database::execute($sql_unassign_promotores, [':id' => $id]);
        } catch (Exception $e) {
            error_log("⚠️ Tabla promotores no existe o no tiene id_zona_principal: " . $e->getMessage());
        }

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Zona eliminada correctamente'
        ], JSON_UNESCAPED_UNICODE);

        // Log de auditoría
        error_log("✅ Zona eliminada - ID: {$id} - Usuario: " . ($_SESSION['username'] ?? 'desconocido') . " - IP: " . $_SERVER['REMOTE_ADDR']);

    } catch (Exception $e) {
        error_log("❌ Error en deleteZona: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error al eliminar zona',
            'error' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
}

// ===== FUNCIÓN: OBTENER SUPERVISORES DISPONIBLES (SOLO ROL SUPERVISOR) =====
function getSupervisores() {
    try {
        error_log("👥 Obteniendo supervisores");
        
        $sql = "SELECT 
                    id,
                    nombre,
                    apellido,
                    email,
                    username,
                    rol
                FROM usuarios
                WHERE rol = 'supervisor' AND activo = 1
                ORDER BY nombre, apellido";

        $supervisores = Database::select($sql);
        error_log("✅ Supervisores encontrados: " . count($supervisores));

        // Formatear nombres completos
        foreach ($supervisores as &$supervisor) {
            $supervisor['nombre_completo'] = trim($supervisor['nombre'] . ' ' . $supervisor['apellido']);
        }

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'data' => $supervisores
        ], JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        error_log("❌ Error en getSupervisores: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error al obtener supervisores',
            'error' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
}

// ===== FUNCIÓN: OBTENER TIENDAS DISPONIBLES =====
function getTiendas() {
    try {
        error_log("🏪 Obteniendo tiendas");
        
        $sql = "SELECT 
                    id_tienda,
                    nombre_tienda,
                    cadena,
                    num_tienda,
                    ciudad,
                    estado,
                    region,
                    id_zona
                FROM tiendas
                WHERE estado_reg = 1
                ORDER BY nombre_tienda";

        $tiendas = Database::select($sql);
        error_log("✅ Tiendas encontradas: " . count($tiendas));

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'data' => $tiendas
        ], JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        error_log("❌ Error en getTiendas: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error al obtener tiendas',
            'error' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
}
// ===== FUNCIÓN: OBTENER REGIONES =====
function getRegiones() {
    try {
        error_log("🗺️ Obteniendo regiones");
        
        // 🆕 CONSULTAR DIRECTAMENTE DESDE TIENDAS
        // Obtener las regiones únicas que tienen tiendas activas
        $sql_regiones_tiendas = "SELECT DISTINCT region 
                                  FROM tiendas 
                                  WHERE estado_reg = 1 
                                  AND region IS NOT NULL
                                  ORDER BY region";
        
        $regiones_tiendas = Database::select($sql_regiones_tiendas);
        error_log("📊 Regiones encontradas en tiendas: " . count($regiones_tiendas));
        
        if (count($regiones_tiendas) === 0) {
            error_log("⚠️ No hay tiendas activas con regiones");
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'data' => []
            ], JSON_UNESCAPED_UNICODE);
            return;
        }
        
        // Construir el array de regiones
        $regiones = [];
        foreach ($regiones_tiendas as $rt) {
            $num_region = intval($rt['region']);
            
            // Buscar si existe en la tabla regiones
            $sql_check = "SELECT id_region, numero_region, nombre_region, descripcion, activa 
                          FROM regiones 
                          WHERE numero_region = :numero_region 
                          AND activa = 1
                          LIMIT 1";
            
            $region_data = Database::selectOne($sql_check, [':numero_region' => $num_region]);
            
            if ($region_data) {
                // Si existe en la tabla regiones, usar esos datos
                $regiones[] = $region_data;
            } else {
                // Si no existe, crear datos básicos
                $regiones[] = [
                    'id_region' => $num_region,
                    'numero_region' => $num_region,
                    'nombre_region' => "Región $num_region",
                    'descripcion' => null,
                    'activa' => 1
                ];
            }
        }
        
        error_log("✅ Regiones procesadas: " . count($regiones));

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'data' => $regiones
        ], JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        error_log("❌ Error en getRegiones: " . $e->getMessage());
        error_log("Stack: " . $e->getTraceAsString());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error al obtener regiones',
            'error' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
}
// ===== FUNCIÓN: OBTENER PROMOTORES POR TIENDA =====
function getPromotoresPorTiendas($tiendas_ids) {
    try {
        error_log("========================================");
        error_log("👥 INICIO getPromotoresPorTiendas");
        error_log("IDs recibidos: " . print_r($tiendas_ids, true));
        
        if (empty($tiendas_ids)) {
            error_log("⚠️ Array vacío, devolviendo array vacío");
            http_response_code(200);
            echo json_encode([
                'success' => true,
                'data' => []
            ], JSON_UNESCAPED_UNICODE);
            return;
        }
        
        // Convertir todos a enteros
        $tiendas_ids = array_map('intval', $tiendas_ids);
        error_log("IDs convertidos a int: " . print_r($tiendas_ids, true));
        
        $promotoresPorTienda = [];
        
        foreach ($tiendas_ids as $tienda_id) {
            error_log("🔍 Buscando promotores para tienda ID: $tienda_id");
            
            // 🆕 CONSULTA CORREGIDA: Usar solo "apellido" (no apellido_paterno/materno)
            $sql = "SELECT DISTINCT
                        p.id_promotor,
                        p.nombre,
                        p.apellido,
                        p.estatus,
                        pta.id_tienda,
                        pta.fecha_inicio,
                        pta.fecha_fin,
                        pta.activo
                    FROM promotor_tienda_asignaciones pta
                    INNER JOIN promotores p ON pta.id_promotor = p.id_promotor
                    WHERE pta.id_tienda = :tienda_id
                    AND pta.activo = 1
                    AND pta.fecha_inicio <= CURDATE()
                    AND (pta.fecha_fin IS NULL OR pta.fecha_fin >= CURDATE())
                    AND p.estado = 1
                    ORDER BY p.nombre, p.apellido";
            
            $params = [':tienda_id' => $tienda_id];
            
            error_log("SQL: $sql");
            error_log("Params: " . print_r($params, true));
            
            try {
                $promotores = Database::select($sql, $params);
                error_log("✅ Promotores encontrados: " . count($promotores));
                error_log("📊 Datos de promotores: " . print_r($promotores, true));
                
                if (count($promotores) > 0) {
                    $promotoresPorTienda[$tienda_id] = [];
                    
                    foreach ($promotores as $promotor) {
                        // 🆕 Concatenar solo nombre + apellido (un solo campo)
                        $nombre_completo = trim(
                            ($promotor['nombre'] ?? '') . ' ' . 
                            ($promotor['apellido'] ?? '')
                        );
                        
                        $promotoresPorTienda[$tienda_id][] = [
                            'id' => $promotor['id_promotor'],
                            'nombre_completo' => $nombre_completo,
                            'estatus' => $promotor['estatus'] ?? 'ACTIVO'
                        ];
                        
                        error_log("👤 Promotor agregado: " . $nombre_completo);
                    }
                    
                    error_log("✅ Total promotores agregados para tienda $tienda_id: " . count($promotoresPorTienda[$tienda_id]));
                }
            } catch (Exception $e) {
                error_log("❌ Error al buscar promotores para tienda $tienda_id: " . $e->getMessage());
                error_log("Stack trace: " . $e->getTraceAsString());
            }
        }
        
        error_log("✅ Total tiendas con promotores: " . count($promotoresPorTienda));
        error_log("📊 Resultado final: " . print_r($promotoresPorTienda, true));
        error_log("========================================");
        
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'data' => $promotoresPorTienda
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        error_log("❌❌❌ ERROR EN getPromotoresPorTiendas ❌❌❌");
        error_log("Error: " . $e->getMessage());
        error_log("File: " . $e->getFile());
        error_log("Line: " . $e->getLine());
        error_log("Trace: " . $e->getTraceAsString());
        error_log("========================================");
        
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error al obtener promotores',
            'error' => $e->getMessage(),
            'debug' => [
                'file' => basename($e->getFile()),
                'line' => $e->getLine()
            ]
        ], JSON_UNESCAPED_UNICODE);
    }
}
?>