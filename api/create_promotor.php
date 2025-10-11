<?php
/**
 * API para Gestión de Incidencias de Promotores
 * Maneja CRUD completo de incidencias
 * Estructura basada en create_promotor.php
 */

// Evitar cualquier output antes de JSON
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);

session_start();

// 🔒 DEFINIR CONSTANTE ANTES DE INCLUIR DB_CONNECT
define('APP_ACCESS', true);

try {
    // Headers de seguridad y CORS
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
    
    // Limpiar cualquier output previo
    ob_clean();

    // Incluir la API de base de datos
    require_once __DIR__ . '/../config/db_connect.php';
    
    // 🆕 VERIFICAR QUE LA CLASE DATABASE ESTÉ DISPONIBLE
    $use_database_class = class_exists('Database');
    if (!$use_database_class && (!isset($pdo) || !$pdo instanceof PDO)) {
        throw new Exception('Conexión a base de datos no disponible');
    }

    // Verificar que sea POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'message' => 'Método no permitido'
        ]);
        exit;
    }

    // ===== VERIFICAR SESIÓN Y ROL =====
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['rol'])) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'No hay sesión activa'
        ]);
        exit;
    }

    // ===== FUNCIONES HELPER PARA BASE DE DATOS (COMPATIBILIDAD) =====
    function executeQuery($sql, $params = []) {
        global $pdo, $use_database_class;
        
        if ($use_database_class && method_exists('Database', 'selectOne')) {
            return Database::selectOne($sql, $params);
        } else if ($pdo) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            throw new Exception('No hay método de base de datos disponible');
        }
    }

    function executeSelect($sql, $params = []) {
        global $pdo, $use_database_class;
        
        if ($use_database_class && method_exists('Database', 'select')) {
            return Database::select($sql, $params);
        } else if ($pdo) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            throw new Exception('No hay método de base de datos disponible');
        }
    }

    function executeInsert($sql, $params = []) {
        global $pdo, $use_database_class;
        
        if ($use_database_class && method_exists('Database', 'insert')) {
            return Database::insert($sql, $params);
        } else if ($pdo) {
            $stmt = $pdo->prepare($sql);
            $result = $stmt->execute($params);
            return $result ? $pdo->lastInsertId() : false;
        } else {
            throw new Exception('No hay método de base de datos disponible');
        }
    }

    function executeUpdate($sql, $params = []) {
        global $pdo, $use_database_class;
        
        if ($use_database_class && method_exists('Database', 'execute')) {
            return Database::execute($sql, $params);
        } else if ($pdo) {
            $stmt = $pdo->prepare($sql);
            $result = $stmt->execute($params);
            return $result ? $stmt->rowCount() : 0;
        } else {
            throw new Exception('No hay método de base de datos disponible');
        }
    }

    function sanitizeInput($input) {
        global $use_database_class;
        
        if ($use_database_class && method_exists('Database', 'sanitize')) {
            return Database::sanitize($input);
        } else {
            return trim(htmlspecialchars($input, ENT_QUOTES, 'UTF-8'));
        }
    }

    // ===== OBTENER DATOS =====
    $input = null;
    
    // Detectar si es JSON o form-data
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

    // ===== DETERMINAR ACCIÓN =====
    $accion = $input['accion'] ?? 'listar';

    // ===== PROCESAR SEGÚN ACCIÓN =====
    switch ($accion) {
        case 'listar':
            listarIncidencias($input);
            break;
            
        case 'crear':
            crearIncidencia($input);
            break;
            
        case 'actualizar':
            actualizarIncidencia($input);
            break;
            
        case 'eliminar':
            eliminarIncidencia($input);
            break;
            
        case 'obtener':
            obtenerIncidencia($input);
            break;
            
        case 'promotores':
            obtenerPromotores();
            break;
            
        default:
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => "Acción no válida: {$accion}"
            ]);
            exit;
    }

} catch (Exception $e) {
    error_log("❌ Error en incidencias.php: " . $e->getMessage() . " - Usuario: " . ($_SESSION['username'] ?? 'desconocido') . " - IP: " . $_SERVER['REMOTE_ADDR']);
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor: ' . $e->getMessage()
    ]);
} finally {
    if (ob_get_length()) {
        ob_end_flush();
    }
}

// ===== FUNCIONES DE LA API =====

/**
 * Listar incidencias con filtros
 */
function listarIncidencias($input) {
    global $use_database_class;
    
    try {
        // Construir query base
        $sql = "SELECT 
                    i.id_incidencia,
                    i.fecha_incidencia,
                    i.id_promotor,
                    CONCAT(p.nombre, ' ', p.apellido) as promotor_nombre,
                    p.rfc as promotor_rfc,
                    p.telefono as promotor_telefono,
                    i.id_tienda,
                    i.tienda_nombre,
                    i.tipo_incidencia,
                    i.descripcion,
                    i.estatus,
                    i.prioridad,
                    i.notas,
                    i.usuario_registro,
                    i.fecha_registro,
                    i.fecha_modificacion
                FROM incidencias i
                INNER JOIN promotores p ON i.id_promotor = p.id_promotor
                WHERE 1=1";
        
        $params = [];
        
        // Aplicar filtros
        if (!empty($input['fecha_inicio'])) {
            $sql .= " AND i.fecha_incidencia >= " . ($use_database_class ? ":fecha_inicio" : "?");
            $params[$use_database_class ? ':fecha_inicio' : 0] = $input['fecha_inicio'];
        }
        
        if (!empty($input['fecha_fin'])) {
            $sql .= " AND i.fecha_incidencia <= " . ($use_database_class ? ":fecha_fin" : "?");
            $params[$use_database_class ? ':fecha_fin' : 1] = $input['fecha_fin'];
        }
        
        if (!empty($input['id_promotor'])) {
            $sql .= " AND i.id_promotor = " . ($use_database_class ? ":id_promotor" : "?");
            $params[$use_database_class ? ':id_promotor' : 2] = (int)$input['id_promotor'];
        }
        
        if (!empty($input['tipo_incidencia'])) {
            $sql .= " AND i.tipo_incidencia = " . ($use_database_class ? ":tipo_incidencia" : "?");
            $params[$use_database_class ? ':tipo_incidencia' : 3] = $input['tipo_incidencia'];
        }
        
        if (!empty($input['estatus'])) {
            $sql .= " AND i.estatus = " . ($use_database_class ? ":estatus" : "?");
            $params[$use_database_class ? ':estatus' : 4] = $input['estatus'];
        }
        
        if (!empty($input['prioridad'])) {
            $sql .= " AND i.prioridad = " . ($use_database_class ? ":prioridad" : "?");
            $params[$use_database_class ? ':prioridad' : 5] = $input['prioridad'];
        }
        
        // Ordenar por fecha más reciente primero
        $sql .= " ORDER BY i.fecha_incidencia DESC, i.fecha_registro DESC";
        
        // Si no usa Database class, reordenar params como array indexado
        if (!$use_database_class && !empty($params)) {
            $params = array_values($params);
        }
        
        $incidencias = executeSelect($sql, $params);
        
        // Obtener estadísticas
        $estadisticas = obtenerEstadisticas($input);
        
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'data' => [
                'incidencias' => $incidencias,
                'estadisticas' => $estadisticas,
                'total' => count($incidencias)
            ],
            'message' => 'Incidencias obtenidas exitosamente'
        ]);
        
    } catch (Exception $e) {
        throw new Exception('Error al obtener incidencias: ' . $e->getMessage());
    }
}

/**
 * Obtener estadísticas de incidencias
 */
function obtenerEstadisticas($filtros = []) {
    global $use_database_class;
    
    try {
        $sql = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN estatus = 'pendiente' THEN 1 ELSE 0 END) as pendientes,
                    SUM(CASE WHEN estatus = 'revision' THEN 1 ELSE 0 END) as revision,
                    SUM(CASE WHEN estatus = 'resuelta' THEN 1 ELSE 0 END) as resueltas,
                    SUM(CASE WHEN prioridad = 'alta' THEN 1 ELSE 0 END) as prioridad_alta,
                    SUM(CASE WHEN prioridad = 'media' THEN 1 ELSE 0 END) as prioridad_media,
                    SUM(CASE WHEN prioridad = 'baja' THEN 1 ELSE 0 END) as prioridad_baja
                FROM incidencias i
                WHERE 1=1";
        
        $params = [];
        
        if (!empty($filtros['fecha_inicio'])) {
            $sql .= " AND i.fecha_incidencia >= " . ($use_database_class ? ":fecha_inicio" : "?");
            $params[$use_database_class ? ':fecha_inicio' : 0] = $filtros['fecha_inicio'];
        }
        
        if (!empty($filtros['fecha_fin'])) {
            $sql .= " AND i.fecha_incidencia <= " . ($use_database_class ? ":fecha_fin" : "?");
            $params[$use_database_class ? ':fecha_fin' : 1] = $filtros['fecha_fin'];
        }
        
        // Si no usa Database class, reordenar params como array indexado
        if (!$use_database_class && !empty($params)) {
            $params = array_values($params);
        }
        
        $stats = executeQuery($sql, $params);
        
        return $stats ?: [
            'total' => 0,
            'pendientes' => 0,
            'revision' => 0,
            'resueltas' => 0,
            'prioridad_alta' => 0,
            'prioridad_media' => 0,
            'prioridad_baja' => 0
        ];
        
    } catch (Exception $e) {
        return [
            'total' => 0,
            'pendientes' => 0,
            'revision' => 0,
            'resueltas' => 0,
            'prioridad_alta' => 0,
            'prioridad_media' => 0,
            'prioridad_baja' => 0
        ];
    }
}

/**
 * Crear nueva incidencia
 */
function crearIncidencia($input) {
    global $use_database_class, $pdo;
    
    try {
        // Validar campos requeridos
        $required_fields = ['fecha_incidencia', 'id_promotor', 'tipo_incidencia', 'descripcion', 'estatus', 'prioridad'];
        $errors = [];
        
        foreach ($required_fields as $field) {
            if (!isset($input[$field]) || trim($input[$field]) === '') {
                $errors[] = "El campo '{$field}' es requerido";
            }
        }
        
        if (!empty($errors)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Campos requeridos faltantes',
                'errors' => $errors
            ]);
            exit;
        }
        
        // Sanitizar datos
        $fecha_incidencia = sanitizeInput($input['fecha_incidencia']);
        $id_promotor = (int)$input['id_promotor'];
        $id_tienda = isset($input['id_tienda']) ? (int)$input['id_tienda'] : null;
        $tienda_nombre = isset($input['tienda_nombre']) ? sanitizeInput($input['tienda_nombre']) : null;
        $tipo_incidencia = sanitizeInput($input['tipo_incidencia']);
        $descripcion = sanitizeInput($input['descripcion']);
        $estatus = sanitizeInput($input['estatus']);
        $prioridad = sanitizeInput($input['prioridad']);
        $notas = isset($input['notas']) ? sanitizeInput($input['notas']) : null;
        $usuario_registro = $_SESSION['username'] ?? 'sistema';
        
        // Validaciones específicas
        if (!DateTime::createFromFormat('Y-m-d', $fecha_incidencia)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Fecha de incidencia inválida. Formato requerido: YYYY-MM-DD'
            ]);
            exit;
        }
        
        if (!in_array($tipo_incidencia, ['falta', 'retardo', 'abandono', 'salud', 'imprevisto', 'otro'])) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Tipo de incidencia inválido'
            ]);
            exit;
        }
        
        if (!in_array($estatus, ['pendiente', 'revision', 'resuelta'])) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Estatus inválido'
            ]);
            exit;
        }
        
        if (!in_array($prioridad, ['baja', 'media', 'alta'])) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Prioridad inválida'
            ]);
            exit;
        }
        
        // Verificar que el promotor existe
        $sql_check = "SELECT id_promotor FROM promotores WHERE id_promotor = " . ($use_database_class ? ":id_promotor" : "?");
        $params_check = $use_database_class ? [':id_promotor' => $id_promotor] : [$id_promotor];
        $promotor = executeQuery($sql_check, $params_check);
        
        if (!$promotor) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'El promotor especificado no existe'
            ]);
            exit;
        }
        
        // Insertar incidencia
        if ($use_database_class) {
            $sql_insert = "INSERT INTO incidencias 
                            (fecha_incidencia, id_promotor, id_tienda, tienda_nombre, tipo_incidencia, 
                             descripcion, estatus, prioridad, notas, usuario_registro) 
                            VALUES (:fecha_incidencia, :id_promotor, :id_tienda, :tienda_nombre, :tipo_incidencia, 
                                    :descripcion, :estatus, :prioridad, :notas, :usuario_registro)";
            
            $params = [
                ':fecha_incidencia' => $fecha_incidencia,
                ':id_promotor' => $id_promotor,
                ':id_tienda' => $id_tienda,
                ':tienda_nombre' => $tienda_nombre,
                ':tipo_incidencia' => $tipo_incidencia,
                ':descripcion' => $descripcion,
                ':estatus' => $estatus,
                ':prioridad' => $prioridad,
                ':notas' => $notas,
                ':usuario_registro' => $usuario_registro
            ];
        } else {
            $sql_insert = "INSERT INTO incidencias 
                            (fecha_incidencia, id_promotor, id_tienda, tienda_nombre, tipo_incidencia, 
                             descripcion, estatus, prioridad, notas, usuario_registro) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $params = [
                $fecha_incidencia, $id_promotor, $id_tienda, $tienda_nombre, $tipo_incidencia,
                $descripcion, $estatus, $prioridad, $notas, $usuario_registro
            ];
        }
        
        $id_nueva_incidencia = executeInsert($sql_insert, $params);
        
        if (!$id_nueva_incidencia) {
            throw new Exception('No se pudo crear la incidencia');
        }
        
        // Actualizar contador de incidencias del promotor
        actualizarContadorIncidencias($id_promotor);
        
        // Obtener la incidencia creada
        $sql_get = "SELECT i.*, CONCAT(p.nombre, ' ', p.apellido) as promotor_nombre, p.rfc as promotor_rfc
                    FROM incidencias i
                    INNER JOIN promotores p ON i.id_promotor = p.id_promotor
                    WHERE i.id_incidencia = " . ($use_database_class ? ":id_incidencia" : "?");
        $params_get = $use_database_class ? [':id_incidencia' => $id_nueva_incidencia] : [$id_nueva_incidencia];
        $nueva_incidencia = executeQuery($sql_get, $params_get);
        
        // Log de auditoría
        error_log("✅ Incidencia creada - ID: {$id_nueva_incidencia} - Promotor: {$nueva_incidencia['promotor_nombre']} - Tipo: {$tipo_incidencia} - Usuario: {$usuario_registro}");
        
        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'Incidencia creada exitosamente',
            'data' => [
                'id_incidencia' => $id_nueva_incidencia,
                'incidencia' => $nueva_incidencia
            ]
        ]);
        
    } catch (Exception $e) {
        throw new Exception('Error al crear incidencia: ' . $e->getMessage());
    }
}

/**
 * Actualizar incidencia existente
 */
function actualizarIncidencia($input) {
    global $use_database_class;
    
    try {
        // Verificar ID
        if (empty($input['id_incidencia'])) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'ID de incidencia requerido'
            ]);
            exit;
        }
        
        $id_incidencia = (int)$input['id_incidencia'];
        
        // Verificar que la incidencia existe
        $sql_check = "SELECT id_incidencia, id_promotor FROM incidencias WHERE id_incidencia = " . ($use_database_class ? ":id_incidencia" : "?");
        $params_check = $use_database_class ? [':id_incidencia' => $id_incidencia] : [$id_incidencia];
        $incidencia = executeQuery($sql_check, $params_check);
        
        if (!$incidencia) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'La incidencia especificada no existe'
            ]);
            exit;
        }
        
        // Construir query de actualización dinámicamente
        $campos = [];
        $params = [];
        $param_index = 0;
        
        if (isset($input['fecha_incidencia'])) {
            $campos[] = "fecha_incidencia = " . ($use_database_class ? ":fecha_incidencia" : "?");
            $params[$use_database_class ? ':fecha_incidencia' : $param_index++] = sanitizeInput($input['fecha_incidencia']);
        }
        
        if (isset($input['id_promotor'])) {
            $campos[] = "id_promotor = " . ($use_database_class ? ":id_promotor" : "?");
            $params[$use_database_class ? ':id_promotor' : $param_index++] = (int)$input['id_promotor'];
        }
        
        if (isset($input['id_tienda'])) {
            $campos[] = "id_tienda = " . ($use_database_class ? ":id_tienda" : "?");
            $params[$use_database_class ? ':id_tienda' : $param_index++] = (int)$input['id_tienda'];
        }
        
        if (isset($input['tienda_nombre'])) {
            $campos[] = "tienda_nombre = " . ($use_database_class ? ":tienda_nombre" : "?");
            $params[$use_database_class ? ':tienda_nombre' : $param_index++] = sanitizeInput($input['tienda_nombre']);
        }
        
        if (isset($input['tipo_incidencia'])) {
            $campos[] = "tipo_incidencia = " . ($use_database_class ? ":tipo_incidencia" : "?");
            $params[$use_database_class ? ':tipo_incidencia' : $param_index++] = sanitizeInput($input['tipo_incidencia']);
        }
        
        if (isset($input['descripcion'])) {
            $campos[] = "descripcion = " . ($use_database_class ? ":descripcion" : "?");
            $params[$use_database_class ? ':descripcion' : $param_index++] = sanitizeInput($input['descripcion']);
        }
        
        if (isset($input['estatus'])) {
            $campos[] = "estatus = " . ($use_database_class ? ":estatus" : "?");
            $params[$use_database_class ? ':estatus' : $param_index++] = sanitizeInput($input['estatus']);
        }
        
        if (isset($input['prioridad'])) {
            $campos[] = "prioridad = " . ($use_database_class ? ":prioridad" : "?");
            $params[$use_database_class ? ':prioridad' : $param_index++] = sanitizeInput($input['prioridad']);
        }
        
        if (isset($input['notas'])) {
            $campos[] = "notas = " . ($use_database_class ? ":notas" : "?");
            $params[$use_database_class ? ':notas' : $param_index++] = sanitizeInput($input['notas']);
        }
        
        if (empty($campos)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'No hay campos para actualizar'
            ]);
            exit;
        }
        
        $sql_update = "UPDATE incidencias SET " . implode(", ", $campos) . " WHERE id_incidencia = " . ($use_database_class ? ":id_incidencia_where" : "?");
        $params[$use_database_class ? ':id_incidencia_where' : $param_index] = $id_incidencia;
        
        // Si no usa Database class, reordenar params como array indexado
        if (!$use_database_class) {
            $params = array_values($params);
        }
        
        $resultado = executeUpdate($sql_update, $params);
        
        // Actualizar contador de incidencias del promotor
        actualizarContadorIncidencias($incidencia['id_promotor']);
        
        // Log de auditoría
        error_log("✅ Incidencia actualizada - ID: {$id_incidencia} - Usuario: " . ($_SESSION['username'] ?? 'sistema'));
        
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Incidencia actualizada exitosamente',
            'data' => [
                'id_incidencia' => $id_incidencia
            ]
        ]);
        
    } catch (Exception $e) {
        throw new Exception('Error al actualizar incidencia: ' . $e->getMessage());
    }
}

/**
 * Eliminar incidencia
 */
function eliminarIncidencia($input) {
    global $use_database_class;
    
    try {
        // Verificar ID
        if (empty($input['id_incidencia'])) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'ID de incidencia requerido'
            ]);
            exit;
        }
        
        $id_incidencia = (int)$input['id_incidencia'];
        
        // Obtener id_promotor antes de eliminar
        $sql_get = "SELECT id_promotor FROM incidencias WHERE id_incidencia = " . ($use_database_class ? ":id_incidencia" : "?");
        $params_get = $use_database_class ? [':id_incidencia' => $id_incidencia] : [$id_incidencia];
        $incidencia = executeQuery($sql_get, $params_get);
        
        if (!$incidencia) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'La incidencia especificada no existe'
            ]);
            exit;
        }
        
        $id_promotor = $incidencia['id_promotor'];
        
        // Eliminar incidencia
        $sql_delete = "DELETE FROM incidencias WHERE id_incidencia = " . ($use_database_class ? ":id_incidencia" : "?");
        $params_delete = $use_database_class ? [':id_incidencia' => $id_incidencia] : [$id_incidencia];
        $resultado = executeUpdate($sql_delete, $params_delete);
        
        if ($resultado === 0) {
            throw new Exception('No se pudo eliminar la incidencia');
        }
        
        // Actualizar contador de incidencias del promotor
        actualizarContadorIncidencias($id_promotor);
        
        // Log de auditoría
        error_log("✅ Incidencia eliminada - ID: {$id_incidencia} - Usuario: " . ($_SESSION['username'] ?? 'sistema'));
        
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Incidencia eliminada exitosamente',
            'data' => null
        ]);
        
    } catch (Exception $e) {
        throw new Exception('Error al eliminar incidencia: ' . $e->getMessage());
    }
}

/**
 * Obtener una incidencia específica
 */
function obtenerIncidencia($input) {
    global $use_database_class;
    
    try {
        // Verificar ID
        if (empty($input['id_incidencia'])) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'ID de incidencia requerido'
            ]);
            exit;
        }
        
        $id_incidencia = (int)$input['id_incidencia'];
        
        $sql = "SELECT 
                    i.*,
                    CONCAT(p.nombre, ' ', p.apellido) as promotor_nombre,
                    p.rfc as promotor_rfc,
                    p.telefono as promotor_telefono
                FROM incidencias i
                INNER JOIN promotores p ON i.id_promotor = p.id_promotor
                WHERE i.id_incidencia = " . ($use_database_class ? ":id_incidencia" : "?");
        
        $params = $use_database_class ? [':id_incidencia' => $id_incidencia] : [$id_incidencia];
        $incidencia = executeQuery($sql, $params);
        
        if (!$incidencia) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Incidencia no encontrada'
            ]);
            exit;
        }
        
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'data' => [
                'incidencia' => $incidencia
            ],
            'message' => 'Incidencia obtenida exitosamente'
        ]);
        
    } catch (Exception $e) {
        throw new Exception('Error al obtener incidencia: ' . $e->getMessage());
    }
}

/**
 * Obtener lista de promotores activos
 */
function obtenerPromotores() {
    try {
        $sql = "SELECT 
                    id_promotor,
                    CONCAT(nombre, ' ', apellido) as nombre_completo,
                    rfc,
                    telefono,
                    correo,
                    region,
                    estatus
                FROM promotores
                WHERE estado = 1
                ORDER BY nombre, apellido";
        
        $promotores = executeSelect($sql, []);
        
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'data' => [
                'promotores' => $promotores
            ],
            'message' => 'Promotores obtenidos exitosamente'
        ]);
        
    } catch (Exception $e) {
        throw new Exception('Error al obtener promotores: ' . $e->getMessage());
    }
}

/**
 * Actualizar contador de incidencias en tabla promotores
 */
function actualizarContadorIncidencias($id_promotor) {
    global $use_database_class;
    
    try {
        $sql = "UPDATE promotores 
                SET incidencias = (
                    SELECT COUNT(*) 
                    FROM incidencias 
                    WHERE id_promotor = " . ($use_database_class ? ":id_promotor_subquery" : "?") . " 
                    AND estatus IN ('pendiente', 'revision')
                )
                WHERE id_promotor = " . ($use_database_class ? ":id_promotor" : "?");
        
        $params = $use_database_class 
            ? [':id_promotor_subquery' => $id_promotor, ':id_promotor' => $id_promotor] 
            : [$id_promotor, $id_promotor];
        
        executeUpdate($sql, $params);
        
    } catch (Exception $e) {
        error_log("Error actualizando contador de incidencias: " . $e->getMessage());
    }
}

?>