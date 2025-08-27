<?php
session_start();
require_once 'auth.php';
require_once 'db.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

/* ========= Validación empresa ========= */
if (!isset($_SESSION['company_id'])) {
    header("Location: choose_company.php");
    exit();
}
$company_id = (int)$_SESSION['company_id'];

/* ========= Catálogos ========= */
// Proyectos
$stmt = $pdo->prepare("SELECT id, name FROM projects WHERE company_id = ? ORDER BY name");
$stmt->execute([$company_id]);
$projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Categorías
$catStmt = $pdo->prepare("SELECT id, name FROM expenses_category WHERE company_id = ? ORDER BY name");
$catStmt->execute([$company_id]);
$categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);

// Formas de pago
$methodStmt = $pdo->prepare("SELECT id, name FROM payment_methods WHERE company_id = ? ORDER BY name");
$methodStmt->execute([$company_id]);
$availableMethods = $methodStmt->fetchAll(PDO::FETCH_ASSOC);

include 'header.php';
?>

<h2 class="mb-4">📤 Subir XML CFDI</h2>

<div class="card shadow mb-4">
  <div class="card-header">Formulario de carga de XML</div>
  <div class="card-body">
    <form method="POST" action="process_uploaded_xml.php" enctype="multipart/form-data">
      <div class="row mb-3">
        <div class="col-md-4">
          <label class="form-label">Proyecto</label>
          <select name="xml_project_id" id="xml_project_id" class="form-select" required>
            <option value="">Selecciona un proyecto</option>
            <?php foreach ($projects as $proj): ?>
              <option value="<?= (int)$proj['id'] ?>"><?= htmlspecialchars($proj['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-4">
          <label class="form-label">Subproyecto</label>
          <select name="xml_subproject_id" id="xml_subproject_id" class="form-select">
            <option value="">Selecciona un proyecto primero</option>
          </select>
        </div>

        <div class="col-md-4">
          <label class="form-label">Categoría</label>
          <select name="category_id" id="category_id" class="form-select" required>
            <option value="">Selecciona una categoría</option>
            <?php foreach ($categories as $cat): ?>
              <option value="<?= (int)$cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="row mb-3">
        <div class="col-md-4">
          <label class="form-label">Subcategoría</label>
          <select name="subcategory_id" id="subcategory_id" class="form-select">
            <option value="">Selecciona una categoría primero</option>
          </select>
        </div>

        <div class="col-md-4">
          <label class="form-label">Forma de pago</label>
          <select name="xml_payment_method_id" id="xml_payment_method_id" class="form-select" required>
            <option value="">Selecciona método de pago</option>
            <?php foreach ($availableMethods as $pm): ?>
              <option value="<?= (int)$pm['id'] ?>"><?= htmlspecialchars($pm['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-4">
          <label class="form-label">Archivo XML</label>
          <input type="file" id="xmlfiles" name="xmlfiles[]" accept=".xml" class="form-control" multiple required>
          <small class="text-muted"><span id="xmlcount">0</span> archivos seleccionados</small>
        </div>
      </div>

      <!-- Contenedor de bloques -->
      <div id="xmlPreviewContainer" class="vstack gap-3 mb-4"></div>

      <div class="row mb-3">
        <div class="col-md-6">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="import_inventory" id="import_inventory" value="1">
            <label class="form-check-label" for="import_inventory">Importar conceptos al inventario</label>
          </div>
        </div>
        <div class="col-md-6">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="import_expense" id="import_expense" value="1">
            <label class="form-check-label" for="import_expense">Importar como gasto</label>
          </div>
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label">Notas</label>
        <textarea name="notes" class="form-control" rows="3" placeholder="Escribe alguna observación (opcional)"></textarea>
      </div>

      <button type="submit" id="btnImportar" name="submit_xml" value="1" class="btn btn-primary">Guardar e Importar</button>
    </form>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(function () {
  // Subproyectos dependientes
  $('#xml_project_id').on('change', function () {
    const projectId = $(this).val();
    const subSelect = $('#xml_subproject_id');
    subSelect.html('<option value="">Cargando...</option>');
    if (!projectId) {
      subSelect.html('<option value="">Selecciona un proyecto primero</option>');
      return;
    }
    $.get('get_subprojects.php', { project_id: projectId }, function (data) {
      subSelect.empty();
      if (Array.isArray(data) && data.length > 0) {
        subSelect.append('<option value="">(Opcional) Selecciona un subproyecto</option>');
        data.forEach(sub => subSelect.append(`<option value="${sub.id}">${sub.name}</option>`));
      } else {
        subSelect.append('<option value="">(Sin subproyectos registrados)</option>');
      }
    });
    const catId = $('#category_id').val();
    if (catId) { cargarSubcategorias(catId); }
  });

  // Exclusión mutua inventario/gasto
  $('#import_inventory').on('change', function () {
    if (this.checked) $('#import_expense').prop('checked', false);
  });
  $('#import_expense').on('change', function () {
    if (this.checked) $('#import_inventory').prop('checked', false);
  });

  // Subcategorías dependientes
  $('#category_id').on('change', function () {
    const catId = $(this).val();
    cargarSubcategorias(catId);
  });

  function cargarSubcategorias(catId) {
    const sub = $('#subcategory_id');
    sub.html('<option value="">Cargando...</option>');
    if (!catId) {
      sub.html('<option value="">Selecciona una categoría primero</option>');
      return;
    }
    const projectId = $('#xml_project_id').val() || '';
    $.get('get_subcategories.php', { category_id: catId, project_id: projectId }, function (data) {
      sub.empty();
      if (Array.isArray(data) && data.length > 0) {
        sub.append('<option value="">(Opcional) Selecciona una subcategoría</option>');
        data.forEach(row => sub.append(`<option value="${row.id}">${row.name}</option>`));
      } else {
        sub.append('<option value="">(Sin subcategorías registradas)</option>');
      }
    }).fail(() => {
      sub.html('<option value="">Error al cargar subcategorías</option>');
    });
  }
});
</script>

<script>
(() => {
  let selectedFiles = [];
  const input       = document.getElementById('xmlfiles');
  const countSpan   = document.getElementById('xmlcount');
  const previewWrap = document.getElementById('xmlPreviewContainer');
  const btnImportar = document.getElementById('btnImportar');

  input.addEventListener('change', async () => {
    selectedFiles = Array.from(input.files || []);
    await renderAllBlocks();
  });

  async function renderAllBlocks() {
    previewWrap.innerHTML = '';
    countSpan.textContent = selectedFiles.length;
    for (let i = 0; i < selectedFiles.length; i++) {
      const file = selectedFiles[i];
      const block = document.createElement('div');
      block.className = 'card shadow-sm mb-3';
      block.dataset.index = i;
      block.innerHTML = `
        <div class="card-header d-flex justify-content-between align-items-center">
          <div class="fw-semibold text-truncate" title="${file.name}">📄 ${file.name}</div>
          <div class="d-flex align-items-center gap-2">
            <span class="badge bg-light text-dark">${(file.size/1024).toFixed(1)} KB</span>
            <button type="button" class="btn btn-sm btn-outline-danger" data-action="remove">Quitar</button>
          </div>
        </div>
        <div class="card-body">
          <div class="small text-muted">Vista previa</div>
          <div class="xml-preview" data-loader>Analizando…</div>
        </div>`;
      previewWrap.appendChild(block);

      // Llamamos al servidor para obtener la vista previa real
      const htmlPreview = await buildServerPreview(file);
      block.querySelector('.xml-preview').innerHTML = htmlPreview;
    }
  }

  async function buildServerPreview(file) {
    const fd = new FormData();
    fd.append('xmlfiles[]', file);
    fd.append('company_id', <?= $company_id ?>);
    fd.append('project_id', document.getElementById('xml_project_id').value || '');
    fd.append('subproject_id', document.getElementById('xml_subproject_id').value || '');
    fd.append('category_id', document.getElementById('category_id').value || '');
    fd.append('subcategory_id', document.getElementById('subcategory_id').value || '');
    fd.append('payment_method_id', document.getElementById('xml_payment_method_id').value || '');
    try {
      const res = await fetch('preview_xml.php', { method: 'POST', body: fd });
      return await res.text();
    } catch (err) {
      return `<div class="text-danger">Error al cargar vista previa</div>`;
    }
  }

  previewWrap.addEventListener('click', (e) => {
    const btn = e.target.closest('button[data-action="remove"]');
    if (!btn) return;
    const card = btn.closest('.card');
    const idx  = parseInt(card.dataset.index, 10);
    selectedFiles.splice(idx, 1);
    card.remove();
    Array.from(previewWrap.querySelectorAll('.card')).forEach((c, i) => c.dataset.index = i);
    countSpan.textContent = selectedFiles.length;
    input.value = '';
  });

  btnImportar.addEventListener('click', async (e) => {
    e.preventDefault();
    if (selectedFiles.length === 0) {
      alert('No hay archivos para importar.');
      return;
    }
    const form = btnImportar.closest('form') || document.querySelector('form');
    const fd = new FormData(form);
    fd.delete('xmlfiles[]');
    selectedFiles.forEach(f => fd.append('xmlfiles[]', f, f.name));
    const res = await fetch(form.action || 'process_uploaded_xml.php', { method: 'POST', body: fd });
    const html = await res.text();
    const w = window.open('', '_blank');
    w.document.write(html);
    w.document.close();
  });
})();
</script>

<?php include 'footer.php'; ?>
