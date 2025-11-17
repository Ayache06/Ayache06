<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}
include('connection.php');
include('includes/config.php');

// === CONNEXION + CRÉATION AUTO DES TABLES ===
try {
    $pdo = new PDO("mysql:host=$servername;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("CREATE TABLE IF NOT EXISTS sharing_sites (
        id INT AUTO_INCREMENT PRIMARY KEY,
        Region VARCHAR(100),
        Wilaya VARCHAR(100),
        `Offline Date` DATE NULL,
        `Type de Cohabitation` VARCHAR(100),
        `Code Site OTA` VARCHAR(50) UNIQUE,
        `code site operateur` VARCHAR(50),
        Operateur VARCHAR(50),
        `statut sharing` VARCHAR(100),
        `type site` VARCHAR(100),
        `type installation` VARCHAR(100),
        `type Branchement power` VARCHAR(100),
        Notes TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS import_history_sharing (
        id INT AUTO_INCREMENT PRIMARY KEY,
        file_name VARCHAR(255),
        added INT DEFAULT 0,
        updated INT DEFAULT 0,
        skipped INT DEFAULT 0,
        errors TEXT,
        summary TEXT,
        imported_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

} catch (Exception $e) {
    die("<div class='alert alert-danger'>Erreur DB : " . htmlspecialchars($e->getMessage()) . "</div>");
}

/* ====================== ACTIONS AJAX ====================== */
if (isset($_GET['delete_id'])) {
    $stmt = $pdo->prepare("DELETE FROM sharing_sites WHERE id = ?");
    $stmt->execute([$_GET['delete_id']]);
    echo json_encode(['success' => true, 'message' => 'Site supprimé']);
    exit;
}

if (isset($_GET['get_site'])) {
    $stmt = $pdo->prepare("SELECT * FROM sharing_sites WHERE id = ?");
    $stmt->execute([$_GET['get_site']]);
    echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
    exit;
}

if (isset($_POST['save_site'])) {
    $id = $_POST['site_id'] ?? null;
    $data = [
        $_POST['region'], $_POST['wilaya'], $_POST['offline_date'] ?: null,
        $_POST['type_cohabitation'], $_POST['code_site_ota'], $_POST['code_operateur'],
        $_POST['operateur'], $_POST['statut_sharing'], $_POST['type_site'],
        $_POST['type_installation'], $_POST['type_power'], $_POST['notes']
    ];

    try {
        if ($id) {
            $sql = "UPDATE sharing_sites SET Region=?, Wilaya=?, `Offline Date`=?, `Type de Cohabitation`=?, 
                    `Code Site OTA`=?, `code site operateur`=?, Operateur=?, `statut sharing`=?,
                    `type site`=?, `type installation`=?, `type Branchement power`=?, Notes=? WHERE id=?";
            $data[] = $id;
        } else {
            $sql = "INSERT INTO sharing_sites (Region, Wilaya, `Offline Date`, `Type de Cohabitation`, `Code Site OTA`,
                    `code site operateur`, Operateur, `statut sharing`, `type site`, `type installation`,
                    `type Branchement power`, Notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        }
        $pdo->prepare($sql)->execute($data);
        echo json_encode(['success' => true, 'message' => $id ? 'Modifié avec succès' : 'Ajouté avec succès']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if (isset($_POST['import_ods'])) {
    require_once 'vendor/autoload.php';
    $errors = [];
    $added = 0;
    $updated = 0;
    $skipped = 0;

    if (!isset($_FILES['ods_file']) || $_FILES['ods_file']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Erreur lors du téléchargement du fichier.';
    } else {
        $file = $_FILES['ods_file']['tmp_name'];
        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray();

            if (count($rows) < 2) {
                $errors[] = 'Le fichier ne contient pas de données valides.';
            } else {
                // Skip header row
                array_shift($rows);
                foreach ($rows as $row) {
                    if (empty($row[0]) && empty($row[1])) continue; // Skip empty rows

                    $region = trim($row[0] ?? '');
                    $wilaya = trim($row[1] ?? '');
                    $offline_date = !empty($row[2]) ? date('Y-m-d', strtotime($row[2])) : null;
                    $type_cohabitation = trim($row[3] ?? '');
                    $code_site_ota = trim($row[4] ?? '');
                    $code_operateur = trim($row[5] ?? '');
                    $operateur = trim($row[6] ?? '');
                    $statut_sharing = trim($row[7] ?? '');
                    $type_site = trim($row[8] ?? '');
                    $type_installation = trim($row[9] ?? '');
                    $type_power = trim($row[10] ?? '');
                    $notes = trim($row[11] ?? '');

                    if (empty($code_site_ota)) {
                        $skipped++;
                        continue;
                    }

                    // Check if exists
                    $stmt = $pdo->prepare("SELECT id FROM sharing_sites WHERE `Code Site OTA` = ?");
                    $stmt->execute([$code_site_ota]);
                    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($existing) {
                        // Update
                        $updateStmt = $pdo->prepare("UPDATE sharing_sites SET Region=?, Wilaya=?, `Offline Date`=?, `Type de Cohabitation`=?, 
                            `code site operateur`=?, Operateur=?, `statut sharing`=?, `type site`=?, `type installation`=?, 
                            `type Branchement power`=?, Notes=? WHERE `Code Site OTA`=?");
                        $updateStmt->execute([$region, $wilaya, $offline_date, $type_cohabitation, $code_operateur, $operateur, 
                            $statut_sharing, $type_site, $type_installation, $type_power, $notes, $code_site_ota]);
                        $updated++;
                    } else {
                        // Insert
                        $insertStmt = $pdo->prepare("INSERT INTO sharing_sites (Region, Wilaya, `Offline Date`, `Type de Cohabitation`, 
                            `Code Site OTA`, `code site operateur`, Operateur, `statut sharing`, `type site`, `type installation`, 
                            `type Branchement power`, Notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $insertStmt->execute([$region, $wilaya, $offline_date, $type_cohabitation, $code_site_ota, $code_operateur, 
                            $operateur, $statut_sharing, $type_site, $type_installation, $type_power, $notes]);
                        $added++;
                    }
                }
            }
        } catch (Exception $e) {
            $errors[] = 'Erreur lors du traitement du fichier : ' . $e->getMessage();
        }
    }

    // Log import history
    $summary = "Ajoutés: $added, Modifiés: $updated, Ignorés: $skipped";
    $errorStr = implode('; ', $errors);
    $pdo->prepare("INSERT INTO import_history_sharing (file_name, added, updated, skipped, errors, summary) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$_FILES['ods_file']['name'], $added, $updated, $skipped, $errorStr, $summary]);

    // Redirect with message
    $message = $summary;
    if (!empty($errors)) {
        $message .= ' Erreurs: ' . $errorStr;
    }
    header("Location: ?import_success=" . urlencode($message));
    exit;
}

if (isset($_GET['download_template'])) {
    require_once 'vendor/autoload.php';
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $headers = ['Région','Wilaya','Offline Date','Type de Cohabitation','Code Site OTA','code site operateur','Opérateur','statut sharing','type site','type installation','type Branchement power','Notes'];
    $sheet->fromArray([$headers], null, 'A1');
    $sheet->getStyle('A1:L1')->getFont()->setBold(true);
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Ods($spreadsheet);
    header('Content-Type: application/vnd.oasis.opendocument.spreadsheet');
    header('Content-Disposition: attachment;filename="modele_djezzy_sharing.ods"');
    $writer->save('php://output');
    exit;
}

$sharing_data = $pdo->query("SELECT * FROM sharing_sites ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$history = $pdo->query("SELECT * FROM import_history_sharing ORDER BY imported_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);

include('includes/header.php');
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Djezzy - Sites Partagés</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { 
            background-color: #ffffff; 
            color: #333;
            font-family: 'Arial', sans-serif;
        }
        .navbar { 
            background-color: #d70000; 
            padding: 15px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .navbar-brand, .navbar a { 
            color: white !important; 
            font-weight: bold;
        }
        .main-content {
            padding: 20px;
        }
        .card {
            margin-bottom: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            border: none;
        }
        .card-header {
            background-color: #f8f9fa;
            border-bottom: 2px solid #d70000;
            font-weight: bold;
            color: #d70000;
        }
        .chart-container {
            position: relative;
            height: 300px;
            margin-bottom: 20px;
        }
        .table-responsive {
            margin-top: 20px;
        }
        .badge-cluster {
            background-color: #d70000;
        }
        .badge-sitekeeper {
            background-color: #007bff;
        }
        .badge-minicluster {
            background-color: #fd7e14;
        }
        .content-section {
            display: none;
        }
        .content-section.active {
            display: block;
        }
        .fade-in {
            animation: fadeIn 0.3s ease-in-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .content-section {
            display: none;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .content-section.active {
            display: block;
            opacity: 1;
        }
        .status-online {
            background-color: #28a745;
            color: white;
        }
        .status-offline {
            background-color: #dc3545;
            color: white;
        }
        .content-wrapper {
            background: #ffffff !important;
        }
        .btn-warning {
            background-color: #d70000;
            border-color: #d70000;
        }
        .btn-warning:hover {
            background-color: #b30000;
            border-color: #b30000;
        }
        .modal-content {
            border-radius: 10px;
        }
        .modal-header {
            background-color: #d70000;
            color: white;
            border-radius: 10px 10px 0 0;
        }
        .modal-footer {
            background-color: #f8f9fa;
            border-top: 2px solid #d70000;
        }
        .footer {
            background-color: #d70000;
            color: white;
            text-align: center;
            padding: 10px;
        }
        h1 {
            color: #d70000;
            font-weight: 900;
            text-shadow: 0 3px 10px rgba(215, 0, 0, 0.2);
        }
        .dataTables_wrapper .dataTables_filter input {
            border: 1px solid #d70000;
        }
        .dataTables_wrapper .dataTables_length select {
            border: 1px solid #d70000;
        }
        .page-item.active .page-link {
            background-color: #d70000;
            border-color: #d70000;
        }
        .page-link {
            color: #d70000;
        }
        .page-link:hover {
            color: #b30000;
        }
    </style>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">
    <?php include('includes/nav.php'); ?>
    <?php include('includes/sidebar.php'); ?>

    <div class="content-wrapper">
        <section class="content-header">
            <div class="container-fluid">
                <h1 class="text-center mt-4 mb-4">
                    <i class="fas fa-share-alt"></i> DJEZZY - Gestion Sites Partagés
                </h1>
                <?php if (isset($_GET['import_success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle"></i> Import réussi: <?= htmlspecialchars($_GET['import_success']) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="content">
            <div class="container-fluid">

                <!-- IMPORT CARD -->
                <div class="card fade-in">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-upload"></i> Import & Actions Rapides</h3>
                    </div>
                    <div class="card-body">
                        <form method="POST" enctype="multipart/form-data" class="row g-3">
                            <div class="col-md-8">
                                <input type="file" name="ods_file" accept=".ods" required class="form-control">
                            </div>
                            <div class="col-md-4">
                                <button name="import_ods" class="btn btn-warning w-100 fw-bold">
                                    <i class="fas fa-file-import"></i> IMPORTER ODS
                                </button>
                            </div>
                        </form>
                        <div class="mt-3 d-flex gap-3 flex-wrap">
                            <a href="?download_template=1" class="btn btn-info fw-bold">
                                <i class="fas fa-download"></i> Télécharger Modèle ODS
                            </a>
                            <button onclick="showAddModal()" class="btn btn-success fw-bold">
                                <i class="fas fa-plus-circle"></i> Nouveau Site
                            </button>
                        </div>
                    </div>
                </div>

                <!-- TABLEAU PRINCIPAL -->
                <div class="card fade-in">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-table"></i> Liste des Sites en Cohabitation (<?= count($sharing_data) ?> sites)</h3>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive p-3">
                            <table id="sharingTable" class="table table-striped table-hover table-bordered">
                                <thead class="table-dark">
                                    <tr>
                                        <th>#</th>
                                        <th>Région</th>
                                        <th>Wilaya</th>
                                        <th>Offline Date</th>
                                        <th>Type Cohabitation</th>
                                        <th>Code Site OTA</th>
                                        <th>Code Opérateur</th>
                                        <th>Opérateur</th>
                                        <th>Statut Sharing</th>
                                        <th>Type Site</th>
                                        <th>Installation</th>
                                        <th>Power</th>
                                        <th>Notes</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($sharing_data as $site): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($site['id']) ?></td>
                                        <td><?= htmlspecialchars($site['Region']) ?></td>
                                        <td><?= htmlspecialchars($site['Wilaya']) ?></td>
                                        <td><?= $site['Offline Date'] ? date('d/m/Y', strtotime($site['Offline Date'])) : '-' ?></td>
                                        <td><?= htmlspecialchars($site['Type de Cohabitation']) ?></td>
                                        <td><strong><?= htmlspecialchars($site['Code Site OTA']) ?></strong></td>
                                        <td><?= htmlspecialchars($site['code site operateur']) ?></td>
                                        <td>
                                            <span class="badge bg-primary"><?= htmlspecialchars($site['Operateur']) ?></span>
                                        </td>
                                        <td><?= htmlspecialchars($site['statut sharing']) ?></td>
                                        <td><?= htmlspecialchars($site['type site']) ?></td>
                                        <td><?= htmlspecialchars($site['type installation']) ?></td>
                                        <td><?= htmlspecialchars($site['type Branchement power']) ?></td>
                                        <td><?= htmlspecialchars(substr($site['Notes'], 0, 50)) ?><?= strlen($site['Notes']) > 50 ? '...' : '' ?></td>
                                        <td>
                                            <button onclick="editSite(<?= $site['id'] ?>)" class="btn btn-sm btn-primary" title="Modifier">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button onclick="deleteSite(<?= $site['id'] ?>)" class="btn btn-sm btn-danger" title="Supprimer">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- HISTORIQUE D'IMPORT -->
                <div class="card fade-in">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-history"></i> Historique des Imports</h3>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Fichier</th>
                                        <th>Ajoutés</th>
                                        <th>Modifiés</th>
                                        <th>Ignorés</th>
                                        <th>Résumé</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($history as $h): ?>
                                    <tr>
                                        <td><?= date('d/m/Y H:i', strtotime($h['imported_at'])) ?></td>
                                        <td><?= htmlspecialchars($h['file_name']) ?></td>
                                        <td><span class="badge bg-success"><?= $h['added'] ?></span></td>
                                        <td><span class="badge bg-info"><?= $h['updated'] ?></span></td>
                                        <td><span class="badge bg-warning"><?= $h['skipped'] ?></span></td>
                                        <td><?= htmlspecialchars($h['summary']) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>
        </section>
    </div>

    <!-- MODAL AJOUT/MODIFICATION -->
    <div class="modal fade" id="siteModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="modalTitle">
                        <i class="fas fa-plus-circle"></i> Nouveau Site Partagé
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="siteForm">
                    <div class="modal-body bg-light">
                        <input type="hidden" name="site_id" id="site_id">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Région *</label>
                                <input name="region" id="region" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Wilaya *</label>
                                <input name="wilaya" id="wilaya" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Date Offline</label>
                                <input name="offline_date" id="offline_date" type="date" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Type Cohabitation</label>
                                <input name="type_cohabitation" id="type_cohabitation" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Code Site OTA *</label>
                                <input name="code_site_ota" id="code_site_ota" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Code Opérateur</label>
                                <input name="code_operateur" id="code_operateur" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Opérateur *</label>
                                <select name="operateur" id="operateur" class="form-select" required>
                                    <option value="">-- Sélectionner --</option>
                                    <option value="Djezzy">Djezzy</option>
                                    <option value="Mobilis">Mobilis</option>
                                    <option value="Ooredoo">Ooredoo</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Statut Sharing</label>
                                <input name="statut_sharing" id="statut_sharing" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Type Site</label>
                                <input name="type_site" id="type_site" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Type Installation</label>
                                <input name="type_installation" id="type_installation" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Type Branchement Power</label>
                                <input name="type_power" id="type_power" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Notes</label>
                                <textarea name="notes" id="notes" class="form-control" rows="3"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times"></i> Annuler
                        </button>
                        <button type="submit" class="btn btn-warning">
                            <i class="fas fa-save"></i> Enregistrer
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php include('includes/footer.php'); ?>
</div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
$(document).ready(function() {
    // Initialize DataTable
    $('#sharingTable').DataTable({
        language: {
            url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/fr-FR.json'
        },
        pageLength: 25,
        order: [[0, 'desc']],
        responsive: true,
        dom: 'Bfrtip',
        buttons: ['copy', 'csv', 'excel', 'pdf', 'print']
    });
});

// Show Add Modal
function showAddModal() {
    document.getElementById('siteForm').reset();
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus-circle"></i> Nouveau Site Partagé';
    new bootstrap.Modal(document.getElementById('siteModal')).show();
}

// Edit Site
function editSite(id) {
    fetch('?get_site=' + id)
        .then(res => res.json())
        .then(data => {
            document.getElementById('site_id').value = data.id;
            document.getElementById('region').value = data.Region || '';
            document.getElementById('wilaya').value = data.Wilaya || '';
            document.getElementById('offline_date').value = data['Offline Date'] || '';
            document.getElementById('type_cohabitation').value = data['Type de Cohabitation'] || '';
            document.getElementById('code_site_ota').value = data['Code Site OTA'] || '';
            document.getElementById('code_operateur').value = data['code site operateur'] || '';
            document.getElementById('operateur').value = data.Operateur || '';
            document.getElementById('statut_sharing').value = data['statut sharing'] || '';
            document.getElementById('type_site').value = data['type site'] || '';
            document.getElementById('type_installation').value = data['type installation'] || '';
            document.getElementById('type_power').value = data['type Branchement power'] || '';
            document.getElementById('notes').value = data.Notes || '';
            
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Modifier Site';
            new bootstrap.Modal(document.getElementById('siteModal')).show();
        });
}

// Delete Site
function deleteSite(id) {
    Swal.fire({
        title: 'Êtes-vous sûr?',
        text: "Cette action est irréversible!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d70000',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Oui, supprimer!',
        cancelButtonText: 'Annuler'
    }).then((result) => {
        if (result.isConfirmed) {
            fetch('?delete_id=' + id)
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire('Supprimé!', data.message, 'success')
                            .then(() => location.reload());
                    }
                });
        }
    });
}

// Save Site (Add/Edit)
document.getElementById('siteForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    formData.append('save_site', '1');

    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            Swal.fire('Succès!', data.message, 'success')
                .then(() => location.reload());
        } else {
            Swal.fire('Erreur!', data.message, 'error');
        }
    });
});
</script>

</body>
</html>
