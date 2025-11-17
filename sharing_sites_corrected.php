<?php
session_start();

// Vérification de l'authentification
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

require_once 'connection.php';
require_once 'includes/config.php';

// === CONFIGURATION ET CONNEXION PDO ===
try {
    // Utiliser la connexion existante ou créer une nouvelle
    if (!isset($pdo)) {
        $pdo = new PDO(
            "mysql:host=$servername;dbname=$dbname;charset=utf8mb4", 
            $username, 
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]
        );
    }

    // Création des tables si elles n'existent pas
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
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_region (Region),
        INDEX idx_wilaya (Wilaya),
        INDEX idx_operateur (Operateur),
        INDEX idx_statut (`statut sharing`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS import_history_sharing (
        id INT AUTO_INCREMENT PRIMARY KEY,
        file_name VARCHAR(255),
        added INT DEFAULT 0,
        updated INT DEFAULT 0,
        skipped INT DEFAULT 0,
        errors TEXT,
        summary TEXT,
        imported_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_imported_at (imported_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

} catch (PDOException $e) {
    error_log("Erreur DB: " . $e->getMessage());
    die("<div class='alert alert-danger'>Erreur de connexion à la base de données. Veuillez contacter l'administrateur.</div>");
}

/* ====================== FONCTIONS UTILITAIRES ====================== */

/**
 * Convertit une date de différents formats vers Y-m-d
 */
function parseDate($raw) {
    if (empty(trim($raw))) {
        return null;
    }
    
    $raw = trim($raw);
    
    // Traitement des dates Excel (numéro de série)
    if (is_numeric($raw) && $raw > 1000) {
        try {
            $unix = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToTimestamp($raw);
            if ($unix !== false) {
                return date('Y-m-d', $unix);
            }
        } catch (Exception $e) {
            // Continue avec les autres méthodes
        }
    }
    
    // Formats de date courants
    $formats = ['d/m/Y', 'd-m-Y', 'd.m.Y', 'Y-m-d', 'd/m/Y H:i', 'Y/m/d', 'j/n/Y', 'm/d/Y'];
    foreach ($formats as $format) {
        $date = DateTime::createFromFormat($format, $raw);
        if ($date && $date->format($format) === $raw) {
            return $date->format('Y-m-d');
        }
    }
    
    // Tentative avec strtotime
    $ts = strtotime($raw);
    if ($ts && $ts > 0 && $ts < 2147483647) {
        return date('Y-m-d', $ts);
    }
    
    return null;
}

/**
 * Retourne une réponse JSON et termine le script
 */
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Nettoie et valide les données d'entrée
 */
function sanitizeInput($data) {
    return is_string($data) ? trim($data) : $data;
}

/* ====================== ACTIONS AJAX ====================== */

// Récupérer toutes les données pour DataTables (AJAX)
if (isset($_GET['get_all_data'])) {
    try {
        $stmt = $pdo->query("SELECT * FROM sharing_sites ORDER BY id DESC");
        $data = $stmt->fetchAll();
        jsonResponse($data);
    } catch (PDOException $e) {
        error_log("Erreur get_all_data: " . $e->getMessage());
        jsonResponse(['error' => 'Erreur lors de la récupération des données'], 500);
    }
}

// Supprimer un site
if (isset($_GET['delete_id'])) {
    try {
        $id = filter_var($_GET['delete_id'], FILTER_VALIDATE_INT);
        if (!$id) {
            jsonResponse(['success' => false, 'message' => 'ID invalide'], 400);
        }
        
        $stmt = $pdo->prepare("DELETE FROM sharing_sites WHERE id = ?");
        $stmt->execute([$id]);
        
        if ($stmt->rowCount() > 0) {
            jsonResponse(['success' => true, 'message' => 'Site supprimé avec succès']);
        } else {
            jsonResponse(['success' => false, 'message' => 'Site non trouvé'], 404);
        }
    } catch (PDOException $e) {
        error_log("Erreur delete_id: " . $e->getMessage());
        jsonResponse(['success' => false, 'message' => 'Erreur lors de la suppression'], 500);
    }
}

// Récupérer un site spécifique
if (isset($_GET['get_site'])) {
    try {
        $id = filter_var($_GET['get_site'], FILTER_VALIDATE_INT);
        if (!$id) {
            jsonResponse(['error' => 'ID invalide'], 400);
        }
        
        $stmt = $pdo->prepare("SELECT * FROM sharing_sites WHERE id = ?");
        $stmt->execute([$id]);
        $site = $stmt->fetch();
        
        if ($site) {
            jsonResponse($site);
        } else {
            jsonResponse(['error' => 'Site non trouvé'], 404);
        }
    } catch (PDOException $e) {
        error_log("Erreur get_site: " . $e->getMessage());
        jsonResponse(['error' => 'Erreur lors de la récupération'], 500);
    }
}

// Sauvegarder (ajouter ou modifier) un site
if (isset($_POST['save_site'])) {
    try {
        $id = !empty($_POST['site_id']) ? filter_var($_POST['site_id'], FILTER_VALIDATE_INT) : null;
        
        // Validation des champs obligatoires
        $required = ['region', 'wilaya', 'Code_Site_OTA', 'Opérateur'];
        foreach ($required as $field) {
            if (empty($_POST[$field])) {
                jsonResponse(['success' => false, 'message' => "Le champ $field est obligatoire"], 400);
            }
        }
        
        // Préparation des données
        $data = [
            'region' => sanitizeInput($_POST['region']),
            'wilaya' => sanitizeInput($_POST['wilaya']),
            'offline_date' => !empty($_POST['offline_date']) ? $_POST['offline_date'] : null,
            'type_cohabitation' => sanitizeInput($_POST['type_cohabitation'] ?? ''),
            'code_site_ota' => sanitizeInput($_POST['Code_Site_OTA']),
            'code_operateur' => sanitizeInput($_POST['code_operateur'] ?? ''),
            'operateur' => sanitizeInput($_POST['Opérateur']),
            'statut_sharing' => sanitizeInput($_POST['statut_sharing'] ?? 'Online'),
            'type_site' => sanitizeInput($_POST['type_site'] ?? ''),
            'type_installation' => sanitizeInput($_POST['type_installation'] ?? ''),
            'type_power' => sanitizeInput($_POST['type_power'] ?? ''),
            'notes' => sanitizeInput($_POST['notes'] ?? '')
        ];
        
        if ($id) {
            // Mise à jour
            $sql = "UPDATE sharing_sites SET 
                    Region = ?, Wilaya = ?, `Offline Date` = ?, `Type de Cohabitation` = ?, 
                    `Code Site OTA` = ?, `code site operateur` = ?, Operateur = ?, 
                    `statut sharing` = ?, `type site` = ?, `type installation` = ?, 
                    `type Branchement power` = ?, Notes = ? 
                    WHERE id = ?";
            $params = array_values($data);
            $params[] = $id;
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            jsonResponse(['success' => true, 'message' => 'Site modifié avec succès']);
        } else {
            // Insertion
            $sql = "INSERT INTO sharing_sites 
                    (Region, Wilaya, `Offline Date`, `Type de Cohabitation`, `Code Site OTA`,
                    `code site operateur`, Operateur, `statut sharing`, `type site`, 
                    `type installation`, `type Branchement power`, Notes) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_values($data));
            
            jsonResponse(['success' => true, 'message' => 'Site ajouté avec succès', 'id' => $pdo->lastInsertId()]);
        }
    } catch (PDOException $e) {
        error_log("Erreur save_site: " . $e->getMessage());
        
        // Gestion des erreurs de contrainte unique
        if ($e->getCode() == 23000) {
            jsonResponse(['success' => false, 'message' => 'Ce code site OTA existe déjà'], 400);
        }
        
        jsonResponse(['success' => false, 'message' => 'Erreur lors de l\'enregistrement'], 500);
    }
}

// Import de fichier ODS
if (isset($_POST['import_ods'])) {
    require_once 'vendor/autoload.php';
    
    $errors = [];
    $added = 0;
    $updated = 0;
    $skipped = 0;
    $fileName = 'unknown';

    try {
        if (!isset($_FILES['ods_file']) || $_FILES['ods_file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Erreur lors du téléchargement du fichier.');
        }
        
        $fileName = basename($_FILES['ods_file']['name']);
        $file = $_FILES['ods_file']['tmp_name'];
        
        // Vérification de l'extension
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if ($ext !== 'ods') {
            throw new Exception('Seuls les fichiers .ods sont acceptés.');
        }
        
        // Chargement du fichier
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);

        if (count($rows) < 2) {
            throw new Exception('Le fichier ne contient pas de données valides.');
        }
        
        // Suppression de l'en-tête
        array_shift($rows);
        
        // Préparation des requêtes
        $stmt_check = $pdo->prepare("SELECT id FROM sharing_sites WHERE `Code Site OTA` = ?");
        $stmt_insert = $pdo->prepare("INSERT INTO sharing_sites 
            (Region, Wilaya, `Offline Date`, `Type de Cohabitation`, `Code Site OTA`, 
            `code site operateur`, Operateur, `statut sharing`, `type site`, 
            `type installation`, `type Branchement power`, Notes) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt_update = $pdo->prepare("UPDATE sharing_sites SET 
            Region = ?, Wilaya = ?, `Offline Date` = ?, `Type de Cohabitation` = ?, 
            `code site operateur` = ?, Operateur = ?, `statut sharing` = ?, 
            `type site` = ?, `type installation` = ?, `type Branchement power` = ?, Notes = ? 
            WHERE `Code Site OTA` = ?");

        // Traitement des lignes
        $pdo->beginTransaction();
        
        foreach ($rows as $i => $row) {
            $row = array_values($row);
            
            // Ignorer les lignes vides
            if (empty($row[0]) && empty($row[1]) && empty($row[4])) {
                continue;
            }

            // Extraction et nettoyage des données
            $region = sanitizeInput($row[0] ?? '');
            $wilaya = sanitizeInput($row[1] ?? '');
            $offline_date = parseDate($row[2] ?? '');
            $type_cohabitation = sanitizeInput($row[3] ?? '');
            $code_site_ota = sanitizeInput($row[4] ?? '');
            $code_operateur = sanitizeInput($row[5] ?? '');
            $operateur = sanitizeInput($row[6] ?? '');
            $statut_sharing = sanitizeInput($row[7] ?? 'Online');
            $type_site = sanitizeInput($row[8] ?? '');
            $type_installation = sanitizeInput($row[9] ?? '');
            $type_power = sanitizeInput($row[10] ?? '');
            $notes = sanitizeInput($row[11] ?? '');

            // Validation du code site OTA (obligatoire)
            if (empty($code_site_ota)) {
                $skipped++;
                $errors[] = "Ligne " . ($i + 2) . ": Code Site OTA manquant";
                continue;
            }

            try {
                // Vérifier si le site existe
                $stmt_check->execute([$code_site_ota]);
                $existing = $stmt_check->fetch();

                if ($existing) {
                    // Mise à jour
                    $stmt_update->execute([
                        $region, $wilaya, $offline_date, $type_cohabitation, 
                        $code_operateur, $operateur, $statut_sharing, $type_site, 
                        $type_installation, $type_power, $notes, $code_site_ota
                    ]);
                    $updated++;
                } else {
                    // Insertion
                    $stmt_insert->execute([
                        $region, $wilaya, $offline_date, $type_cohabitation, $code_site_ota, 
                        $code_operateur, $operateur, $statut_sharing, $type_site, 
                        $type_installation, $type_power, $notes
                    ]);
                    $added++;
                }
            } catch (PDOException $e) {
                $errors[] = "Ligne " . ($i + 2) . ": " . $e->getMessage();
                $skipped++;
            }
        }
        
        $pdo->commit();
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errors[] = 'Erreur critique: ' . $e->getMessage();
        error_log("Erreur import_ods: " . $e->getMessage());
    }

    // Enregistrement de l'historique
    try {
        $summary = "✅ $added ajoutés | 🔄 $updated modifiés | ⏭️ $skipped ignorés";
        $errorStr = implode('; ', array_slice($errors, 0, 20));
        
        $stmt = $pdo->prepare("INSERT INTO import_history_sharing 
            (file_name, added, updated, skipped, errors, summary) 
            VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$fileName, $added, $updated, $skipped, $errorStr, $summary]);
    } catch (PDOException $e) {
        error_log("Erreur enregistrement historique: " . $e->getMessage());
    }

    // Redirection avec message
    $message = $summary;
    if (!empty($errors)) {
        $message .= ' | ' . count($errors) . ' erreur(s) détectée(s)';
    }
    header("Location: ?import_success=" . urlencode($message));
    exit;
}

// Télécharger le modèle ODS
if (isset($_GET['download_template'])) {
    require_once 'vendor/autoload.php';
    
    try {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        $headers = [
            'Région', 'Wilaya', 'Offline Date', 'Type de Cohabitation', 
            'Code Site OTA', 'code site operateur', 'Opérateur', 'statut sharing', 
            'type site', 'type installation', 'type Branchement power', 'Notes'
        ];
        
        $sheet->fromArray([$headers], null, 'A1');
        
        // Style de l'en-tête
        $headerStyle = $sheet->getStyle('A1:L1');
        $headerStyle->getFont()->setBold(true)->setSize(12)->getColor()->setARGB('FFFFFFFF');
        $headerStyle->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFD70000');
        $headerStyle->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        
        // Ajustement automatique des colonnes
        foreach(range('A','L') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        
        // Ajout d'une ligne d'exemple
        $example = [
            'Centre', 'Alger', '2024-01-15', 'Cohabitation Active', 
            'OTA001', 'OP001', 'Djezzy', 'Online', 
            'Macro', 'Extérieur', 'SONELGAZ', 'Notes exemple'
        ];
        $sheet->fromArray([$example], null, 'A2');
        
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Ods($spreadsheet);
        
        header('Content-Type: application/vnd.oasis.opendocument.spreadsheet');
        header('Content-Disposition: attachment;filename="modele_djezzy_sharing_' . date('Y-m-d') . '.ods"');
        header('Cache-Control: max-age=0');
        header('Pragma: public');
        
        $writer->save('php://output');
        exit;
    } catch (Exception $e) {
        error_log("Erreur download_template: " . $e->getMessage());
        die("Erreur lors de la génération du modèle");
    }
}

// Exporter vers Excel
if (isset($_GET['export_excel'])) {
    require_once 'vendor/autoload.php';
    
    try {
        $stmt = $pdo->query("SELECT * FROM sharing_sites ORDER BY id DESC");
        $data = $stmt->fetchAll();
        
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        $headers = [
            'ID', 'Région', 'Wilaya', 'Offline Date', 'Type Cohabitation', 
            'Code Site OTA', 'Code Opérateur', 'Opérateur', 'Statut', 
            'Type Site', 'Installation', 'Power', 'Notes', 'Date Création'
        ];
        
        $sheet->fromArray([$headers], null, 'A1');
        
        // Style de l'en-tête
        $headerStyle = $sheet->getStyle('A1:N1');
        $headerStyle->getFont()->setBold(true)->setSize(12)->getColor()->setARGB('FFFFFFFF');
        $headerStyle->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FFD70000');
        $headerStyle->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        
        // Ajout des données
        $row = 2;
        foreach($data as $site) {
            $sheet->fromArray([
                $site['id'], 
                $site['Region'], 
                $site['Wilaya'], 
                $site['Offline Date'],
                $site['Type de Cohabitation'], 
                $site['Code Site OTA'], 
                $site['code site operateur'],
                $site['Operateur'], 
                $site['statut sharing'], 
                $site['type site'],
                $site['type installation'], 
                $site['type Branchement power'], 
                $site['Notes'], 
                $site['created_at']
            ], null, 'A' . $row++);
        }
        
        // Ajustement automatique des colonnes
        foreach(range('A','N') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        
        // Filtres automatiques
        $sheet->setAutoFilter('A1:N1');
        
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="export_djezzy_sharing_' . date('Y-m-d') . '.xlsx"');
        header('Cache-Control: max-age=0');
        header('Pragma: public');
        
        $writer->save('php://output');
        exit;
    } catch (Exception $e) {
        error_log("Erreur export_excel: " . $e->getMessage());
        die("Erreur lors de l'export Excel");
    }
}

/* ====================== RÉCUPÉRATION DES DONNÉES POUR L'AFFICHAGE ====================== */

try {
    // Statistiques
    $stats = $pdo->query("SELECT 
        COUNT(*) as total,
        COUNT(CASE WHEN `statut sharing` LIKE '%offline%' THEN 1 END) as offline,
        COUNT(CASE WHEN `statut sharing` LIKE '%online%' THEN 1 END) as online,
        COUNT(DISTINCT Region) as regions
        FROM sharing_sites")->fetch();
    
    // Historique des imports (limité aux 15 derniers)
    $history = $pdo->query("SELECT * FROM import_history_sharing 
        ORDER BY imported_at DESC LIMIT 15")->fetchAll();
        
} catch (PDOException $e) {
    error_log("Erreur récupération données: " . $e->getMessage());
    $stats = ['total' => 0, 'offline' => 0, 'online' => 0, 'regions' => 0];
    $history = [];
}

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
    <link href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { 
            background-color: #ffffff; 
            color: #333;
            font-family: 'Arial', sans-serif;
        }
        .sidebar .nav-link {
            padding: 12px 15px;
            color: #333;
            font-family: 'Poppins', sans-serif;
            transition: all 0.3s ease;
            border-radius: 5px;
        }
        .sidebar .nav-link:hover, .sidebar .nav-link.active {
            background-color: #e9ecef;
            color: #dc3545;
            transform: translateX(5px);
        }
        .sidebar .nav-icon {
            font-size: 1.1rem;
            margin-right: 10px;
            color: #dc3545;
        }
        .stats-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 2px 15px rgba(215,0,0,0.1);
            margin-bottom: 20px;
            transition: all 0.3s ease;
            border-left: 5px solid #d70000;
        }
        .stats-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 25px rgba(215,0,0,0.2);
        }
        .stats-card .icon {
            font-size: 2.5rem;
            color: #d70000;
            margin-bottom: 10px;
        }
        .stats-card h3 {
            font-size: 2rem;
            font-weight: bold;
            margin: 10px 0 5px 0;
            color: #d70000;
        }
        .stats-card p {
            color: #666;
            margin: 0;
            font-size: 0.9rem;
        }
        .card {
            margin-bottom: 25px;
            border-radius: 15px;
            box-shadow: 0 2px 15px rgba(0,0,0,0.08);
            border: none;
        }
        .card-header {
            background: linear-gradient(135deg, #d70000 0%, #a00000 100%);
            color: white;
            border-radius: 15px 15px 0 0 !important;
            padding: 1.25rem;
            border: none;
        }
        .card-title {
            color: white;
            margin: 0;
            font-weight: 600;
            font-size: 1.1rem;
        }
        .btn-djezzy {
            background-color: #d70000;
            border-color: #d70000;
            color: white;
            font-weight: bold;
            transition: all 0.3s ease;
        }
        .btn-djezzy:hover {
            background-color: #b30000;
            border-color: #b30000;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(215,0,0,0.3);
        }
        .table-hover tbody tr:hover {
            background-color: rgba(215,0,0,0.05);
            transition: background-color 0.2s ease;
        }
        .modal-header {
            background: linear-gradient(135deg, #d70000 0%, #a00000 100%);
            color: white;
            border-radius: 0;
        }
        .status-online {
            background-color: #28a745 !important;
            color: white !important;
        }
        .status-offline {
            background-color: #dc3545 !important;
            color: white !important;
        }
        .badge-djezzy {
            background-color: #d70000;
            color: white;
        }
        h1 {
            color: #d70000;
            font-weight: 900;
            text-shadow: 0 3px 10px rgba(215, 0, 0, 0.2);
            margin-bottom: 30px;
        }
        .import-zone {
            border: 2px dashed #d70000;
            border-radius: 10px;
            padding: 20px;
            background-color: #fff5f5;
            transition: all 0.3s ease;
        }
        .import-zone:hover {
            border-color: #b30000;
            background-color: #ffe5e5;
        }
        .fade-in {
            animation: fadeIn 0.5s ease-in-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .action-buttons {
            display: flex;
            gap: 5px;
            justify-content: center;
            flex-wrap: nowrap;
        }
        .btn-xs {
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
            line-height: 1.2;
            border-radius: 0.25rem;
        }
        .form-label {
            font-weight: 600;
            color: #495057;
            margin-bottom: 0.4rem;
            font-size: 0.9rem;
        }
        .dt-button {
            border-radius: 0.375rem !important;
            margin-right: 5px !important;
        }
        .content-wrapper {
            background: #f8f9fa !important;
        }
        .dataTables_processing {
            background: linear-gradient(135deg, #d70000 0%, #a00000 100%);
            color: white;
            border: none;
            border-radius: 10px;
            padding: 20px;
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
                <h1 class="text-center mt-4 mb-4 fade-in">
                    <i class="fas fa-tower-broadcast"></i> Sites Sharing OTA
                </h1>
                <?php if (isset($_GET['import_success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show fade-in" role="alert">
                        <i class="fas fa-check-circle"></i> <?= htmlspecialchars($_GET['import_success']) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="content">
            <div class="container-fluid">

                <!-- STATISTIQUES -->
                <div class="row mb-4">
                    <div class="col-lg-3 col-md-6">
                        <div class="stats-card fade-in">
                            <div class="icon"><i class="fas fa-database"></i></div>
                            <h3><?= number_format($stats['total']) ?></h3>
                            <p>Total Sites</p>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="stats-card fade-in" style="animation-delay: 0.1s">
                            <div class="icon"><i class="fas fa-check-circle"></i></div>
                            <h3><?= number_format($stats['online']) ?></h3>
                            <p>Sites Online</p>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="stats-card fade-in" style="animation-delay: 0.2s">
                            <div class="icon"><i class="fas fa-times-circle"></i></div>
                            <h3><?= number_format($stats['offline']) ?></h3>
                            <p>Sites Offline</p>
                        </div>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <div class="stats-card fade-in" style="animation-delay: 0.3s">
                            <div class="icon"><i class="fas fa-map-marked-alt"></i></div>
                            <h3><?= number_format($stats['regions']) ?></h3>
                            <p>Régions</p>
                        </div>
                    </div>
                </div>

                <!-- IMPORT CARD -->
                <div class="card fade-in">
                    <div class="card-header">
                        <h5 class="card-title"><i class="fas fa-file-import"></i> Import & Actions Rapides</h5>
                    </div>
                    <div class="card-body">
                        <div class="import-zone">
                            <form method="POST" enctype="multipart/form-data" class="row g-3 align-items-end" id="importForm">
                                <div class="col-md-8">
                                    <label class="form-label"><i class="fas fa-file-excel"></i> Fichier ODS à importer</label>
                                    <input type="file" name="ods_file" accept=".ods" required class="form-control" id="odsFileInput">
                                    <small class="text-muted">Format accepté : .ods uniquement</small>
                                </div>
                                <div class="col-md-4">
                                    <button type="submit" name="import_ods" class="btn btn-djezzy w-100" id="importBtn">
                                        <i class="fas fa-upload"></i> IMPORTER ODS
                                    </button>
                                </div>
                            </form>
                        </div>
                        <div class="mt-4 d-flex gap-2 flex-wrap">
                            <a href="?download_template=1" class="btn btn-info">
                                <i class="fas fa-download"></i> Modèle ODS
                            </a>
                            <a href="?export_excel=1" class="btn btn-success">
                                <i class="fas fa-file-excel"></i> Export Excel
                            </a>
                            <button class="btn btn-secondary" data-bs-toggle="modal" data-bs-target="#historyModal">
                                <i class="fas fa-history"></i> Historique (<?= count($history) ?>)
                            </button>
                            <button onclick="showAddModal()" class="btn btn-djezzy">
                                <i class="fas fa-plus-circle"></i> Nouveau Site
                            </button>
                        </div>
                    </div>
                </div>

                <!-- TABLEAU PRINCIPAL -->
                <div class="card fade-in">
                    <div class="card-header">
                        <h5 class="card-title"><i class="fas fa-table"></i> Liste des Sites en Cohabitation</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table id="sharingTable" class="table table-striped table-hover table-sm" style="width:100%">
                                <thead class="table-dark">
                                    <tr>
                                        <th>ID</th>
                                        <th>Région</th>
                                        <th>Wilaya</th>
                                        <th>Date Offline</th>
                                        <th>Type Cohab.</th>
                                        <th>Code OTA</th>
                                        <th>Code Opérateur</th>
                                        <th>Opérateur</th>
                                        <th>Statut</th>
                                        <th>Type Site</th>
                                        <th>Installation</th>
                                        <th>Power</th>
                                        <th>Notes</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <!-- Les données seront chargées via DataTables AJAX -->
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
                    <h5 class="modal-title" id="modalTitle"><i class="fas fa-tower-broadcast"></i> Nouveau Site Partagé</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="siteForm">
                    <div class="modal-body">
                        <input type="hidden" name="site_id">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label"><i class="fas fa-map-marked-alt"></i> Région *</label>
                                <input name="region" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label"><i class="fas fa-map-pin"></i> Wilaya *</label>
                                <input name="wilaya" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label"><i class="fas fa-calendar-times"></i> Date Offline</label>
                                <input name="offline_date" type="date" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label"><i class="fas fa-handshake"></i> Type Cohabitation</label>
                                <input name="type_cohabitation" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label"><i class="fas fa-barcode"></i> Code Site OTA *</label>
                                <input name="Code_Site_OTA" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label"><i class="fas fa-hashtag"></i> Code Opérateur</label>
                                <input name="code_operateur" class="form-control">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label"><i class="fas fa-building"></i> Opérateur *</label>
                                <select name="Opérateur" class="form-select" required>
                                    <option value="">-- Sélectionner --</option>
                                    <option value="Djezzy">Djezzy</option>
                                    <option value="Mobilis">Mobilis</option>
                                    <option value="Ooredoo">Ooredoo</option>
                                    <option value="Autre">Autre</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label"><i class="fas fa-signal"></i> Statut Sharing</label>
                                <select name="statut_sharing" class="form-select">
                                    <option value="Online">Online</option>
                                    <option value="Offline">Offline</option>
                                    <option value="En cours">En cours</option>
                                    <option value="Maintenance">Maintenance</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label"><i class="fas fa-sitemap"></i> Type Site</label>
                                <input name="type_site" class="form-control" placeholder="Ex: Macro, Micro">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label"><i class="fas fa-tools"></i> Type Installation</label>
                                <input name="type_installation" class="form-control" placeholder="Ex: Intérieur, Extérieur">
                            </div>
                            <div class="col-md-12">
                                <label class="form-label"><i class="fas fa-plug"></i> Type Branchement Power</label>
                                <input name="type_power" class="form-control" placeholder="Ex: SONELGAZ, Groupe électrogène">
                            </div>
                            <div class="col-md-12">
                                <label class="form-label"><i class="fas fa-sticky-note"></i> Notes</label>
                                <textarea name="notes" class="form-control" rows="3" placeholder="Notes supplémentaires..."></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times"></i> Annuler
                        </button>
                        <button type="submit" class="btn btn-djezzy">
                            <i class="fas fa-save"></i> Enregistrer
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- MODAL HISTORIQUE -->
    <div class="modal fade" id="historyModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-history"></i> Historique des Imports</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-striped mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Date & Heure</th>
                                    <th>Fichier</th>
                                    <th>Ajoutés</th>
                                    <th>Modifiés</th>
                                    <th>Ignorés</th>
                                    <th>Résumé</th>
                                    <th>Erreurs</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(empty($history)): ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">
                                        <i class="fas fa-inbox fa-3x mb-2"></i><br>
                                        Aucun historique d'import disponible
                                    </td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($history as $h): ?>
                                <tr>
                                    <td class="text-nowrap">
                                        <i class="fas fa-clock text-muted"></i> 
                                        <?= date('d/m/Y H:i:s', strtotime($h['imported_at'])) ?>
                                    </td>
                                    <td>
                                        <i class="fas fa-file-alt" style="color: #d70000;"></i> 
                                        <?= htmlspecialchars($h['file_name']) ?>
                                    </td>
                                    <td><span class="badge bg-success"><?= $h['added'] ?></span></td>
                                    <td><span class="badge bg-warning text-dark"><?= $h['updated'] ?></span></td>
                                    <td><span class="badge bg-danger"><?= $h['skipped'] ?></span></td>
                                    <td><small><?= htmlspecialchars($h['summary']) ?></small></td>
                                    <td>
                                        <?php if(!empty($h['errors'])): ?>
                                        <button class="btn btn-xs btn-outline-danger" onclick="showErrors(`<?= htmlspecialchars(addslashes($h['errors'])) ?>`)">
                                            <i class="fas fa-exclamation-triangle"></i> Voir
                                        </button>
                                        <?php else: ?>
                                        <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <footer class="main-footer">
        <strong>&copy; <?= date('Y') ?> DJEZZY M_Alger</strong> - Tous droits réservés.
        <div class="float-right d-none d-sm-inline-block">
            <b>Version</b> 2.1 | <b>Développé par</b> <a href="https://tosaprod.otalgerie.com/tosaportal/" target="_blank" style="color: #d70000; font-weight: bold;">Ayache - AFMR ALGER</a>
        </div>
    </footer>
</div>

<?php include('includes/footer.php'); ?>

<!-- SCRIPTS -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
// Configuration globale
const APP_CONFIG = {
    ajaxTimeout: 30000,
    animationDelay: 30
};

// Initialisation au chargement du DOM
$(document).ready(function() {
    initDataTable();
    initFormHandlers();
    initImportForm();
    autoHideAlerts();
});

// Initialisation DataTable avec AJAX
function initDataTable() {
    window.sharingTable = $('#sharingTable').DataTable({
        ajax: {
            url: window.location.pathname,
            type: 'GET',
            data: { get_all_data: true },
            dataSrc: function(json) {
                if (json.error) {
                    console.error('Erreur AJAX:', json.error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Erreur de chargement',
                        text: json.error,
                        confirmButtonColor: '#d70000'
                    });
                    return [];
                }
                return json;
            },
            error: function(xhr, error, thrown) {
                console.error('Erreur AJAX:', error, thrown);
                Swal.fire({
                    icon: 'error',
                    title: 'Erreur de connexion',
                    text: 'Impossible de charger les données. Veuillez rafraîchir la page.',
                    confirmButtonColor: '#d70000'
                });
            }
        },
        processing: true,
        responsive: true,
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "Tous"]],
        language: { 
            url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/fr-FR.json',
            processing: '<i class="fas fa-spinner fa-spin fa-3x"></i><br>Chargement des données...'
        },
        order: [[0, 'desc']],
        dom: 'Blfrtip',
        buttons: [
            {
                extend: 'copy',
                text: '<i class="fas fa-copy"></i> Copier',
                className: 'btn btn-sm btn-secondary'
            },
            {
                extend: 'excel',
                text: '<i class="fas fa-file-excel"></i> Excel',
                className: 'btn btn-sm btn-success',
                exportOptions: { columns: ':not(:last-child)' }
            },
            {
                extend: 'pdf',
                text: '<i class="fas fa-file-pdf"></i> PDF',
                className: 'btn btn-sm btn-danger',
                orientation: 'landscape',
                pageSize: 'A4',
                exportOptions: { columns: ':not(:last-child)' }
            },
            {
                extend: 'print',
                text: '<i class="fas fa-print"></i> Imprimer',
                className: 'btn btn-sm btn-info',
                exportOptions: { columns: ':not(:last-child)' }
            }
        ],
        columns: [
            { 
                data: 'id',
                render: (d) => `<span class="badge badge-djezzy">#${d}</span>`
            },
            { 
                data: 'Region',
                render: (d) => d || '<span class="text-muted">-</span>'
            },
            { 
                data: 'Wilaya',
                render: (d) => d || '<span class="text-muted">-</span>'
            },
            { 
                data: 'Offline Date', 
                render: (d) => {
                    if (!d || d === '0000-00-00' || d === null) return '<span class="text-muted">-</span>';
                    try {
                        const date = new Date(d);
                        return `<small>${date.toLocaleDateString('fr-FR')}</small>`;
                    } catch(e) {
                        return '<span class="text-muted">-</span>';
                    }
                }
            },
            { 
                data: 'Type de Cohabitation',
                render: (d) => d ? `<small>${escapeHtml(d)}</small>` : '<span class="text-muted">-</span>'
            },
            { 
                data: 'Code Site OTA', 
                render: (d) => d ? `<strong style="color: #d70000;">${escapeHtml(d)}</strong>` : '<span class="text-muted">-</span>'
            },
            { 
                data: 'code site operateur',
                render: (d) => d ? escapeHtml(d) : '<span class="text-muted">-</span>'
            },
            { 
                data: 'Operateur', 
                render: (d) => {
                    if (!d) return '<span class="text-muted">-</span>';
                    const colors = {
                        'Djezzy': 'danger',
                        'Mobilis': 'info',
                        'Ooredoo': 'warning'
                    };
                    const color = colors[d] || 'secondary';
                    return `<span class="badge bg-${color}">${escapeHtml(d)}</span>`;
                }
            },
            { 
                data: 'statut sharing', 
                render: (d) => {
                    if (!d) return '<span class="badge bg-secondary">N/A</span>';
                    const isOffline = d.toLowerCase().includes('offline');
                    const cls = isOffline ? 'status-offline' : 'status-online';
                    const icon = isOffline ? 'times-circle' : 'check-circle';
                    return `<span class="badge ${cls}"><i class="fas fa-${icon}"></i> ${escapeHtml(d)}</span>`;
                }
            },
            { 
                data: 'type site',
                render: (d) => d ? `<small>${escapeHtml(d)}</small>` : '<span class="text-muted">-</span>'
            },
            { 
                data: 'type installation',
                render: (d) => d ? `<small>${escapeHtml(d)}</small>` : '<span class="text-muted">-</span>'
            },
            { 
                data: 'type Branchement power',
                render: (d) => d ? `<small>${escapeHtml(d)}</small>` : '<span class="text-muted">-</span>'
            },
            { 
                data: 'Notes', 
                render: (d) => {
                    if (!d || d === '') return '<span class="text-muted">-</span>';
                    const short = d.substring(0, 30);
                    const full = escapeHtml(d);
                    return `<small class="text-muted" style="cursor:pointer" 
                            onclick="showNotes(\`${full}\`)" 
                            title="Cliquer pour voir">
                            ${escapeHtml(short)}${d.length > 30 ? '...' : ''}
                            </small>`;
                }
            },
            { 
                data: null, 
                orderable: false,
                render: (d) => `
                    <div class="action-buttons">
                        <button class="btn btn-primary btn-xs" onclick="editSite(${d.id})" title="Modifier">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="btn btn-danger btn-xs" onclick="deleteSite(${d.id})" title="Supprimer">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    </div>
                `
            }
        ],
        drawCallback: function() {
            $('#sharingTable tbody tr').each(function(i) {
                $(this).css({
                    'animation': `fadeIn 0.5s ease ${i * APP_CONFIG.animationDelay / 1000}s both`
                });
            });
        }
    });
}

// Initialisation des gestionnaires de formulaire
function initFormHandlers() {
    $('#siteForm').on('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        formData.append('save_site', '1');

        const submitBtn = $(this).find('button[type="submit"]');
        const originalHtml = submitBtn.html();
        submitBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Enregistrement...');

        $.ajax({
            url: window.location.pathname,
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json',
            timeout: APP_CONFIG.ajaxTimeout,
            success: function(response) {
                if (response.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Succès !',
                        text: response.message,
                        timer: 2000,
                        showConfirmButton: false,
                        confirmButtonColor: '#d70000'
                    }).then(() => {
                        $('#siteModal').modal('hide');
                        window.sharingTable.ajax.reload(null, false);
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Erreur',
                        text: response.message,
                        confirmButtonColor: '#d70000'
                    });
                    submitBtn.prop('disabled', false).html(originalHtml);
                }
            },
            error: function(xhr, status, error) {
                console.error('Erreur AJAX:', status, error);
                Swal.fire({
                    icon: 'error',
                    title: 'Erreur serveur',
                    text: 'Une erreur est survenue lors de l\'enregistrement',
                    confirmButtonColor: '#d70000'
                });
                submitBtn.prop('disabled', false).html(originalHtml);
            }
        });
    });
}

// Initialisation du formulaire d'import
function initImportForm() {
    $('#importForm').on('submit', function(e) {
        const fileInput = $('#odsFileInput')[0];
        if (fileInput.files.length === 0) {
            e.preventDefault();
            Swal.fire({
                icon: 'warning',
                title: 'Attention',
                text: 'Veuillez sélectionner un fichier ODS',
                confirmButtonColor: '#d70000'
            });
            return false;
        }
        
        const file = fileInput.files[0];
        if (!file.name.toLowerCase().endsWith('.ods')) {
            e.preventDefault();
            Swal.fire({
                icon: 'error',
                title: 'Format invalide',
                text: 'Seuls les fichiers .ods sont acceptés',
                confirmButtonColor: '#d70000'
            });
            return false;
        }
        
        // Afficher un loader pendant l'import
        const btn = $('#importBtn');
        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Import en cours...');
        
        Swal.fire({
            title: 'Import en cours',
            html: 'Veuillez patienter pendant le traitement du fichier...',
            allowOutsideClick: false,
            allowEscapeKey: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });
    });
}

// Masquer automatiquement les alertes
function autoHideAlerts() {
    setTimeout(function() {
        $('.alert-success').fadeOut('slow');
    }, 5000);
}

// Échapper les caractères HTML
function escapeHtml(text) {
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;',
        '`': '&#96;'
    };
    return String(text).replace(/[&<>"'`]/g, m => map[m]);
}

// Afficher modal ajout
function showAddModal() {
    $('#modalTitle').html('<i class="fas fa-tower-broadcast"></i> Nouveau Site Partagé');
    $('#siteForm')[0].reset();
    $('[name="site_id"]').val('');
    $('[name="statut_sharing"]').val('Online');
    $('#siteModal').modal('show');
}

// Éditer un site
function editSite(id) {
    $.ajax({
        url: window.location.pathname,
        method: 'GET',
        data: { get_site: id },
        dataType: 'json',
        timeout: APP_CONFIG.ajaxTimeout,
        success: function(site) {
            if (site && !site.error) {
                $('#modalTitle').html('<i class="fas fa-edit"></i> Modifier le Site #' + site.id);
                $('[name="site_id"]').val(site.id);
                $('[name="region"]').val(site.Region || '');
                $('[name="wilaya"]').val(site.Wilaya || '');
                $('[name="offline_date"]').val(site['Offline Date'] || '');
                $('[name="type_cohabitation"]').val(site['Type de Cohabitation'] || '');
                $('[name="Code_Site_OTA"]').val(site['Code Site OTA'] || '');
                $('[name="code_operateur"]').val(site['code site operateur'] || '');
                $('[name="Opérateur"]').val(site.Operateur || '');
                $('[name="statut_sharing"]').val(site['statut sharing'] || 'Online');
                $('[name="type_site"]').val(site['type site'] || '');
                $('[name="type_installation"]').val(site['type installation'] || '');
                $('[name="type_power"]').val(site['type Branchement power'] || '');
                $('[name="notes"]').val(site.Notes || '');
                $('#siteModal').modal('show');
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Erreur',
                    text: site.error || 'Impossible de charger les données du site',
                    confirmButtonColor: '#d70000'
                });
            }
        },
        error: function(xhr, status, error) {
            console.error('Erreur editSite:', status, error);
            Swal.fire({
                icon: 'error',
                title: 'Erreur',
                text: 'Erreur de connexion au serveur',
                confirmButtonColor: '#d70000'
            });
        }
    });
}

// Supprimer un site
function deleteSite(id) {
    Swal.fire({
        title: 'Confirmer la suppression ?',
        text: "Cette action est définitive et irréversible !",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d70000',
        cancelButtonColor: '#6c757d',
        confirmButtonText: '<i class="fas fa-trash-alt"></i> Oui, supprimer',
        cancelButtonText: '<i class="fas fa-times"></i> Annuler',
        showLoaderOnConfirm: true,
        preConfirm: () => {
            return $.ajax({
                url: window.location.pathname,
                method: 'GET',
                data: { delete_id: id },
                dataType: 'json',
                timeout: APP_CONFIG.ajaxTimeout
            }).then(response => {
                if (!response.success) {
                    throw new Error(response.message);
                }
                return response;
            }).catch(error => {
                Swal.showValidationMessage(`Erreur: ${error.message || error}`);
            });
        },
        allowOutsideClick: () => !Swal.isLoading()
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire({
                icon: 'success',
                title: 'Supprimé !',
                text: 'Le site a été supprimé avec succès',
                timer: 2000,
                showConfirmButton: false,
                confirmButtonColor: '#d70000'
            }).then(() => {
                window.sharingTable.ajax.reload(null, false);
            });
        }
    });
}

// Afficher les notes complètes
function showNotes(notes) {
    Swal.fire({
        title: '<i class="fas fa-sticky-note"></i> Notes complètes',
        html: `<div style="text-align: left; max-height: 400px; overflow-y: auto; padding: 10px; white-space: pre-wrap;">
                ${notes}
               </div>`,
        icon: 'info',
        confirmButtonColor: '#d70000',
        confirmButtonText: '<i class="fas fa-check"></i> Fermer',
        width: '600px'
    });
}

// Afficher les erreurs d'import
function showErrors(errors) {
    Swal.fire({
        title: '<i class="fas fa-exclamation-triangle"></i> Erreurs d\'import',
        html: `<div style="text-align: left; max-height: 400px; overflow-y: auto; padding: 10px;">
                ${errors.replace(/;/g, '<br>')}
               </div>`,
        icon: 'error',
        confirmButtonColor: '#d70000',
        confirmButtonText: '<i class="fas fa-check"></i> Fermer',
        width: '700px'
    });
}
</script>
</body>
</html>
