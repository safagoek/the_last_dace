<?php
session_start();

// Oturum kontrolü
if (!isset($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit;
}

require_once '../config/db.php';

// İlan silme işlemi
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $job_id = (int)$_GET['delete'];
    
    try {
        $db->beginTransaction();
        
        // İlana bağlı başvurular varsa silmek için onay gerekir
        if (isset($_GET['confirm']) && $_GET['confirm'] == 'yes') {
            // İlana bağlı başvuruları sil
            $stmt = $db->prepare("DELETE FROM applications WHERE job_id = :job_id");
            $stmt->bindParam(':job_id', $job_id, PDO::PARAM_INT);
            $stmt->execute();
            
            // İlanı sil
            $stmt = $db->prepare("DELETE FROM jobs WHERE id = :job_id");
            $stmt->bindParam(':job_id', $job_id, PDO::PARAM_INT);
            $stmt->execute();
            
            $delete_success = "İş ilanı ve bağlı tüm başvurular başarıyla silindi.";
        } else {
            // İlana bağlı başvuru sayısını kontrol et
            $stmt = $db->prepare("SELECT COUNT(*) FROM applications WHERE job_id = :job_id");
            $stmt->bindParam(':job_id', $job_id, PDO::PARAM_INT);
            $stmt->execute();
            $app_count = $stmt->fetchColumn();
            
            if ($app_count > 0) {
                // Başvurular varsa onay isteme
                $confirm_needed = true;
                $app_count_message = "Bu iş ilanına ait $app_count adet başvuru bulunmaktadır. Silmek istediğinizden emin misiniz?";
                $job_to_delete = $job_id;
            } else {
                // Başvuru yoksa direk sil
                $stmt = $db->prepare("DELETE FROM jobs WHERE id = :job_id");
                $stmt->bindParam(':job_id', $job_id, PDO::PARAM_INT);
                $stmt->execute();
                
                $delete_success = "İş ilanı başarıyla silindi.";
            }
        }
        
        $db->commit();
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $delete_error = "İş ilanı silinirken bir hata oluştu: " . $e->getMessage();
    }
}

// İlan durumunu değiştirme işlemi
if (isset($_GET['toggle_status']) && is_numeric($_GET['toggle_status'])) {
    $job_id = (int)$_GET['toggle_status'];
    
    try {
        // Mevcut durumu kontrol et
        $stmt = $db->prepare("SELECT status FROM jobs WHERE id = :job_id");
        $stmt->bindParam(':job_id', $job_id, PDO::PARAM_INT);
        $stmt->execute();
        $current_status = $stmt->fetchColumn();
        
        // Durumu değiştir
        $new_status = ($current_status == 'active') ? 'inactive' : 'active';
        
        $stmt = $db->prepare("UPDATE jobs SET status = :status WHERE id = :job_id");
        $stmt->bindParam(':status', $new_status);
        $stmt->bindParam(':job_id', $job_id, PDO::PARAM_INT);
        $stmt->execute();
        
        $status_success = "İş ilanı durumu başarıyla güncellendi.";
    } catch (PDOException $e) {
        $status_error = "İş ilanı durumu güncellenirken bir hata oluştu: " . $e->getMessage();
    }
}

// Filtreleme parametreleri
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$location_filter = isset($_GET['location']) ? $_GET['location'] : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'newest';

// SQL koşulları
$conditions = [];
$params = [];

if (!empty($status_filter)) {
    $conditions[] = "j.status = :status";
    $params[':status'] = $status_filter;
}

if (!empty($location_filter)) {
    $conditions[] = "j.location = :location";
    $params[':location'] = $location_filter;
}

if (!empty($search)) {
    $conditions[] = "(j.title LIKE :search OR j.description LIKE :search)";
    $params[':search'] = "%$search%";
}

// WHERE cümlesi oluştur
$where_clause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

// Sıralama
$order_clause = "ORDER BY j.created_at DESC"; // Varsayılan: En yeni
if ($sort === 'oldest') {
    $order_clause = "ORDER BY j.created_at ASC";
} elseif ($sort === 'alphabetical') {
    $order_clause = "ORDER BY j.title ASC";
} elseif ($sort === 'most_applications') {
    $order_clause = "ORDER BY application_count DESC";
}

// Lokasyon listesi (filtre için)
$stmt = $db->query("SELECT DISTINCT location FROM jobs ORDER BY location");
$locations = $stmt->fetchAll(PDO::FETCH_COLUMN);

// İstatistikler
$stmt = $db->query("SELECT COUNT(*) as total FROM jobs");
$total_jobs = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

$stmt = $db->query("SELECT COUNT(*) as active FROM jobs WHERE status = 'active'");
$active_jobs = $stmt->fetch(PDO::FETCH_ASSOC)['active'];

$stmt = $db->query("SELECT COUNT(*) as inactive FROM jobs WHERE status = 'inactive'");
$inactive_jobs = $stmt->fetch(PDO::FETCH_ASSOC)['inactive'];

$stmt = $db->query("SELECT COUNT(*) as total FROM applications");
$total_applications = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Tüm iş ilanlarını al
$sql = "SELECT j.*, 
        (SELECT COUNT(*) FROM applications WHERE job_id = j.id) as application_count,
        (SELECT COUNT(*) FROM questions WHERE job_id = j.id) as question_count,
        qt.template_name
        FROM jobs j
        LEFT JOIN question_templates qt ON j.template_id = qt.id
        $where_clause
        $order_clause";

$stmt = $db->prepare($sql);
foreach ($params as $key => $val) {
    $stmt->bindValue($key, $val);
}
$stmt->execute();
$jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>İş İlanları Yönetimi | İş Başvuru Sistemi</title>
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
        
        .badge-active {
            background-color: rgba(46, 204, 113, 0.15);
            color: #2ecc71;
        }
        
        .badge-inactive {
            background-color: rgba(231, 76, 60, 0.15);
            color: #e74c3c;
        }
        
        .badge-count {
            background-color: rgba(52, 152, 219, 0.15);
            color: #3498db;
        }
        
        .table {
            margin-bottom: 0;
        }
        
        .table th {
            font-weight: 600;
            color: var(--secondary);
            border-top: none;
            padding: 1rem 0.75rem;
        }
        
        .table td {
            vertical-align: middle;
            padding: 0.75rem;
        }
        
        .table-hover tbody tr:hover {
            background-color: rgba(0, 0, 0, 0.01);
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
        
        .job-actions .btn {
            width: 36px;
            height: 36px;
            padding: 0.25rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        
        .alert-modern {
            border-radius: 10px;
            border-left-width: 4px;
            padding: 1rem 1.25rem;
        }
        
        .alert-success {
            border-left-color: var(--success);
            background-color: rgba(46, 204, 113, 0.1);
            color: #2c7a54;
        }
        
        .alert-danger {
            border-left-color: var(--danger);
            background-color: rgba(231, 76, 60, 0.1);
            color: #a94442;
        }
        
        .alert-warning {
            border-left-color: var(--warning);
            background-color: rgba(243, 156, 18, 0.1);
            color: #9a7a40;
        }
        
        .confirmation-card {
            background-color: #fff8e1;
            border-left: 4px solid var(--warning);
            border-radius: 10px;
            padding: 1.25rem;
            margin-bottom: 1.5rem;
        }
    </style>
</head>
<body>
    <!-- Modern Admin Navbar -->
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
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="application-statistics.php"><i class="bi bi-bar-chart me-2"></i>Başvuru İstatistikleri</a></li>
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
                    <h1 class="page-title">İş İlanları Yönetimi</h1>
                    <p class="page-subtitle">İş ilanlarını oluştur, düzenle ve yönet</p>
                </div>
                <div class="col-md-6 text-md-end">
                    <a href="create-job.php" class="btn btn-primary">
                        <i class="bi bi-plus-lg me-1"></i> Yeni İlan Oluştur
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="container mb-5">
        <!-- İstatistik Kartları -->
        <div class="row mb-4">
            <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
                <div class="stats-card stats-primary">
                    <div class="stats-icon">
                        <i class="bi bi-briefcase"></i>
                    </div>
                    <div class="stats-content">
                        <div class="stats-value"><?= $total_jobs ?></div>
                        <div class="stats-label">Toplam İlan</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6 mb-3 mb-md-0">
                <div class="stats-card stats-success">
                    <div class="stats-icon">
                        <i class="bi bi-check-circle"></i>
                    </div>
                    <div class="stats-content">
                        <div class="stats-value"><?= $active_jobs ?></div>
                        <div class="stats-label">Aktif İlan</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6 mb-3 mb-sm-0">
                <div class="stats-card stats-warning">
                    <div class="stats-icon">
                        <i class="bi bi-x-circle"></i>
                    </div>
                    <div class="stats-content">
                        <div class="stats-value"><?= $inactive_jobs ?></div>
                        <div class="stats-label">Pasif İlan</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="stats-card stats-info">
                    <div class="stats-icon">
                        <i class="bi bi-file-earmark-person"></i>
                    </div>
                    <div class="stats-content">
                        <div class="stats-value"><?= $total_applications ?></div>
                        <div class="stats-label">Toplam Başvuru</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filtreler -->
        <div class="filters-wrapper mb-4">
            <form method="get" action="">
                <div class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label for="status" class="form-label">Durum</label>
                        <select class="form-select" id="status" name="status">
                            <option value="">Tümü</option>
                            <option value="active" <?= $status_filter === 'active' ? 'selected' : '' ?>>Aktif</option>
                            <option value="inactive" <?= $status_filter === 'inactive' ? 'selected' : '' ?>>Pasif</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="location" class="form-label">Lokasyon</label>
                        <select class="form-select" id="location" name="location">
                            <option value="">Tüm Lokasyonlar</option>
                            <?php foreach ($locations as $location): ?>
                                <option value="<?= htmlspecialchars($location) ?>" <?= $location_filter === $location ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($location) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="sort" class="form-label">Sıralama</label>
                        <select class="form-select" id="sort" name="sort">
                            <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>En Yeni</option>
                            <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>En Eski</option>
                            <option value="alphabetical" <?= $sort === 'alphabetical' ? 'selected' : '' ?>>İsme Göre (A-Z)</option>
                            <option value="most_applications" <?= $sort === 'most_applications' ? 'selected' : '' ?>>En Çok Başvuru</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <div class="position-relative">
                            <i class="bi bi-search search-icon"></i>
                            <input type="text" class="form-control search-input" name="search" placeholder="İlan ara..." value="<?= htmlspecialchars($search) ?>">
                        </div>
                    </div>
                    <div class="col-12 text-end">
                        <button type="submit" class="btn btn-primary me-2">
                            <i class="bi bi-funnel me-1"></i>Filtrele
                        </button>
                        <a href="manage-jobs.php" class="btn btn-outline-secondary">
                            <i class="bi bi-x-circle me-1"></i>Temizle
                        </a>
                    </div>
                </div>
            </form>
        </div>

        <?php if (isset($delete_success)): ?>
            <div class="alert alert-success alert-modern alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i> <?= $delete_success ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <?php if (isset($delete_error)): ?>
            <div class="alert alert-danger alert-modern alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= $delete_error ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <?php if (isset($status_success)): ?>
            <div class="alert alert-success alert-modern alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i> <?= $status_success ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <?php if (isset($status_error)): ?>
            <div class="alert alert-danger alert-modern alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= $status_error ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <?php if (isset($confirm_needed)): ?>
            <div class="confirmation-card">
                <h5 class="fw-bold"><i class="bi bi-exclamation-triangle-fill me-2 text-warning"></i> Silme Onayı</h5>
                <p class="mb-3"><?= $app_count_message ?></p>
                <div class="d-flex">
                    <a href="manage-jobs.php?delete=<?= $job_to_delete ?>&confirm=yes" class="btn btn-danger me-2">
                        <i class="bi bi-trash me-1"></i> Evet, Sil
                    </a>
                    <a href="manage-jobs.php" class="btn btn-outline-secondary">İptal</a>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- İş İlanları Tablosu -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title">
                    <i class="bi bi-briefcase me-2"></i>İş İlanları (<?= count($jobs) ?>)
                </h5>
                <div>
                    <a href="create-job.php" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-plus-lg me-1"></i> Yeni İlan
                    </a>
                </div>
            </div>
            <div class="card-body p-0">
                <?php if (empty($jobs)): ?>
                    <div class="text-center py-5">
                        <div class="mb-3">
                            <i class="bi bi-briefcase text-secondary" style="font-size: 3rem;"></i>
                        </div>
                        <h5>Henüz İş İlanı Bulunmamaktadır</h5>
                        <p class="text-muted">İş ilanı eklemek için "Yeni İlan Oluştur" butonunu kullanabilirsiniz.</p>
                        <a href="create-job.php" class="btn btn-primary mt-2">
                            <i class="bi bi-plus-lg me-1"></i> Yeni İlan Oluştur
                        </a>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>İlan Başlığı</th>
                                    <th>Lokasyon</th>
                                    <th>Şablon</th>
                                    <th>Soru Sayısı</th>
                                    <th>Başvuru Sayısı</th>
                                    <th>Durum</th>
                                    <th>Oluşturulma Tarihi</th>
                                    <th>İşlemler</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($jobs as $job): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-medium"><?= htmlspecialchars($job['title']) ?></div>
                                            <span class="text-secondary small">ID: <?= $job['id'] ?></span>
                                        </td>
                                        <td><?= htmlspecialchars($job['location']) ?></td>
                                        <td>
                                            <?php if (!empty($job['template_name'])): ?>
                                                <span class="badge bg-light text-dark">
                                                    <i class="bi bi-file-earmark-text me-1 text-primary"></i>
                                                    <?= htmlspecialchars($job['template_name']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-light text-secondary">Şablon Yok</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge badge-count">
                                                <?= $job['question_count'] ?> soru
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($job['application_count'] > 0): ?>
                                                <a href="manage-applications.php?job_id=<?= $job['id'] ?>" class="badge badge-count text-decoration-none">
                                                    <?= $job['application_count'] ?> başvuru
                                                </a>
                                            <?php else: ?>
                                                <span class="badge bg-light text-secondary">0 başvuru</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($job['status'] == 'active'): ?>
                                                <span class="status-badge badge-active">Aktif</span>
                                            <?php else: ?>
                                                <span class="status-badge badge-inactive">Pasif</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div><?= date('d.m.Y', strtotime($job['created_at'])) ?></div>
                                            <div class="small text-muted"><?= date('H:i', strtotime($job['created_at'])) ?></div>
                                        </td>
                                        <td>
                                            <div class="job-actions">
                                                <a href="edit-job.php?id=<?= $job['id'] ?>" class="btn btn-sm btn-outline-primary" title="Düzenle">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <a href="manage-questions.php?job_id=<?= $job['id'] ?>" class="btn btn-sm btn-outline-info" title="Soruları Yönet">
                                                    <i class="bi bi-list-check"></i>
                                                </a>
                                                <a href="manage-jobs.php?toggle_status=<?= $job['id'] ?>" class="btn btn-sm <?= ($job['status'] == 'active') ? 'btn-outline-warning' : 'btn-outline-success' ?>" title="<?= ($job['status'] == 'active') ? 'Pasif Yap' : 'Aktif Yap' ?>">
                                                    <i class="bi <?= ($job['status'] == 'active') ? 'bi-x-lg' : 'bi-check-lg' ?>"></i>
                                                </a>
                                                <a href="manage-jobs.php?delete=<?= $job['id'] ?>" class="btn btn-sm btn-outline-danger" title="Sil" onclick="return confirm('Bu iş ilanını silmek istediğinizden emin misiniz?')">
                                                    <i class="bi bi-trash"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>