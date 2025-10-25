<?php
/**
 * API DE NOTIFICACIONES - VERSIÓN CORREGIDA
 * Compatible con la estructura REAL de la tabla notificaciones
 * Solo usa columnas que EXISTEN en la base de datos
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/notificaciones_errors.log');

// Headers CORS y Content-Type
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Iniciar buffer
ob_start();

define('APP_ACCESS', true);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/db_connect.php';

class NotificacionesAPI {
    
    /**
     * Obtener notificaciones del usuario logueado
     * SOLO USA COLUMNAS QUE EXISTEN EN LA TABLA
     */
    public function obtenerNotificaciones($id_usuario, $filtros = []) {
        try {
            error_log("📋 Obteniendo notificaciones para usuario ID: {$id_usuario}");
            
            // SQL CORREGIDO - Solo columnas que existen
            $sql = "SELECT 
                        n.id_notificacion,
                        n.id_incidencia,
                        n.id_destinatario,
                        n.id_remitente,
                        n.tipo_notificacion,
                        n.mensaje,
                        n.leida,
                        n.fecha_creacion,
                        n.fecha_lectura,
                        CONCAT(u_remitente.nombre, ' ', u_remitente.apellido) as remitente_nombre,
                        u_remitente.rol as remitente_rol,
                        i.tipo_incidencia,
                        i.prioridad,
                        i.estatus as incidencia_estatus,
                        CONCAT(p.nombre, ' ', p.apellido) as promotor_nombre,
                        TIMESTAMPDIFF(MINUTE, n.fecha_creacion, NOW()) as minutos_sin_leer
                    FROM notificaciones n
                    INNER JOIN usuarios u_remitente ON n.id_remitente = u_remitente.id
                    INNER JOIN incidencias i ON n.id_incidencia = i.id_incidencia
                    INNER JOIN promotores p ON i.id_promotor = p.id_promotor
                    WHERE n.id_destinatario = :id_usuario";
            
            $params = ['id_usuario' => $id_usuario];
            
            // Filtrar solo no leídas
            if (isset($filtros['solo_no_leidas']) && $filtros['solo_no_leidas']) {
                $sql .= " AND n.leida = 0";
            }
            
            // Filtrar por tipo
            if (!empty($filtros['tipo_notificacion'])) {
                $sql .= " AND n.tipo_notificacion = :tipo_notificacion";
                $params['tipo_notificacion'] = $filtros['tipo_notificacion'];
            }
            
            $sql .= " ORDER BY n.fecha_creacion DESC LIMIT 50";
            
            $notificaciones = Database::select($sql, $params);
            
            // Contar no leídas
            $sql_count = "SELECT COUNT(*) as total 
                          FROM notificaciones 
                          WHERE id_destinatario = :id_usuario AND leida = 0";
            $count = Database::selectOne($sql_count, ['id_usuario' => $id_usuario]);
            
            error_log("✅ Notificaciones obtenidas: " . count($notificaciones) . " total, " . ($count['total'] ?? 0) . " no leídas");
            
            return [
                'success' => true,
                'data' => [
                    'notificaciones' => $notificaciones,
                    'total_no_leidas' => $count['total'] ?? 0,
                    'total' => count($notificaciones)
                ],
                'message' => 'Notificaciones obtenidas correctamente'
            ];
            
        } catch (Exception $e) {
            error_log("❌ Error obteniendo notificaciones: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error al obtener notificaciones: ' . $e->getMessage(),
                'data' => [
                    'notificaciones' => [],
                    'total_no_leidas' => 0,
                    'total' => 0
                ]
            ];
        }
    }
    
    /**
     * Marcar notificación como leída (automático al hacer click)
     */
    public function marcarComoLeida($id_notificacion, $id_usuario) {
        try {
            error_log("✅ Marcando notificación #{$id_notificacion} como leída");
            
            // Verificar que la notificación pertenece al usuario
            $sql_verificar = "SELECT id_notificacion 
                              FROM notificaciones 
                              WHERE id_notificacion = :id_notificacion 
                              AND id_destinatario = :id_usuario";
            
            $existe = Database::selectOne($sql_verificar, [
                'id_notificacion' => $id_notificacion,
                'id_usuario' => $id_usuario
            ]);
            
            if (!$existe) {
                throw new Exception("Notificación no encontrada o no pertenece al usuario");
            }
            
            // Marcar como leída - SOLO COLUMNAS QUE EXISTEN
            $sql = "UPDATE notificaciones 
                    SET leida = 1, 
                        fecha_lectura = NOW()
                    WHERE id_notificacion = :id_notificacion";
            
            Database::execute($sql, ['id_notificacion' => $id_notificacion]);
            
            error_log("✅ Notificación #{$id_notificacion} marcada como leída por usuario #{$id_usuario}");
            
            return [
                'success' => true,
                'message' => 'Notificación marcada como leída'
            ];
            
        } catch (Exception $e) {
            error_log("❌ Error marcando como leída: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Marcar TODAS las notificaciones como leídas
     */
    public function marcarTodasLeidas($id_usuario) {
        try {
            error_log("✅ Marcando TODAS las notificaciones como leídas para usuario #{$id_usuario}");
            
            // SOLO COLUMNAS QUE EXISTEN
            $sql = "UPDATE notificaciones 
                    SET leida = 1, 
                        fecha_lectura = NOW()
                    WHERE id_destinatario = :id_usuario 
                    AND leida = 0";
            
            Database::execute($sql, ['id_usuario' => $id_usuario]);
            
            error_log("✅ Todas las notificaciones marcadas como leídas para usuario #{$id_usuario}");
            
            return [
                'success' => true,
                'message' => 'Todas las notificaciones marcadas como leídas'
            ];
            
        } catch (Exception $e) {
            error_log("❌ Error marcando todas como leídas: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Obtener estadísticas de notificaciones
     */
    public function obtenerEstadisticas($id_usuario) {
        try {
            $sql = "SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN leida = 0 THEN 1 ELSE 0 END) as no_leidas,
                        SUM(CASE WHEN leida = 1 THEN 1 ELSE 0 END) as leidas,
                        SUM(CASE WHEN leida = 0 AND TIMESTAMPDIFF(MINUTE, fecha_creacion, NOW()) >= 15 THEN 1 ELSE 0 END) as criticas
                    FROM notificaciones
                    WHERE id_destinatario = :id_usuario";
            
            $stats = Database::selectOne($sql, ['id_usuario' => $id_usuario]);
            
            return [
                'success' => true,
                'data' => $stats
            ];
            
        } catch (Exception $e) {
            error_log("❌ Error obteniendo estadísticas: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null
            ];
        }
    }
}

// ===== MANEJO DE PETICIONES =====
try {
    // Conectar a base de datos
    Database::connect();
    
    // Crear instancia del API
    $api = new NotificacionesAPI();
    
    // Obtener datos de la petición
    $metodo = $_SERVER['REQUEST_METHOD'];
    $input = file_get_contents('php://input');
    $datos = json_decode($input, true);
    
    // Determinar acción
    $accion = $datos['accion'] ?? $_GET['accion'] ?? $_POST['accion'] ?? null;
    
    if (!$accion) {
        throw new Exception("Acción no especificada");
    }
    
    error_log("📥 Acción solicitada: {$accion}");
    
    // IMPORTANTE: Obtener usuario de la sesión
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Buscar ID en todas las claves posibles
    $id_usuario_actual = null;
    $posibles_claves = ['user_id', 'id', 'usuario_id', 'id_usuario', 'userId'];
    
    foreach ($posibles_claves as $clave) {
        if (isset($_SESSION[$clave]) && !empty($_SESSION[$clave])) {
            $id_usuario_actual = $_SESSION[$clave];
            error_log("✅ Usuario encontrado en sesión: {$clave} = {$id_usuario_actual}");
            break;
        }
    }
    
    $respuesta = null;
    
    // Procesar según la acción
    switch ($accion) {
        case 'listar':
            if (!$id_usuario_actual) {
                throw new Exception("Usuario no autenticado");
            }
            
            $filtros = [
                'solo_no_leidas' => $datos['solo_no_leidas'] ?? false,
                'tipo_notificacion' => $datos['tipo_notificacion'] ?? null
            ];
            
            $respuesta = $api->obtenerNotificaciones($id_usuario_actual, $filtros);
            break;
            
        case 'marcar_leida':
            if (!$id_usuario_actual) {
                throw new Exception("Usuario no autenticado");
            }
            if (empty($datos['id_notificacion'])) {
                throw new Exception("ID de notificación requerido");
            }
            
            $respuesta = $api->marcarComoLeida($datos['id_notificacion'], $id_usuario_actual);
            break;
            
        case 'marcar_todas_leidas':
            if (!$id_usuario_actual) {
                throw new Exception("Usuario no autenticado");
            }
            
            $respuesta = $api->marcarTodasLeidas($id_usuario_actual);
            break;
            
        case 'estadisticas':
            if (!$id_usuario_actual) {
                throw new Exception("Usuario no autenticado");
            }
            
            $respuesta = $api->obtenerEstadisticas($id_usuario_actual);
            break;
            
        default:
            throw new Exception("Acción no válida: {$accion}");
    }
    
    // Limpiar buffer y enviar respuesta
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    ob_start();
    
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($respuesta, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    ob_end_flush();
    
} catch (Exception $e) {
    error_log("❌ ERROR en API Notificaciones: " . $e->getMessage());
    error_log("   Trace: " . $e->getTraceAsString());
    
    // Limpiar buffer
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    ob_start();
    
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'data' => null
    ], JSON_UNESCAPED_UNICODE);
    
    ob_end_flush();
}
?>