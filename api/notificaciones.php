<?php
/**
 * API DE NOTIFICACIONES - VERSIÓN DEFINITIVA
 * Sistema completo de notificaciones con recordatorios automáticos estilo WhatsApp
 * Compatible con tu base de datos real
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
     * Crear notificación cuando SUPERVISOR reporta incidencia
     * Esta función se llama desde el API de incidencias
     */
    public function crearNotificacionIncidencia($id_incidencia, $id_supervisor_reporta, $tipo_notificacion, $datos_incidencia) {
        try {
            error_log("🔔 Creando notificación de incidencia #{$id_incidencia}");
            
            // Obtener todos los usuarios ROOT activos
            $sql_root = "SELECT id, CONCAT(nombre, ' ', apellido) as nombre_completo 
                         FROM usuarios 
                         WHERE rol = 'root' AND activo = 1";
            $usuarios_root = Database::select($sql_root);
            
            if (empty($usuarios_root)) {
                error_log("⚠️ No hay usuarios ROOT activos");
                return false;
            }
            
            // Construir mensaje según el tipo
            $mensajes = [
                'nueva' => "🚨 Nueva incidencia reportada\nPromotor: {$datos_incidencia['promotor_nombre']}\nTipo: " . strtoupper($datos_incidencia['tipo_incidencia']) . "\nPrioridad: " . strtoupper($datos_incidencia['prioridad']),
                'actualizada' => "📝 Incidencia #{$id_incidencia} actualizada\nPromotor: {$datos_incidencia['promotor_nombre']}\nNuevo estatus: " . strtoupper($datos_incidencia['estatus']),
                'extension' => "📅 Extensión de incidencia #{$id_incidencia}\nPromotor: {$datos_incidencia['promotor_nombre']}\nNuevos días: {$datos_incidencia['dias_adicionales']}"
            ];
            
            $mensaje = $mensajes[$tipo_notificacion] ?? 'Nueva notificación de incidencia';
            
            // Crear notificación para cada ROOT
            $sql_notif = "INSERT INTO notificaciones 
                          (id_incidencia, id_destinatario, id_remitente, tipo_notificacion, 
                           mensaje, leida, fecha_creacion, recordatorios_enviados, 
                           ultimo_recordatorio, fecha_proximo_recordatorio) 
                          VALUES 
                          (:id_incidencia, :id_destinatario, :id_remitente, :tipo_notificacion, 
                           :mensaje, 0, NOW(), 0, NULL, DATE_ADD(NOW(), INTERVAL 5 MINUTE))";
            
            $contador = 0;
            foreach ($usuarios_root as $root) {
                $params = [
                    'id_incidencia' => $id_incidencia,
                    'id_destinatario' => $root['id'],
                    'id_remitente' => $id_supervisor_reporta,
                    'tipo_notificacion' => $tipo_notificacion,
                    'mensaje' => $mensaje
                ];
                
                Database::execute($sql_notif, $params);
                $contador++;
            }
            
            error_log("✅ {$contador} notificaciones creadas para ROOT");
            return true;
            
        } catch (Exception $e) {
            error_log("❌ Error creando notificación: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Crear notificación cuando ROOT resuelve incidencia
     * Para notificar al SUPERVISOR que reportó
     */
    public function notificarSupervisorResolucion($id_incidencia, $id_root, $id_supervisor_destino, $datos_incidencia) {
        try {
            $mensaje = "✅ Tu reporte de incidencia #{$id_incidencia} ha sido RESUELTO\n" .
                       "Resuelto por: ROOT\n" .
                       "Promotor: {$datos_incidencia['promotor_nombre']}\n" .
                       "Revisa las notas para ver la resolución completa";
            
            $sql = "INSERT INTO notificaciones 
                    (id_incidencia, id_destinatario, id_remitente, tipo_notificacion, 
                     mensaje, leida, fecha_creacion, recordatorios_enviados, 
                     ultimo_recordatorio, fecha_proximo_recordatorio) 
                    VALUES 
                    (:id_incidencia, :id_destinatario, :id_remitente, 'resuelta', 
                     :mensaje, 0, NOW(), 0, NULL, NULL)";
            
            $params = [
                'id_incidencia' => $id_incidencia,
                'id_destinatario' => $id_supervisor_destino,
                'id_remitente' => $id_root,
                'mensaje' => $mensaje
            ];
            
            Database::execute($sql, $params);
            error_log("✅ Notificación de resolución enviada al supervisor #{$id_supervisor_destino}");
            return true;
            
        } catch (Exception $e) {
            error_log("❌ Error notificando supervisor: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Obtener notificaciones del usuario logueado
     * Compatible con los dashboards
     */
    public function obtenerNotificaciones($id_usuario, $filtros = []) {
        try {
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
                        n.recordatorios_enviados,
                        n.ultimo_recordatorio,
                        n.fecha_proximo_recordatorio,
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
            
            // Marcar como leída
            $sql = "UPDATE notificaciones 
                    SET leida = 1, 
                        fecha_lectura = NOW(),
                        fecha_proximo_recordatorio = NULL
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
            $sql = "UPDATE notificaciones 
                    SET leida = 1, 
                        fecha_lectura = NOW(),
                        fecha_proximo_recordatorio = NULL
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
     * Procesar recordatorios automáticos (ejecutado por CRON)
     */
    public function procesarRecordatorios() {
        try {
            error_log("⏰ Iniciando procesamiento de recordatorios automáticos");
            
            // Obtener notificaciones NO LEÍDAS con recordatorio pendiente
            $sql = "SELECT 
                        n.id_notificacion,
                        n.id_incidencia,
                        n.id_destinatario,
                        n.recordatorios_enviados,
                        n.tipo_notificacion,
                        CONCAT(u.nombre, ' ', u.apellido) as destinatario_nombre,
                        CONCAT(p.nombre, ' ', p.apellido) as promotor_nombre,
                        i.tipo_incidencia,
                        i.prioridad,
                        TIMESTAMPDIFF(MINUTE, n.fecha_creacion, NOW()) as minutos_sin_leer
                    FROM notificaciones n
                    INNER JOIN usuarios u ON n.id_destinatario = u.id
                    INNER JOIN incidencias i ON n.id_incidencia = i.id_incidencia
                    INNER JOIN promotores p ON i.id_promotor = p.id_promotor
                    WHERE n.leida = 0 
                    AND n.fecha_proximo_recordatorio IS NOT NULL
                    AND n.fecha_proximo_recordatorio <= NOW()
                    AND n.recordatorios_enviados < 10
                    AND u.rol = 'root'";
            
            $notificaciones_pendientes = Database::select($sql);
            
            if (empty($notificaciones_pendientes)) {
                error_log("✅ No hay recordatorios pendientes en este momento");
                return [
                    'success' => true,
                    'message' => 'No hay recordatorios pendientes',
                    'procesados' => 0
                ];
            }
            
            error_log("📋 " . count($notificaciones_pendientes) . " notificaciones pendientes de recordatorio");
            
            $contador = 0;
            foreach ($notificaciones_pendientes as $notif) {
                $minutos = $notif['minutos_sin_leer'];
                $nivel_recordatorio = $notif['recordatorios_enviados'] + 1;
                
                // Enviar recordatorio (logging, email, push, etc.)
                $this->enviarRecordatorio($notif, $nivel_recordatorio);
                
                // Actualizar contador de recordatorios
                $sql_update = "UPDATE notificaciones 
                               SET recordatorios_enviados = :nivel,
                                   ultimo_recordatorio = NOW(),
                                   fecha_proximo_recordatorio = DATE_ADD(NOW(), INTERVAL 5 MINUTE)
                               WHERE id_notificacion = :id_notificacion";
                
                Database::execute($sql_update, [
                    'nivel' => $nivel_recordatorio,
                    'id_notificacion' => $notif['id_notificacion']
                ]);
                
                $contador++;
            }
            
            error_log("✅ {$contador} recordatorios procesados exitosamente");
            
            return [
                'success' => true,
                'message' => "{$contador} recordatorios procesados",
                'procesados' => $contador
            ];
            
        } catch (Exception $e) {
            error_log("❌ Error procesando recordatorios: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'procesados' => 0
            ];
        }
    }
    
    /**
     * Enviar recordatorio (logging, email, push notification, etc.)
     */
    private function enviarRecordatorio($notif, $nivel) {
        $urgencia = [
            1 => '⚠️ RECORDATORIO',
            2 => '🔴 URGENTE',
            3 => '🚨 CRÍTICO',
            4 => '🔴🔴 MUY CRÍTICO'
        ];
        
        $titulo = $urgencia[$nivel] ?? '🔴🔴🔴 CRÍTICO';
        
        error_log("📢 Recordatorio #{$nivel} enviado:");
        error_log("   ├─ Usuario: {$notif['destinatario_nombre']}");
        error_log("   ├─ Incidencia: #{$notif['id_incidencia']}");
        error_log("   ├─ Promotor: {$notif['promotor_nombre']}");
        error_log("   ├─ Tipo: {$notif['tipo_incidencia']}");
        error_log("   ├─ Prioridad: {$notif['prioridad']}");
        error_log("   ├─ Minutos sin leer: {$notif['minutos_sin_leer']}");
        error_log("   └─ Nivel: {$titulo}");
        
        // AQUÍ puedes agregar lógica adicional:
        // - Enviar email
        // - Push notification
        // - SMS
        // - Webhook a Slack/Teams
        // Por ahora solo logueamos
        
        return true;
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
                        SUM(CASE WHEN leida = 0 AND recordatorios_enviados >= 1 THEN 1 ELSE 0 END) as con_recordatorios,
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
    $id_usuario_actual = $_SESSION['user_id'] ?? null;
    
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
            
        case 'procesar_recordatorios':
            // Esta acción la ejecutará el cron job
            // NO requiere autenticación porque viene del servidor
            $respuesta = $api->procesarRecordatorios();
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