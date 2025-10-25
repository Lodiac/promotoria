<?php
/**
 * =====================================================
 * API COMPLETA DE INCIDENCIAS PARA SUPERVISORES
 * Versión PDO - Compatible con tu Database
 * 🔔 CON NOTIFICACIONES A ROOT
 * =====================================================
 */

session_start();

define('APP_ACCESS', true);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// Log inicial
error_log("========================================");
error_log("🚀 INCIDENCIAS SUPERVISOR - INICIO");
error_log("Método: " . $_SERVER['REQUEST_METHOD']);
error_log("Acción: " . ($_GET['accion'] ?? $_POST['accion'] ?? 'NO_DEFINIDA'));
error_log("========================================");

try {
    // ===== BUSCAR ID DE USUARIO EN TODAS LAS CLAVES POSIBLES =====
    $usuario_id = null;
    $posibles_claves_id = ['id', 'usuario_id', 'user_id', 'id_usuario', 'userId', 'id_supervisor'];
    
    foreach ($posibles_claves_id as $clave) {
        if (isset($_SESSION[$clave]) && !empty($_SESSION[$clave])) {
            $usuario_id = $_SESSION[$clave];
            error_log("✅ ID encontrado en clave: '$clave' = $usuario_id");
            break;
        }
    }
    
    if (!$usuario_id) {
        error_log("❌ FALLO: No se encontró ID de usuario en ninguna clave");
        error_log("Claves disponibles: " . implode(', ', array_keys($_SESSION)));
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'No se encontró ID de usuario en la sesión',
            'error' => 'no_user_id',
            'debug' => [
                'session_keys' => array_keys($_SESSION),
                'claves_buscadas' => $posibles_claves_id
            ]
        ]);
        exit;
    }
    
    // ===== BUSCAR ROL EN TODAS LAS CLAVES POSIBLES =====
    $rol = null;
    $posibles_claves_rol = ['rol', 'role', 'user_role', 'tipo_usuario', 'perfil'];
    
    foreach ($posibles_claves_rol as $clave) {
        if (isset($_SESSION[$clave]) && !empty($_SESSION[$clave])) {
            $rol = strtolower($_SESSION[$clave]);
            error_log("✅ ROL encontrado en clave: '$clave' = $rol");
            break;
        }
    }
    
    if (!$rol) {
        error_log("❌ FALLO: No se encontró ROL en ninguna clave");
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'No se encontró rol en la sesión',
            'error' => 'no_role'
        ]);
        exit;
    }
    
    // Validar que el rol sea permitido
    if (!in_array($rol, ['supervisor', 'root'])) {
        error_log("❌ FALLO: Rol '$rol' no permitido");
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Rol no autorizado para acceder a incidencias',
            'error' => 'unauthorized_role',
            'debug' => ['rol' => $rol]
        ]);
        exit;
    }
    
    error_log("✅ Autenticación exitosa - Usuario ID: $usuario_id, Rol: $rol");
    
    // ===== INCLUIR CONEXIÓN A BASE DE DATOS =====
    $db_path = __DIR__ . '/../config/db_connect.php';
    if (!file_exists($db_path)) {
        throw new Exception('Archivo de configuración de BD no encontrado');
    }
    require_once $db_path;
    
    error_log("✅ Base de datos incluida");
    
    
    // ===== ROUTER DE ACCIONES =====
    $method = $_SERVER['REQUEST_METHOD'];
    
    // 🔧 LEER ACCIÓN DEL JSON EN POST
    if ($method === 'POST') {
        $input = file_get_contents('php://input');
        $json_data = json_decode($input, true);
        $accion = $json_data['accion'] ?? $_POST['accion'] ?? $_GET['accion'] ?? '';
    } else {
        $accion = $_GET['accion'] ?? '';
    }
    
    error_log("📋 Procesando acción: '$accion' (Método: $method)");
    
    switch ($accion) {
        case 'promotores':
            obtenerPromotores($rol, $usuario_id);
            break;
            
        case 'tiendas':
            $id_promotor = $_GET['id_promotor'] ?? $_POST['id_promotor'] ?? null;
            if (!$id_promotor) {
                throw new Exception('ID de promotor no especificado');
            }
            obtenerTiendasPromotor($rol, $usuario_id, $id_promotor);
            break;
        
        case 'listar':
            listarIncidencias($rol, $usuario_id);
            break;
            
        case 'crear':
            if ($method !== 'POST') {
                throw new Exception('Método no permitido para crear incidencia');
            }
            crearIncidencia($rol, $usuario_id);
            break;
            
        case 'actualizar':
            if ($method !== 'POST') {
                throw new Exception('Método no permitido para actualizar incidencia');
            }
            actualizarIncidencia($rol, $usuario_id);
            break;
            
        case 'eliminar':
            if ($method !== 'POST' && $method !== 'DELETE') {
                throw new Exception('Método no permitido para eliminar incidencia');
            }
            eliminarIncidencia($rol, $usuario_id);
            break;
            
        case 'detalle':
            $id_incidencia = $_GET['id'] ?? $_POST['id'] ?? null;
            if (!$id_incidencia) {
                throw new Exception('ID de incidencia no especificado');
            }
            obtenerDetalleIncidencia($rol, $usuario_id, $id_incidencia);
            break;
            
        default:
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Acción no especificada o no válida',
                'error' => 'invalid_action',
                'accion_recibida' => $accion
            ]);
            break;
    }
    
} catch (Exception $e) {
    error_log("❌ ERROR CRÍTICO: " . $e->getMessage());
    error_log("Archivo: " . $e->getFile());
    error_log("Línea: " . $e->getLine());
    error_log("Stack: " . $e->getTraceAsString());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor',
        'error' => $e->getMessage(),
        'debug' => [
            'file' => basename($e->getFile()),
            'line' => $e->getLine()
        ]
    ]);
}

// =====================================================
// FUNCIÓN: OBTENER PROMOTORES
// =====================================================
function obtenerPromotores($rol, $usuario_id) {
    try {
        error_log("📋 Obteniendo promotores para rol: $rol");
        
        if ($rol === 'root') {
            // ROOT ve todos los promotores
            error_log("👑 Usuario ROOT - Acceso completo");
            
            $sql = "SELECT DISTINCT
                        p.id_promotor,
                        p.nombre,
                        p.apellido,
                        CONCAT(p.nombre, ' ', p.apellido) as nombre_completo,
                        p.estatus,
                        p.telefono,
                        p.correo,
                        p.region,
                        p.tipo_trabajo
                    FROM promotores p
                    WHERE p.estado = 1
                      AND p.estatus = 'ACTIVO'
                    ORDER BY p.nombre, p.apellido";
            
            $promotores = Database::select($sql);
            
        } else {
            // SUPERVISOR ve solo sus promotores asignados
            error_log("👮 Usuario SUPERVISOR - Filtrado por zonas");
            
            $sql = "SELECT DISTINCT
                        p.id_promotor,
                        p.nombre,
                        p.apellido,
                        CONCAT(p.nombre, ' ', p.apellido) as nombre_completo,
                        p.estatus,
                        p.telefono,
                        p.correo,
                        p.region,
                        p.tipo_trabajo
                    FROM promotores p
                    INNER JOIN promotor_tienda_asignaciones pta ON p.id_promotor = pta.id_promotor
                    INNER JOIN tiendas t ON pta.id_tienda = t.id_tienda
                    INNER JOIN zona_supervisor zs ON t.id_zona = zs.id_zona
                    WHERE p.estado = 1
                      AND p.estatus = 'ACTIVO'
                      AND pta.activo = 1
                      AND zs.id_supervisor = :usuario_id
                      AND zs.activa = 1
                    ORDER BY p.nombre, p.apellido";
            
            $promotores = Database::select($sql, [':usuario_id' => $usuario_id]);
        }
        
        error_log("✅ Promotores encontrados: " . count($promotores));
        
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'data' => $promotores,
            'total' => count($promotores)
        ]);
        
    } catch (Exception $e) {
        error_log("❌ Error en obtenerPromotores: " . $e->getMessage());
        throw $e;
    }
}

// =====================================================
// FUNCIÓN: OBTENER TIENDAS DE UN PROMOTOR
// =====================================================
function obtenerTiendasPromotor($rol, $usuario_id, $id_promotor) {
    try {
        error_log("🏪 Obteniendo tiendas del promotor: $id_promotor");
        
        if ($rol === 'root') {
            // ROOT ve todas las tiendas del promotor
            $sql = "SELECT DISTINCT
                        t.id_tienda,
                        t.nombre_tienda,
                        t.cadena,
                        t.num_tienda,
                        t.ciudad,
                        t.estado,
                        pta.fecha_inicio,
                        pta.fecha_fin,
                        pta.activo
                    FROM tiendas t
                    INNER JOIN promotor_tienda_asignaciones pta ON t.id_tienda = pta.id_tienda
                    WHERE pta.id_promotor = :id_promotor
                      AND t.estado_reg = 1
                      AND pta.activo = 1
                    ORDER BY t.nombre_tienda";
            
            $tiendas = Database::select($sql, [':id_promotor' => $id_promotor]);
            
        } else {
            // SUPERVISOR ve solo tiendas de su zona
            $sql = "SELECT DISTINCT
                        t.id_tienda,
                        t.nombre_tienda,
                        t.cadena,
                        t.num_tienda,
                        t.ciudad,
                        t.estado,
                        pta.fecha_inicio,
                        pta.fecha_fin,
                        pta.activo
                    FROM tiendas t
                    INNER JOIN promotor_tienda_asignaciones pta ON t.id_tienda = pta.id_tienda
                    INNER JOIN zona_supervisor zs ON t.id_zona = zs.id_zona
                    WHERE pta.id_promotor = :id_promotor
                      AND t.estado_reg = 1
                      AND pta.activo = 1
                      AND zs.id_supervisor = :usuario_id
                      AND zs.activa = 1
                    ORDER BY t.nombre_tienda";
            
            $tiendas = Database::select($sql, [
                ':id_promotor' => $id_promotor,
                ':usuario_id' => $usuario_id
            ]);
        }
        
        error_log("✅ Tiendas encontradas: " . count($tiendas));
        
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'data' => $tiendas,
            'total' => count($tiendas)
        ]);
        
    } catch (Exception $e) {
        error_log("❌ Error en obtenerTiendasPromotor: " . $e->getMessage());
        throw $e;
    }
}

// =====================================================
// FUNCIÓN: LISTAR INCIDENCIAS
// =====================================================
function listarIncidencias($rol, $usuario_id) {
    try {
        error_log("📋 Listando incidencias para rol: $rol");
        
        if ($rol === 'root') {
            // ROOT ve todas las incidencias
            $sql = "SELECT 
                        i.*,
                        CONCAT(p.nombre, ' ', p.apellido) as nombre_promotor,
                        p.telefono as telefono_promotor,
                        p.correo as correo_promotor,
                        t.nombre_tienda,
                        t.cadena,
                        t.ciudad,
                        t.estado,
                        CONCAT(u.nombre, ' ', u.apellido) as supervisor_reporta
                    FROM incidencias i
                    INNER JOIN promotores p ON i.id_promotor = p.id_promotor
                    LEFT JOIN tiendas t ON i.id_tienda = t.id_tienda
                    LEFT JOIN usuarios u ON i.id_supervisor_reporta = u.id
                    ORDER BY i.fecha_incidencia DESC, i.fecha_registro DESC";
            
            $incidencias = Database::select($sql);
            
        } else {
            // SUPERVISOR ve solo sus incidencias
            $sql = "SELECT 
                        i.*,
                        CONCAT(p.nombre, ' ', p.apellido) as nombre_promotor,
                        p.telefono as telefono_promotor,
                        p.correo as correo_promotor,
                        t.nombre_tienda,
                        t.cadena,
                        t.ciudad,
                        t.estado,
                        CONCAT(u.nombre, ' ', u.apellido) as supervisor_reporta
                    FROM incidencias i
                    INNER JOIN promotores p ON i.id_promotor = p.id_promotor
                    LEFT JOIN tiendas t ON i.id_tienda = t.id_tienda
                    LEFT JOIN usuarios u ON i.id_supervisor_reporta = u.id
                    INNER JOIN promotor_tienda_asignaciones pta ON p.id_promotor = pta.id_promotor
                    INNER JOIN tiendas t2 ON pta.id_tienda = t2.id_tienda
                    INNER JOIN zona_supervisor zs ON t2.id_zona = zs.id_zona
                    WHERE zs.id_supervisor = :usuario_id
                      AND zs.activa = 1
                    ORDER BY i.fecha_incidencia DESC, i.fecha_registro DESC";
            
            $incidencias = Database::select($sql, [':usuario_id' => $usuario_id]);
        }
        
        error_log("✅ Incidencias encontradas: " . count($incidencias));
        
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'data' => $incidencias,
            'total' => count($incidencias)
        ]);
        
    } catch (Exception $e) {
        error_log("❌ Error en listarIncidencias: " . $e->getMessage());
        throw $e;
    }
}

// =====================================================
// FUNCIÓN: CREAR INCIDENCIA
// =====================================================
function crearIncidencia($rol, $usuario_id) {
    try {
        error_log("➕ Creando nueva incidencia");
        
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) {
            $data = $_POST;
        }
        
        error_log("Datos recibidos: " . print_r($data, true));
        
        // Validar campos requeridos
        $required = ['id_promotor', 'tipo_incidencia', 'fecha_incidencia', 'descripcion', 'prioridad'];
        foreach ($required as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                throw new Exception("Campo requerido faltante: $field");
            }
        }
        
        // Validar que el promotor existe y el supervisor tiene acceso
        if ($rol === 'supervisor') {
            $sql_check = "SELECT COUNT(*) as tiene_acceso
                         FROM promotores p
                         INNER JOIN promotor_tienda_asignaciones pta ON p.id_promotor = pta.id_promotor
                         INNER JOIN tiendas t ON pta.id_tienda = t.id_tienda
                         INNER JOIN zona_supervisor zs ON t.id_zona = zs.id_zona
                         WHERE p.id_promotor = :id_promotor
                           AND zs.id_supervisor = :usuario_id
                           AND zs.activa = 1";
            
            $check = Database::selectOne($sql_check, [
                ':id_promotor' => $data['id_promotor'],
                ':usuario_id' => $usuario_id
            ]);
            
            if ($check['tiene_acceso'] == 0) {
                throw new Exception("No tienes permiso para crear incidencias de este promotor");
            }
        }
        
        // Calcular días totales si hay fecha_fin
        $dias_totales = null;
        if (!empty($data['fecha_fin'])) {
            $fecha_inicio = new DateTime($data['fecha_incidencia']);
            $fecha_fin = new DateTime($data['fecha_fin']);
            $diferencia = $fecha_inicio->diff($fecha_fin);
            $dias_totales = $diferencia->days + 1;
        }
        
        // Insertar incidencia
        $sql = "INSERT INTO incidencias (
                    fecha_incidencia,
                    fecha_fin,
                    dias_totales,
                    id_promotor,
                    id_tienda,
                    tienda_nombre,
                    tipo_incidencia,
                    descripcion,
                    estatus,
                    prioridad,
                    notas,
                    id_supervisor_reporta
                ) VALUES (
                    :fecha_incidencia,
                    :fecha_fin,
                    :dias_totales,
                    :id_promotor,
                    :id_tienda,
                    :tienda_nombre,
                    :tipo_incidencia,
                    :descripcion,
                    'pendiente',
                    :prioridad,
                    :notas,
                    :usuario_id
                )";
        
        $id_incidencia = Database::insert($sql, [
            ':fecha_incidencia' => $data['fecha_incidencia'],
            ':fecha_fin' => $data['fecha_fin'] ?? null,
            ':dias_totales' => $dias_totales,
            ':id_promotor' => $data['id_promotor'],
            ':id_tienda' => $data['id_tienda'] ?? null,
            ':tienda_nombre' => $data['tienda_nombre'] ?? null,
            ':tipo_incidencia' => $data['tipo_incidencia'],
            ':descripcion' => $data['descripcion'],
            ':prioridad' => $data['prioridad'],
            ':notas' => $data['notas'] ?? null,
            ':usuario_id' => $usuario_id
        ]);
        
        error_log("✅ Incidencia creada con ID: $id_incidencia");
        
        // 🔔 CREAR NOTIFICACIÓN PARA ROOT (NUEVO - ESTE ES EL ARREGLO)
        notificarRoot($id_incidencia, $usuario_id, $data);
        
        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'Incidencia creada correctamente',
            'id_incidencia' => $id_incidencia
        ]);
        
    } catch (Exception $e) {
        error_log("❌ Error en crearIncidencia: " . $e->getMessage());
        throw $e;
    }
}

// =====================================================
// 🔔 FUNCIÓN NUEVA: NOTIFICAR A ROOT
// =====================================================
function notificarRoot($id_incidencia, $id_supervisor, $datos_incidencia) {
    try {
        error_log("🔔 Iniciando notificación a ROOT para incidencia #{$id_incidencia}");
        
        // 1. Obtener todos los usuarios ROOT activos
        $sql_root = "SELECT id, username, CONCAT(nombre, ' ', apellido) as nombre_completo 
                     FROM usuarios 
                     WHERE rol = 'root' AND activo = 1";
        
        $usuarios_root = Database::select($sql_root);
        
        if (empty($usuarios_root)) {
            error_log("⚠️ No hay usuarios ROOT activos para notificar");
            return false;
        }
        
        error_log("✅ Se encontraron " . count($usuarios_root) . " usuarios ROOT");
        
        // 2. Obtener nombre del promotor
        $sql_promotor = "SELECT CONCAT(nombre, ' ', apellido) as nombre_completo 
                         FROM promotores 
                         WHERE id_promotor = :id";
        
        $promotor = Database::selectOne($sql_promotor, ['id' => $datos_incidencia['id_promotor']]);
        $nombre_promotor = $promotor['nombre_completo'] ?? 'Promotor';
        
        // 3. Construir mensaje de notificación
        $tipo = ucfirst($datos_incidencia['tipo_incidencia']);
        $prioridad = strtoupper($datos_incidencia['prioridad']);
        
        $mensaje = "🚨 Nueva incidencia: {$tipo}\n";
        $mensaje .= "Promotor: {$nombre_promotor}\n";
        $mensaje .= "Prioridad: {$prioridad}\n";
        $mensaje .= "Descripción: " . substr($datos_incidencia['descripcion'], 0, 100);
        
        // 4. Insertar notificación para cada usuario ROOT
        $sql_notif = "INSERT INTO notificaciones 
                      (id_incidencia, id_destinatario, id_remitente, 
                       tipo_notificacion, mensaje, leida, fecha_creacion) 
                      VALUES 
                      (:id_incidencia, :id_destinatario, :id_remitente, 
                       'nueva', :mensaje, 0, NOW())";
        
        $notificaciones_creadas = 0;
        
        foreach ($usuarios_root as $root) {
            try {
                $params = [
                    'id_incidencia' => $id_incidencia,
                    'id_destinatario' => $root['id'],
                    'id_remitente' => $id_supervisor,
                    'mensaje' => $mensaje
                ];
                
                Database::execute($sql_notif, $params);
                $notificaciones_creadas++;
                
                error_log("✅ Notificación creada para usuario ROOT: {$root['username']} (ID: {$root['id']})");
                
            } catch (Exception $e) {
                error_log("⚠️ Error al crear notificación para {$root['username']}: " . $e->getMessage());
            }
        }
        
        error_log("✅ Total de notificaciones creadas: {$notificaciones_creadas}");
        return $notificaciones_creadas > 0;
        
    } catch (Exception $e) {
        error_log("❌ ERROR en notificarRoot: " . $e->getMessage());
        error_log("   Trace: " . $e->getTraceAsString());
        return false;
    }
}

// =====================================================
// FUNCIÓN: ACTUALIZAR INCIDENCIA
// =====================================================
function actualizarIncidencia($rol, $usuario_id) {
    try {
        error_log("📝 Actualizando incidencia");
        
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) {
            $data = $_POST;
        }
        
        if (!isset($data['id_incidencia'])) {
            throw new Exception("ID de incidencia no especificado");
        }
        
        $id_incidencia = $data['id_incidencia'];
        
        // Verificar permisos
        if ($rol === 'supervisor') {
            $sql_check = "SELECT COUNT(*) as tiene_acceso
                         FROM incidencias i
                         INNER JOIN promotores p ON i.id_promotor = p.id_promotor
                         INNER JOIN promotor_tienda_asignaciones pta ON p.id_promotor = pta.id_promotor
                         INNER JOIN tiendas t ON pta.id_tienda = t.id_tienda
                         INNER JOIN zona_supervisor zs ON t.id_zona = zs.id_zona
                         WHERE i.id_incidencia = :id_incidencia
                           AND zs.id_supervisor = :usuario_id
                           AND zs.activa = 1";
            
            $check = Database::selectOne($sql_check, [
                ':id_incidencia' => $id_incidencia,
                ':usuario_id' => $usuario_id
            ]);
            
            if ($check['tiene_acceso'] == 0) {
                throw new Exception("No tienes permiso para actualizar esta incidencia");
            }
        }
        
        // Construir UPDATE dinámico
        $campos_actualizables = ['estatus', 'prioridad', 'notas', 'descripcion', 'fecha_fin', 'dias_totales'];
        $updates = [];
        $params = [':id_incidencia' => $id_incidencia];
        
        foreach ($campos_actualizables as $campo) {
            if (isset($data[$campo])) {
                $updates[] = "$campo = :$campo";
                $params[":$campo"] = $data[$campo];
            }
        }
        
        if (empty($updates)) {
            throw new Exception("No hay campos para actualizar");
        }
        
        $sql = "UPDATE incidencias SET " . implode(', ', $updates) . " WHERE id_incidencia = :id_incidencia";
        
        Database::execute($sql, $params);
        
        error_log("✅ Incidencia actualizada: $id_incidencia");
        
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Incidencia actualizada correctamente'
        ]);
        
    } catch (Exception $e) {
        error_log("❌ Error en actualizarIncidencia: " . $e->getMessage());
        throw $e;
    }
}

// =====================================================
// FUNCIÓN: ELIMINAR INCIDENCIA
// =====================================================
function eliminarIncidencia($rol, $usuario_id) {
    try {
        error_log("🗑️ Eliminando incidencia");
        
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) {
            $data = $_POST;
        }
        
        $id_incidencia = $data['id'] ?? $_GET['id'] ?? null;
        
        if (!$id_incidencia) {
            throw new Exception("ID de incidencia no especificado");
        }
        
        // Verificar permisos
        if ($rol === 'supervisor') {
            $sql_check = "SELECT COUNT(*) as tiene_acceso
                         FROM incidencias i
                         INNER JOIN promotores p ON i.id_promotor = p.id_promotor
                         INNER JOIN promotor_tienda_asignaciones pta ON p.id_promotor = pta.id_promotor
                         INNER JOIN tiendas t ON pta.id_tienda = t.id_tienda
                         INNER JOIN zona_supervisor zs ON t.id_zona = zs.id_zona
                         WHERE i.id_incidencia = :id_incidencia
                           AND zs.id_supervisor = :usuario_id
                           AND zs.activa = 1";
            
            $check = Database::selectOne($sql_check, [
                ':id_incidencia' => $id_incidencia,
                ':usuario_id' => $usuario_id
            ]);
            
            if ($check['tiene_acceso'] == 0) {
                throw new Exception("No tienes permiso para eliminar esta incidencia");
            }
        }
        
        $sql = "DELETE FROM incidencias WHERE id_incidencia = :id_incidencia";
        Database::execute($sql, [':id_incidencia' => $id_incidencia]);
        
        error_log("✅ Incidencia eliminada: $id_incidencia");
        
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Incidencia eliminada correctamente'
        ]);
        
    } catch (Exception $e) {
        error_log("❌ Error en eliminarIncidencia: " . $e->getMessage());
        throw $e;
    }
}

// =====================================================
// FUNCIÓN: OBTENER DETALLE DE INCIDENCIA
// =====================================================
function obtenerDetalleIncidencia($rol, $usuario_id, $id_incidencia) {
    try {
        error_log("🔍 Obteniendo detalle de incidencia: $id_incidencia");
        
        $params = [':id_incidencia' => $id_incidencia];
        
        if ($rol === 'root') {
            $sql = "SELECT 
                        i.*,
                        CONCAT(p.nombre, ' ', p.apellido) as nombre_promotor,
                        p.telefono as telefono_promotor,
                        p.correo as correo_promotor,
                        t.nombre_tienda,
                        t.cadena,
                        t.ciudad,
                        t.estado,
                        CONCAT(u.nombre, ' ', u.apellido) as supervisor_reporta
                    FROM incidencias i
                    INNER JOIN promotores p ON i.id_promotor = p.id_promotor
                    LEFT JOIN tiendas t ON i.id_tienda = t.id_tienda
                    LEFT JOIN usuarios u ON i.id_supervisor_reporta = u.id
                    WHERE i.id_incidencia = :id_incidencia";
            
        } else {
            $sql = "SELECT 
                        i.*,
                        CONCAT(p.nombre, ' ', p.apellido) as nombre_promotor,
                        p.telefono as telefono_promotor,
                        p.correo as correo_promotor,
                        t.nombre_tienda,
                        t.cadena,
                        t.ciudad,
                        t.estado,
                        CONCAT(u.nombre, ' ', u.apellido) as supervisor_reporta
                    FROM incidencias i
                    INNER JOIN promotores p ON i.id_promotor = p.id_promotor
                    LEFT JOIN tiendas t ON i.id_tienda = t.id_tienda
                    LEFT JOIN usuarios u ON i.id_supervisor_reporta = u.id
                    INNER JOIN promotor_tienda_asignaciones pta ON p.id_promotor = pta.id_promotor
                    INNER JOIN tiendas t2 ON pta.id_tienda = t2.id_tienda
                    INNER JOIN zona_supervisor zs ON t2.id_zona = zs.id_zona
                    WHERE i.id_incidencia = :id_incidencia
                      AND zs.id_supervisor = :usuario_id
                      AND zs.activa = 1";
            
            $params[':usuario_id'] = $usuario_id;
        }
        
        $incidencia = Database::selectOne($sql, $params);
        
        if (!$incidencia) {
            throw new Exception("Incidencia no encontrada o sin permisos para verla");
        }
        
        error_log("✅ Detalle de incidencia obtenido");
        
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'data' => $incidencia
        ]);
        
    } catch (Exception $e) {
        error_log("❌ Error en obtenerDetalleIncidencia: " . $e->getMessage());
        throw $e;
    }
}

?>