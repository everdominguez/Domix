<?php
$host = 'localhost';
$db   = 'admin_proyectos';
$user = 'admin_proyectos';
$pass = 'Xiapo084ever.-';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Mostrar errores en desarrollo
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Retornar resultados como arrays asociativos
    PDO::ATTR_EMULATE_PREPARES   => false,                  // Usar consultas preparadas reales
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    die('Error de conexión a la base de datos: ' . $e->getMessage());
}
?>
