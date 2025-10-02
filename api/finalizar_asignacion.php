<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

define('APP_ACCESS', true);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Método no permitido'
    ]);
    exit;
}

try {
    // Verificar sesión y permisos
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'No hay sesión activa'
        ]);
        exit;
    }

    if (!isset($_SESSION['rol']) || !in_array($_SESSION['rol'], ['supervisor', 'root'])) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Sin permisos para eliminar asignaciones.'
        ]);
        exit;
    }

    // Incluir conexión DB
    require_once __DIR__ . '/../config/db_connect.php';

    // Obtener datos
    $input = json_decode(file_get_contents('php://input'), true);
    $id_asignacion = intval($input['id_asignacion'] ?? 0);

    error_log("=== INICIO DELETE ASIGNACION ===");
    error_log("ID Asignación a eliminar: {$id_asignacion}");
    error_log("Usuario: " . ($_SESSION['username'] ?? $_SESSION['user_id']));

    if ($id_asignacion <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'ID de asignación inválido'
        ]);
        exit;
    }

    // Obtener información de la asignación a eliminar
    $sql_asignacion = "SELECT 
                          pta.id_asignacion,
                          pta.id_promotor,
                          pta.id_tienda,
                          pta.fecha_inicio,
                          pta.fecha_fin,
                          pta.activo,
                          p.nombre as promotor_nombre,
                          p.apellido as promotor_apellido,
                          t.cadena,
                          t.num_tienda,
                          t.nombre_tienda
                       FROM promotor_tienda_asignaciones pta
                       INNER JOIN promotores p ON pta.id_promotor = p.id_promotor
                       INNER JOIN tiendas t ON pta.id_tienda = t.id_tienda
                       WHERE pta.id_asignacion = :id_asignacion";

    $asignacion = Database::selectOne($sql_asignacion, [':id_asignacion' => $id_asignacion]);

    if (!$asignacion) {
        error_log("ERROR: Asignación no encontrada");
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Asignación no encontrada'
        ]);
        exit;
    }

    $id_promotor = $asignacion['id_promotor'];
    $fecha_inicio_asignacion_actual = $asignacion['fecha_inicio'];

    error_log("Asignación a eliminar:");
    error_log("  - ID Promotor: {$id_promotor}");
    error_log("  - Fecha inicio: {$fecha_inicio_asignacion_actual}");
    error_log("  - Fecha fin: " . ($asignacion['fecha_fin'] ?? 'NULL'));
    error_log("  - Activo: {$asignacion['activo']}");
    error_log("  - Tienda: {$asignacion['cadena']} #{$asignacion['num_tienda']}");

    // DEBUG: Ver todas las asignaciones del promotor ANTES de eliminar
    $sql_debug_antes = "SELECT id_asignacion, fecha_inicio, fecha_fin, activo, id_tienda 
                        FROM promotor_tienda_asignaciones 
                        WHERE id_promotor = :id_promotor 
                        ORDER BY fecha_inicio ASC";
    
    $todas_antes = Database::select($sql_debug_antes, [':id_promotor' => $id_promotor]);
    
    error_log("=== ESTADO ANTES DE ELIMINAR ===");
    error_log("Total asignaciones: " . count($todas_antes));
    foreach ($todas_antes as $idx => $asig) {
        $es_actual = ($asig['id_asignacion'] == $id_asignacion) ? " <-- ELIMINANDO ESTA" : "";
        error_log(sprintf(
            "%d. ID:%d | Inicio:%s | Fin:%s | Activo:%s%s",
            $idx + 1,
            $asig['id_asignacion'],
            $asig['fecha_inicio'],
            $asig['fecha_fin'] ?? 'NULL',
            $asig['activo'],
            $es_actual
        ));
    }

    // BUSCAR ASIGNACIÓN ANTERIOR CORRECTA (la que empezó antes)
    error_log("Buscando asignación anterior a la fecha: {$fecha_inicio_asignacion_actual}");
    
    $sql_asignacion_anterior = "SELECT 
                                    id_asignacion,
                                    id_tienda,
                                    fecha_inicio,
                                    fecha_fin,
                                    activo
                                FROM promotor_tienda_asignaciones
                                WHERE id_promotor = :id_promotor
                                AND id_asignacion != :id_asignacion_actual
                                AND fecha_inicio < :fecha_inicio_actual
                                ORDER BY fecha_inicio DESC
                                LIMIT 1";
    
    $asignacion_anterior = Database::selectOne($sql_asignacion_anterior, [
        ':id_promotor' => $id_promotor,
        ':id_asignacion_actual' => $id_asignacion,
        ':fecha_inicio_actual' => $fecha_inicio_asignacion_actual
    ]);
    
    if ($asignacion_anterior) {
        error_log("ASIGNACIÓN ANTERIOR ENCONTRADA:");
        error_log("  - ID: {$asignacion_anterior['id_asignacion']}");
        error_log("  - Fecha inicio: {$asignacion_anterior['fecha_inicio']}");
        error_log("  - Fecha fin actual: " . ($asignacion_anterior['fecha_fin'] ?? 'NULL'));
        error_log("  - Activo actual: {$asignacion_anterior['activo']}");
    } else {
        error_log("NO SE ENCONTRÓ ASIGNACIÓN ANTERIOR");
        error_log("Criterios de búsqueda:");
        error_log("  - id_promotor: {$id_promotor}");
        error_log("  - id_asignacion diferente de: {$id_asignacion}");
        error_log("  - fecha_inicio menor a: {$fecha_inicio_asignacion_actual}");
    }

    // ELIMINAR LA ASIGNACIÓN
    error_log("Procediendo a eliminar asignación ID: {$id_asignacion}");
    
    $sql_delete = "DELETE FROM promotor_tienda_asignaciones WHERE id_asignacion = :id_asignacion";
    $affected = Database::execute($sql_delete, [':id_asignacion' => $id_asignacion]);

    error_log("Filas afectadas por DELETE: {$affected}");

    if ($affected === 0) {
        error_log("ERROR: No se pudo eliminar la asignación");
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'No se pudo eliminar la asignación'
        ]);
        exit;
    }

    error_log("Asignación eliminada exitosamente");

    // REACTIVAR ASIGNACIÓN ANTERIOR
    $asignacion_reactivada = false;
    $id_asignacion_reactivada = null;
    
    if ($asignacion_anterior) {
        error_log("Procediendo a reactivar asignación anterior ID: {$asignacion_anterior['id_asignacion']}");
        
        $motivo_reactivacion = "Reactivada automáticamente por eliminación de asignación posterior (ID: {$id_asignacion}) el " . date('Y-m-d H:i:s');
        
        $sql_reactivar = "UPDATE promotor_tienda_asignaciones
                         SET fecha_fin = NULL,
                             activo = 1,
                             motivo_cambio = :motivo_reactivacion,
                             usuario_cambio = :usuario_cambio,
                             fecha_modificacion = NOW()
                         WHERE id_asignacion = :id_asignacion_anterior";
        
        error_log("Ejecutando UPDATE para reactivar...");
        
        $reactivada = Database::execute($sql_reactivar, [
            ':motivo_reactivacion' => $motivo_reactivacion,
            ':usuario_cambio' => $_SESSION['user_id'],
            ':id_asignacion_anterior' => $asignacion_anterior['id_asignacion']
        ]);
        
        error_log("Filas afectadas por UPDATE: {$reactivada}");
        
        if ($reactivada > 0) {
            $asignacion_reactivada = true;
            $id_asignacion_reactivada = $asignacion_anterior['id_asignacion'];
            error_log("ÉXITO: Asignación anterior REACTIVADA");
            
            // Verificar el cambio
            $sql_verificar = "SELECT id_asignacion, fecha_inicio, fecha_fin, activo 
                             FROM promotor_tienda_asignaciones 
                             WHERE id_asignacion = :id";
            $verificacion = Database::selectOne($sql_verificar, [':id' => $asignacion_anterior['id_asignacion']]);
            
            if ($verificacion) {
                error_log("Verificación post-UPDATE:");
                error_log("  - ID: {$verificacion['id_asignacion']}");
                error_log("  - Fecha inicio: {$verificacion['fecha_inicio']}");
                error_log("  - Fecha fin: " . ($verificacion['fecha_fin'] ?? 'NULL'));
                error_log("  - Activo: {$verificacion['activo']}");
            }
            
            // Log de actividad
            try {
                $sql_log = "INSERT INTO log_actividades (tabla, accion, id_registro, usuario_id, fecha, detalles) 
                           VALUES ('promotor_tienda_asignaciones', 'REACTIVACION_AUTO_DELETE', :id_registro, :usuario_id, NOW(), :detalles)";
                
                Database::insert($sql_log, [
                    ':id_registro' => $asignacion_anterior['id_asignacion'],
                    ':usuario_id' => $_SESSION['user_id'],
                    ':detalles' => "Asignación ID {$asignacion_anterior['id_asignacion']} reactivada automáticamente tras eliminar asignación ID {$id_asignacion}"
                ]);
                
                error_log("Log de reactivación guardado");
            } catch (Exception $log_error) {
                error_log("Error guardando log de reactivación: " . $log_error->getMessage());
            }
        } else {
            error_log("WARNING: UPDATE no afectó ninguna fila");
        }
    } else {
        error_log("No hay asignación anterior para reactivar");
    }

    // Log de eliminación
    try {
        $detalle = "Asignación eliminada: {$asignacion['promotor_nombre']} {$asignacion['promotor_apellido']} - Tienda {$asignacion['cadena']} #{$asignacion['num_tienda']}";
        if ($asignacion_reactivada) {
            $detalle .= " | Asignación anterior ID {$id_asignacion_reactivada} reactivada automáticamente";
        }
        
        $sql_log = "INSERT INTO log_actividades (tabla, accion, id_registro, usuario_id, fecha, detalles) 
                   VALUES ('promotor_tienda_asignaciones', 'ELIMINACION', :id_registro, :usuario_id, NOW(), :detalles)";
        
        Database::insert($sql_log, [
            ':id_registro' => $id_asignacion,
            ':usuario_id' => $_SESSION['user_id'],
            ':detalles' => $detalle
        ]);
        
        error_log("Log de eliminación guardado");
    } catch (Exception $log_error) {
        error_log("Error en log de eliminación: " . $log_error->getMessage());
    }

    // DEBUG: Ver todas las asignaciones del promotor DESPUÉS de reactivar
    $sql_debug_despues = "SELECT id_asignacion, fecha_inicio, fecha_fin, activo, id_tienda 
                          FROM promotor_tienda_asignaciones 
                          WHERE id_promotor = :id_promotor 
                          ORDER BY fecha_inicio ASC";
    
    $todas_despues = Database::select($sql_debug_despues, [':id_promotor' => $id_promotor]);
    
    error_log("=== ESTADO DESPUÉS DE REACTIVAR ===");
    error_log("Total asignaciones: " . count($todas_despues));
    foreach ($todas_despues as $idx => $asig) {
        $es_reactivada = ($asignacion_reactivada && $asig['id_asignacion'] == $id_asignacion_reactivada) ? " <-- REACTIVADA" : "";
        error_log(sprintf(
            "%d. ID:%d | Inicio:%s | Fin:%s | Activo:%s%s",
            $idx + 1,
            $asig['id_asignacion'],
            $asig['fecha_inicio'],
            $asig['fecha_fin'] ?? 'NULL',
            $asig['activo'],
            $es_reactivada
        ));
    }

    // Respuesta
    $mensaje = 'Asignación eliminada correctamente';
    if ($asignacion_reactivada) {
        $mensaje .= ' y asignación anterior reactivada automáticamente';
    }

    error_log("=== FIN DELETE ASIGNACION (EXITOSO) ===");

    echo json_encode([
        'success' => true,
        'message' => $mensaje,
        'asignacion_anterior_reactivada' => $asignacion_reactivada,
        'id_asignacion_anterior' => $id_asignacion_reactivada,
        'debug' => [
            'asignacion_eliminada' => $id_asignacion,
            'promotor_id' => $id_promotor,
            'asignacion_anterior_encontrada' => $asignacion_anterior ? true : false,
            'total_asignaciones_restantes' => count($todas_despues)
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("ERROR CRÍTICO en finalizar_asignacion.php: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor',
        'error_detail' => $e->getMessage()
    ]);
}
?>