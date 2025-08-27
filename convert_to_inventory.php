<button type="button" id="btnConvert" class="btn btn-warning"
        onclick="convertToInventory(<?= (int)$expense['id'] ?>)">
  Convertir a inventario
</button>

<script>
async function convertToInventory(expenseId) {
  if (!confirm('Se crearán partidas en inventario con los conceptos del CFDI. ¿Deseas continuar?')) return;

  const form = new FormData();
  form.append('expense_id', expenseId);

  try {
    const r = await fetch('convert_to_inventory.php', {
      method: 'POST',
      body: form,
      credentials: 'same-origin'
    });
    const data = await r.json();
    if (!r.ok || !data.ok) {
      alert('No se pudo convertir: ' + (data.error || r.statusText));
      return;
    }
    alert('¡Convertido a inventario!');
    // Redirige al listado o recarga para reflejar cambios
    window.location.href = 'expenses_list.php?project_id=<?= (int)$expense['project_id'] ?>';
  } catch (err) {
    alert('Error de red: ' + err.message);
  }
}
</script>
