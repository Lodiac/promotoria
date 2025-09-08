<?php
header("Content-Type: application/json; charset=utf-8");
define("APP_ACCESS", true);
require_once __DIR__ . "/../config/db_connect.php";

$response = ["success" => false, "message" => ""];

try {
    $conn = Database::connect(); // ✅ ahora sí tenemos la conexión

    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        $id = $_POST["id"] ?? null;

        if (!$id) {
            throw new Exception("❌ ID de promotor no proporcionado.");
        }

        $sql = "DELETE FROM promotores WHERE id_promotor = :id";
        $stmt = $conn->prepare($sql);

        if ($stmt->execute([":id" => $id])) {
            if ($stmt->rowCount() > 0) {
                $response["success"] = true;
                $response["message"] = "🗑️ Promotor eliminado correctamente.";
            } else {
                $response["message"] = "⚠️ No se encontró el promotor con ID $id.";
            }
        } else {
            throw new Exception("Error al eliminar promotor.");
        }
    } else {
        throw new Exception("Método no permitido.");
    }
} catch (Exception $e) {
    $response["message"] = $e->getMessage();
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
