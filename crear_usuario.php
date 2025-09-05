<?php
/**
 * Página para crear usuarios correctamente
 */

// Mostrar errores
error_reporting(E_ALL);
ini_set('display_errors', 1);

define('APP_ACCESS', true);
require_once 'config/db_connect.php';

$mensaje = '';
$tipo_mensaje = '';

// Procesar formulario
if ($_POST) {
    try {
        $username = trim($_POST['username']);
        $email = trim($_POST['email']);
        $password = $_POST['password'];
        $nombre = trim($_POST['nombre']);
        $apellido = trim($_POST['apellido']);
        $rol = $_POST['rol'];
        
        // Validaciones básicas
        if (empty($username) || empty($email) || empty($password) || empty($nombre) || empty($apellido)) {
            throw new Exception('Todos los campos son obligatorios');
        }
        
        if (strlen($username) < 3 || strlen($username) > 50) {
            throw new Exception('El username debe tener entre 3 y 50 caracteres');
        }
        
        if (strlen($password) < 6) {
            throw new Exception('La contraseña debe tener mínimo 6 caracteres');
        }
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Email inválido');
        }
        
        // Verificar si ya existe
        $existe = Database::selectOne(
            "SELECT id FROM usuarios WHERE username = :username OR email = :email",
            [':username' => $username, ':email' => $email]
        );
        
        if ($existe) {
            throw new Exception('Ya existe un usuario con ese username o email');
        }
        
        // Hashear contraseña usando la función de Database
        $password_hash = Database::hashPassword($password);
        
        // Crear usuario
        $sql = "INSERT INTO usuarios (username, email, password, nombre, apellido, rol, activo, fecha_registro) 
                VALUES (:username, :email, :password, :nombre, :apellido, :rol, 1, NOW())";
        
        $id = Database::insert($sql, [
            ':username' => $username,
            ':email' => $email,
            ':password' => $password_hash,
            ':nombre' => $nombre,
            ':apellido' => $apellido,
            ':rol' => $rol
        ]);
        
        $mensaje = "✅ Usuario creado exitosamente con ID: $id<br><br>
                   <strong>Datos para login:</strong><br>
                   Username: <code>$username</code><br>
                   Password: <code>$password</code><br><br>
                   <a href='index.html' style='background: #28a745; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Ir al Login</a>";
        $tipo_mensaje = 'success';
        
    } catch (Exception $e) {
        $mensaje = "❌ Error: " . $e->getMessage();
        $tipo_mensaje = 'error';
    }
}

// Obtener usuarios existentes
try {
    $usuarios = Database::select("SELECT id, username, email, nombre, apellido, rol, activo FROM usuarios ORDER BY id DESC");
} catch (Exception $e) {
    $usuarios = [];
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crear Usuario - Sistema</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f5f5f5;
            margin: 0;
            padding: 20px;
        }
        
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        h1 {
            color: #333;
            text-align: center;
            margin-bottom: 30px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #555;
        }
        
        input[type="text"], input[type="email"], input[type="password"], select {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 16px;
            box-sizing: border-box;
        }
        
        input:focus, select:focus {
            border-color: #007cba;
            outline: none;
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 4px;
            font-size: 16px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-primary {
            background: #007cba;
            color: white;
        }
        
        .btn-primary:hover {
            background: #005a87;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .mensaje {
            margin-bottom: 20px;
            padding: 15px;
            border-radius: 4px;
        }
        
        .success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .usuarios-existentes {
            margin-top: 40px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        
        th {
            background: #f8f9fa;
            font-weight: bold;
        }
        
        .activo {
            color: #28a745;
            font-weight: bold;
        }
        
        .inactivo {
            color: #dc3545;
            font-weight: bold;
        }
        
        .role-root { color: #dc3545; }
        .role-supervisor { color: #ffc107; }
        .role-usuario { color: #28a745; }
        
        .quick-users {
            background: #e9ecef;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
        }
        
        .quick-user {
            background: white;
            margin: 10px 0;
            padding: 15px;
            border-radius: 5px;
            border-left: 4px solid #007cba;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>👤 Crear Usuario</h1>
        
        <?php if ($mensaje): ?>
            <div class="mensaje <?= $tipo_mensaje ?>">
                <?= $mensaje ?>
            </div>
        <?php endif; ?>
        
        <!-- Usuarios rápidos sugeridos -->
        <div class="quick-users">
            <h3>🚀 Usuarios Rápidos Sugeridos</h3>
            <p>Haz clic en cualquiera para auto-completar el formulario:</p>
            
            <div class="quick-user" onclick="fillForm('admin', 'admin@test.com', '123456', 'Admin', 'Sistema', 'root')">
                <strong>admin</strong> / 123456 (Root) - Administrador del sistema
            </div>
            
            <div class="quick-user" onclick="fillForm('test', 'test@test.com', '123456', 'Usuario', 'Prueba', 'usuario')">
                <strong>test</strong> / 123456 (Usuario) - Usuario de prueba
            </div>
            
            <div class="quick-user" onclick="fillForm('supervisor', 'supervisor@test.com', '123456', 'Super', 'Visor', 'supervisor')">
                <strong>supervisor</strong> / 123456 (Supervisor) - Usuario supervisor
            </div>
        </div>

        <form method="POST">
            <div class="form-group">
                <label for="username">Username:</label>
                <input type="text" id="username" name="username" required 
                       value="<?= $_POST['username'] ?? '' ?>"
                       placeholder="Ej: admin, test, usuario123">
            </div>

            <div class="form-group">
                <label for="email">Email:</label>
                <input type="email" id="email" name="email" required 
                       value="<?= $_POST['email'] ?? '' ?>"
                       placeholder="usuario@ejemplo.com">
            </div>

            <div class="form-group">
                <label for="password">Contraseña:</label>
                <input type="password" id="password" name="password" required 
                       value="<?= $_POST['password'] ?? '' ?>"
                       placeholder="Mínimo 6 caracteres">
            </div>

            <div class="form-group">
                <label for="nombre">Nombre:</label>
                <input type="text" id="nombre" name="nombre" required 
                       value="<?= $_POST['nombre'] ?? '' ?>"
                       placeholder="Nombre del usuario">
            </div>

            <div class="form-group">
                <label for="apellido">Apellido:</label>
                <input type="text" id="apellido" name="apellido" required 
                       value="<?= $_POST['apellido'] ?? '' ?>"
                       placeholder="Apellido del usuario">
            </div>

            <div class="form-group">
                <label for="rol">Rol:</label>
                <select id="rol" name="rol" required>
                    <option value="">Seleccionar rol...</option>
                    <option value="usuario" <?= ($_POST['rol'] ?? '') == 'usuario' ? 'selected' : '' ?>>
                        Usuario - Acceso básico
                    </option>
                    <option value="supervisor" <?= ($_POST['rol'] ?? '') == 'supervisor' ? 'selected' : '' ?>>
                        Supervisor - Acceso intermedio
                    </option>
                    <option value="root" <?= ($_POST['rol'] ?? '') == 'root' ? 'selected' : '' ?>>
                        Root - Acceso total
                    </option>
                </select>
            </div>

            <button type="submit" class="btn btn-primary">Crear Usuario</button>
            <a href="index.html" class="btn btn-secondary">Volver al Login</a>
        </form>

        <!-- Usuarios existentes -->
        <div class="usuarios-existentes">
            <h3>👥 Usuarios Existentes (<?= count($usuarios) ?>)</h3>
            
            <?php if (empty($usuarios)): ?>
                <p>No hay usuarios en la base de datos.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Nombre</th>
                            <th>Rol</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($usuarios as $user): ?>
                            <tr>
                                <td><?= $user['id'] ?></td>
                                <td><strong><?= htmlspecialchars($user['username']) ?></strong></td>
                                <td><?= htmlspecialchars($user['email']) ?></td>
                                <td><?= htmlspecialchars($user['nombre'] . ' ' . $user['apellido']) ?></td>
                                <td><span class="role-<?= $user['rol'] ?>"><?= $user['rol'] ?></span></td>
                                <td>
                                    <span class="<?= $user['activo'] ? 'activo' : 'inactivo' ?>">
                                        <?= $user['activo'] ? 'Activo' : 'Inactivo' ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function fillForm(username, email, password, nombre, apellido, rol) {
            document.getElementById('username').value = username;
            document.getElementById('email').value = email;
            document.getElementById('password').value = password;
            document.getElementById('nombre').value = nombre;
            document.getElementById('apellido').value = apellido;
            document.getElementById('rol').value = rol;
        }
    </script>
</body>
</html>