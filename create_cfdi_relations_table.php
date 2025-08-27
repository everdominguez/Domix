<?php
// Este archivo debe ejecutarse una vez para crear la tabla de relaciones CFDI

require_once 'db.php';

try {
    $sql = "CREATE TABLE IF NOT EXISTS cfdi_relations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        parent_uuid VARCHAR(50) NOT NULL,
        child_uuid VARCHAR(50) NOT NULL,
        relation_type VARCHAR(5) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $pdo->exec($sql);
    echo "✅ Tabla 'cfdi_relations' creada correctamente.";
} catch (PDOException $e) {
    echo "❌ Error al crear la tabla: " . $e->getMessage();
}
?>