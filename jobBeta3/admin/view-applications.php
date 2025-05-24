<?php
session_start();

// Oturum kontrolü
if (!isset($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit;
}

require_once '../config/db.php';

// Filtre parametreleri
$filter_job = isset($_GET['job_id']) ? (int)$_GET['job_id'] : 0;
$filter_status = isset($_GET['status']) ? $_GET['status'] : '';

// İş ilanlarını al (filtre için)
$stmt = $db->query("SELECT id, title FROM jobs ORDER BY title");
$jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Başvuruları al
$query = "SELECT a.*, j.title as job_title 
          FROM applications a 
          INNER JOIN jobs j ON a.job_id = j.id 
          WHERE 1=1";
$params = [];

if ($filter_job > 0) {
    $query .= " AND a.job_id = :job_id";
    $params[':job_id'] = $filter_job;
}

if (!empty($filter_status)) {
    $query .= " AND a.status = :status";
    $params[':status'] = $filter_status;
}

$query .= " ORDER BY a.created_at DESC";

$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$applications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Durum güncelleme işlemi
if (isset($_POST['update_status']) && isset($_POST['application_id'])) {
    $app_id = (int)$_POST['application_id'];
    $new_status = $_POST['new_status'];
    
    $stmt = $db->prepare("UPDATE applications SET status = :status WHERE id = :id");
    $stmt->bindParam(':status', $new_status);
    $stmt->bindParam(':id', $app_id, PDO::PARAM_INT);
    
    if ($stmt->execute()) {
        $update_success = true;
        
        // Sayfayı yeniden yükle
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Başvuruları Görüntüle - İş Başvuru Sistemi</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
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
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg bg-white shadow-sm sticky-top">
        <div class="container-fluid">
            <a class="navbar-brand fw-bold text-primary" href="dashboard.php">
                <i class="bi bi-briefcase-fill me-2"></i>İş Başvuru Sistemi
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#adminNavbar" aria-controls="adminNavbar" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="adminNavbar">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>" href="dashboard.php">
                            <i class="bi bi-house-door me-1"></i>Ana Sayfa
                        </a>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?php echo in_array(basename($_SERVER['PHP_SELF']), ['manage-applications.php', 'view-applications.php', 'application-detail.php', 'application-detail2.php']) ? 'active' : ''; ?>" href="#" id="applicationsDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-file-earmark-person me-1"></i>Başvurular
                        </a>
                        <ul class="dropdown-menu" aria-labelledby="applicationsDropdown">
                            <li><a class="dropdown-item" href="manage-applications.php"><i class="bi bi-list-ul me-2"></i>Başvuru Listesi</a></li>
                            <li><a class="dropdown-item" href="view-applications.php"><i class="bi bi-eye me-2"></i>Başvuru Görüntüle</a></li>
                        </ul>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?php echo in_array(basename($_SERVER['PHP_SELF']), ['manage-jobs.php', 'create-job.php', 'edit-job.php', 'manage-job-questions.php']) ? 'active' : ''; ?>" href="#" id="jobsDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-briefcase me-1"></i>İş İlanları
                        </a>
                        <ul class="dropdown-menu" aria-labelledby="jobsDropdown">
                            <li><a class="dropdown-item" href="manage-jobs.php"><i class="bi bi-list-ul me-2"></i>İlan Listesi</a></li>
                            <li><a class="dropdown-item" href="create-job.php"><i class="bi bi-plus-circle me-2"></i>Yeni İlan</a></li>
                            <li><a class="dropdown-item" href="manage-job-questions.php"><i class="bi bi-question-circle me-2"></i>İlan Soruları</a></li>
                        </ul>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?php echo in_array(basename($_SERVER['PHP_SELF']), ['manage-templates.php', 'create-template.php', 'edit-template.php', 'edit-template-question.php', 'edit-question.php']) ? 'active' : ''; ?>" href="#" id="templatesDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-file-earmark-text me-1"></i>Şablonlar
                        </a>
                        <ul class="dropdown-menu" aria-labelledby="templatesDropdown">
                            <li><a class="dropdown-item" href="manage-templates.php"><i class="bi bi-list-ul me-2"></i>Şablon Listesi</a></li>
                            <li><a class="dropdown-item" href="create-template.php"><i class="bi bi-plus-circle me-2"></i>Yeni Şablon</a></li>
                        </ul>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?php echo basename($_SERVER['PHP_SELF']) == 'application-statistics.php' ? 'active' : ''; ?>" href="#" id="analyticsDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-graph-up me-1"></i>Analitik
                        </a>
                        <ul class="dropdown-menu" aria-labelledby="analyticsDropdown">
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

    <div class="container mt-4">
        <h1>Başvuruları Görüntüle</h1>
        
        <!-- Filtreler -->
        <div class="card mb-4">
            <div class="card-header bg-secondary text-white">
                <h5 class="mb-0">Filtrele</h5>
            </div>
            <div class="card-body">
                <form method="get" class="row align-items-end">
                    <div class="col-md-5 mb-3">
                        <label for="job_id" class="form-label">İş İlanı</label>
                        <select class="form-select" id="job_id" name="job_id">
                            <option value="0">Tümü</option>
                            <?php foreach ($jobs as $job): ?>
                                <option value="<?= $job['id'] ?>" <?= ($filter_job == $job['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($job['title']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-5 mb-3">
                        <label for="status" class="form-label">Durum</label>
                        <select class="form-select" id="status" name="status">
                            <option value="">Tümü</option>
                            <option value="new" <?= ($filter_status == 'new') ? 'selected' : '' ?>>İncelenmedi</option>
                            <option value="reviewed" <?= ($filter_status == 'reviewed') ? 'selected' : '' ?>>İncelendi</option>
                        </select>
                    </div>
                    <div class="col-md-2 mb-3">
                        <button type="submit" class="btn btn-primary w-100">Filtrele</button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Başvuru Listesi -->
        <?php if (empty($applications)): ?>
            <div class="alert alert-info">Filtrelere uygun başvuru bulunamadı.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-bordered table-striped">
                    <thead class="table-dark">
                        <tr>
                            <th>ID</th>
                            <th>İsim</th>
                            <th>İş İlanı</th>
                            <th>E-posta</th>
                            <th>Telefon</th>
                            <th>Skor</th>
                            <th>Başvuru Tarihi</th>
                            <th>Durum</th>
                            <th>İşlemler</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($applications as $app): ?>
                            <tr>
                                <td><?= $app['id'] ?></td>
                                <td><?= htmlspecialchars($app['first_name'] . ' ' . $app['last_name']) ?></td>
                                <td><?= htmlspecialchars($app['job_title']) ?></td>
                                <td><?= htmlspecialchars($app['email']) ?></td>
                                <td><?= htmlspecialchars($app['phone']) ?></td>
                                <td>
                                    <?php if ($app['score'] > 0): ?>
                                        <span class="badge bg-success"><?= $app['score'] ?> puan</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">0 puan</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= date('d.m.Y H:i', strtotime($app['created_at'])) ?></td>
                                <td>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="application_id" value="<?= $app['id'] ?>">
                                        <select class="form-select form-select-sm" name="new_status" onchange="this.form.submit()">
                                            <option value="new" <?= ($app['status'] == 'new') ? 'selected' : '' ?>>İncelenmedi</option>
                                            <option value="reviewed" <?= ($app['status'] == 'reviewed') ? 'selected' : '' ?>>İncelendi</option>
                                        </select>
                                        <input type="hidden" name="update_status" value="1">
                                    </form>
                                </td>
                                <td>
                                    <a href="application-detail.php?id=<?= $app['id'] ?>" class="btn btn-sm btn-info">Detay</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>