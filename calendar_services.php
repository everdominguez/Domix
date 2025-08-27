<?php
require_once 'auth.php';
require_once 'db.php';
include 'header.php';

if (!isset($_SESSION['company_id'])) {
    echo "<div class='alert alert-danger'>Empresa no seleccionada.</div>";
    include 'footer.php';
    exit;
}
?>

<h2 class="mb-4">🗓️ Calendario de Servicios y Eventos</h2>

<!-- Contenedor del calendario -->
<div id="calendar"></div>

<!-- Modal de detalles -->
<div class="modal fade" id="eventoModal" tabindex="-1" aria-labelledby="eventoModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="eventoModalLabel">Detalles del Evento</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <p><strong>Descripción:</strong> <span id="detalleDescripcion"></span></p>
        <p><strong>Proveedor:</strong> <span id="detalleProveedor"></span></p>
        <p><strong>Estatus:</strong> <span id="detalleEstatus"></span></p>
      </div>
    </div>
  </div>
</div>

<!-- FullCalendar + estilos -->
<link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/locales-all.global.min.js"></script>

<!-- Script para inicializar el calendario -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    const calendarEl = document.getElementById('calendar');
    const calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'dayGridMonth',
        locale: 'es',
        height: 'auto',
        events: '/get_service_events.php',
        eventClick: function (info) {
            const event = info.event;
            document.getElementById('detalleDescripcion').textContent = event.extendedProps.descripcion || '';
            document.getElementById('detalleProveedor').textContent = event.extendedProps.proveedor || '';
            document.getElementById('detalleEstatus').textContent = event.extendedProps.estatus || '';
            new bootstrap.Modal(document.getElementById('eventoModal')).show();
        }
    });
    calendar.render();
});
</script>

<style>
#calendar {
    max-width: 100%;
    margin: 0 auto;
    background-color: white;
    padding: 20px;
    border-radius: 10px;
}
</style>

<?php include 'footer.php'; ?>
