<?php
/**
 * API para Gestión de Incidencias de Promotores
 * VERSIÓN FINAL CORREGIDA - Sin errores 500
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/incidencias_errors.log');

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

class IncidenciasAPI {
    
    private function calcularDias($fecha_inicio, $fecha_fin) {
        if (empty($fecha_inicio) || empty($fecha_fin)) {
            return null;
        }
        
        $inicio = new DateTime($fecha_inicio);
        $fin = new DateTime($fecha_fin);
        $diferencia = $inicio->diff($fin);
        
        return $diferencia->days + 1;
    }
    
    public function obtenerIncidencias($filtros = []) {
        try {
            $sql = "SELECT 
                        i.id_incidencia,
                        i.fecha_incidencia,
                        i.fecha_fin,
                        i.dias_totales,
                        i.id_promotor,
                        CONCAT(p.nombre, ' ', p.apellido) as promotor_nombre,
                        p.rfc as promotor_rfc,
                        p.telefono as promotor_telefono,
                        i.id_tienda,
                        i.tienda_nombre,
                        t.num_tienda,
                        t.cadena as tienda_cadena,
                        i.tipo_incidencia,
                        i.descripcion,
                        i.estatus,
                        i.prioridad,
                        i.notas,
                        i.es_extension,
                        i.incidencia_extendida_de,
                        i.usuario_registro,
                        i.fecha_registro,
                        i.fecha_modificacion
                    FROM incidencias i
                    INNER JOIN promotores p ON i.id_promotor = p.id_promotor
                    LEFT JOIN tiendas t ON i.id_tienda = t.id_tienda
                    WHERE 1=1";
                
            $params = [];
            
            if (!empty($filtros['fecha_inicio'])) {
                $sql .= " AND i.fecha_incidencia >= :fecha_inicio";
                $params['fecha_inicio'] = $filtros['fecha_inicio'];
            }
            
            if (!empty($filtros['fecha_fin'])) {
                $sql .= " AND i.fecha_incidencia <= :fecha_fin";
                $params['fecha_fin'] = $filtros['fecha_fin'];
            }
            
            if (!empty($filtros['id_promotor'])) {
                $sql .= " AND i.id_promotor = :id_promotor";
                $params['id_promotor'] = $filtros['id_promotor'];
            }
            
            if (!empty($filtros['tipo_incidencia'])) {
                $sql .= " AND i.tipo_incidencia = :tipo_incidencia";
                $params['tipo_incidencia'] = $filtros['tipo_incidencia'];
            }
            
            if (!empty($filtros['estatus'])) {
                $sql .= " AND i.estatus = :estatus";
                $params['estatus'] = $filtros['estatus'];
            }
            
            if (!empty($filtros['prioridad'])) {
                $sql .= " AND i.prioridad = :prioridad";
                $params['prioridad'] = $filtros['prioridad'];
            }
            
            $sql .= " ORDER BY i.fecha_incidencia DESC, i.fecha_registro DESC";
            
            $incidencias = Database::select($sql, $params);
            $estadisticas = $this->obtenerEstadisticas($filtros);
            
            return [
                'success' => true,
                'data' => [
                    'incidencias' => $incidencias,
                    'estadisticas' => $estadisticas,
                    'total' => count($incidencias)
                ],
                'message' => 'Incidencias obtenidas exitosamente'
            ];
            
        } catch (Exception $e) {
            error_log("❌ Error en obtenerIncidencias: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error al obtener incidencias: ' . $e->getMessage(),
                'data' => null
            ];
        }
    }
    
    private function obtenerEstadisticas($filtros = []) {
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
                $sql .= " AND i.fecha_incidencia >= :fecha_inicio";
                $params['fecha_inicio'] = $filtros['fecha_inicio'];
            }
            
            if (!empty($filtros['fecha_fin'])) {
                $sql .= " AND i.fecha_incidencia <= :fecha_fin";
                $params['fecha_fin'] = $filtros['fecha_fin'];
            }
            
            $stats = Database::selectOne($sql, $params);
            
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
            error_log("❌ Error en obtenerEstadisticas: " . $e->getMessage());
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
    
    public function crearIncidencia($datos) {
        try {
            error_log("📝 Creando incidencia con datos: " . json_encode($datos, JSON_UNESCAPED_UNICODE));
            
            // Validar campos requeridos
            $campos_requeridos = ['id_promotor', 'fecha_incidencia', 'tipo_incidencia', 'descripcion'];
            foreach ($campos_requeridos as $campo) {
                if (empty($datos[$campo])) {
                    throw new Exception("Campo requerido faltante: {$campo}");
                }
            }
            
            // Calcular días si hay fecha_fin
            $dias_totales = null;
            if (!empty($datos['fecha_fin'])) {
                $dias_totales = $this->calcularDias($datos['fecha_incidencia'], $datos['fecha_fin']);
            }
            
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
                        usuario_registro,
                        fecha_registro
                    ) VALUES (
                        :fecha_incidencia,
                        :fecha_fin,
                        :dias_totales,
                        :id_promotor,
                        :id_tienda,
                        :tienda_nombre,
                        :tipo_incidencia,
                        :descripcion,
                        :estatus,
                        :prioridad,
                        :notas,
                        :usuario_registro,
                        NOW()
                    )";
            
            $params = [
                'fecha_incidencia' => $datos['fecha_incidencia'],
                'fecha_fin' => $datos['fecha_fin'] ?? null,
                'dias_totales' => $dias_totales,
                'id_promotor' => $datos['id_promotor'],
                'id_tienda' => $datos['id_tienda'] ?? null,
                'tienda_nombre' => $datos['tienda_nombre'] ?? null,
                'tipo_incidencia' => $datos['tipo_incidencia'],
                'descripcion' => $datos['descripcion'],
                'estatus' => $datos['estatus'] ?? 'pendiente',
                'prioridad' => $datos['prioridad'] ?? 'media',
                'notas' => $datos['notas'] ?? null,
                'usuario_registro' => $datos['usuario_registro'] ?? 'sistema'
            ];
            
            error_log("📤 Ejecutando INSERT con params: " . json_encode($params, JSON_UNESCAPED_UNICODE));
            
            $id_incidencia = Database::insert($sql, $params);
            
            error_log("✅ Incidencia creada con ID: {$id_incidencia}");
            
            return [
                'success' => true,
                'data' => [
                    'id_incidencia' => $id_incidencia
                ],
                'message' => 'Incidencia creada exitosamente'
            ];
            
        } catch (Exception $e) {
            error_log("❌ Error en crearIncidencia: " . $e->getMessage());
            error_log("❌ Trace: " . $e->getTraceAsString());
            return [
                'success' => false,
                'message' => 'Error al crear incidencia: ' . $e->getMessage(),
                'data' => null
            ];
        }
    }
    
    public function actualizarIncidencia($id_incidencia, $datos) {
        try {
            error_log("📝 Actualizando incidencia {$id_incidencia}");
            
            // Verificar que la incidencia existe
            $sql_existe = "SELECT id_incidencia FROM incidencias WHERE id_incidencia = :id_incidencia";
            $existe = Database::selectOne($sql_existe, ['id_incidencia' => $id_incidencia]);
            
            if (!$existe) {
                throw new Exception("Incidencia no encontrada");
            }
            
            // Calcular días si hay fecha_fin
            $dias_totales = null;
            if (!empty($datos['fecha_fin']) && !empty($datos['fecha_incidencia'])) {
                $dias_totales = $this->calcularDias($datos['fecha_incidencia'], $datos['fecha_fin']);
            }
            
            $sql = "UPDATE incidencias SET
                        fecha_incidencia = :fecha_incidencia,
                        fecha_fin = :fecha_fin,
                        dias_totales = :dias_totales,
                        id_promotor = :id_promotor,
                        id_tienda = :id_tienda,
                        tienda_nombre = :tienda_nombre,
                        tipo_incidencia = :tipo_incidencia,
                        descripcion = :descripcion,
                        estatus = :estatus,
                        prioridad = :prioridad,
                        notas = :notas,
                        fecha_modificacion = NOW()
                    WHERE id_incidencia = :id_incidencia";
            
            $params = [
                'id_incidencia' => $id_incidencia,
                'fecha_incidencia' => $datos['fecha_incidencia'],
                'fecha_fin' => $datos['fecha_fin'] ?? null,
                'dias_totales' => $dias_totales,
                'id_promotor' => $datos['id_promotor'],
                'id_tienda' => $datos['id_tienda'] ?? null,
                'tienda_nombre' => $datos['tienda_nombre'] ?? null,
                'tipo_incidencia' => $datos['tipo_incidencia'],
                'descripcion' => $datos['descripcion'],
                'estatus' => $datos['estatus'] ?? 'pendiente',
                'prioridad' => $datos['prioridad'] ?? 'media',
                'notas' => $datos['notas'] ?? null
            ];
            
            Database::execute($sql, $params);
            
            error_log("✅ Incidencia {$id_incidencia} actualizada");
            
            return [
                'success' => true,
                'message' => 'Incidencia actualizada exitosamente',
                'data' => ['id_incidencia' => $id_incidencia]
            ];
            
        } catch (Exception $e) {
            error_log("❌ Error en actualizarIncidencia: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error al actualizar incidencia: ' . $e->getMessage(),
                'data' => null
            ];
        }
    }
    
    public function extenderIncidencia($datos) {
        try {
            error_log("📝 Extendiendo incidencia");
            
            // Validar datos requeridos
            if (empty($datos['id_incidencia_original']) || 
                empty($datos['fecha_inicio']) || 
                empty($datos['fecha_fin']) || 
                empty($datos['motivo'])) {
                throw new Exception("Faltan datos requeridos para la extensión");
            }
            
            // Obtener incidencia original
            $sql_original = "SELECT * FROM incidencias WHERE id_incidencia = :id_incidencia";
            $incidencia_original = Database::selectOne($sql_original, [
                'id_incidencia' => $datos['id_incidencia_original']
            ]);
            
            if (!$incidencia_original) {
                throw new Exception("Incidencia original no encontrada");
            }
            
            // Calcular días de la extensión
            $dias_totales = $this->calcularDias($datos['fecha_inicio'], $datos['fecha_fin']);
            
            // Crear nueva incidencia como extensión
            $sql = "INSERT INTO incidencias (
                        fecha_incidencia,
                        fecha_fin,
                        dias_totales,
                        incidencia_extendida_de,
                        es_extension,
                        id_promotor,
                        id_tienda,
                        tienda_nombre,
                        tipo_incidencia,
                        descripcion,
                        estatus,
                        prioridad,
                        notas,
                        usuario_registro,
                        fecha_registro
                    ) VALUES (
                        :fecha_incidencia,
                        :fecha_fin,
                        :dias_totales,
                        :incidencia_extendida_de,
                        1,
                        :id_promotor,
                        :id_tienda,
                        :tienda_nombre,
                        :tipo_incidencia,
                        :descripcion,
                        :estatus,
                        :prioridad,
                        :notas,
                        :usuario_registro,
                        NOW()
                    )";
            
            $params = [
                'fecha_incidencia' => $datos['fecha_inicio'],
                'fecha_fin' => $datos['fecha_fin'],
                'dias_totales' => $dias_totales,
                'incidencia_extendida_de' => $datos['id_incidencia_original'],
                'id_promotor' => $incidencia_original['id_promotor'],
                'id_tienda' => $incidencia_original['id_tienda'],
                'tienda_nombre' => $incidencia_original['tienda_nombre'],
                'tipo_incidencia' => $incidencia_original['tipo_incidencia'],
                'descripcion' => 'EXTENSIÓN: ' . $datos['motivo'],
                'estatus' => $incidencia_original['estatus'],
                'prioridad' => $incidencia_original['prioridad'],
                'notas' => 'Extensión de incidencia #' . $datos['id_incidencia_original'] . '. ' . $datos['motivo'],
                'usuario_registro' => $datos['usuario_registro'] ?? 'sistema'
            ];
            
            $id_nueva = Database::insert($sql, $params);
            
            error_log("✅ Incidencia extendida. Nueva ID: {$id_nueva}");
            
            return [
                'success' => true,
                'data' => [
                    'id_incidencia' => $id_nueva,
                    'id_original' => $datos['id_incidencia_original']
                ],
                'message' => 'Incidencia extendida exitosamente'
            ];
            
        } catch (Exception $e) {
            error_log("❌ Error en extenderIncidencia: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error al extender incidencia: ' . $e->getMessage(),
                'data' => null
            ];
        }
    }
    
    public function eliminarIncidencia($id_incidencia) {
        try {
            error_log("🗑️ Eliminando incidencia {$id_incidencia}");
            
            // Verificar que existe
            $sql_existe = "SELECT id_incidencia FROM incidencias WHERE id_incidencia = :id_incidencia";
            $existe = Database::selectOne($sql_existe, ['id_incidencia' => $id_incidencia]);
            
            if (!$existe) {
                throw new Exception("Incidencia no encontrada");
            }
            
            $sql = "DELETE FROM incidencias WHERE id_incidencia = :id_incidencia";
            Database::execute($sql, ['id_incidencia' => $id_incidencia]);
            
            error_log("✅ Incidencia {$id_incidencia} eliminada");
            
            return [
                'success' => true,
                'message' => 'Incidencia eliminada exitosamente'
            ];
            
        } catch (Exception $e) {
            error_log("❌ Error en eliminarIncidencia: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error al eliminar incidencia: ' . $e->getMessage(),
                'data' => null
            ];
        }
    }
    
    public function obtenerIncidenciaPorId($id_incidencia) {
        try {
            $sql = "SELECT 
                        i.*,
                        CONCAT(p.nombre, ' ', p.apellido) as promotor_nombre,
                        p.rfc as promotor_rfc,
                        t.nombre_tienda,
                        t.num_tienda,
                        t.cadena
                    FROM incidencias i
                    INNER JOIN promotores p ON i.id_promotor = p.id_promotor
                    LEFT JOIN tiendas t ON i.id_tienda = t.id_tienda
                    WHERE i.id_incidencia = :id_incidencia";
            
            $incidencia = Database::selectOne($sql, ['id_incidencia' => $id_incidencia]);
            
            if (!$incidencia) {
                throw new Exception("Incidencia no encontrada");
            }
            
            return [
                'success' => true,
                'data' => $incidencia
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null
            ];
        }
    }
    
    public function obtenerPromotores() {
        try {
            $sql = "SELECT 
                        id_promotor,
                        CONCAT(nombre, ' ', apellido) as nombre_completo,
                        rfc,
                        telefono
                    FROM promotores
                    WHERE estado = 1
                    ORDER BY nombre, apellido";
            
            $promotores = Database::select($sql);
            
            return [
                'success' => true,
                'data' => [
                    'promotores' => $promotores
                ],
                'total' => count($promotores),
                'message' => 'Promotores obtenidos exitosamente'
            ];
            
        } catch (Exception $e) {
            error_log("❌ Error en obtenerPromotores: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error al obtener promotores: ' . $e->getMessage(),
                'data' => null
            ];
        }
    }
    
    public function obtenerTiendas() {
        try {
            $sql = "SELECT 
                        id_tienda,
                        nombre_tienda,
                        num_tienda,
                        cadena,
                        ciudad,
                        estado
                    FROM tiendas
                    WHERE estado_reg = 1
                    ORDER BY cadena, num_tienda";
            
            $tiendas = Database::select($sql);
            
            return [
                'success' => true,
                'data' => [
                    'tiendas' => $tiendas
                ],
                'message' => 'Tiendas obtenidas exitosamente'
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error al obtener tiendas: ' . $e->getMessage(),
                'data' => null
            ];
        }
    }
}

// ===== PROCESAR REQUEST =====
try {
    Database::connect();
    
    $api = new IncidenciasAPI();
    
    $metodo = $_SERVER['REQUEST_METHOD'];
    $input = file_get_contents('php://input');
    $datos = json_decode($input, true);
    
    $accion = isset($datos['accion']) ? $datos['accion'] : (isset($_GET['accion']) ? $_GET['accion'] : 'listar');
    
    $respuesta = null;
    
    switch ($accion) {
        case 'listar':
            $filtros = [
                'fecha_inicio' => $datos['fecha_inicio'] ?? null,
                'fecha_fin' => $datos['fecha_fin'] ?? null,
                'id_promotor' => $datos['id_promotor'] ?? null,
                'tipo_incidencia' => $datos['tipo_incidencia'] ?? null,
                'estatus' => $datos['estatus'] ?? null,
                'prioridad' => $datos['prioridad'] ?? null
            ];
            $respuesta = $api->obtenerIncidencias($filtros);
            break;
            
        case 'crear':
            if ($metodo !== 'POST') {
                throw new Exception("Método no permitido");
            }
            
            error_log("📝 Datos a crear: " . json_encode($datos, JSON_UNESCAPED_UNICODE));
            $respuesta = $api->crearIncidencia($datos);
            error_log("📤 Respuesta: " . json_encode($respuesta, JSON_UNESCAPED_UNICODE));
            break;
            
        case 'actualizar':
            if ($metodo !== 'POST' && $metodo !== 'PUT') {
                throw new Exception("Método no permitido");
            }
            if (empty($datos['id_incidencia'])) {
                throw new Exception("ID de incidencia requerido");
            }
            $respuesta = $api->actualizarIncidencia($datos['id_incidencia'], $datos);
            break;
            
        case 'extender':
            if ($metodo !== 'POST') {
                throw new Exception("Método no permitido");
            }
            $respuesta = $api->extenderIncidencia($datos);
            break;
            
        case 'eliminar':
            if ($metodo !== 'POST' && $metodo !== 'DELETE') {
                throw new Exception("Método no permitido");
            }
            if (empty($datos['id_incidencia'])) {
                throw new Exception("ID de incidencia requerido");
            }
            $respuesta = $api->eliminarIncidencia($datos['id_incidencia']);
            break;
            
        case 'obtener':
            if (empty($datos['id_incidencia'])) {
                throw new Exception("ID de incidencia requerido");
            }
            $respuesta = $api->obtenerIncidenciaPorId($datos['id_incidencia']);
            break;
            
        case 'promotores':
            $respuesta = $api->obtenerPromotores();
            break;
            
        case 'tiendas':
            $respuesta = $api->obtenerTiendas();
            break;
            
        default:
            throw new Exception("Acción no válida: {$accion}");
    }
    
    // ✅ CRÍTICO: Limpiar buffer antes de JSON
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    ob_start();
    
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($respuesta, JSON_UNESCAPED_UNICODE);
    ob_end_flush();
    
} catch (Exception $e) {
    error_log("❌ ERROR FATAL: " . $e->getMessage());
    error_log("❌ Trace: " . $e->getTraceAsString());
    
    // ✅ Limpiar TODO el buffer
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    ob_start();
    
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'data' => null,
        'file' => basename($e->getFile()),
        'line' => $e->getLine()
    ], JSON_UNESCAPED_UNICODE);
    
    ob_end_flush();
}
?>