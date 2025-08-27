<?php
require_once 'auth.php';
require_once 'db.php';
include 'header.php';

// Only allow access to admins
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    echo "<div class='alert alert-danger'>Access denied. Admins only.</div>";
    include 'footer.php';
    exit;
}

// Handle form submission
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['name'])) {
    $name = trim($_POST['name']);

    // Check for duplicates
    $stmt = $pdo->prepare("SELECT id FROM companies WHERE name = ?");
    $stmt->execute([$name]);
    if ($stmt->fetch()) {
        $message = "<div class='alert alert-warning'>A company with this name already exists.</div>";
    } else {
        $stmt = $pdo->prepare("INSERT INTO companies (name) VALUES (?)");
        if ($stmt->execute([$name])) {
            $message = "<div class='alert alert-success'>Company successfully created.</div>";
        } else {
            $message = "<div class='alert alert-danger'>Error while creating the company.</div>";
        }
    }
}
?>

<div class="container py-4">
    <h2 class="mb-4">🏢 Create New Company</h2>
    <?= $message ?>

    <form method="POST" class="card p-4 shadow-sm">
        <div class="mb-3">
            <label for="name" class="form-label">Company Name</label>
            <input type="text" name="name" id="name" class="form-control" required>
        </div>
        <button type="submit" class="btn btn-primary">Save</button>
    </form>
</div>

<?php include 'footer.php'; ?>
