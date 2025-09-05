<?php
/**
 * API de conexión segura a MariaDB
 * Protegida contra SQL injection y ataques comunes
 */

// Prevenir acceso directo
if (!defined('APP_ACCESS')) {
    http_response_code(403);
    die('Acceso directo no permitido');
}

class Database {
    
    // Configuración de conexión
    private static $host = '192.168.0.106';
    private static $port = 3306;
    private static $database = 'promotoria';
    private static $username = 'evo_promotor';        // Cambiar por tu usuario
    private static $password = '!2JLqo?ovGr11pqx';     // Cambiar por tu contraseña
    private static $charset = 'utf8mb4';
    
    // Instancia singleton
    private static $connection = null;
    
    /**
     * Obtener conexión PDO segura
     */
    public static function connect() {
        if (self::$connection === null) {
            try {
                $dsn = "mysql:host=" . self::$host . 
                       ";port=" . self::$port . 
                       ";dbname=" . self::$database . 
                       ";charset=" . self::$charset;
                
                $options = [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_PERSISTENT => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
                    PDO::ATTR_TIMEOUT => 30
                ];
                
                self::$connection = new PDO($dsn, self::$username, self::$password, $options);
                
            } catch (PDOException $e) {
                error_log('Error de conexión DB: ' . $e->getMessage());
                throw new Exception('Error de conexión a la base de datos');
            }
        }
        
        return self::$connection;
    }
    
    /**
     * Ejecutar consulta preparada segura
     */
    public static function query($sql, $params = []) {
        try {
            $pdo = self::connect();
            $stmt = $pdo->prepare($sql);
            
            // Validar parámetros
            $params = self::validateParams($params);
            
            $stmt->execute($params);
            return $stmt;
            
        } catch (PDOException $e) {
            error_log('Error en consulta: ' . $e->getMessage());
            throw new Exception('Error en la consulta');
        }
    }
    
    /**
     * SELECT - Obtener múltiples registros
     */
    public static function select($sql, $params = []) {
        $stmt = self::query($sql, $params);
        return $stmt->fetchAll();
    }
    
    /**
     * SELECT - Obtener un solo registro
     */
    public static function selectOne($sql, $params = []) {
        $stmt = self::query($sql, $params);
        return $stmt->fetch();
    }
    
    /**
     * INSERT - Insertar registro
     */
    public static function insert($sql, $params = []) {
        self::query($sql, $params);
        return self::connect()->lastInsertId();
    }
    
    /**
     * UPDATE/DELETE - Ejecutar y retornar filas afectadas
     */
    public static function execute($sql, $params = []) {
        $stmt = self::query($sql, $params);
        return $stmt->rowCount();
    }
    
    /**
     * Hash de contraseña con SHA256
     */
    public static function hashPassword($password) {
        if (strlen($password) < 6) {
            throw new InvalidArgumentException('Contraseña muy corta');
        }
        return hash('sha256', $password);
    }
    
    /**
     * Verificar contraseña con hash SHA256
     */
    public static function verifyPassword($password, $hash) {
        return hash_equals($hash, hash('sha256', $password));
    }
    
    /**
     * Validar parámetros de entrada
     */
    private static function validateParams($params) {
        $clean = [];
        
        foreach ($params as $key => $value) {
            // Detectar patrones maliciosos
            if (is_string($value) && self::containsMaliciousCode($value)) {
                error_log('Posible ataque detectado: ' . $key);
                throw new Exception('Entrada inválida detectada');
            }
            
            // Limpiar valor
            if (is_string($value)) {
                $value = trim($value);
                $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
            }
            
            $clean[$key] = $value;
        }
        
        return $clean;
    }
    
    /**
     * Detectar código malicioso
     */
    private static function containsMaliciousCode($input) {
        $patterns = [
            '/\b(union|select|insert|update|delete|drop|create|alter)\b/i',
            '/<script[^>]*>/i',
            '/javascript:/i',
            '/on\w+\s*=/i',
            '/\.\.\//i'
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $input)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Sanitizar entrada de texto
     */
    public static function sanitize($input) {
        if (is_array($input)) {
            return array_map([self::class, 'sanitize'], $input);
        }
        
        $input = trim($input);
        $input = stripslashes($input);
        $input = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
        
        return $input;
    }
    
    /**
     * Validar tipos de entrada específicos
     */
    public static function validate($input, $type) {
        switch ($type) {
            case 'email':
                return filter_var($input, FILTER_VALIDATE_EMAIL) !== false;
                
            case 'username':
                return preg_match('/^[a-zA-Z0-9_]{3,50}$/', $input);
                
            case 'password':
                return strlen($input) >= 6 && strlen($input) <= 255;
                
            case 'int':
                return filter_var($input, FILTER_VALIDATE_INT) !== false;
                
            default:
                return !self::containsMaliciousCode($input);
        }
    }
    
    /**
     * Cerrar conexión
     */
    public static function disconnect() {
        self::$connection = null;
    }
}

// Configurar headers de seguridad
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// NOTA: NO definir APP_ACCESS aquí - debe ser definida por los archivos que incluyen este
?>