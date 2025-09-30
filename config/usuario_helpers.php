<?php
/**
 * FUNCIONES HELPER PARA USUARIO_ASIGNO
 * 
 * Incluir estas funciones en tus archivos PHP existentes
 * o crear un archivo helpers/usuario_asigno_helpers.php
 */

/**
 * Obtener ID del usuario actual de la sesión
 * Usar en lugar de hardcodear usuario_asigno = 1
 */
function getCurrentUserId() {
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        throw new Exception('No hay sesión de usuario activa');
    }
    return intval($_SESSION['user_id']);
}

/**
 * Verificar si el usuario tiene permisos para asignar claves
 */
function canAssignKeys($rol = null) {
    if ($rol === null) {
        $rol = $_SESSION['rol'] ?? null;
    }
    
    return in_array($rol, ['root', 'supervisor']);
}

/**
 * Obtener información completa del usuario que asignó una clave
 */
function getUsuarioAsignoInfo($usuario_id) {
    if (!$usuario_id) {
        return null;
    }
    
    try {
        $sql = "SELECT 
                    id,
                    username,
                    CONCAT(nombre, ' ', apellido) as nombre_completo,
                    nombre,
                    apellido,
                    rol,
                    email,
                    fecha_registro
                FROM usuarios 
                WHERE id = :id AND activo = 1 
                LIMIT 1";
        
        $usuario = Database::selectOne($sql, [':id' => $usuario_id]);
        
        if ($usuario) {
            return [
                'id' => intval($usuario['id']),
                'username' => $usuario['username'],
                'nombre_completo' => $usuario['nombre_completo'],
                'nombre' => $usuario['nombre'],
                'apellido' => $usuario['apellido'],
                'rol' => $usuario['rol'],
                'rol_formatted' => ucfirst($usuario['rol']),
                'email' => $usuario['email'],
                'fecha_registro' => $usuario['fecha_registro'],
                'fecha_registro_formatted' => date('d/m/Y', strtotime($usuario['fecha_registro']))
            ];
        }
        
        return null;
        
    } catch (Exception $e) {
        error_log("Error obteniendo información de usuario asigno: " . $e->getMessage());
        return null;
    }
}

/**
 * Obtener usuario por defecto para asignaciones automáticas
 * (Para procesos que no tienen sesión activa)
 */
function getDefaultAssignUser() {
    try {
        $sql = "SELECT id 
                FROM usuarios 
                WHERE activo = 1 
                AND rol IN ('root', 'supervisor') 
                ORDER BY 
                    CASE rol 
                        WHEN 'root' THEN 1 
                        WHEN 'supervisor' THEN 2 
                        ELSE 3 
                    END,
                    fecha_registro ASC
                LIMIT 1";
        
        $usuario = Database::selectOne($sql, []);
        
        if ($usuario) {
            return intval($usuario['id']);
        }
        
        // Si no hay usuario ROOT/SUPERVISOR, crear uno
        $password_hash = hash('sha256', 'admin123');
        $sql_create = "INSERT INTO usuarios (username, email, password, nombre, apellido, rol, activo) 
                      VALUES ('system', 'system@sistema.com', ?, 'Sistema', 'Automatico', 'root', 1)";
        
        $new_id = Database::insert($sql_create, [$password_hash]);
        
        if ($new_id) {
            error_log("Usuario sistema creado automáticamente con ID: {$new_id}");
            return $new_id;
        }
        
        throw new Exception('No se pudo obtener o crear usuario por defecto');
        
    } catch (Exception $e) {
        error_log("Error obteniendo usuario por defecto: " . $e->getMessage());
        throw $e;
    }
}

/**
 * Validar y obtener usuario para asignación
 * Prioriza sesión actual, fallback a usuario por defecto
 */
function getAssignUserId($require_session = true) {
    try {
        // Intentar obtener de sesión actual
        if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
            $user_id = getCurrentUserId();
            
            // Verificar que el usuario tiene permisos
            if (canAssignKeys()) {
                return $user_id;
            } else {
                if ($require_session) {
                    throw new Exception('Usuario sin permisos para asignar claves');
                }
                // Fallback a usuario por defecto si no se requiere sesión
                return getDefaultAssignUser();
            }
        } else {
            if ($require_session) {
                throw new Exception('Se requiere sesión activa para asignar claves');
            }
            // Usar usuario por defecto para procesos automáticos
            return getDefaultAssignUser();
        }
        
    } catch (Exception $e) {
        error_log("Error obteniendo usuario para asignación: " . $e->getMessage());
        throw $e;
    }
}

/**
 * Agregar información de usuario a respuestas de API
 */
function addUsuarioAsignoToResponse($data, $usuario_asigno_field = 'usuario_asigno') {
    if (!is_array($data)) {
        return $data;
    }
    
    // Si es un array de elementos
    if (isset($data[0]) && is_array($data[0])) {
        foreach ($data as &$item) {
            if (isset($item[$usuario_asigno_field]) && $item[$usuario_asigno_field]) {
                $usuario_info = getUsuarioAsignoInfo($item[$usuario_asigno_field]);
                $item['usuario_asigno_info'] = $usuario_info;
                $item['asignado_por'] = $usuario_info ? $usuario_info['nombre_completo'] : 'Usuario no encontrado';
                $item['asignado_por_username'] = $usuario_info ? $usuario_info['username'] : null;
                $item['asignado_por_rol'] = $usuario_info ? $usuario_info['rol_formatted'] : null;
            }
        }
    } else {
        // Si es un elemento único
        if (isset($data[$usuario_asigno_field]) && $data[$usuario_asigno_field]) {
            $usuario_info = getUsuarioAsignoInfo($data[$usuario_asigno_field]);
            $data['usuario_asigno_info'] = $usuario_info;
            $data['asignado_por'] = $usuario_info ? $usuario_info['nombre_completo'] : 'Usuario no encontrado';
            $data['asignado_por_username'] = $usuario_info ? $usuario_info['username'] : null;
            $data['asignado_por_rol'] = $usuario_info ? $usuario_info['rol_formatted'] : null;
        }
    }
    
    return $data;
}

/**
 * Log de actividad de asignación con usuario
 */
function logClaveAssignment($accion, $codigo_clave, $id_promotor, $detalles = null) {
    try {
        $usuario_id = getCurrentUserId();
        $usuario_info = getUsuarioAsignoInfo($usuario_id);
        $username = $usuario_info ? $usuario_info['username'] : 'unknown';
        
        $log_message = "CLAVE_{$accion}: {$codigo_clave} - Promotor: {$id_promotor} - Usuario: {$username} (ID: {$usuario_id})";
        
        if ($detalles) {
            $log_message .= " - Detalles: {$detalles}";
        }
        
        error_log($log_message);
        
        // También registrar en base de datos si tienes tabla de logs
        /*
        $sql_log = "INSERT INTO log_actividades (tabla, accion, id_registro, usuario_id, fecha, detalles) 
                   VALUES ('claves_tienda', :accion, :codigo_clave, :usuario_id, NOW(), :detalles)";
        
        Database::insert($sql_log, [
            ':accion' => $accion,
            ':codigo_clave' => $codigo_clave,
            ':usuario_id' => $usuario_id,
            ':detalles' => $log_message
        ]);
        */
        
    } catch (Exception $e) {
        error_log("Error registrando log de asignación: " . $e->getMessage());
    }
}

?>