<?php
// Output buffering başlat
ob_start();

// Oturum kontrolü
session_start();
if (!isset($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit;
}

require_once '../config/db.php';

// İstatistik verileri
try {
    // Toplam başvuru sayısı
    $stmt = $db->query("SELECT COUNT(*) as count FROM applications");
    $total_applications = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Bugünkü başvuru sayısı
    $stmt = $db->query("SELECT COUNT(*) as count FROM applications WHERE DATE(created_at) = CURDATE()");
    $today_applications = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Aktif iş ilanı sayısı
    $stmt = $db->query("SELECT COUNT(*) as count FROM jobs WHERE status = 'active' AND deadline >= CURDATE()");
    $active_jobs = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // İncelenmemiş başvuru sayısı
    $stmt = $db->query("SELECT COUNT(*) as count FROM applications WHERE status = 'new'");
    $new_applications = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // İncelenmiş başvuru sayısı
    $stmt = $db->query("SELECT COUNT(*) as count FROM applications WHERE status = 'reviewed'");
    $reviewed_applications = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Son eklenen başvurular
    $stmt = $db->query("
        SELECT a.*, j.title as job_title 
        FROM applications a
        JOIN jobs j ON a.job_id = j.id
        ORDER BY a.created_at DESC
        LIMIT 5
    ");
    $recent_applications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Pozisyon bazında başvuru sayıları
    $stmt = $db->query("
        SELECT j.title, COUNT(a.id) as application_count
        FROM jobs j
        LEFT JOIN applications a ON j.id = a.job_id
        WHERE j.status = 'active'
        GROUP BY j.id
        ORDER BY application_count DESC
        LIMIT 5
    ");
    $job_statistics = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Ortalama test puanları
    $stmt = $db->query("
        SELECT AVG(score) as avg_score,
               MIN(score) as min_score,
               MAX(score) as max_score
        FROM applications
        WHERE score > 0
    ");
    $test_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    $avg_test_score = round($test_stats['avg_score'] ?? 0);
    $min_test_score = round($test_stats['min_score'] ?? 0);
    $max_test_score = round($test_stats['max_score'] ?? 0);
    
    // CV ve Klasik soru ortalama puanları
    $stmt = $db->query("
        SELECT AVG(cv_score) as avg_cv_score
        FROM applications
        WHERE cv_score > 0
    ");
    $cv_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    $avg_cv_score = round($cv_stats['avg_cv_score'] ?? 0);
    
    $stmt = $db->query("
        SELECT AVG(aa.answer_score) as avg_classic_score
        FROM application_answers aa
        JOIN questions q ON aa.question_id = q.id
        WHERE q.question_type = 'open_ended' AND aa.answer_score > 0
    ");
    $classic_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    $avg_classic_score = round($classic_stats['avg_classic_score'] ?? 0);
    
} catch (PDOException $e) {
    error_log("Dashboard veri hatası: " . $e->getMessage());
    // Hata durumunda varsayılan değerler
    $total_applications = 0;
    $today_applications = 0;
    $active_jobs = 0;
    $new_applications = 0;
    $reviewed_applications = 0;
    $recent_applications = [];
    $job_statistics = [];
    $avg_test_score = 0;
    $min_test_score = 0;
    $max_test_score = 0;
    $avg_cv_score = 0;
    $avg_classic_score = 0;
}
// İlanlara göre maaş istatistikleri
try {
    // Ortalama maaş bilgileri
    $stmt = $db->query("
        SELECT 
            AVG(min_salary) as avg_min_salary,
            AVG(max_salary) as avg_max_salary,
            MAX(max_salary) as highest_salary,
            MIN(min_salary) as lowest_salary
        FROM jobs
        WHERE status = 'active' AND min_salary > 0
    ");
    $salary_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Departman/kategori bazlı maaş ortalaması
    $stmt = $db->query("
        SELECT 
            j.department,
            ROUND(AVG((j.min_salary + j.max_salary) / 2)) as avg_salary,
            COUNT(j.id) as job_count
        FROM 
            jobs j
        WHERE 
            j.status = 'active' 
            AND j.min_salary > 0
        GROUP BY 
            j.department
        ORDER BY 
            avg_salary DESC
        LIMIT 5
    ");
    $department_salaries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Maaş verilerini çekerken hata: " . $e->getMessage());
    $salary_stats = [
        'avg_min_salary' => 0,
        'avg_max_salary' => 0,
        'highest_salary' => 0,
        'lowest_salary' => 0
    ];
    $department_salaries = [];
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | İş Başvuru Sistemi</title>
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
            transition: box-shadow 0.2s, transform 0.2s;
        }
        
        .card:hover {
            box-shadow: 0 5px 12px rgba(0, 0, 0, 0.08);
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
        
        .stat-card {
            display: flex;
            align-items: center;
            background-color: #fff;
            border-radius: 10px;
            box-shadow: var(--card-shadow);
            padding: 1.25rem;
            height: 100%;
            border-left: 4px solid var(--primary);
            transition: transform 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1rem;
            font-size: 1.5rem;
        }
        
        .stat-content {
            flex: 1;
        }
        
        .stat-value {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            line-height: 1.2;
        }
        
        .stat-label {
            color: var(--secondary);
            font-size: 0.85rem;
            margin-bottom: 0;
        }
        
        .stat-primary {
            border-left-color: var(--primary);
        }
        
        .stat-primary .stat-icon {
            background-color: rgba(67, 97, 238, 0.15);
            color: var(--primary);
        }
        
        .stat-success {
            border-left-color: var(--success);
        }
        
        .stat-success .stat-icon {
            background-color: rgba(46, 204, 113, 0.15);
            color: var(--success);
        }
        
        .stat-info {
            border-left-color: var(--info);
        }
        
        .stat-info .stat-icon {
            background-color: rgba(52, 152, 219, 0.15);
            color: var(--info);
        }
        
        .stat-warning {
            border-left-color: var(--warning);
        }
        
        .stat-warning .stat-icon {
            background-color: rgba(243, 156, 18, 0.15);
            color: var(--warning);
        }
        
        .stat-danger {
            border-left-color: var(--danger);
        }
        
        .stat-danger .stat-icon {
            background-color: rgba(231, 76, 60, 0.15);
            color: var(--danger);
        }
        
        .stats-comparison {
            display: flex;
            align-items: center;
            margin-top: 0.5rem;
            font-size: 0.85rem;
        }
        
        .stats-comparison i {
            margin-right: 0.25rem;
        }
        
        .stats-increase {
            color: var(--success);
        }
        
        .stats-decrease {
            color: var(--danger);
        }
        
        .progress {
            height: 8px;
            margin-top: 0.5rem;
            border-radius: 10px;
        }
        
        .progress-sm {
            height: 4px;
        }
        
        .progress-bar {
            border-radius: 10px;
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
            padding: 0.75rem 1.25rem;
        }
        
        .table-hover tbody tr:hover {
            background-color: rgba(0, 0, 0, 0.01);
        }
        
        .badge {
            padding: 0.4em 0.65em;
            font-weight: 600;
        }
        
        .badge-success-soft {
            background-color: rgba(46, 204, 113, 0.15);
            color: var(--success);
        }
        
        .badge-info-soft {
            background-color: rgba(52, 152, 219, 0.15);
            color: var(--info);
        }
        
        .badge-warning-soft {
            background-color: rgba(243, 156, 18, 0.15);
            color: var(--warning);
        }
        
        .badge-danger-soft {
            background-color: rgba(231, 76, 60, 0.15);
            color: var(--danger);
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
        
        .chart-container {
            position: relative;
            height: 300px;
        }
        
        /* Grafik Stilleri */
        .chart-loading, .chart-no-data {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 350px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background-color: rgba(255, 255, 255, 0.8);
        }

        .chart-no-data {
            color: #6c757d;
        }

        .chart-no-data i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }

        .dot {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
        }

        .bg-primary-light {
            background-color: rgba(67, 97, 238, 0.2);
            color: #4361ee;
        }

        .bg-success-light {
            background-color: rgba(46, 204, 113, 0.2);
            color: #2ecc71;
        }

        .bg-warning-light {
            background-color: rgba(243, 156, 18, 0.2);
            color: #f39c12;
        }

        .bg-info-light {
            background-color: rgba(52, 152, 219, 0.2);
            color: #3498db;
        }

        .bg-danger-light {
            background-color: rgba(231, 76, 60, 0.2);
            color: #e74c3c;
        }

        .stat-icon-lg {
            width: 48px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            font-size: 1.5rem;
        }

        .quality-stats .card {
            transition: transform 0.2s;
            border-radius: 10px;
        }

        .quality-stats .card:hover {
            transform: translateY(-5px);
        }
        
        /* Yanlış Sorular İçin */
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
    </style>
</head>
<body>
    <!-- Admin Navbar -->
    <nav class="navbar navbar-expand-lg bg-white shadow-sm sticky-top">
        <div class="container-fluid">
            <!-- Brand -->
            <a class="navbar-brand fw-bold" href="dashboard.php">
                <i class="bi bi-briefcase-fill text-primary me-2"></i>
                <span class="text-primary">İş Başvuru Sistemi</span>
            </a>

            <!-- Mobile toggle -->
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#adminNavbar">
                <span class="navbar-toggler-icon"></span>
            </button>

            <!-- Navbar content -->
            <div class="collapse navbar-collapse" id="adminNavbar">
                <!-- Main navigation -->
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : '' ?>" href="dashboard.php">
                            <i class="bi bi-speedometer2 me-1"></i>Dashboard
                        </a>
                    </li>                    <li class="nav-item">
                        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'dashboard2.php' ? 'active' : '' ?>" href="dashboard2.php">
                            <i class="bi bi-bar-chart me-1"></i>Dashboard 2
                        </a>
                    </li>
                    
                    <!-- Başvurular Dropdown -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?= in_array(basename($_SERVER['PHP_SELF']), ['manage-applications.php', 'view-applications.php', 'application-detail.php', 'application-detail2.php', 'application-statistics.php']) ? 'active' : '' ?>" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-file-earmark-person me-1"></i>Başvurular
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="manage-applications.php"><i class="bi bi-list-ul me-2"></i>Başvuruları Yönet</a></li>
                            <li><a class="dropdown-item" href="view-applications.php"><i class="bi bi-eye me-2"></i>Başvuruları Görüntüle</a></li>
                            <li><a class="dropdown-item" href="application-statistics.php"><i class="bi bi-graph-up me-2"></i>İstatistikler</a></li>
                        </ul>
                    </li>

                    <!-- İş İlanları Dropdown -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?= in_array(basename($_SERVER['PHP_SELF']), ['manage-jobs.php', 'create-job.php', 'edit-job.php', 'manage-job-questions.php']) ? 'active' : '' ?>" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-briefcase me-1"></i>İş İlanları
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="manage-jobs.php"><i class="bi bi-list-ul me-2"></i>İlanları Yönet</a></li>
                            <li><a class="dropdown-item" href="create-job.php"><i class="bi bi-plus-circle me-2"></i>Yeni İlan</a></li>
                            <li><a class="dropdown-item" href="manage-job-questions.php"><i class="bi bi-question-circle me-2"></i>Soru Yönetimi</a></li>
                        </ul>
                    </li>

                    <!-- Şablonlar Dropdown -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?= in_array(basename($_SERVER['PHP_SELF']), ['manage-templates.php', 'create-template.php', 'edit-template.php', 'edit-template-question.php', 'edit-question.php']) ? 'active' : '' ?>" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-file-earmark-text me-1"></i>Şablonlar
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="manage-templates.php"><i class="bi bi-list-ul me-2"></i>Şablonları Yönet</a></li>
                            <li><a class="dropdown-item" href="create-template.php"><i class="bi bi-plus-circle me-2"></i>Yeni Şablon</a></li>
                        </ul>
                    </li>                    <!-- Analitik bölümünü kaldır -->
                </ul><!-- Right side navigation -->
                <ul class="navbar-nav">
                    <!-- User menu -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" role="button" data-bs-toggle="dropdown">
                            <div class="rounded-circle bg-primary d-flex align-items-center justify-content-center me-2" style="width: 32px; height: 32px;">
                                <i class="bi bi-person-fill text-white"></i>
                            </div>
                            Admin
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>Çıkış Yap</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <!-- Başvuru Kalitesi ve Puan Kartları -->
        <div class="row mb-4">
            <div class="col-md-4 mb-4 mb-md-0">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="card-title">
                            <i class="bi bi-graph-up me-2"></i>Başvuru Puanları
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="d-flex align-items-center mb-4">
                            <div class="stat-icon-lg bg-primary-light me-3">
                                <i class="bi bi-check-circle"></i>
                            </div>
                            <div>
                                <h6 class="mb-0">Test Puanları</h6>
                                <h2 class="mb-0"><?= $avg_test_score ?><small class="text-muted fs-6">/100</small></h2>
                                <div class="small text-muted">Min: <?= $min_test_score ?> | Max: <?= $max_test_score ?></div>
                            </div>
                        </div>
                        
                        <div class="d-flex align-items-center mb-4">
                            <div class="stat-icon-lg bg-success-light me-3">
                                <i class="bi bi-file-earmark-text"></i>
                            </div>
                            <div>
                                <h6 class="mb-0">CV Puanları</h6>
                                <h2 class="mb-0"><?= $avg_cv_score ?><small class="text-muted fs-6">/100</small></h2>
                                <div class="progress mt-2" style="height: 5px;">
                                    <div class="progress-bar bg-success" style="width: <?= $avg_cv_score ?>%;"></div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="d-flex align-items-center">
                            <div class="stat-icon-lg bg-warning-light me-3">
                                <i class="bi bi-journal-text"></i>
                            </div>
                            <div>
                                <h6 class="mb-0">Klasik Soru Puanları</h6>
                                <h2 class="mb-0"><?= $avg_classic_score ?><small class="text-muted fs-6">/100</small></h2>
                                <div class="progress mt-2" style="height: 5px;">
                                    <div class="progress-bar bg-warning" style="width: <?= $avg_classic_score ?>%;"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-8">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title">
                            <i class="bi bi-bar-chart-line me-2"></i>Pozisyon Başına Başvuru Sayısı
                        </h5>
                    </div>
                    <div class="card-body">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Pozisyon</th>
                                    <th>Başvuru</th>
                                    <th>Oran</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                // Toplam başvuruları hesapla
                                $total_apps = array_sum(array_column($job_statistics, 'application_count'));
                                
                                foreach ($job_statistics as $job):
                                    $percentage = ($total_apps > 0) ? ($job['application_count'] / $total_apps) * 100 : 0;
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars($job['title']) ?></td>
                                    <td><?= $job['application_count'] ?></td>
                                    <td width="50%">
                                        <div class="d-flex align-items-center">
                                            <div class="progress flex-grow-1 me-2" style="height: 6px;">
                                                <div class="progress-bar bg-primary" style="width: <?= $percentage ?>%"></div>
                                            </div>
                                            <span class="text-muted small"><?= number_format($percentage, 1) ?>%</span>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                
                                <?php if (empty($job_statistics)): ?>
                                <tr>
                                    <td colspan="3" class="text-center">Henüz veri bulunmamaktadır.</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <!-- İlanlara Göre Beklenen Maaş Miktarı Kartı -->
<div class="col-md-6 mb-4">
    <div class="card h-100">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="card-title">
                <i class="bi bi-cash-stack me-2 text-success"></i>İlanlara Göre Beklenen Maaş
            </h5>
            <span class="badge bg-success-soft">Aktif İlanlar</span>
        </div>
        <div class="card-body">
            <div class="row mb-4">
                <div class="col-sm-6">
                    <div class="bg-light p-3 rounded-3 h-100">
                        <h6 class="text-muted mb-2">Ortalama Maaş Aralığı</h6>
                        <h3 class="mb-1">
                            <span class="text-primary"><?= number_format($salary_stats['avg_min_salary'] ?? 0, 0, ',', '.') ?> ₺</span> - 
                            <span class="text-success"><?= number_format($salary_stats['avg_max_salary'] ?? 0, 0, ',', '.') ?> ₺</span>
                        </h3>
                        <div class="small text-muted mt-1">
                            Aktif ilanlardaki maaş aralığı ortalaması
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 mt-3 mt-sm-0">
                    <div class="d-flex justify-content-between mb-3">
                        <div class="small text-muted">En Düşük Maaş</div>
                        <div class="fw-bold"><?= number_format($salary_stats['lowest_salary'] ?? 0, 0, ',', '.') ?> ₺</div>
                    </div>
                    <div class="progress progress-sm mb-3">
                        <div class="progress-bar bg-danger" style="width: 100%"></div>
                    </div>
                    
                    <div class="d-flex justify-content-between mb-3">
                        <div class="small text-muted">En Yüksek Maaş</div>
                        <div class="fw-bold"><?= number_format($salary_stats['highest_salary'] ?? 0, 0, ',', '.') ?> ₺</div>
                    </div>
                    <div class="progress progress-sm">
                        <div class="progress-bar bg-success" style="width: 100%"></div>
                    </div>
                </div>
            </div>
            
            <div class="mt-4">
                <h6 class="mb-3">Departmanlara Göre Ortalama Maaş</h6>
                
                <?php if (!empty($department_salaries)): ?>
                    <?php foreach ($department_salaries as $dept): ?>
                        <?php 
                            // Maaş miktarına göre yüzde belirle (en yüksek maaş %100)
                            $highest_dept_salary = $department_salaries[0]['avg_salary']; // İlk sıradaki en yüksek
                            $percentage = ($highest_dept_salary > 0) ? ($dept['avg_salary'] / $highest_dept_salary) * 100 : 0;
                        ?>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <div class="d-flex align-items-center">
                                    <span class="text-truncate"><?= htmlspecialchars($dept['department']) ?></span>
                                    <span class="badge bg-light text-dark ms-2"><?= $dept['job_count'] ?> ilan</span>
                                </div>
                                <span class="fw-bold"><?= number_format($dept['avg_salary'], 0, ',', '.') ?> ₺</span>
                            </div>
                            <div class="progress" style="height: 6px;">
                                <div class="progress-bar bg-info" style="width: <?= $percentage ?>%"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center py-3 text-muted">
                        <i class="bi bi-info-circle"></i> Henüz maaş bilgisi olan ilan bulunmamaktadır.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
        <!-- Zaman İçinde Başvuru Kalitesi Grafiği -->
        <div class="col-lg-12 mb-4">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title">
                        <i class="bi bi-graph-up-arrow me-2 text-primary"></i>Zaman İçinde Başvuru Kalitesi
                    </h5>
                    <div class="d-flex align-items-center">
                        <div class="btn-group me-3" role="group" aria-label="Veri tipi seçimi">
                            <input type="radio" class="btn-check" name="quality-view" id="quality-view-average" autocomplete="off" checked onchange="updateQualityChartType('average')">
                            <label class="btn btn-sm btn-outline-primary" for="quality-view-average">Ortalama</label>
                            
                            <input type="radio" class="btn-check" name="quality-view" id="quality-view-trend" autocomplete="off" onchange="updateQualityChartType('trend')">
                            <label class="btn btn-sm btn-outline-primary" for="quality-view-trend">Trend</label>
                        </div>
                        
                        <select class="form-select form-select-sm" id="qualityTimeRange" onchange="updateQualityChart(this.value)">
                            <option value="7">Son 7 Gün</option>
                            <option value="30" selected>Son 30 Gün</option>
                            <option value="90">Son 3 Ay</option>
                            <option value="180">Son 6 Ay</option>
                            <option value="365">Son 1 Yıl</option>
                        </select>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row align-items-center mb-3">
                        <div class="col-md-8">
                            <div class="d-flex flex-wrap align-items-center">
                                <div class="d-flex align-items-center me-4 mb-2">
                                    <span class="dot bg-primary me-2"></span>
                                    <span>Test Puanı</span>
                                </div>
                                <div class="d-flex align-items-center me-4 mb-2">
                                    <span class="dot bg-success me-2"></span>
                                    <span>CV Puanı</span>
                                </div>
                                <div class="d-flex align-items-center me-4 mb-2">
                                    <span class="dot bg-warning me-2"></span>
                                    <span>Klasik Soru Puanı</span>
                                </div>
                                <div class="d-flex align-items-center mb-2">
                                    <span class="dot bg-info me-2"></span>
                                    <span>Toplam Puan</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 text-md-end">
                            <div class="fs-5 d-flex justify-content-md-end align-items-center">
                                <span class="text-muted me-2">Ortalama Kalite:</span>
                                <span id="average-quality-score" class="fw-bold">0</span>
                                <span id="quality-trend-indicator" class="ms-2"><i class="bi bi-dash"></i></span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="position-relative">
                        <div class="chart-container" style="position: relative; height:350px; width:100%">
                            <canvas id="applicationQualityChart"></canvas>
                        </div>
                        <div id="chart-loading" class="chart-loading">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Yükleniyor...</span>
                            </div>
                        </div>
                        <div id="chart-no-data" class="chart-no-data" style="display:none">
                            <i class="bi bi-bar-chart-line"></i>
                            <p>Bu zaman aralığında veri bulunamadı</p>
                        </div>
                    </div>
                    
                    <div class="row mt-4 quality-stats">
                        <div class="col-md-3 col-sm-6 mb-3">
                            <div class="card border-0 bg-light h-100">
                                <div class="card-body">
                                    <h6 class="text-muted mb-1">En Yüksek Test Puanı</h6>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div class="display-6 fw-bold text-primary" id="max-test-score">0</div>
                                        <div class="stat-icon bg-primary-light">
                                            <i class="bi bi-check-circle"></i>
                                        </div>
                                    </div>
                                    <small class="text-muted" id="max-test-date">-</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6 mb-3">
                            <div class="card border-0 bg-light h-100">
                                <div class="card-body">
                                    <h6 class="text-muted mb-1">En Yüksek CV Puanı</h6>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div class="display-6 fw-bold text-success" id="max-cv-score">0</div>
                                        <div class="stat-icon bg-success-light">
                                            <i class="bi bi-file-earmark-person"></i>
                                        </div>
                                    </div>
                                    <small class="text-muted" id="max-cv-date">-</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6 mb-3">
                            <div class="card border-0 bg-light h-100">
                                <div class="card-body">
                                    <h6 class="text-muted mb-1">En Yüksek Klasik Puanı</h6>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div class="display-6 fw-bold text-warning" id="max-classic-score">0</div>
                                        <div class="stat-icon bg-warning-light">
                                            <i class="bi bi-journal-text"></i>
                                        </div>
                                    </div>
                                    <small class="text-muted" id="max-classic-date">-</small>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 col-sm-6 mb-3">
                            <div class="card border-0 bg-light h-100">
                                <div class="card-body">
                                    <h6 class="text-muted mb-1">Toplam Başvuru</h6>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div class="display-6 fw-bold text-info" id="total-applications-trend">0</div>
                                        <div class="stat-icon bg-info-light">
                                            <i class="bi bi-people"></i>
                                        </div>
                                    </div>
                                    <small id="application-trend">
                                        <i class="bi bi-dash"></i> %0
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- En Çok Yanlış Yapılan Sorular Kartı -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title">
                            <i class="bi bi-exclamation-circle-fill me-2 text-danger"></i>En Çok Yanlış Yapılan Sorular
                        </h5>
                        <span class="badge bg-danger">Top 7</span>
                    </div>
                    <div class="card-body">
                        <?php
                        // En çok yanlış cevaplanan soruları çek
                        $wrong_answers_query = "
                            SELECT 
                                q.id,
                                q.question_text,
                                j.title as job_title,
                                COUNT(aa.id) as wrong_answers,
                                (
                                    SELECT COUNT(aa2.id) 
                                    FROM application_answers aa2 
                                    JOIN options o2 ON aa2.option_id = o2.id 
                                    WHERE aa2.question_id = q.id
                                ) as total_answers,
                                ROUND(
                                    COUNT(aa.id) * 100.0 / (
                                        SELECT COUNT(aa2.id) 
                                        FROM application_answers aa2 
                                        WHERE aa2.question_id = q.id
                                    )
                                ) as error_rate
                            FROM 
                                questions q
                            JOIN 
                                application_answers aa ON q.id = aa.question_id
                            JOIN 
                                options o ON aa.option_id = o.id
                            JOIN 
                                jobs j ON q.job_id = j.id
                            WHERE 
                                q.question_type = 'multiple_choice' 
                                AND o.is_correct = 0
                            GROUP BY 
                                q.id, q.question_text, j.title
                            HAVING 
                                COUNT(aa.id) > 0
                            ORDER BY 
                                wrong_answers DESC
                            LIMIT 7
                        ";
                        
                        try {
                            $stmt = $db->query($wrong_answers_query);
                            $wrong_questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
                            
                            if (count($wrong_questions) > 0):
                        ?>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th>#</th>
                                            <th>Soru</th>
                                            <th>İlan</th>
                                            <th>Yanlış Cevap</th>
                                            <th>Hata Oranı</th>
                                            <th>İşlem</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($wrong_questions as $index => $question): ?>
                                            <tr>
                                                <td><?= $index + 1 ?></td>
                                                <td>
                                                    <?php
                                                        // Soru metni uzunsa kısalt
                                                        $question_text = $question['question_text'];
                                                        if(mb_strlen($question_text) > 70) {
                                                            $question_text = mb_substr($question_text, 0, 70) . '...';
                                                        }
                                                        echo htmlspecialchars($question_text);
                                                    ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-secondary"><?= htmlspecialchars($question['job_title']) ?></span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-danger"><?= $question['wrong_answers'] ?></span>
                                                    <small class="text-muted">/ <?= $question['total_answers'] ?></small>
                                                </td>
                                                <td>
                                                    <?php 
                                                        $error_rate = $question['error_rate'];
                                                        $rate_class = 'bg-success';
                                                        if($error_rate > 50) $rate_class = 'bg-danger';
                                                        else if($error_rate > 30) $rate_class = 'bg-warning';
                                                    ?>
                                                    <div class="progress" style="height: 8px; width: 100px;">
                                                        <div class="progress-bar <?= $rate_class ?>" role="progressbar" style="width: <?= $error_rate ?>%" aria-valuenow="<?= $error_rate ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                                    </div>
                                                    <small><?= $error_rate ?>%</small>
                                                </td>
                                                <td>
                                                    <a href="edit-question.php?id=<?= $question['id'] ?>" class="btn btn-sm btn-outline-primary">
                                                        <i class="bi bi-pencil-square"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <i class="bi bi-check-circle text-success fs-1"></i>
                                <p class="mt-3">Henüz yanlış cevaplanan soru bulunmamaktadır.</p>
                            </div>
                        <?php 
                            endif;
                        } catch (PDOException $e) {
                            echo '<div class="alert alert-danger">Sorular alınırken bir hata oluştu.</div>';
                            error_log($e->getMessage());
                        }
                        ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Son Başvurular -->
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title">
                            <i class="bi bi-clock-history me-2"></i>Son Başvurular
                        </h5>
                        <a href="manage-applications.php" class="btn btn-sm btn-outline-primary">
                            Tümünü Görüntüle
                        </a>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Başvuran</th>
                                        <th>İlan</th>
                                        <th>Test Skoru</th>
                                        <th>Durum</th>
                                        <th>Tarih</th>
                                        <th>İşlem</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($recent_applications)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-4">Henüz başvuru bulunmamaktadır.</td>
                                    </tr>
                                    <?php else: ?>
                                        <?php foreach ($recent_applications as $app): ?>
                                        <tr>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <?php
                                                        // Avatar için rastgele renk
                                                        $colors = ['bg-primary', 'bg-success', 'bg-info', 'bg-warning', 'bg-danger'];
                                                        $color = $colors[array_rand($colors)];
                                                    ?>
                                                    <div class="avatar <?= $color ?> me-2">
                                                        <?= strtoupper(substr($app['first_name'], 0, 1) . substr($app['last_name'], 0, 1)) ?>
                                                    </div>
                                                    <div>
                                                        <?= htmlspecialchars($app['first_name'] . ' ' . $app['last_name']) ?>
                                                        <div class="small text-muted"><?= htmlspecialchars($app['email']) ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <?= htmlspecialchars($app['job_title']) ?>
                                            </td>
                                            <td>
                                                <?php
                                                    $score = $app['score'];
                                                    if ($score >= 80) $badgeClass = 'badge-success-soft';
                                                    else if ($score >= 60) $badgeClass = 'badge-info-soft';
                                                    else if ($score >= 40) $badgeClass = 'badge-warning-soft';
                                                    else $badgeClass = 'badge-danger-soft';
                                                ?>
                                                <span class="badge <?= $badgeClass ?>"><?= $score ?></span>
                                            </td>
                                            <td>
                                                <?php if ($app['status'] === 'new'): ?>
                                                    <span class="badge badge-info-soft">Yeni</span>
                                                <?php else: ?>
                                                    <span class="badge badge-success-soft">İncelendi</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div><?= date('d.m.Y', strtotime($app['created_at'])) ?></div>
                                                <div class="small text-muted"><?= date('H:i', strtotime($app['created_at'])) ?></div>
                                            </td>
                                            <td>
                                                <a href="application-detail.php?id=<?= $app['id'] ?>" class="btn btn-sm btn-outline-primary">
                                                    <i class="bi bi-eye"></i>
                                                </a>
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
    </div>

    <!-- Footer -->
    <footer class="text-center text-muted py-4 border-top mt-5">
        <div class="container">
            <p class="mb-0">İş Başvuru Sistemi &copy; <?= date('Y') ?>. Tüm hakları saklıdır.</p>
        </div>
    </footer>

    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-annotation"></script>

    <script>
    // Chart nesnesi
    let qualityChart = null;
    let chartType = 'average'; // 'average' veya 'trend' olabilir

    // Sayfa yüklendiğinde
    document.addEventListener('DOMContentLoaded', function() {
        // İlk grafiği çiz
        updateQualityChart(30);
    });

    // Kalite grafiğini güncelle - API'den veri çek
    function updateQualityChart(days) {
        // Yükleniyor göster
        document.getElementById('chart-loading').style.display = 'flex';
        document.getElementById('chart-no-data').style.display = 'none';
        
        // API'den veri çek
        fetch(`api/application-quality-trend.php?days=${days}`)
            .then(response => response.json())
            .then(data => {
                document.getElementById('chart-loading').style.display = 'none';
                
                // Veri var mı kontrol et
                if (!data.dates || data.dates.length === 0) {
                    document.getElementById('chart-no-data').style.display = 'flex';
                    return;
                }
                
                // İstatistikleri güncelle
                updateStatistics(data.statistics);
                
                // Grafik tipine göre uygun grafiği çiz
                if (chartType === 'average') {
                    renderAverageChart(data.dates, data.testScores, data.cvScores, data.classicScores, data.totalScores);
                } else {
                    renderTrendChart(data.dates, data.totalScores);
                }
            })
            .catch(error => {
                console.error("Veri çekme hatası:", error);
                document.getElementById('chart-loading').style.display = 'none';
                document.getElementById('chart-no-data').style.display = 'flex';
            });
    }

    // İstatistikleri güncelle
    function updateStatistics(stats) {
        // Ortalama kalite skoru
        document.getElementById('average-quality-score').textContent = stats.avgQuality;
        
        // Trend göstergesi
        const trendIcon = stats.applicationTrend > 0 ? 'bi-arrow-up-short text-success' : 
                        (stats.applicationTrend < 0 ? 'bi-arrow-down-short text-danger' : 'bi-dash');
        document.getElementById('quality-trend-indicator').innerHTML = `<i class="bi ${trendIcon}"></i>`;
        
        // En yüksek skorlar
        document.getElementById('max-test-score').textContent = stats.maxTest.score;
        document.getElementById('max-test-date').textContent = stats.maxTest.date;
        
        document.getElementById('max-cv-score').textContent = stats.maxCv.score;
        document.getElementById('max-cv-date').textContent = stats.maxCv.date;
        
        document.getElementById('max-classic-score').textContent = stats.maxClassic.score;
        document.getElementById('max-classic-date').textContent = stats.maxClassic.date;
        
        // Toplam başvuru sayısı
        document.getElementById('total-applications-trend').textContent = stats.totalApplications;
        
        // Başvuru trendi
        const trendClass = stats.applicationTrend >= 0 ? 'success' : 'danger';
        const trendDirection = stats.applicationTrend >= 0 ? 'up' : 'down';
        document.getElementById('application-trend').className = `text-${trendClass}`;
        document.getElementById('application-trend').innerHTML = 
            `<i class="bi bi-arrow-${trendDirection}-short"></i> %${Math.abs(stats.applicationTrend)} ${stats.applicationTrend >= 0 ? 'artış' : 'azalış'}`;
    }

    // Grafik tipini güncelle
    function updateQualityChartType(type) {
        chartType = type;
        const days = document.getElementById('qualityTimeRange').value;
        updateQualityChart(days);
    }

    // Ortalama verileri gösteren grafik
    function renderAverageChart(dates, testScores, cvScores, classicScores, totalScores) {
        const ctx = document.getElementById('applicationQualityChart').getContext('2d');
        
        // Önceki grafiği temizle
        if (qualityChart) {
            qualityChart.destroy();
        }
        
        // Yeni grafiği oluştur
        qualityChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: dates,
                datasets: [
                    {
                        label: 'Test Puanı',
                        data: testScores,
                        borderColor: '#4361ee',
                        backgroundColor: 'rgba(67, 97, 238, 0.1)',
                        tension: 0.4,
                        fill: true,
                        pointBackgroundColor: '#fff',
                        pointBorderColor: '#4361ee',
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 6
                    },
                    {
                        label: 'CV Puanı',
                        data: cvScores,
                        borderColor: '#2ecc71',
                        backgroundColor: 'rgba(46, 204, 113, 0.1)',
                        tension: 0.4,
                        fill: true,
                        pointBackgroundColor: '#fff',
                        pointBorderColor: '#2ecc71',
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 6
                    },
                    {
                        label: 'Klasik Soru Puanı',
                        data: classicScores,
                        borderColor: '#f39c12',
                        backgroundColor: 'rgba(243, 156, 18, 0.1)',
                        tension: 0.4,
                        fill: true,
                        pointBackgroundColor: '#fff',
                        pointBorderColor: '#f39c12',
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 6
                    },
                    {
                        label: 'Toplam Puan',
                        data: totalScores,
                        borderColor: '#3498db',
                        borderWidth: 3,
                        backgroundColor: 'transparent',
                        tension: 0.4,
                        fill: false,
                        pointBackgroundColor: '#fff',
                        pointBorderColor: '#3498db',
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 6
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        enabled: true,
                        mode: 'index',
                        intersect: false,
                        backgroundColor: 'rgba(255, 255, 255, 0.9)',
                        titleColor: '#333',
                        bodyColor: '#555',
                        borderColor: '#ddd',
                        borderWidth: 1,
                        padding: 10,
                        cornerRadius: 4,
                        boxShadow: '0 2px 4px rgba(0,0,0,0.1)',
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                if (context.parsed.y !== null) {
                                    label += context.parsed.y + ' puan';
                                }
                                return label;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                        grid: {
                            display: true,
                            drawBorder: true,
                            color: 'rgba(200, 200, 200, 0.2)',
                        },
                        ticks: {
                            callback: function(value) {
                                return value + ' P';
                            }
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            maxRotation: 0
                        }
                    }
                },
                animations: {
                    tension: {
                        duration: 1000,
                        easing: 'linear'
                    }
                }
            }
        });
    }

    // Trend grafiği (sadece toplam skor)
    function renderTrendChart(dates, totalScores) {
        const ctx = document.getElementById('applicationQualityChart').getContext('2d');
        
        // Önceki grafiği temizle
        if (qualityChart) {
            qualityChart.destroy();
        }
        
        // Hareketli ortalama hesapla (3 noktalık)
        let movingAverage = [];
        for (let i = 0; i < totalScores.length; i++) {
            if (i < 2) {
                movingAverage.push(null); // İlk 2 nokta için yeterli veri yok
            } else {
                let avg = (totalScores[i] + totalScores[i-1] + totalScores[i-2]) / 3;
                movingAverage.push(Math.round(avg));
            }
        }
        
        // Linear regresyon çizgisi için hesaplama
        const regressionLine = calculateRegressionLine(totalScores);
        const regressionData = dates.map((_, i) => {
            return regressionLine.slope * i + regressionLine.intercept;
        });
        
        // Yeni trend grafiğini oluştur
        qualityChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: dates,
                datasets: [
                    {
                        label: 'Toplam Puan',
                        data: totalScores,
                        borderColor: '#3498db',
                        borderWidth: 3,
                        backgroundColor: 'rgba(52, 152, 219, 0.1)',
                        tension: 0.4,
                        fill: true,
                        pointBackgroundColor: '#fff',
                        pointBorderColor: '#3498db',
                        pointBorderWidth: 2,
                        pointRadius: 5,
                        pointHoverRadius: 7
                    },
                    {
                        label: '3-Gün Hareketli Ortalama',
                        data: movingAverage,
                        borderColor: '#e74c3c',
                        borderWidth: 2,
                        borderDash: [5, 5],
                        backgroundColor: 'transparent',
                        tension: 0.4,
                        fill: false,
                        pointRadius: 0,
                        pointHoverRadius: 0
                    },
                    {
                        label: 'Trend Çizgisi',
                        data: regressionData,
                        borderColor: '#8e44ad',
                        borderWidth: 2,
                        backgroundColor: 'transparent',
                        tension: 0,
                        fill: false,
                        pointRadius: 0,
                        pointHoverRadius: 0
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'top'
                    },
                    tooltip: {
                        enabled: true,
                        backgroundColor: 'rgba(255, 255, 255, 0.9)',
                        titleColor: '#333',
                        bodyColor: '#555',
                        borderColor: '#ddd',
                        borderWidth: 1,
                        padding: 10,
                        cornerRadius: 4,
                        boxShadow: '0 2px 4px rgba(0,0,0,0.1)'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: false,
                        grid: {
                            display: true,
                            drawBorder: true,
                            color: 'rgba(200, 200, 200, 0.2)',
                        },
                        ticks: {
                            callback: function(value) {
                                return value + ' P';
                            }
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            maxRotation: 0,
                            maxTicksLimit: 10
                        }
                    }
                }
            }
        });
    }

    // Linear regresyon hesaplama
    function calculateRegressionLine(yValues) {
        const xValues = Array.from({length: yValues.length}, (_, i) => i);
        
        const xMean = xValues.reduce((a, b) => a + b, 0) / xValues.length;
        const yMean = yValues.reduce((a, b) => a + b, 0) / yValues.length;
        
        let numerator = 0;
        let denominator = 0;
        
        for (let i = 0; i < xValues.length; i++) {
            numerator += (xValues[i] - xMean) * (yValues[i] - yMean);
            denominator += Math.pow(xValues[i] - xMean, 2);
        }
        
        const slope = denominator !== 0 ? numerator / denominator : 0;
        const intercept = yMean - (slope * xMean);
        
        return { slope, intercept };
    }
    </script>
</body>
</html>

<?php
// Output buffer içeriğini gönder ve buffer'ı temizle
ob_end_flush();
?>