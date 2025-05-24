<?php
// Output buffering başlat
ob_start();

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit;
}

require_once '../config/db.php';

// Filtreleme parametreleri
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$job_filter = isset($_GET['job_id']) ? (int)$_GET['job_id'] : 0;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'newest';
$time_filter = isset($_GET['time_filter']) ? $_GET['time_filter'] : '';

// Sayfalama
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// SQL koşulları
$conditions = [];
$params = [];

if (!empty($status_filter)) {
    $conditions[] = "a.status = :status";
    $params[':status'] = $status_filter;
}

if ($job_filter > 0) {
    $conditions[] = "a.job_id = :job_id";
    $params[':job_id'] = $job_filter;
}

if (!empty($search)) {
    $conditions[] = "(a.first_name LIKE :search OR a.last_name LIKE :search OR a.email LIKE :search OR j.title LIKE :search)";
    $params[':search'] = "%$search%";
}

// Zaman filtresi
if (!empty($time_filter)) {
    switch ($time_filter) {
        case 'today':
            $conditions[] = "DATE(a.created_at) = CURDATE()";
            break;
        case 'yesterday':
            $conditions[] = "DATE(a.created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
            break;
        case 'last7days':
            $conditions[] = "a.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
            break;
        case 'last30days':
            $conditions[] = "a.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
            break;
        case 'thismonth':
            $conditions[] = "MONTH(a.created_at) = MONTH(CURRENT_DATE()) AND YEAR(a.created_at) = YEAR(CURRENT_DATE())";
            break;
    }
}

// WHERE cümlesi oluştur
$where_clause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

// Sıralama
$order_clause = "ORDER BY a.created_at DESC"; // Varsayılan: En yeni
if ($sort === 'oldest') {
    $order_clause = "ORDER BY a.created_at ASC";
} elseif ($sort === 'highest_score') {
    $order_clause = "ORDER BY a.score DESC";
} elseif ($sort === 'alphabetical') {
    $order_clause = "ORDER BY a.first_name ASC, a.last_name ASC";
}

// Toplam başvuru sayısı ve sayfalama
$count_sql = "
    SELECT COUNT(*) as total 
    FROM applications a 
    JOIN jobs j ON a.job_id = j.id 
    $where_clause
";

$stmt = $db->prepare($count_sql);
foreach ($params as $key => $val) {
    $stmt->bindValue($key, $val);
}
$stmt->execute();
$count_result = $stmt->fetch(PDO::FETCH_ASSOC);
$total_applications = $count_result['total'];
$total_pages = ceil($total_applications / $per_page);

// Veritabanı tablolarını kontrol et (cv_score ve answer_score için)
try {
    // cv_score sütununu kontrol et
    $columnCheckStmt = $db->prepare("SHOW COLUMNS FROM applications LIKE 'cv_score'");
    $columnCheckStmt->execute();
    if ($columnCheckStmt->rowCount() == 0) {
        $db->exec("ALTER TABLE applications ADD COLUMN cv_score INT DEFAULT 0 COMMENT 'Adayın CV puanı (0-100)'");
    }
    
    // answer_score sütununu kontrol et
    $columnCheckStmt = $db->prepare("SHOW COLUMNS FROM application_answers LIKE 'answer_score'");
    $columnCheckStmt->execute();
    if ($columnCheckStmt->rowCount() == 0) {
        $db->exec("ALTER TABLE application_answers ADD COLUMN answer_score INT DEFAULT 0 COMMENT 'Açık uçlu sorulara verilen cevapların puanı (0-100)'");
    }
} catch (PDOException $e) {
    // Hata durumunda sessizce devam et
    error_log("Sütun kontrolü hatası: " . $e->getMessage());
}

// Başvuruları getir - CV skorunu ve açık uçlu soru skorlarını da dahil et
$sql = "
    SELECT 
        a.*, 
        j.title as job_title,
        j.location as job_location,
        (SELECT COUNT(*) FROM applications WHERE job_id = j.id) as application_count,
        a.cv_score,
        (SELECT AVG(aa.answer_score) FROM application_answers aa
         JOIN questions q ON aa.question_id = q.id
         WHERE aa.application_id = a.id AND q.question_type = 'open_ended') as open_ended_score
    FROM applications a 
    JOIN jobs j ON a.job_id = j.id 
    $where_clause
    $order_clause
    LIMIT :offset, :per_page
";

$stmt = $db->prepare($sql);
foreach ($params as $key => $val) {
    $stmt->bindValue($key, $val);
}
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->bindValue(':per_page', $per_page, PDO::PARAM_INT);
$stmt->execute();
$applications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Tüm iş ilanlarını getir (filtre için)
$stmt = $db->query("SELECT id, title FROM jobs ORDER BY title");
$jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Toplu durum güncelleme
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action']) && isset($_POST['selected_apps'])) {
    $bulk_action = $_POST['bulk_action'];
    $selected_ids = $_POST['selected_apps'];
    
    if (!empty($selected_ids) && in_array($bulk_action, ['mark_reviewed', 'mark_new', 'delete'])) {
        try {
            $db->beginTransaction();
            
            if ($bulk_action === 'delete') {
                // Seçili başvuruları sil
                $delete_ids = implode(',', array_map('intval', $selected_ids));
                $db->exec("DELETE FROM applications WHERE id IN ($delete_ids)");
                $success_message = count($selected_ids) . " başvuru başarıyla silindi.";
            } else {
                // Durum güncelle
                $new_status = ($bulk_action === 'mark_reviewed') ? 'reviewed' : 'new';
                $update_ids = implode(',', array_map('intval', $selected_ids));
                $db->exec("UPDATE applications SET status = '$new_status' WHERE id IN ($update_ids)");
                $status_text = ($new_status === 'reviewed') ? 'İncelendi' : 'Yeni';
                $success_message = count($selected_ids) . " başvurunun durumu '$status_text' olarak güncellendi.";
            }
            
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            $error_message = "İşlem sırasında bir hata oluştu: " . $e->getMessage();
        }
    }
}

// İstatistiksel veriler
$stmt = $db->query("SELECT COUNT(*) as total FROM applications");
$all_count = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$stmt = $db->query("SELECT COUNT(*) as new_count FROM applications WHERE status = 'new'");
$new_count = $stmt->fetch(PDO::FETCH_ASSOC)['new_count'];

$stmt = $db->query("SELECT COUNT(*) as reviewed_count FROM applications WHERE status = 'reviewed'");
$reviewed_count = $stmt->fetch(PDO::FETCH_ASSOC)['reviewed_count'];

$stmt = $db->query("SELECT COUNT(*) as today_count FROM applications WHERE DATE(created_at) = CURDATE()");
$today_count = $stmt->fetch(PDO::FETCH_ASSOC)['today_count'];
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Başvuru Yönetimi | İş Başvuru Sistemi</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <!-- Özel CSS -->
    <style>
        :root {
            --primary: #4361ee;
            --primary-hover: #3a56d4;
            --secondary: #747f8d;
            --success: #2ecc71;
            --info: #3498db;
            --warning: #f39c12;
            --danger: #e74c3c;
            --light: #f5f7fb;
            --dark: #343a40;
            --body-bg: #f9fafb;
            --body-color: #333;
            --card-bg: #ffffff;
            --card-border: #eaedf1;
            --card-shadow: 0 2px 6px rgba(0, 0, 0, 0.04);
        }
        
        body {
            background-color: var(--body-bg);
            color: var(--body-color);
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            font-size: 0.9rem;
        }
        
        .navbar {
            background-color: #ffffff !important;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            z-index: 1000;
            padding: 0.75rem 0;
        }
        
        .navbar-brand {
            font-weight: 700;
            color: var(--primary) !important;
        }
        
        .navbar-nav .nav-link {
            color: #6c757d;
            padding: 0.75rem 1rem;
            position: relative;
        }
        
        .navbar-nav .nav-link.active {
            color: var(--primary);
            font-weight: 600;
        }
        
        .navbar-nav .nav-link.active:after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 1rem;
            right: 1rem;
            height: 2px;
            background-color: var(--primary);
        }
        
        .navbar-nav .nav-link:hover {
            color: var(--primary);
        }
        
        .page-header {
            padding: 1.5rem 0;
            margin-bottom: 1.5rem;
            border-bottom: 1px solid var(--card-border);
            background-color: #fff;
        }
        
        .page-title {
            font-weight: 700;
            font-size: 1.75rem;
            margin-bottom: 0.25rem;
        }
        
        .page-subtitle {
            color: var(--secondary);
            font-weight: 400;
            margin-bottom: 0;
        }
        
        .card {
            background-color: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 10px;
            box-shadow: var(--card-shadow);
            margin-bottom: 1.5rem;
        }
        
        .card-header {
            background-color: transparent;
            border-bottom: 1px solid var(--card-border);
            padding: 1rem 1.25rem;
            font-weight: 600;
        }
        
        .card-title {
            margin-bottom: 0;
            font-size: 1rem;
            font-weight: 600;
        }
        
        .card-title {
            margin-bottom: 0;
            font-size: 1rem;
            font-weight: 600;
        }
        
        .filter-card {
            background-color: #fff;
            border-radius: 10px;
            box-shadow: var(--card-shadow);
            margin-bottom: 1.5rem;
            padding: 1.25rem;
        }
        
        .stats-card {
            display: flex;
            align-items: center;
            background-color: #fff;
            border-radius: 10px;
            box-shadow: var(--card-shadow);
            padding: 1.25rem;
            height: 100%;
            border-left: 4px solid var(--primary);
        }
        
        .stats-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1rem;
            font-size: 1.5rem;
        }
        
        .stats-primary {
            border-left-color: var(--primary);
        }
        .stats-primary .stats-icon {
            background-color: rgba(67, 97, 238, 0.15);
            color: var(--primary);
        }
        
        .stats-success {
            border-left-color: var(--success);
        }
        .stats-success .stats-icon {
            background-color: rgba(46, 204, 113, 0.15);
            color: var(--success);
        }
        
        .stats-warning {
            border-left-color: var(--warning);
        }
        .stats-warning .stats-icon {
            background-color: rgba(243, 156, 18, 0.15);
            color: var(--warning);
        }
        
        .stats-info {
            border-left-color: var(--info);
        }
        .stats-info .stats-icon {
            background-color: rgba(52, 152, 219, 0.15);
            color: var(--info);
        }
        
        .stats-content {
            flex: 1;
        }
        
        .stats-value {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            line-height: 1.2;
        }
        
        .stats-label {
            color: var(--secondary);
            font-size: 0.85rem;
            margin-bottom: 0;
        }
        
        .status-badge {
            padding: 0.35em 0.65em;
            font-size: 0.75em;
            font-weight: 600;
            border-radius: 50px;
        }
        
        .badge-new {
            background-color: rgba(52, 152, 219, 0.15);
            color: #3498db;
        }
        
        .badge-reviewed {
            background-color: rgba(46, 204, 113, 0.15);
            color: #2ecc71;
        }
        
        .avatar {
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            font-size: 14px;
            font-weight: 600;
            color: white;
        }
        
        .avatar-primary {
            background-color: var(--primary);
        }
        
        .avatar-success {
            background-color: var(--success);
        }
        
        .avatar-info {
            background-color: var(--info);
        }
        
        .avatar-warning {
            background-color: var(--warning);
        }
        
        .avatar-danger {
            background-color: var(--danger);
        }
        
        .avatar-secondary {
            background-color: var(--secondary);
        }
        
        .table {
            margin-bottom: 0;
        }
        
        .table th {
            font-weight: 600;
            color: var(--secondary);
            border-top: none;
        }
        
        .table td {
            vertical-align: middle;
        }
        
        .table-hover tbody tr:hover {
            background-color: rgba(0, 0, 0, 0.01);
        }
        
        .pagination {
            margin-bottom: 0;
        }
        
        .page-link {
            color: var(--primary);
            border-color: #eaedf1;
            padding: 0.5rem 0.75rem;
        }
        
        .page-link:hover {
            color: var(--primary-hover);
            background-color: #f5f7fb;
            border-color: #eaedf1;
        }
        
        .page-item.active .page-link {
            background-color: var(--primary);
            border-color: var(--primary);
        }
        
        .search-input {
            border-radius: 50px;
            padding-left: 40px;
            background-color: #fff;
        }
        
        .search-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--secondary);
        }
        
        .btn {
            border-radius: 5px;
            padding: 0.5rem 1.25rem;
            font-weight: 500;
        }
        
        .btn-primary {
            background-color: var(--primary);
            border-color: var(--primary);
        }
        
        .btn-primary:hover {
            background-color: var(--primary-hover);
            border-color: var(--primary-hover);
        }
        
        .btn-outline-primary {
            color: var(--primary);
            border-color: var(--primary);
        }
        
        .btn-outline-primary:hover {
            background-color: var(--primary);
            border-color: var(--primary);
        }
        
        .btn-sm {
            padding: 0.25rem 0.75rem;
            font-size: 0.85rem;
        }
        
        .filters-wrapper {
            background-color: #fff;
            border-radius: 10px;
            padding: 1.25rem;
            box-shadow: var(--card-shadow);
        }
        
        .form-select, .form-control {
            border-color: #e2e8f0;
            border-radius: 5px;
        }
        
        .form-select:focus, .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 0.25rem rgba(67, 97, 238, 0.25);
        }
        
        .required-label::after {
            content: " *";
            color: var(--danger);
        }
        
        .form-text {
            color: var(--secondary);
        }
        
        .score-badge {
            border-radius: 4px; 
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        
        .score-high {
            background-color: rgba(46, 204, 113, 0.2);
            color: #27ae60;
        }
        
        .score-medium {
            background-color: rgba(243, 156, 18, 0.2);
            color: #f39c12;
        }
        
        .score-low {
            background-color: rgba(231, 76, 60, 0.2);
            color: #e74c3c;
        }
        
        .score-unavailable {
            background-color: #f5f5f5;
            color: #999;
        }
    </style>
</head>
<body>    <!-- Modern Admin Navbar -->
    <nav class="navbar navbar-expand-lg bg-white shadow-sm sticky-top">
        <div class="container-fluid">
            <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
                <i class="bi bi-briefcase me-2 text-primary"></i>
                <span class="fw-bold">İş Başvuru Sistemi</span>
            </a>
            
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#adminNavbar">
                <span class="navbar-toggler-icon"></span>
            </button>
            
            <div class="collapse navbar-collapse" id="adminNavbar">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : '' ?>" href="dashboard.php">
                            <i class="bi bi-speedometer2 me-1"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?= in_array(basename($_SERVER['PHP_SELF']), ['manage-applications.php', 'application-detail.php', 'application-detail2.php']) ? 'active' : '' ?>" href="#" id="applicationsDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-file-earmark-person me-1"></i> Başvurular
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="manage-applications.php"><i class="bi bi-list-ul me-2"></i>Tüm Başvurular</a></li>
                            <li><a class="dropdown-item" href="application-detail2.php"><i class="bi bi-search me-2"></i>Aday Değerlendirme</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="application-statistics.php"><i class="bi bi-bar-chart me-2"></i>İstatistikler</a></li>
                        </ul>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?= in_array(basename($_SERVER['PHP_SELF']), ['manage-jobs.php', 'create-job.php', 'edit-job.php', 'manage-job-questions.php']) ? 'active' : '' ?>" href="#" id="jobsDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-briefcase me-1"></i> İş İlanları
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="manage-jobs.php"><i class="bi bi-list-ul me-2"></i>Tüm İlanlar</a></li>
                            <li><a class="dropdown-item" href="create-job.php"><i class="bi bi-plus-circle me-2"></i>Yeni İlan</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="manage-job-questions.php"><i class="bi bi-question-circle me-2"></i>Soru Yönetimi</a></li>
                        </ul>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?= in_array(basename($_SERVER['PHP_SELF']), ['manage-templates.php', 'create-template.php', 'edit-template.php', 'edit-template-question.php']) ? 'active' : '' ?>" href="#" id="templatesDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-file-earmark-text me-1"></i> Şablonlar
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="manage-templates.php"><i class="bi bi-list-ul me-2"></i>Tüm Şablonlar</a></li>
                            <li><a class="dropdown-item" href="create-template.php"><i class="bi bi-plus-circle me-2"></i>Yeni Şablon</a></li>
                        </ul>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?= in_array(basename($_SERVER['PHP_SELF']), ['dashboard2.php', 'application-statistics.php']) ? 'active' : '' ?>" href="#" id="analyticsDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-graph-up me-1"></i> Analitik
                        </a>
                        <ul class="dropdown-menu">                            <li><a class="dropdown-item" href="application-statistics.php"><i class="bi bi-bar-chart me-2"></i>Başvuru İstatistikleri</a></li>
                            <li><a class="dropdown-item" href="dashboard2.php"><i class="bi bi-speedometer2 me-2"></i>Dashboard V2</a></li>
                        </ul>
                    </li>
                </ul>
                  <ul class="navbar-nav ms-auto">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown">
                            <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 32px; height: 32px;">
                                <i class="bi bi-person"></i>
                            </div>
                            <span class="d-none d-lg-inline">Admin</span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>Çıkış Yap</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="page-header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h1 class="page-title">Başvuru Yönetimi</h1>
                    <p class="page-subtitle">Tüm iş başvurularını görüntüle, filtrele ve yönet</p>
                </div>
                <div class="col-md-6 text-md-end">
                    <div class="btn-group" role="group">
                        <a href="?status=new" class="btn <?= $status_filter === 'new' ? 'btn-primary' : 'btn-outline-primary' ?>">
                            Yeni <span class="badge bg-white text-primary ms-1"><?= $new_count ?></span>
                        </a>
                        <a href="?status=reviewed" class="btn <?= $status_filter === 'reviewed' ? 'btn-primary' : 'btn-outline-primary' ?>">
                            İncelendi <span class="badge bg-white text-primary ms-1"><?= $reviewed_count ?></span>
                        </a>
                        <a href="manage-applications.php" class="btn <?= empty($status_filter) ? 'btn-primary' : 'btn-outline-primary' ?>">
                            Tümü <span class="badge bg-white text-primary ms-1"><?= $all_count ?></span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="container mb-5">
        <!-- Bilgi Kartları -->
        <div class="row mb-4">
            <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
                <div class="stats-card stats-primary">
                    <div class="stats-icon">
                        <i class="bi bi-file-earmark-person"></i>
                    </div>
                    <div class="stats-content">
                        <div class="stats-value"><?= $all_count ?></div>
                        <div class="stats-label">Toplam Başvuru</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
                <div class="stats-card stats-info">
                    <div class="stats-icon">
                        <i class="bi bi-envelope"></i>
                    </div>
                    <div class="stats-content">
                        <div class="stats-value"><?= $new_count ?></div>
                        <div class="stats-label">Yeni Başvuru</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6 mb-3 mb-sm-0">
                <div class="stats-card stats-success">
                    <div class="stats-icon">
                        <i class="bi bi-check-circle"></i>
                    </div>
                    <div class="stats-content">
                        <div class="stats-value"><?= $reviewed_count ?></div>
                        <div class="stats-label">İncelendi</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="stats-card stats-warning">
                    <div class="stats-icon">
                        <i class="bi bi-calendar-check"></i>
                    </div>
                    <div class="stats-content">
                        <div class="stats-value"><?= $today_count ?></div>
                        <div class="stats-label">Bugünkü Başvurular</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filtreler ve Arama -->
        <div class="filters-wrapper mb-4">
            <form method="get" action="">
                <div class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label for="job_id" class="form-label">İş İlanı</label>
                        <select class="form-select" id="job_id" name="job_id">
                            <option value="0">Tüm İlanlar</option>
                            <?php foreach ($jobs as $job): ?>
                                <option value="<?= $job['id'] ?>" <?= $job_filter == $job['id'] ? 'selected' : '' ?>><?= htmlspecialchars($job['title']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="time_filter" class="form-label">Tarih Filtresi</label>
                        <select class="form-select" id="time_filter" name="time_filter">
                            <option value="">Tüm Zamanlar</option>
                            <option value="today" <?= $time_filter === 'today' ? 'selected' : '' ?>>Bugün</option>
                            <option value="yesterday" <?= $time_filter === 'yesterday' ? 'selected' : '' ?>>Dün</option>
                            <option value="last7days" <?= $time_filter === 'last7days' ? 'selected' : '' ?>>Son 7 Gün</option>
                            <option value="last30days" <?= $time_filter === 'last30days' ? 'selected' : '' ?>>Son 30 Gün</option>
                            <option value="thismonth" <?= $time_filter === 'thismonth' ? 'selected' : '' ?>>Bu Ay</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="sort" class="form-label">Sıralama</label>
                        <select class="form-select" id="sort" name="sort">
                            <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>En Yeni</option>
                            <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>En Eski</option>
                            <option value="alphabetical" <?= $sort === 'alphabetical' ? 'selected' : '' ?>>İsme Göre (A-Z)</option>
                            <option value="highest_score" <?= $sort === 'highest_score' ? 'selected' : '' ?>>En Yüksek Puan</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <div class="position-relative">
                            <i class="bi bi-search search-icon"></i>
                            <input type="text" class="form-control search-input" name="search" placeholder="Ara..." value="<?= htmlspecialchars($search) ?>">
                        </div>
                    </div>
                    
                    <!-- Gizli durum filtresi (mevcut durumu koru) -->
                    <?php if (!empty($status_filter)): ?>
                        <input type="hidden" name="status" value="<?= htmlspecialchars($status_filter) ?>">
                    <?php endif; ?>
                    
                    <div class="col-12 text-end">
                        <button type="submit" class="btn btn-primary me-2">
                            <i class="bi bi-funnel me-2"></i>Filtrele
                        </button>
                        <a href="manage-applications.php" class="btn btn-outline-secondary">
                            <i class="bi bi-x-circle me-2"></i>Temizle
                        </a>
                    </div>
                </div>
            </form>
        </div>

        <?php if (isset($success_message)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i> <?= $success_message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($error_message)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= $error_message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Başvuru Listesi -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title">
                    <i class="bi bi-list-ul me-2"></i>Başvurular (<?= $total_applications ?>)
                </h5>
                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" id="bulkActionDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                        Toplu İşlemler
                    </button>
                    <ul class="dropdown-menu" aria-labelledby="bulkActionDropdown">
                        <li><a class="dropdown-item bulk-action" href="#" data-action="mark_reviewed"><i class="bi bi-check-circle me-2"></i>İncelendi olarak işaretle</a></li>
                        <li><a class="dropdown-item bulk-action" href="#" data-action="mark_new"><i class="bi bi-arrow-repeat me-2"></i>Yeni olarak işaretle</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item bulk-action text-danger" href="#" data-action="delete"><i class="bi bi-trash me-2"></i>Seçilenleri Sil</a></li>
                    </ul>
                </div>
            </div>
            <div class="card-body p-0">
                <?php if (empty($applications)): ?>
                    <div class="text-center py-5">
                        <div class="mb-3">
                            <i class="bi bi-inbox text-secondary" style="font-size: 3rem;"></i>
                        </div>
                        <h5>Başvuru Bulunamadı</h5>
                        <p class="text-muted">Seçilen filtrelere uygun başvuru bulunmamaktadır.</p>
                        <a href="manage-applications.php" class="btn btn-outline-primary">Tüm Başvuruları Görüntüle</a>
                    </div>
                <?php else: ?>
                    <form id="bulk-action-form" method="post" action="">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead>
                                    <tr>
                                        <th width="40px">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="selectAll">
                                            </div>
                                        </th>
                                        <th>Başvuran</th>
                                        <th>İş İlanı</th>
                                        <th>Tarih</th>
                                        <th>Durum</th>
                                        <th>Test Puanı</th>
                                        <th>CV Puanı</th>
                                        <th>Klasik Puanı</th>
                                        <th>İşlem</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($applications as $app): ?>
                                        <tr>
                                            <td>
                                                <div class="form-check">
                                                    <input class="form-check-input application-checkbox" type="checkbox" name="selected_apps[]" value="<?= $app['id'] ?>">
                                                </div>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <?php
                                                        // Rastgele bir renk sınıfı seç
                                                        $colors = ['primary', 'success', 'info', 'warning', 'danger', 'secondary'];
                                                        $color = $colors[array_rand($colors)];
                                                    ?>
                                                    <div class="avatar avatar-<?= $color ?> me-3">
                                                        <?= strtoupper(substr($app['first_name'], 0, 1) . substr($app['last_name'], 0, 1)) ?>
                                                    </div>
                                                    <div>
                                                        <h6 class="mb-0"><?= htmlspecialchars($app['first_name'] . ' ' . $app['last_name']) ?></h6>
                                                        <div class="small text-muted"><?= htmlspecialchars($app['email']) ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td><?= htmlspecialchars($app['job_title']) ?></td>
                                            <td>
                                                <div><?= date('d.m.Y', strtotime($app['created_at'])) ?></div>
                                                <div class="small text-muted"><?= date('H:i', strtotime($app['created_at'])) ?></div>
                                            </td>
                                            <td>
                                                <?php if ($app['status'] === 'new'): ?>
                                                    <span class="status-badge badge-new">Yeni</span>
                                                <?php elseif ($app['status'] === 'reviewed'): ?>
                                                    <span class="status-badge badge-reviewed">İncelendi</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($app['score'] > 0): ?>
                                                    <?php
                                                        if ($app['score'] >= 80) $scoreClass = 'score-high';
                                                        else if ($app['score'] >= 60) $scoreClass = 'score-medium';
                                                        else $scoreClass = 'score-low';
                                                    ?>
                                                    <span class="score-badge <?= $scoreClass ?>">
                                                        <i class="bi bi-check-circle-fill me-1"></i> <?= $app['score'] ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="score-badge score-unavailable">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (isset($app['cv_score']) && $app['cv_score'] > 0): ?>
                                                    <?php
                                                        if ($app['cv_score'] >= 80) $cvScoreClass = 'score-high';
                                                        else if ($app['cv_score'] >= 60) $cvScoreClass = 'score-medium';
                                                        else $cvScoreClass = 'score-low';
                                                    ?>
                                                    <span class="score-badge <?= $cvScoreClass ?>">
                                                        <i class="bi bi-file-earmark-fill me-1"></i> <?= $app['cv_score'] ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="score-badge score-unavailable">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (isset($app['open_ended_score']) && $app['open_ended_score'] > 0): ?>
                                                    <?php
                                                        $openEndedScore = round($app['open_ended_score']);
                                                        if ($openEndedScore >= 80) $openEndedClass = 'score-high';
                                                        else if ($openEndedScore >= 60) $openEndedClass = 'score-medium';
                                                        else $openEndedClass = 'score-low';
                                                    ?>
                                                    <span class="score-badge <?= $openEndedClass ?>">
                                                        <i class="bi bi-journal-text me-1"></i> <?= $openEndedScore ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="score-badge score-unavailable">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="btn-group">
                                                    <a href="application-detail.php?id=<?= $app['id'] ?>" class="btn btn-sm btn-outline-primary">
                                                        <i class="bi bi-eye"></i>
                                                    </a>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false">
                                                        <span class="visually-hidden">Diğer</span>
                                                    </button>
                                                    <ul class="dropdown-menu">
                                                        <li><a class="dropdown-item" href="application-detail.php?id=<?= $app['id'] ?>"><i class="bi bi-eye me-2"></i>Detaylar</a></li>
                                                        <li><a class="dropdown-item" href="download-cv.php?id=<?= $app['id'] ?>"><i class="bi bi-file-pdf me-2"></i>CV İndir</a></li>
                                                        <?php if ($app['status'] === 'new'): ?>
                                                            <li><a class="dropdown-item status-action" href="#" data-id="<?= $app['id'] ?>" data-status="reviewed"><i class="bi bi-check-circle me-2"></i>İncelendi</a></li>
                                                        <?php else: ?>
                                                            <li><a class="dropdown-item status-action" href="#" data-id="<?= $app['id'] ?>" data-status="new"><i class="bi bi-arrow-repeat me-2"></i>Yeni</a></li>
                                                        <?php endif; ?>
                                                        <li><hr class="dropdown-divider"></li>
                                                        <li><a class="dropdown-item text-danger delete-application" href="#" data-id="<?= $app['id'] ?>" data-name="<?= htmlspecialchars($app['first_name'] . ' ' . $app['last_name']) ?>"><i class="bi bi-trash me-2"></i>Sil</a></li>
                                                    </ul>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <input type="hidden" name="bulk_action" id="bulk_action" value="">
                    </form>
                    
                    <!-- Sayfalama -->
                    <?php if ($total_pages > 1): ?>
                        <div class="card-footer">
                            <nav>
                                <ul class="pagination justify-content-center mb-0">
                                    <?php if ($page > 1): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="<?= build_pagination_url($page - 1) ?>">
                                                <i class="bi bi-chevron-left"></i>
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                    
                                    <?php
                                    $start_page = max(1, $page - 2);
                                    $end_page = min($total_pages, $page + 2);
                                    
                                    // İlk sayfa
                                    if ($start_page > 1) {
                                        echo '<li class="page-item"><a class="page-link" href="' . build_pagination_url(1) . '">1</a></li>';
                                        if ($start_page > 2) {
                                            echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                        }
                                    }
                                    
                                    // Sayfa numaraları
                                    for ($i = $start_page; $i <= $end_page; $i++) {
                                        echo '<li class="page-item ' . ($i == $page ? 'active' : '') . '">';
                                        echo '<a class="page-link" href="' . build_pagination_url($i) . '">' . $i . '</a>';
                                        echo '</li>';
                                    }
                                    
                                    // Son sayfa
                                    if ($end_page < $total_pages) {
                                        if ($end_page < $total_pages - 1) {
                                            echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                        }
                                        echo '<li class="page-item"><a class="page-link" href="' . build_pagination_url($total_pages) . '">' . $total_pages . '</a></li>';
                                    }
                                    ?>
                                    
                                    <?php if ($page < $total_pages): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="<?= build_pagination_url($page + 1) ?>">
                                                <i class="bi bi-chevron-right"></i>
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </nav>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Tümünü seç/kaldır
            const selectAllCheckbox = document.getElementById('selectAll');
            if (selectAllCheckbox) {
                selectAllCheckbox.addEventListener('change', function() {
                    const checkboxes = document.querySelectorAll('.application-checkbox');
                    checkboxes.forEach(checkbox => {
                        checkbox.checked = this.checked;
                    });
                });
            }
            
            // Toplu işlem butonları
            const bulkActionButtons = document.querySelectorAll('.bulk-action');
            bulkActionButtons.forEach(button => {
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    
                    const action = this.getAttribute('data-action');
                    const checkboxes = document.querySelectorAll('.application-checkbox:checked');
                    
                    if (checkboxes.length === 0) {
                        alert('Lütfen en az bir başvuru seçin.');
                        return;
                    }
                    
                    let confirmMessage = '';
                    if (action === 'delete') {
                        confirmMessage = `Seçilen ${checkboxes.length} başvuruyu silmek istediğinizden emin misiniz? Bu işlem geri alınamaz.`;
                    } else if (action === 'mark_reviewed') {
                        confirmMessage = `Seçilen ${checkboxes.length} başvuruyu incelendi olarak işaretlemek istediğinizden emin misiniz?`;
                    } else if (action === 'mark_new') {
                        confirmMessage = `Seçilen ${checkboxes.length} başvuruyu yeni olarak işaretlemek istediğinizden emin misiniz?`;
                    }
                    
                    if (confirm(confirmMessage)) {
                        document.getElementById('bulk_action').value = action;
                        document.getElementById('bulk-action-form').submit();
                    }
                });
            });
            
            // Durum değiştirme işlemi
            const statusButtons = document.querySelectorAll('.status-action');
            statusButtons.forEach(button => {
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    
                    const appId = this.getAttribute('data-id');
                    const newStatus = this.getAttribute('data-status');
                    const statusText = newStatus === 'reviewed' ? 'İncelendi' : 'Yeni';
                    
                    if (confirm(`Bu başvurunun durumunu '${statusText}' olarak değiştirmek istediğinizden emin misiniz?`)) {
                        // Form oluştur ve gönder
                        const form = document.createElement('form');
                        form.method = 'post';
                        form.style.display = 'none';
                        
                        const actionInput = document.createElement('input');
                        actionInput.type = 'hidden';
                        actionInput.name = 'bulk_action';
                        actionInput.value = newStatus === 'reviewed' ? 'mark_reviewed' : 'mark_new';
                        
                        const appInput = document.createElement('input');
                        appInput.type = 'hidden';
                        appInput.name = 'selected_apps[]';
                        appInput.value = appId;
                        
                        form.appendChild(actionInput);
                        form.appendChild(appInput);
                        document.body.appendChild(form);
                        form.submit();
                    }
                });
            });
            
            // Başvuru silme işlemi
            const deleteButtons = document.querySelectorAll('.delete-application');
            deleteButtons.forEach(button => {
                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    
                    const appId = this.getAttribute('data-id');
                    const appName = this.getAttribute('data-name');
                    
                    if (confirm(`"${appName}" adlı başvuruyu silmek istediğinizden emin misiniz? Bu işlem geri alınamaz.`)) {
                        // Form oluştur ve gönder
                        const form = document.createElement('form');
                        form.method = 'post';
                        form.style.display = 'none';
                        
                        const actionInput = document.createElement('input');
                        actionInput.type = 'hidden';
                        actionInput.name = 'bulk_action';
                        actionInput.value = 'delete';
                        
                        const appInput = document.createElement('input');
                        appInput.type = 'hidden';
                        appInput.name = 'selected_apps[]';
                        appInput.value = appId;
                        
                        form.appendChild(actionInput);
                        form.appendChild(appInput);
                        document.body.appendChild(form);
                        form.submit();
                    }
                });
            });
        });
    </script>
</body>
</html>

<?php
// Output buffer içeriğini gönder ve buffer'ı temizle
ob_end_flush();

/**
 * Sayfalama URL'si oluşturma fonksiyonu
 */
function build_pagination_url($page) {
    $params = $_GET;
    $params['page'] = $page;
    return '?' . http_build_query($params);
}
?>