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

<link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.8/index.global.min.js"></script>

<div class="container py-4">
    <h2 class="mb-4">📅 Calendario de Servicios Contratados</h2>
    <div id="calendar"></div>
</div>

<!-- Modal para mostrar detalles -->
<div class="modal fade" id="modalEvento" tabindex="-1" aria-labelledby="modalEventoLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="modalEventoLabel">Detalles del Evento</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body" id="eventoContenido">
        <!-- Se llenará dinámicamente -->
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const calendarEl = document.getElementById('calendar');

    const calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'dayGridMonth',
        locale: 'es',
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,listMonth'
        },
        events: 'get_service_events.php',
        eventClick: function (info) {
            info.jsEvent.preventDefault();
            const event = info.event;

            let html = `
                <p><strong>Proveedor:</strong> ${event.extendedProps.proveedor || 'N/A'}</p>
                <p><strong>Descripción:</strong><br>${event.extendedProps.descripcion || 'Sin descripción'}</p>
                <p><strong>Estatus:</strong> ${event.extendedProps.estatus || 'No definido'}</p>
            `;

            document.getElementById('eventoContenido').innerHTML = html;
            const modal = new bootstrap.Modal(document.getElementById('modalEvento'));
            modal.show();
        }
    });

    calendar.render();
});
</script>

<?php include 'footer.php'; ?>
