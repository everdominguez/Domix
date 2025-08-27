<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ========= 1) TÍTULO BASE ========= */
if (!isset($page_title) || trim($page_title) === '') {
    $fname = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
    $key   = strtolower(preg_replace('/\.php$/', '', $fname));

    // Aliases bonitos por archivo:
    $pretty = [
        // Núcleo / acceso
        'index'                      => 'Inicio',
        'login'                      => 'Acceso',
        'login_process'              => 'Acceso',
        'logout'                     => 'Cerrar sesión',
        'change_password'            => 'Cambiar contraseña',

        // Panel
        'dashboard'                  => 'Panel de control',

        // Empresas / multiempresa
        'choose_company'             => 'Elegir empresa',
        'change_company'             => 'Cambiar de empresa',
        'create_company'             => 'Crear empresa',
        'companies'                  => 'Empresas',
        'modify_company'             => 'Modificar empresa',

        // Catálogos / proyectos / clientes / proveedores
        'projects'                   => 'Proyectos',
        'create_project'             => 'Nuevo proyecto',
        'edit_project'               => 'Editar proyecto',
        'edit_subproject'            => 'Editar subproyecto',
        'clients'                    => 'Clientes',
        'buscar_clientes'            => 'Buscar clientes',
        'edit_client'                => 'Editar cliente',
        'delete_client'              => 'Eliminar cliente',
        'admin/provider'             => 'Proveedores', // por si se resuelve como ruta
        'admin'                      => 'Administración',
        'edit_provider'              => 'Editar proveedor', // por si existe
        'finance'                    => 'Configuración financiera',
        'categories'                 => 'Categorías',
        'subcategories'              => 'Subcategorías',
        'get_subcategories'          => 'Subcategorías',
        'get_subcategories_expenses' => 'Subcategorías de gastos',
        'get_subprojects'            => 'Subproyectos',

        // Presupuesto
        'budgets'                    => 'Presupuesto',
        'edit_budget'                => 'Editar presupuesto',
        'budget_report'              => 'Reporte de presupuesto',

        // Gastos
        'expenses'                   => 'Gastos',
        'expenses_list'              => 'Listado de gastos',
        'expenses_table'             => 'Tabla de gastos',
        'expenses_report'            => 'Reporte de gastos',
        'edit_expense'               => 'Editar gasto',
        'upload_xml'                 => 'Gastos XML',
        'preview_xml'                => 'Vista previa XML',
        'process_uploaded_xml'       => 'Procesar XML',
        'apply_pending_xml'          => 'Aplicar XML pendiente',
        '_expenses'                  => 'Gastos (legacy)',
        'convert_to_inventory'       => 'Convertir a inventario',
        'get_expense_details'        => 'Detalle de gasto',
        'reporte_anticipos'          => 'Reporte de anticipos',

        // Inventario
        'inventory'                  => 'Inventario',

        // Pagos / cobranza / reembolsos
        'payments'                   => 'Cobranza',
        'get_pending_payments'       => 'Pagos pendientes',
        'procesar_reembolso'         => 'Procesar reembolso',
        'reembolsos'                 => 'Reembolsos',
        'estado_cuenta'              => 'Estado de cuenta',
        'balance_bancario'           => 'Balance bancario',

        // Órdenes de compra
        'purchase_order'             => 'Órdenes de compra',
        'import_purchase_order'      => 'Nueva orden de compra',
        'view_purchase_order'        => 'Órdenes de compra',
        'view_purchase_order_details'=> 'Detalle de orden de compra',
        'purchase_order_list'        => 'Listado de órdenes de compra',
        'edit_purchase_order'        => 'Editar orden de compra',
        'delete_purchase_order'      => 'Eliminar orden de compra',
        'update_purchase_order_status'=> 'Actualizar estatus de orden',
        'get_provider_term'          => 'Plazos de proveedor',

        // Servicios contratados + calendario
        'contracted_services'        => 'Servicios contratados',
        'new_contracted_service'     => 'Nuevo servicio',
        'edit_contracted_service'    => 'Editar servicio',
        'delete_contracted_service'  => 'Eliminar servicio',
        'view_contracted_service'    => 'Ver servicio',
        'save_contracted_service'    => 'Guardar servicio',
        'update_contracted_service'  => 'Actualizar servicio',
        'extension_contracted_service'=> 'Prórroga de servicio',
        'recalculate_services'       => 'Recalcular servicios',
        'service_calendar'           => 'Calendario de servicios',
        'calendar_services'          => 'Calendario',
        'get_service_events'         => 'Eventos de servicio',
        'add_custom_event'           => 'Agregar evento',

        // Ventas
        'ventas_servicios'           => 'Venta de servicios',
        'sales_list'                 => 'Listado de ventas',
        'sales_view'                 => 'Detalle de venta',
        'associate_sale'             => 'Asociar venta',
        'process_sale'               => 'Procesar venta',
        'save_service_order'         => 'Guardar venta',
        'sale_success'               => 'Venta registrada',

        // Reportes/flujo
        'flujo_efectivo_proyecto'    => 'Flujo de efectivo por proyecto',

        // Utilidades y varios
        'search_autocomplete'        => 'Búsqueda',
        'partials'                   => 'Parciales',
        'demo'                       => 'Demo',

        // Scripts de soporte (normalmente no visibles)
        'create_entity'              => 'Crear entidad',
        'create_cfdi_relations_table'=> 'CFDI relaciones',
        'procesar'                   => 'Procesar',
        'lib'                        => 'Biblioteca'
    ];

    // Fallback bonito si no hay alias
    $fallback = ucwords(str_replace(['_', '-'], ' ', $key));
    $page_title = $pretty[$key] ?? $fallback;
}

/* ========= 2) SUFIJO CON EMPRESA ========= */
$company_suffix = !empty($_SESSION['company_name']) ? ' - ' . $_SESSION['company_name'] : '';

/* ========= 3) VERSIÓN CSS SEGURA ========= */
$stylePath    = $_SERVER['DOCUMENT_ROOT'] . '/css/style.css';
$styleVersion = file_exists($stylePath) ? filemtime($stylePath) : time();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($page_title . $company_suffix, ENT_QUOTES, 'UTF-8') ?></title>

  <!-- Bootstrap CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="/css/style.css?v=<?= $styleVersion ?>">

  <!-- jQuery y jQuery UI -->
  <link rel="stylesheet" href="https://code.jquery.com/ui/1.13.2/themes/smoothness/jquery-ui.css">
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js"></script>
</head>
<body>

<?php if (isset($_SESSION['user_id'])): ?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark px-3">
  <div class="container">
    <a class="navbar-brand fw-bold" href="/dashboard.php">📊 Panel</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="navbarNav">
      <ul class="navbar-nav me-auto">
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" id="navbarDropdownVentas" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            Ventas
          </a>
          <ul class="dropdown-menu dropdown-menu-dark shadow" aria-labelledby="navbarDropdownVentas">
            <li><a class="dropdown-item" href="/ventas_servicios.php">🧾 Venta de Servicios</a></li>
            <li><a class="dropdown-item" href="/sales_list.php">📋 Listado de Ventas</a></li>
          </ul>
        </li>

        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" id="navbarDropdownGastos" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            Gastos
          </a>
          <ul class="dropdown-menu dropdown-menu-dark shadow" aria-labelledby="navbarDropdownGastos">
            <li><a class="dropdown-item" href="/expenses.php">📄 Gastos Manuales</a></li>
            <li><a class="dropdown-item" href="/upload_xml.php">🧾 Gastos XML</a></li>
            <li><a class="dropdown-item" href="/expenses_list.php">📋 Listado de Gastos</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="/reporte_anticipos.php">💳 Reporte de Anticipos</a></li>
          </ul>
        </li>

        <li class="nav-item"><a class="nav-link" href="/payments.php">Cobranza</a></li>

        <li class="nav-item">
          <a class="nav-link" href="/contracted_services.php">Servicios Contratados</a>
        </li>

        <li class="nav-item">
          <a class="nav-link" href="/calendar_services.php">Calendario</a>
        </li>

        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" id="ordenesDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            Órdenes de Compra
          </a>
          <ul class="dropdown-menu" aria-labelledby="ordenesDropdown">
            <li><a class="dropdown-item" href="/import_purchase_order.php">➕ Nueva Orden</a></li>
            <li><a class="dropdown-item" href="/view_purchase_order.php">📄 Ver Órdenes</a></li>
          </ul>
        </li>

        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" id="navbarDropdownReportes" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            Reportes
          </a>
          <ul class="dropdown-menu dropdown-menu-dark shadow" aria-labelledby="navbarDropdownReportes">
            <li><a class="dropdown-item" href="/budget_report.php">📊 Reporte de Presupuesto</a></li>
            <li><a class="dropdown-item" href="/flujo_efectivo_proyecto.php">💵 Flujo de Efectivo</a></li>
            <li><a class="dropdown-item" href="/inventory.php">📦 Reporte de Inventario</a></li>
            <li><a class="dropdown-item" href="/estado_cuenta.php">📑 Estado de Cuenta</a></li>
          </ul>
        </li>

        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" id="navbarDropdownCatalogo" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            Catálogos
          </a>
          <ul class="dropdown-menu dropdown-menu-dark shadow" aria-labelledby="navbarDropdownCatalogo">
            <li><a class="dropdown-item" href="/projects.php">📁 Proyectos</a></li>
            <li><a class="dropdown-item" href="/budgets.php">💰 Presupuesto</a></li>
            <li><a class="dropdown-item" href="/purchase_order.php">📄 Órdenes de Compra</a></li>
            <li><a class="dropdown-item" href="/clients.php">👥 Clientes</a></li>
            <li><a class="dropdown-item" href="/admin/provider.php">🏭 Proveedores</a></li>
            <li><a class="dropdown-item" href="/finance.php">⚙️ Configuración Financiera</a></li>
            <?php if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin'): ?>
              <li><a class="dropdown-item" href="/user/user.php">👤 Usuarios</a></li>
              <li><a class="dropdown-item" href="/create_company.php">🏢 Crear Empresa</a></li>
              <li><a class="dropdown-item" href="/companies.php">🏢 Empresas</a></li>
            <?php endif; ?>
          </ul>
        </li>
      </ul>

      <ul class="navbar-nav ms-auto">

        <?php if (!empty($_SESSION['company_name'])): ?>
        <li class="nav-item dropdown me-3 company-item">
          <a class="nav-link dropdown-toggle text-white company-name"
             href="#" id="empresaDropdown" role="button" data-bs-toggle="dropdown"
             aria-expanded="false"
             title="<?= htmlspecialchars($_SESSION['company_name'], ENT_QUOTES, 'UTF-8') ?>">
            <?php
              $companyNameParts = explode(' ', $_SESSION['company_name']);
              $firstWord = $companyNameParts[0] ?? $_SESSION['company_name'];
            ?>
            🏢 <?= htmlspecialchars($firstWord, ENT_QUOTES, 'UTF-8') ?>
          </a>
          <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="empresaDropdown">
            <li><a class="dropdown-item" href="/change_company.php">Cambiar de empresa</a></li>
          </ul>
        </li>
        <?php endif; ?>

        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle text-white d-flex align-items-center" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            👤 <?= htmlspecialchars($_SESSION['user_name'] ?? 'Usuario', ENT_QUOTES, 'UTF-8') ?>
          </a>
          <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
            <li><a class="dropdown-item" href="/change_password.php">Cambiar contraseña</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="/logout.php">Cerrar sesión</a></li>
          </ul>
        </li>

      </ul>
    </div>
  </div>
</nav>
<?php endif; ?>

<?php if (isset($_SESSION['empresa_cambiada'])): ?>
  <div class="alert alert-success alert-dismissible fade show text-center mb-0 rounded-0" role="alert">
    <?= htmlspecialchars($_SESSION['empresa_cambiada'], ENT_QUOTES, 'UTF-8') ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
  </div>
  <?php unset($_SESSION['empresa_cambiada']); ?>
<?php endif; ?>

<div class="container py-5">
