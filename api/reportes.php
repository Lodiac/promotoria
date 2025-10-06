<?php
session_start();
define('APP_ACCESS', true);
require_once __DIR__ . '/../config/db_connect.php';

/**
 * REPORTE CORREGIDO PARA MÚLTIPLES TIENDAS CON DÍA DE DESCANSO
 */
class ReporteMultiplesTiendasCorregido {
    
    private static $pdo = null;
    
    private static function getConnection() {
        if (self::$pdo !== null) {
            return self::$pdo;
        }
        
        try {
            $host = '192.168.0.105';
            $dbname = 'promotoria';
            $username = 'evo_promotor';
            $password = '!2JLqo?ovGr11pqx';
            
            self::$pdo = new PDO(
                "mysql:host=$host;dbname=$dbname;charset=utf8",
                $username,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
            
            return self::$pdo;
            
        } catch (PDOException $e) {
            throw new Exception('Error de conexión: ' . $e->getMessage());
        }
    }
    
    private static function selectAll($sql, $params = []) {
        $pdo = self::getConnection();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    private static function selectOne($sql, $params = []) {
        $pdo = self::getConnection();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    }
    
    /**
     * CONVERTIR NÚMERO DE DÍA A NOMBRE EN ESPAÑOL
     */
    private static function convertirDiaDescansoANombre($dia_numero) {
        if ($dia_numero === null) return null;
        
        $dias = [
            '1' => 'lunes',
            '2' => 'martes',
            '3' => 'miércoles',
            '4' => 'jueves',
            '5' => 'viernes',
            '6' => 'sábado',
            '7' => 'domingo'
        ];
        
        return $dias[$dia_numero] ?? null;
    }
    
    /**
     * MÉTODO PRINCIPAL - Generar reporte de asignaciones CORREGIDO
     */
    public static function generarReporteAsignaciones($fecha_inicio, $fecha_fin, $filtros = []) {
        // Validación de fechas
        if (!$fecha_inicio || !$fecha_fin) {
            throw new Exception('Las fechas de inicio y fin son obligatorias');
        }
        
        if (!DateTime::createFromFormat('Y-m-d', $fecha_inicio) || !DateTime::createFromFormat('Y-m-d', $fecha_fin)) {
            throw new Exception('Formato de fecha inválido. Use YYYY-MM-DD');
        }
        
        if (strtotime($fecha_fin) < strtotime($fecha_inicio)) {
            throw new Exception('La fecha fin no puede ser anterior a la fecha inicio');
        }
        
        error_log("=== REPORTE MÚLTIPLES TIENDAS CON DÍA DESCANSO ===");
        error_log("Fechas solicitadas: $fecha_inicio al $fecha_fin");
        error_log("Filtros: " . json_encode($filtros));
        
        // PASO 1: Verificar qué datos existen realmente
        $estadoBD = self::verificarEstadoBaseDatos();
        error_log("Estado BD: " . json_encode($estadoBD));
        
        if ($estadoBD['total_asignaciones'] == 0) {
            error_log("❌ No hay asignaciones en la base de datos");
            return [
                'asignaciones' => [],
                'estadisticas' => [
                    'total_tiendas' => 0,
                    'total_promotores' => 0, 
                    'total_asignaciones' => 0,
                    'dias_periodo' => self::calcularDiasPeriodo($fecha_inicio, $fecha_fin)
                ],
                'debug_info' => $estadoBD,
                'estrategia_usada' => 'No hay datos en la base de datos'
            ];
        }
        
        // PASO 2: Intentar consulta optimizada para múltiples tiendas
        try {
            $resultado = self::consultaMultiplesTiendas($fecha_inicio, $fecha_fin, $filtros);
            if (!empty($resultado)) {
                error_log("✅ Consulta múltiples tiendas exitosa: " . count($resultado) . " registros");
                return self::procesarResultado($resultado, $fecha_inicio, $fecha_fin, 'Consulta múltiples tiendas', $estadoBD);
            }
        } catch (Exception $e) {
            error_log("⚠️ Consulta múltiples tiendas falló: " . $e->getMessage());
        }
        
        // PASO 3: Consulta amplia (menos restrictiva)
        try {
            $resultado = self::consultaAmplia($fecha_inicio, $fecha_fin, $filtros);
            if (!empty($resultado)) {
                error_log("✅ Consulta amplia exitosa: " . count($resultado) . " registros");
                return self::procesarResultado($resultado, $fecha_inicio, $fecha_fin, 'Consulta amplia', $estadoBD);
            }
        } catch (Exception $e) {
            error_log("⚠️ Consulta amplia falló: " . $e->getMessage());
        }
        
        // PASO 4: Consulta de todas las asignaciones recientes
        try {
            $resultado = self::consultaTodasLasAsignaciones($filtros);
            if (!empty($resultado)) {
                error_log("✅ Consulta de todas las asignaciones exitosa: " . count($resultado) . " registros");
                $resultado_filtrado = self::filtrarPorFechas($resultado, $fecha_inicio, $fecha_fin);
                return self::procesarResultado($resultado_filtrado, $fecha_inicio, $fecha_fin, 'Consulta todas asignaciones filtrada', $estadoBD);
            }
        } catch (Exception $e) {
            error_log("⚠️ Consulta de todas las asignaciones falló: " . $e->getMessage());
        }
        
        // PASO 5: Si todo falla, devolver datos de diagnóstico
        error_log("❌ Todas las consultas fallaron");
        return [
            'asignaciones' => [],
            'estadisticas' => [
                'total_tiendas' => 0,
                'total_promotores' => 0,
                'total_asignaciones' => 0,
                'dias_periodo' => self::calcularDiasPeriodo($fecha_inicio, $fecha_fin)
            ],
            'debug_info' => $estadoBD,
            'estrategia_usada' => 'Todas las estrategias fallaron'
        ];
    }
    
    /**
     * CONSULTA OPTIMIZADA PARA MÚLTIPLES TIENDAS CON DÍA DESCANSO
     */
    private static function consultaMultiplesTiendas($fecha_inicio, $fecha_fin, $filtros) {
        $sql = "
            SELECT DISTINCT
                t.id_tienda,
                t.nombre_tienda,
                COALESCE(t.num_tienda, 'S/N') as num_tienda,
                COALESCE(t.region, 0) as region,
                COALESCE(t.ciudad, 'No especificada') as ciudad,
                COALESCE(t.estado, 'No especificado') as estado,
                COALESCE(t.cadena, 'No especificada') as cadena,
                p.id_promotor,
                CONCAT(COALESCE(p.nombre, ''), ' ', COALESCE(p.apellido, '')) as promotor_nombre,
                COALESCE(p.rfc, 'Sin RFC') as rfc,
                COALESCE(p.telefono, 'Sin teléfono') as telefono,
                COALESCE(p.correo, 'Sin correo') as correo,
                COALESCE(p.region, 0) as promotor_region,
                COALESCE(p.estatus, 'Activo') as estatus,
                p.dia_descanso,
                p.clave_asistencia,
                pta.fecha_inicio,
                pta.fecha_fin,
                pta.activo
            FROM promotor_tienda_asignaciones pta
            INNER JOIN tiendas t ON t.id_tienda = pta.id_tienda
            INNER JOIN promotores p ON p.id_promotor = pta.id_promotor
            WHERE pta.activo = 1
            AND t.estado_reg = 1
            AND p.estado = 1
            AND (
                (pta.fecha_inicio <= ? AND (pta.fecha_fin IS NULL OR pta.fecha_fin >= ?))
                OR (pta.fecha_inicio BETWEEN ? AND ?)
                OR (pta.fecha_fin BETWEEN ? AND ?)
                OR (? BETWEEN pta.fecha_inicio AND COALESCE(pta.fecha_fin, ?))
                OR (pta.fecha_inicio <= DATE_ADD(?, INTERVAL 30 DAY) AND pta.fecha_inicio >= DATE_SUB(?, INTERVAL 30 DAY))
            )
        ";
        
        $params = [
            $fecha_fin, $fecha_inicio, 
            $fecha_inicio, $fecha_fin,
            $fecha_inicio, $fecha_fin, 
            $fecha_inicio, $fecha_fin,
            $fecha_fin, $fecha_inicio
        ];
        
        // Aplicar filtros adicionales
        if (!empty($filtros['promotor'])) {
            $sql .= " AND p.id_promotor = ?";
            $params[] = (int) $filtros['promotor'];
        }
        
        if (!empty($filtros['tienda'])) {
            $sql .= " AND t.id_tienda = ?";  
            $params[] = (int) $filtros['tienda'];
        }
        
        $sql .= " ORDER BY t.nombre_tienda ASC, p.nombre ASC, pta.fecha_inicio ASC";
        
        error_log("Consulta múltiples tiendas SQL: " . $sql);
        error_log("Parámetros: " . json_encode($params));
        
        return self::selectAll($sql, $params);
    }
    
    /**
     * CONSULTA AMPLIA - Menos restrictiva con fechas
     */
    private static function consultaAmplia($fecha_inicio, $fecha_fin, $filtros) {
        $sql = "
            SELECT DISTINCT
                t.id_tienda,
                t.nombre_tienda,
                COALESCE(t.num_tienda, 'S/N') as num_tienda,
                COALESCE(t.region, 0) as region,
                COALESCE(t.ciudad, 'No especificada') as ciudad,
                COALESCE(t.estado, 'No especificado') as estado,
                COALESCE(t.cadena, 'No especificada') as cadena,
                p.id_promotor,
                CONCAT(COALESCE(p.nombre, ''), ' ', COALESCE(p.apellido, '')) as promotor_nombre,
                COALESCE(p.rfc, 'Sin RFC') as rfc,
                COALESCE(p.telefono, 'Sin teléfono') as telefono,
                COALESCE(p.correo, 'Sin correo') as correo,
                COALESCE(p.region, 0) as promotor_region,
                COALESCE(p.estatus, 'Activo') as estatus,
                p.dia_descanso,
                p.clave_asistencia,
                pta.fecha_inicio,
                pta.fecha_fin,
                pta.activo
            FROM promotor_tienda_asignaciones pta
            INNER JOIN tiendas t ON t.id_tienda = pta.id_tienda
            INNER JOIN promotores p ON p.id_promotor = pta.id_promotor
            WHERE t.estado_reg = 1
            AND p.estado = 1
            AND pta.fecha_inicio <= DATE_ADD(?, INTERVAL 60 DAY)
            AND pta.fecha_inicio >= DATE_SUB(?, INTERVAL 60 DAY)
        ";
        
        $params = [$fecha_fin, $fecha_inicio];
        
        // Aplicar filtros adicionales
        if (!empty($filtros['promotor'])) {
            $sql .= " AND p.id_promotor = ?";
            $params[] = (int) $filtros['promotor'];
        }
        
        if (!empty($filtros['tienda'])) {
            $sql .= " AND t.id_tienda = ?";
            $params[] = (int) $filtros['tienda'];
        }
        
        $sql .= " ORDER BY pta.fecha_inicio DESC, t.nombre_tienda ASC";
        
        error_log("Consulta amplia SQL: " . $sql);
        
        return self::selectAll($sql, $params);
    }
    
    /**
     * CONSULTA DE TODAS LAS ASIGNACIONES - Backup completo
     */
    private static function consultaTodasLasAsignaciones($filtros) {
        $sql = "
            SELECT DISTINCT
                t.id_tienda,
                t.nombre_tienda,
                COALESCE(t.num_tienda, 'S/N') as num_tienda,
                COALESCE(t.region, 0) as region,
                COALESCE(t.ciudad, 'No especificada') as ciudad,
                COALESCE(t.estado, 'No especificado') as estado,
                COALESCE(t.cadena, 'No especificada') as cadena,
                p.id_promotor,
                CONCAT(COALESCE(p.nombre, ''), ' ', COALESCE(p.apellido, '')) as promotor_nombre,
                COALESCE(p.rfc, 'Sin RFC') as rfc,
                COALESCE(p.telefono, 'Sin teléfono') as telefono,
                COALESCE(p.correo, 'Sin correo') as correo,
                COALESCE(p.region, 0) as promotor_region,
                COALESCE(p.estatus, 'Activo') as estatus,
                p.dia_descanso,
                p.clave_asistencia,
                pta.fecha_inicio,
                pta.fecha_fin,
                pta.activo
            FROM promotor_tienda_asignaciones pta
            INNER JOIN tiendas t ON t.id_tienda = pta.id_tienda
            INNER JOIN promotores p ON p.id_promotor = pta.id_promotor
            WHERE t.estado_reg = 1
            AND p.estado = 1
        ";
        
        $params = [];
        
        // Aplicar filtros adicionales
        if (!empty($filtros['promotor'])) {
            $sql .= " AND p.id_promotor = ?";
            $params[] = (int) $filtros['promotor'];
        }
        
        if (!empty($filtros['tienda'])) {
            $sql .= " AND t.id_tienda = ?";
            $params[] = (int) $filtros['tienda'];
        }
        
        $sql .= " ORDER BY pta.fecha_inicio DESC LIMIT 500";
        
        error_log("Consulta todas asignaciones SQL: " . $sql);
        
        return self::selectAll($sql, $params);
    }
    
    /**
     * VERIFICAR ESTADO DE LA BASE DE DATOS
     */
    private static function verificarEstadoBaseDatos() {
        try {
            // Contar registros principales
            $stats = [
                'total_tiendas' => self::selectOne("SELECT COUNT(*) as total FROM tiendas WHERE estado_reg = 1")['total'],
                'total_promotores' => self::selectOne("SELECT COUNT(*) as total FROM promotores WHERE estado = 1")['total'],
                'total_asignaciones' => self::selectOne("SELECT COUNT(*) as total FROM promotor_tienda_asignaciones")['total']
            ];
            
            // Ver rango de fechas en asignaciones
            $rango_fechas = self::selectOne("
                SELECT 
                    MIN(fecha_inicio) as fecha_min,
                    MAX(fecha_inicio) as fecha_max,
                    COUNT(DISTINCT id_tienda) as tiendas_con_asignaciones,
                    COUNT(DISTINCT id_promotor) as promotores_con_asignaciones
                FROM promotor_tienda_asignaciones
                WHERE activo = 1
            ");
            
            // Obtener muestra de tiendas disponibles
            $tiendas_muestra = self::selectAll("
                SELECT t.id_tienda, t.nombre_tienda, t.num_tienda 
                FROM tiendas t 
                WHERE t.estado_reg = 1 
                ORDER BY t.nombre_tienda 
                LIMIT 10
            ");
            
            return array_merge($stats, $rango_fechas ?: [], ['tiendas_muestra' => $tiendas_muestra]);
            
        } catch (Exception $e) {
            error_log("Error verificando estado BD: " . $e->getMessage());
            return ['error' => $e->getMessage()];
        }
    }
    
    /**
     * FILTRAR RESULTADOS POR FECHAS MANUALMENTE
     */
    private static function filtrarPorFechas($asignaciones, $fecha_inicio, $fecha_fin) {
        $resultado = [];
        $fecha_inicio_obj = new DateTime($fecha_inicio);
        $fecha_fin_obj = new DateTime($fecha_fin);
        
        foreach ($asignaciones as $asignacion) {
            $asignacion_inicio = new DateTime($asignacion['fecha_inicio']);
            $asignacion_fin = $asignacion['fecha_fin'] ? new DateTime($asignacion['fecha_fin']) : null;
            
            // Verificar solapamiento con el rango solicitado
            if ($asignacion_inicio <= $fecha_fin_obj && 
                ($asignacion_fin === null || $asignacion_fin >= $fecha_inicio_obj)) {
                $resultado[] = $asignacion;
            }
        }
        
        return $resultado;
    }
    
    /**
     * PROCESAR RESULTADO FINAL
     */
    private static function procesarResultado($asignaciones, $fecha_inicio, $fecha_fin, $estrategia, $estadoBD) {
        if (empty($asignaciones)) {
            return [
                'asignaciones' => [],
                'estadisticas' => [
                    'total_tiendas' => 0,
                    'total_promotores' => 0,
                    'total_asignaciones' => 0,
                    'dias_periodo' => self::calcularDiasPeriodo($fecha_inicio, $fecha_fin)
                ],
                'debug_info' => $estadoBD,
                'estrategia_usada' => $estrategia . ' (sin resultados)'
            ];
        }
        
        // Expandir asignaciones a días individuales
        $asignaciones_expandidas = self::expandirAsignacionesADias($asignaciones, $fecha_inicio, $fecha_fin);
        
        // Calcular estadísticas
        $estadisticas = self::calcularEstadisticas($asignaciones_expandidas, $fecha_inicio, $fecha_fin);
        
        error_log("Resultado procesado: " . count($asignaciones_expandidas) . " días, " . 
                 $estadisticas['total_tiendas'] . " tiendas, " . 
                 $estadisticas['total_promotores'] . " promotores");
        
        return [
            'asignaciones' => $asignaciones_expandidas,
            'estadisticas' => $estadisticas,
            'debug_info' => $estadoBD,
            'estrategia_usada' => $estrategia,
            'asignaciones_brutas' => count($asignaciones)
        ];
    }
    
    /**
     * EXPANDIR ASIGNACIONES A DÍAS INDIVIDUALES - CON DÍA DESCANSO
     */
    private static function expandirAsignacionesADias($asignaciones, $fecha_inicio_consulta, $fecha_fin_consulta) {
        $resultado = [];
        
        foreach ($asignaciones as $asignacion) {
            // Determinar rango efectivo de la asignación
            $inicio_efectivo = max($asignacion['fecha_inicio'], $fecha_inicio_consulta);
            $fin_efectivo = min(
                $asignacion['fecha_fin'] ?? $fecha_fin_consulta,
                $fecha_fin_consulta
            );
            
            $fecha_actual = new DateTime($inicio_efectivo);
            $fecha_limite = new DateTime($fin_efectivo);
            
            // Asegurar que no se genere un loop infinito
            $dias_procesados = 0;
            $max_dias = 365; // Límite de seguridad
            
            // Generar un registro por cada día
            while ($fecha_actual <= $fecha_limite && $dias_procesados < $max_dias) {
                $claves_array = [];
                if (!empty($asignacion['clave_asistencia'])) {
                    $claves_decoded = json_decode($asignacion['clave_asistencia'], true);
                    if (is_array($claves_decoded)) {
                        $claves_array = array_values($claves_decoded);
                    } else {
                        $claves_array = [$asignacion['clave_asistencia']];
                    }
                }
                
                // Convertir día de descanso numérico a nombre
                $dia_descanso_nombre = self::convertirDiaDescansoANombre($asignacion['dia_descanso']);
                
                $resultado[] = [
                    'fecha' => $fecha_actual->format('Y-m-d'),
                    'dia_semana' => self::getDiaSemanaEspanol($fecha_actual->format('w')),
                    'tienda_id' => (int) $asignacion['id_tienda'],
                    'tienda_nombre' => $asignacion['nombre_tienda'],
                    'tienda_numero' => $asignacion['num_tienda'],
                    'tienda_region' => (int) $asignacion['region'],
                    'tienda_cadena' => $asignacion['cadena'],
                    'tienda_ciudad' => $asignacion['ciudad'],
                    'tienda_estado' => $asignacion['estado'],
                    'promotor_id' => (int) $asignacion['id_promotor'],
                    'promotor_nombre' => trim($asignacion['promotor_nombre']),
                    'promotor_rfc' => $asignacion['rfc'],
                    'promotor_telefono' => $asignacion['telefono'],
                    'promotor_correo' => $asignacion['correo'],
                    'promotor_region' => (int) $asignacion['promotor_region'],
                    'promotor_estatus' => $asignacion['estatus'],
                    'promotor_dia_descanso' => $dia_descanso_nombre,
                    'claves' => $claves_array
                ];
                
                $fecha_actual->add(new DateInterval('P1D'));
                $dias_procesados++;
            }
        }
        
        // Eliminar duplicados por fecha, tienda y promotor
        $resultado_unico = [];
        $claves_vistas = [];
        
        foreach ($resultado as $registro) {
            $clave = $registro['fecha'] . '_' . $registro['tienda_id'] . '_' . $registro['promotor_id'];
            if (!isset($claves_vistas[$clave])) {
                $claves_vistas[$clave] = true;
                $resultado_unico[] = $registro;
            }
        }
        
        return $resultado_unico;
    }
    
    /**
     * CALCULAR ESTADÍSTICAS
     */
    private static function calcularEstadisticas($asignaciones_expandidas, $fecha_inicio, $fecha_fin) {
        return [
            'total_tiendas' => count(array_unique(array_column($asignaciones_expandidas, 'tienda_id'))),
            'total_promotores' => count(array_unique(array_column($asignaciones_expandidas, 'promotor_id'))),
            'total_asignaciones' => count($asignaciones_expandidas),
            'dias_periodo' => self::calcularDiasPeriodo($fecha_inicio, $fecha_fin)
        ];
    }
    
    /**
     * CALCULAR DÍAS ENTRE FECHAS
     */
    private static function calcularDiasPeriodo($fecha_inicio, $fecha_fin) {
        $inicio = new DateTime($fecha_inicio);
        $fin = new DateTime($fecha_fin);
        return $fin->diff($inicio)->days + 1;
    }
    
    /**
     * CONVERTIR DÍA NUMÉRICO A ESPAÑOL
     */
    private static function getDiaSemanaEspanol($dia_numero) {
        $dias = [
            '0' => 'Domingo', '1' => 'Lunes', '2' => 'Martes', '3' => 'Miércoles',
            '4' => 'Jueves', '5' => 'Viernes', '6' => 'Sábado'
        ];
        return $dias[$dia_numero] ?? 'Desconocido';
    }
    
    /**
     * GENERAR LISTA DE TIENDAS
     */
    public static function generarReporteTiendas() {
        try {
            $sql = "
                SELECT 
                    id_tienda as id, 
                    nombre_tienda as nombre, 
                    num_tienda as numero, 
                    region,
                    ciudad,
                    estado,
                    cadena
                FROM tiendas 
                WHERE estado_reg = 1
                ORDER BY nombre_tienda ASC
            ";
            
            $tiendas = self::selectAll($sql);
            
            return [
                'tiendas' => $tiendas,
                'total' => count($tiendas)
            ];
            
        } catch (Exception $e) {
            throw new Exception("Error al consultar tiendas: " . $e->getMessage());
        }
    }
    
    /**
     * GENERAR LISTA DE PROMOTORES CON DÍA DESCANSO
     */
    public static function generarReportePromotores() {
        try {
            $sql = "
                SELECT 
                    id_promotor,
                    CONCAT(COALESCE(nombre, ''), ' ', COALESCE(apellido, '')) as nombre_completo,
                    nombre,
                    apellido,
                    rfc,
                    telefono,
                    correo,
                    estatus,
                    dia_descanso
                FROM promotores 
                WHERE estado = 1
                ORDER BY nombre ASC, apellido ASC
            ";
            
            $promotores = self::selectAll($sql);
            
            // Convertir día descanso numérico a nombre
            foreach ($promotores as &$promotor) {
                $promotor['dia_descanso_nombre'] = self::convertirDiaDescansoANombre($promotor['dia_descanso']);
            }
            
            return [
                'promotores' => $promotores,
                'total' => count($promotores)
            ];
            
        } catch (Exception $e) {
            throw new Exception("Error al consultar promotores: " . $e->getMessage());
        }
    }
}

// Configurar headers de respuesta
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// Endpoint principal
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Leer datos de entrada
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        
        if (!$input) {
            throw new Exception('No se recibieron datos en la solicitud');
        }
        
        $tipo_reporte = $input['tipo_reporte'] ?? 'asignaciones';
        error_log("Procesando reporte tipo: $tipo_reporte");
        
        switch ($tipo_reporte) {
            case 'asignaciones':
                // Validar fechas requeridas
                if (empty($input['fecha_inicio']) || empty($input['fecha_fin'])) {
                    throw new Exception('Las fechas de inicio y fin son obligatorias');
                }
                
                $fecha_inicio = trim($input['fecha_inicio']);
                $fecha_fin = trim($input['fecha_fin']);
                
                // Procesar filtros opcionales
                $filtros = [];
                if (!empty($input['filtro_promotor']) && is_numeric($input['filtro_promotor'])) {
                    $filtros['promotor'] = (int) $input['filtro_promotor'];
                }
                if (!empty($input['filtro_tienda']) && is_numeric($input['filtro_tienda'])) {
                    $filtros['tienda'] = (int) $input['filtro_tienda'];
                }
                
                $data = ReporteMultiplesTiendasCorregido::generarReporteAsignaciones($fecha_inicio, $fecha_fin, $filtros);
                break;
                
            case 'promotores':
                $data = ReporteMultiplesTiendasCorregido::generarReportePromotores();
                break;
                
            case 'tiendas':
                $data = ReporteMultiplesTiendasCorregido::generarReporteTiendas();
                break;
                
            default:
                throw new Exception("Tipo de reporte no válido: $tipo_reporte");
        }
        
        // Respuesta exitosa
        echo json_encode([
            'success' => true,
            'data' => $data,
            'message' => 'Reporte generado exitosamente',
            'timestamp' => date('Y-m-d H:i:s')
        ]);

    } catch (Exception $e) {
        error_log("Error en API de reportes: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
} else {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Método no permitido. Use POST.',
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}
?>