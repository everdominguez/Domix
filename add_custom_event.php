<?php
require_once 'auth.php';
require_once 'db.php';
include 'header.php';

if (!isset($_SESSION['company_id'])) {
    echo "<div class='alert alert-danger'>Empresa no seleccionada.</div>";
    include 'footer.php';
    exit;
}

$company_id = $_SESSION['company_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $event_date = $_POST['event_date'] ?? '';
    $color = $_POST['color'] ?? 'orange';

    if ($title && $event_date) {
        $stmt = $pdo->prepare("INSERT INTO custom_events (company_id, title, description, event_date, color)
                               VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$company_id, $title, $description, $event_date, $color]);
        header("Location: service_calendar.php?custom=1");
        exit;
    } else {
        echo "<div class='alert alert-danger'>Título y fecha son obligatorios.</div>";
    }
}
?>

<div class="container py-4">
    <h2 class="mb-4">➕ Añadir Evento Personalizado</h2>

    <form method="POST">
        <div class="mb-3">
            <label class="form-label">Título del evento</label>
            <input type="text" name="title" class="form-control" required>
        </div>

        <div class="mb-3">
            <label class="form-label">Descripción (opcional)</label>
            <textarea name="description" class="form-control" rows="3"></textarea>
        </div>

        <div class="mb-3">
            <label class="form-label">Fecha del evento</label>
            <input type="date" name="event_date" class="form-control" required>
        </div>

        <div class="mb-3">
            <label class="form-label">Color</label>
            <select name="color" class="form-select">
                <option value="orange" selected>🟠 Naranja (Default)</option>
                <option value="purple">🟣 Morado</option>
                <option value="gray">⚪ Gris</option>
                <option value="teal">🟢 Verde azulado</option>
            </select>
        </div>

        <button type="submit" class="btn btn-primary">Guardar Evento</button>
        <a href="service_calendar.php" class="btn btn-secondary">Cancelar</a>
    </form>
</div>

<?php include 'footer.php'; ?>
