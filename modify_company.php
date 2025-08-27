<?php
// modify_company.php

require_once 'auth.php';
require_once 'db.php';
include 'header.php';

// ===== Config =====
$LOGO_DIR = __DIR__ . '/uploads/company_logos';
$LOGO_URL_BASE = 'uploads/company_logos'; // para mostrar en <img>

// Crear dir si no existe
if (!is_dir($LOGO_DIR)) {
    @mkdir($LOGO_DIR, 0755, true);
}

// ===== CSRF =====
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ===== Obtener ID =====
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    echo "<div class='alert alert-danger'>ID de empresa inválido.</div>";
    include 'footer.php';
    exit;
}

// ===== Cargar empresa =====
$stmt = $pdo->prepare("SELECT * FROM companies WHERE id = ?");
$stmt->execute([$id]);
$company = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$company) {
    echo "<div class='alert alert-warning'>Empresa no encontrada.</div>";
    include 'footer.php';
    exit;
}

$errors = [];
$success = null;

// ===== Procesar POST =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $errors[] = "Token de seguridad inválido. Recarga la página e inténtalo de nuevo.";
    }

    // Campos
    $name        = trim($_POST['name'] ?? '');
    $razonsocial = trim($_POST['razonsocial'] ?? '');
    $rfc         = strtoupper(trim($_POST['rfc'] ?? ''));
    $short_code  = strtoupper(trim($_POST['short_code'] ?? ''));
    $remove_logo = isset($_POST['remove_logo']) ? true : false;

    // Validaciones básicas
    if ($name === '')        $errors[] = "El campo <strong>Nombre</strong> es obligatorio.";
    if ($razonsocial === '') $errors[] = "El campo <strong>Razón social</strong> es obligatorio.";
    if ($rfc === '')         $errors[] = "El campo <strong>RFC</strong> es obligatorio.";
    if ($short_code === '')  $errors[] = "El campo <strong>Código corto</strong> es obligatorio.";

    // Validar unicidad de short_code (si cambió)
    if ($short_code !== '' && $short_code !== ($company['short_code'] ?? '')) {
        $chk = $pdo->prepare("SELECT id FROM companies WHERE short_code = ? AND id <> ?");
        $chk->execute([$short_code, $id]);
        if ($chk->fetch()) {
            $errors[] = "Ya existe otra empresa con el código corto <strong>$short_code</strong>.";
        }
    }

    // Manejo de archivo (si viene)
    $newLogoPath = null; // ruta relativa guardable en DB
    $fileDeleteOld = false;

    if (!$errors) {
        if (!empty($_FILES['logo']['name'])) {
            $file = $_FILES['logo'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $errors[] = "Error al subir el logo (código {$file['error']}).";
            } else {
                // Validar tipo y tamaño
                $allowed = [
                    'image/png' => 'png',
                    'image/jpeg' => 'jpg',
                    'image/webp' => 'webp',
                    'image/svg+xml' => 'svg'
                ];
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime  = $finfo->file($file['tmp_name']);
                if (!isset($allowed[$mime])) {
                    $errors[] = "Formato de logo no permitido. Usa PNG, JPG, WEBP o SVG.";
                }
                $maxBytes = 2 * 1024 * 1024; // 2MB
                if ($file['size'] > $maxBytes) {
                    $errors[] = "El archivo de logo excede 2 MB.";
                }

                if (!$errors) {
                    $ext  = $allowed[$mime];
                    $safeName = preg_replace('/[^A-Za-z0-9_\-]/', '-', strtolower($short_code ?: $company['short_code'] ?: 'logo'));
                    $finalName = $safeName . '-' . time() . '.' . $ext;
                    $destPath = $LOGO_DIR . '/' . $finalName;

                    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
                        $errors[] = "No se pudo guardar el archivo en el servidor.";
                    } else {
                        // Ruta relativa para DB
                        $newLogoPath = $LOGO_URL_BASE . '/' . $finalName;
                        // Marcar para eliminar antiguo si existía
                        if (!empty($company['logo'])) {
                            $fileDeleteOld = true;
                        }
                    }
                }
            }
        } elseif ($remove_logo) {
            // Quitar logo sin subir uno nuevo
            if (!empty($company['logo'])) {
                $fileDeleteOld = true;
            }
            $newLogoPath = null; // se guardará NULL en DB
        }
    }

    // Si no hay errores, actualizar
    if (!$errors) {
        try {
            if ($newLogoPath !== null) {
                $sql = "UPDATE companies 
                        SET name = ?, razonsocial = ?, rfc = ?, short_code = ?, logo = ?
                        WHERE id = ?";
                $params = [$name, $razonsocial, $rfc, $short_code, $newLogoPath, $id];
            } else {
                // No se tocó el logo o se pide eliminar con NULL
                if ($remove_logo) {
                    $sql = "UPDATE companies 
                            SET name = ?, razonsocial = ?, rfc = ?, short_code = ?, logo = NULL
                            WHERE id = ?";
                    $params = [$name, $razonsocial, $rfc, $short_code, $id];
                } else {
                    $sql = "UPDATE companies 
                            SET name = ?, razonsocial = ?, rfc = ?, short_code = ?
                            WHERE id = ?";
                    $params = [$name, $razonsocial, $rfc, $short_code, $id];
                }
            }

            $upd = $pdo->prepare($sql);
            $upd->execute($params);

            // Eliminar archivo antiguo si corresponde
            if ($fileDeleteOld && !empty($company['logo'])) {
                $old = __DIR__ . '/' . $company['logo'];
                if (is_file($old)) { @unlink($old); }
            }

            // Refrescar datos en memoria para mostrar en el formulario
            $stmt = $pdo->prepare("SELECT * FROM companies WHERE id = ?");
            $stmt->execute([$id]);
            $company = $stmt->fetch(PDO::FETCH_ASSOC);

            $success = "Empresa actualizada correctamente.";
        } catch (Exception $e) {
            $errors[] = "Error al actualizar: " . htmlspecialchars($e->getMessage());
        }
    }
}
?>

<div class="container">
  <h2 class="mb-3">✏️ Modificar empresa</h2>

  <?php if ($success): ?>
    <div class="alert alert-success"><?= $success ?></div>
  <?php endif; ?>

  <?php if ($errors): ?>
    <div class="alert alert-danger">
      <ul class="mb-0">
        <?php foreach ($errors as $e): ?>
          <li><?= $e ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <div class="card shadow-sm">
    <div class="card-body">
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label">Nombre (corto)</label>
            <input type="text" name="name" class="form-control" maxlength="50"
                   value="<?= htmlspecialchars($company['name'] ?? '') ?>" required>
          </div>

          <div class="col-md-8">
            <label class="form-label">Razón social</label>
            <input type="text" name="razonsocial" class="form-control" maxlength="255"
                   value="<?= htmlspecialchars($company['razonsocial'] ?? '') ?>" required>
          </div>

          <div class="col-md-4">
            <label class="form-label">RFC</label>
            <input type="text" name="rfc" class="form-control" maxlength="13"
                   value="<?= htmlspecialchars($company['rfc'] ?? '') ?>" required>
          </div>

          <div class="col-md-4">
            <label class="form-label">Código corto</label>
            <input type="text" name="short_code" class="form-control" maxlength="10"
                   value="<?= htmlspecialchars($company['short_code'] ?? '') ?>" required>
            <div class="form-text">Se recomienda único (p.ej. FISS, DEMS, MVEC).</div>
          </div>

          <div class="col-md-4">
            <label class="form-label">Logo (PNG/JPG/WEBP/SVG, máx. 2MB)</label>
            <input type="file" name="logo" class="form-control" accept=".png,.jpg,.jpeg,.webp,.svg">
          </div>

          <div class="col-12">
            <?php if (!empty($company['logo'])): ?>
              <div class="d-flex align-items-center gap-3">
                <img src="<?= htmlspecialchars($company['logo']) ?>" alt="Logo" style="height:60px;border:1px solid #eee;padding:4px;border-radius:8px;">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" value="1" id="remove_logo" name="remove_logo">
                  <label class="form-check-label" for="remove_logo">Eliminar logo actual</label>
                </div>
              </div>
            <?php else: ?>
              <div class="text-muted">Sin logo cargado.</div>
            <?php endif; ?>
          </div>
        </div>

        <div class="mt-4 d-flex gap-2">
          <button type="submit" class="btn btn-primary">Guardar cambios</button>
          <a href="companies.php" class="btn btn-outline-secondary">Volver</a>
        </div>
      </form>
    </div>
  </div>
</div>

<?php include 'footer.php'; ?>
