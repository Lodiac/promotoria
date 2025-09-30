<?php
// Evitar cualquier output antes de JSON
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0); // No mostrar errores en la respuesta

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

    // Verificar que sea DELETE o POST
    if (!in_array($_SERVER['REQUEST_METHOD'], ['DELETE', 'POST'])) {
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

    if ($_SESSION['rol'] !== 'root') {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Solo usuarios ROOT pueden eliminar promotores'
        ]);
        exit;
    }

    // ===== OBTENER DATOS =====
    $input = null;
    
    if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        $input = json_decode(file_get_contents('php://input'), true);
    } else {
        $content_type = $_SERVER['CONTENT_TYPE'] ?? '';
        if (strpos($content_type, 'application/json') !== false) {
            $input = json_decode(file_get_contents('php://input'), true);
        } else {
            $input = $_POST;
        }
    }

    $id_promotor = null;
    if (isset($input['id_promotor'])) {
        $id_promotor = $input['id_promotor'];
    } elseif (isset($input['id'])) {
        $id_promotor = $input['id'];
    }

    if (!$id_promotor || empty($id_promotor)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'ID de promotor requerido'
        ]);
        exit;
    }

    $id_promotor = intval($id_promotor);

    if ($id_promotor <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'ID de promotor inválido'
        ]);
        exit;
    }

    // ===== FUNCIÓN HELPER PARA FORMATEAR NUMERO_TIENDA =====
    function formatearNumeroTienda($numero_tienda) {
        if ($numero_tienda === null || $numero_tienda === '') {
            return [
                'original' => null,
                'display' => 'N/A',
                'parsed' => null
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
                    'parsed' => $parsed
                ];
            } elseif (is_array($parsed)) {
                return [
                    'original' => $numero_tienda,
                    'display' => implode(', ', $parsed),
                    'parsed' => $parsed
                ];
            } else {
                return [
                    'original' => $numero_tienda,
                    'display' => is_string($parsed) ? $parsed : json_encode($parsed),
                    'parsed' => $parsed
                ];
            }
        } else {
            // No es JSON válido, asumir que es un entero legacy
            if (is_numeric($numero_tienda)) {
                return [
                    'original' => $numero_tienda,
                    'display' => (string)$numero_tienda,
                    'parsed' => (int)$numero_tienda
                ];
            } else {
                return [
                    'original' => $numero_tienda,
                    'display' => (string)$numero_tienda,
                    'parsed' => $numero_tienda
                ];
            }
        }
    }

    // 🆕 FUNCIONES HELPER PARA BASE DE DATOS (COMPATIBILIDAD)
    function executeQuery($sql, $params = []) {
        global $pdo, $use_database_class;
        
        if ($use_database_class) {
            return Database::selectOne($sql, $params);
        } else {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        }
    }

    function executeSelect($sql, $params = []) {
        global $pdo, $use_database_class;
        
        if ($use_database_class) {
            return Database::select($sql, $params);
        } else {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    function executeUpdate($sql, $params = []) {
        global $pdo, $use_database_class;
        
        if ($use_database_class) {
            return Database::execute($sql, $params);
        } else {
            $stmt = $pdo->prepare($sql);
            $result = $stmt->execute($params);
            return $result ? $stmt->rowCount() : 0;
        }
    }

    // ===== LOG DE SEGUIMIENTO =====
    $log = [];
    $log[] = "🔍 Iniciando eliminación de promotor ID: {$id_promotor}";
    $log[] = "🔧 Usando " . ($use_database_class ? "Database class" : "PDO directo");

    // ===== VERIFICAR PROMOTOR =====
    $sql_check = "SELECT id_promotor, nombre, apellido, correo, estado FROM promotores WHERE id_promotor = " . ($use_database_class ? ":id_promotor" : "?") . " LIMIT 1";
    $params_check = $use_database_class ? [':id_promotor' => $id_promotor] : [$id_promotor];
    
    $promotor = executeQuery($sql_check, $params_check);

    if (!$promotor) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Promotor no encontrado'
        ]);
        exit;
    }

    if ($promotor['estado'] == 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'El promotor ya está eliminado'
        ]);
        exit;
    }

    $log[] = "✅ Promotor encontrado: {$promotor['nombre']} {$promotor['apellido']}";

    // ===== 🆕 PASO CRÍTICO: LIBERAR CLAVES CORRECTAMENTE ANTES DE ELIMINAR =====
    $sql_claves = "SELECT id_clave, codigo_clave, numero_tienda, en_uso FROM claves_tienda WHERE id_promotor_actual = " . ($use_database_class ? ":id_promotor" : "?");
    $params_claves = $use_database_class ? [':id_promotor' => $id_promotor] : [$id_promotor];
    
    $claves_asignadas = executeSelect($sql_claves, $params_claves);

    $total_claves = count($claves_asignadas);
    $claves_ocupadas = array_filter($claves_asignadas, function($c) { return $c['en_uso'] == 1; });
    $total_ocupadas = count($claves_ocupadas);

    $log[] = "🔍 Claves encontradas: {$total_claves} total, {$total_ocupadas} ocupadas";
    
    // ✅ IMPLEMENTAR EL AJUSTE SOLICITADO: LIBERAR CLAVES CON FECHA
    $claves_liberadas = [];
    $fecha_liberacion = date('Y-m-d H:i:s'); // Fecha actual para liberación

    if ($total_ocupadas > 0) {
        $log[] = "🔓 Iniciando liberación de {$total_ocupadas} claves ocupadas con fecha {$fecha_liberacion}...";

        foreach ($claves_ocupadas as $clave) {
            $tienda_info = formatearNumeroTienda($clave['numero_tienda']);
            $log[] = "🔓 Liberando clave: {$clave['codigo_clave']} (Tienda: {$tienda_info['display']})";
            
            try {
                // ✅ QUERY CORREGIDA: Actualizar según los campos específicos solicitados
                if ($use_database_class) {
                    $sql_liberar = "UPDATE claves_tienda 
                                   SET en_uso = 0,                          -- ✅ Cambiar a disponible
                                       fecha_liberacion = :fecha_liberacion  -- ✅ Actualizar fecha de liberación
                                   WHERE id_clave = :id_clave 
                                   AND id_promotor_actual = :id_promotor";
                    
                    $params = [
                        ':fecha_liberacion' => $fecha_liberacion,
                        ':id_clave' => $clave['id_clave'],
                        ':id_promotor' => $id_promotor
                    ];
                } else {
                    $sql_liberar = "UPDATE claves_tienda 
                                   SET en_uso = 0, fecha_liberacion = ?
                                   WHERE id_clave = ? AND id_promotor_actual = ?";
                    
                    $params = [$fecha_liberacion, $clave['id_clave'], $id_promotor];
                }
                               
                // ❗ IMPORTANTE: NO tocar id_promotor_actual - se mantiene intacto como solicitaste
                // ❗ IMPORTANTE: NO tocar fecha_asignacion - se mantiene intacta (historial)
                
                $result = executeUpdate($sql_liberar, $params);
                
                if ($result > 0) {
                    $claves_liberadas[] = [
                        'codigo' => $clave['codigo_clave'],
                        'tienda' => $clave['numero_tienda'],
                        'tienda_display' => $tienda_info['display'],
                        'tienda_parsed' => $tienda_info['parsed'],
                        'id_clave' => $clave['id_clave'],
                        'fecha_liberacion' => $fecha_liberacion
                    ];
                    $log[] = "✅ Clave {$clave['codigo_clave']} liberada correctamente";
                } else {
                    $log[] = "❌ No se pudo liberar clave {$clave['codigo_clave']} (0 filas afectadas)";
                }
                
            } catch (Exception $e) {
                $log[] = "❌ Error liberando clave {$clave['codigo_clave']}: " . $e->getMessage();
            }
        }
    } else {
        $log[] = "ℹ️ No hay claves ocupadas para liberar";
    }

    // ===== ELIMINAR PROMOTOR =====
    $log[] = "🗑️ Eliminando promotor de la tabla principal...";
    
    if ($use_database_class) {
        $sql_delete_promotor = "UPDATE promotores SET estado = 0, fecha_modificacion = NOW() WHERE id_promotor = :id_promotor AND estado = 1";
        $params_delete = [':id_promotor' => $id_promotor];
    } else {
        $sql_delete_promotor = "UPDATE promotores SET estado = 0, fecha_modificacion = NOW() WHERE id_promotor = ? AND estado = 1";
        $params_delete = [$id_promotor];
    }
    
    try {
        $affected = executeUpdate($sql_delete_promotor, $params_delete);
        if ($affected === 0) {
            throw new Exception('No se pudo eliminar el promotor (0 filas afectadas)');
        }
        $log[] = "✅ Promotor eliminado exitosamente";
    } catch (Exception $e) {
        throw new Exception("Error eliminando promotor: " . $e->getMessage());
    }

    // ===== VERIFICACIÓN FINAL DEL AJUSTE =====
    if ($use_database_class) {
        $sql_verificacion = "SELECT 
                                COUNT(*) as total_claves,
                                SUM(CASE WHEN en_uso = 0 THEN 1 ELSE 0 END) as claves_liberadas,
                                SUM(CASE WHEN en_uso = 1 THEN 1 ELSE 0 END) as claves_aun_ocupadas,
                                COUNT(CASE WHEN fecha_liberacion = :fecha_liberacion THEN 1 END) as claves_con_fecha_hoy
                             FROM claves_tienda 
                             WHERE id_promotor_actual = :id_promotor";
        $params_verificacion = [
            ':id_promotor' => $id_promotor,
            ':fecha_liberacion' => $fecha_liberacion
        ];
    } else {
        $sql_verificacion = "SELECT 
                                COUNT(*) as total_claves,
                                SUM(CASE WHEN en_uso = 0 THEN 1 ELSE 0 END) as claves_liberadas,
                                SUM(CASE WHEN en_uso = 1 THEN 1 ELSE 0 END) as claves_aun_ocupadas,
                                COUNT(CASE WHEN fecha_liberacion = ? THEN 1 END) as claves_con_fecha_hoy
                             FROM claves_tienda 
                             WHERE id_promotor_actual = ?";
        $params_verificacion = [$fecha_liberacion, $id_promotor];
    }
                         
    $verificacion = executeQuery($sql_verificacion, $params_verificacion);
    
    $log[] = "🔍 Verificación final: {$verificacion['claves_liberadas']} claves liberadas de {$verificacion['total_claves']} total";
    $log[] = "🔍 Claves con fecha de liberación actual: {$verificacion['claves_con_fecha_hoy']}";
    $log[] = "🔍 id_promotor_actual mantenido intacto en {$verificacion['total_claves']} registros";

    // ===== LOG DE AUDITORÍA =====
    $total_liberadas = count($claves_liberadas);
    $codigos_liberados = array_column($claves_liberadas, 'codigo');
    $tiendas_liberadas = array_map(function($clave) { 
        return $clave['codigo'] . '(' . $clave['tienda_display'] . ')'; 
    }, $claves_liberadas);
    
    $claves_log = $total_ocupadas > 0 ? " - Claves liberadas: {$total_liberadas}/{$total_ocupadas} [" . implode(', ', $tiendas_liberadas) . "]" : "";
    
    error_log("✅ ELIMINACIÓN COMPLETADA CON AJUSTE - ID: {$id_promotor} - {$promotor['nombre']} {$promotor['apellido']}{$claves_log} - Fecha liberación: {$fecha_liberacion} - Usuario: " . $_SESSION['username']);
    error_log("📝 LOG: " . implode(' | ', $log));

    // ===== RESPUESTA FINAL =====
    $liberacion_completa = ($verificacion['claves_aun_ocupadas'] == 0);
    $mensaje = 'Promotor eliminado correctamente';
    
    if ($total_ocupadas > 0) {
        if ($liberacion_completa) {
            $mensaje .= " y todas las {$total_ocupadas} clave(s) fueron liberadas el {$fecha_liberacion}";
        } else {
            $mensaje .= " pero {$verificacion['claves_aun_ocupadas']} clave(s) aún aparecen ocupadas";
        }
    }
    
    // ✅ NUEVA INFORMACIÓN SOBRE EL AJUSTE IMPLEMENTADO
    $ajuste_info = [
        'fecha_liberacion_aplicada' => $fecha_liberacion,
        'claves_con_fecha_liberacion' => $verificacion['claves_con_fecha_hoy'],
        'claves_cambiadas_a_disponible' => $verificacion['claves_liberadas'],
        'id_promotor_actual_mantenido' => $verificacion['total_claves'] > 0 ? true : null,
        'fecha_asignacion_preservada' => true, // ✅ NO se modifica la fecha histórica
        'usuario_asigno_preservado' => true,   // ✅ NO se modifica
        'ajuste_implementado' => 'en_uso=0, fecha_liberacion=HOY, id_promotor_actual=INTACTO, fecha_asignacion=INTACTA',
        'metodo_base_datos' => $use_database_class ? 'Database class' : 'PDO directo'
    ];
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => $mensaje,
        'data' => [
            'id_promotor' => $id_promotor,
            'nombre' => $promotor['nombre'],
            'apellido' => $promotor['apellido'],
            'correo' => $promotor['correo'],
            'claves_encontradas' => $total_claves,
            'claves_ocupadas_inicialmente' => $total_ocupadas,
            'claves_liberadas_exitosamente' => $total_liberadas,
            'liberacion_completa' => $liberacion_completa,
            'fecha_liberacion' => $fecha_liberacion,
            'detalles_ajuste' => $ajuste_info, // ✅ Información del ajuste
            'log_operacion' => $log,
            'verificacion_final' => $verificacion,
            'claves_liberadas_detalle' => $claves_liberadas
        ]
    ]);

} catch (Exception $e) {
    $log[] = "❌ Error crítico: " . $e->getMessage();
    error_log("❌ Error crítico eliminación: " . $e->getMessage() . " - Usuario: " . ($_SESSION['username'] ?? 'desconocido') . " - Log: " . implode(' | ', isset($log) ? $log : []));
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error interno: ' . $e->getMessage(),
        'debug_info' => [
            'log_hasta_error' => isset($log) ? $log : [],
            'ajuste_implementado' => 'Liberación de claves con fecha y mantenimiento de id_promotor_actual',
            'metodo_utilizado' => isset($use_database_class) ? ($use_database_class ? 'Database class' : 'PDO directo') : 'No determinado'
        ]
    ]);
} catch (PDOException $e) {
    // Error específico de base de datos
    error_log("❌ Error PDO eliminación: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error de base de datos'
    ]);
} finally {
    // Asegurar que no hay output adicional
    if (ob_get_length()) {
        ob_end_flush();
    }
}
?>