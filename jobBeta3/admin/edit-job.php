<?php
session_start();

// Oturum kontrolü
if (!isset($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit;
}

require_once '../config/db.php';
require_once '../includes/functions.php';

// İş ilanı ID'si kontrolü
$job_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($job_id <= 0) {
    header('Location: manage-jobs.php');
    exit;
}

// İş ilanı verisini al
$stmt = $db->prepare("SELECT * FROM jobs WHERE id = :job_id");
$stmt->bindParam(':job_id', $job_id, PDO::PARAM_INT);
$stmt->execute();
$job = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$job) {
    header('Location: manage-jobs.php');
    exit;
}

// Soru şablonlarını al
$stmt = $db->query("SELECT * FROM question_templates ORDER BY template_name");
$templates = $stmt->fetchAll(PDO::FETCH_ASSOC);

$success = false;
$error = '';

// Form gönderildiyse işle
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $title = trim($_POST['title']);
        $description = trim($_POST['description']);
        $location = trim($_POST['location']);
        $status = $_POST['status'];
        $template_id = !empty($_POST['template_id']) ? (int)$_POST['template_id'] : null;
        
        // İş ilanını güncelle
        $stmt = $db->prepare("UPDATE jobs SET title = :title, description = :description, location = :location, status = :status, template_id = :template_id WHERE id = :job_id");
        $stmt->bindParam(':title', $title);
        $stmt->bindParam(':description', $description);
        $stmt->bindParam(':location', $location);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':template_id', $template_id, PDO::PARAM_INT);
        $stmt->bindParam(':job_id', $job_id, PDO::PARAM_INT);
        $stmt->execute();
        
        // Şablon değiştiyse ve "apply_template" seçildiyse
        if (isset($_POST['apply_template']) && $_POST['apply_template'] == '1' && $template_id != $job['template_id']) {
            // Mevcut soruları sil (dikkat: bunun başvurulardaki cevapları da sildiğinden emin olmalısınız)
            if ($db->inTransaction()) {
                $db->commit();
            }
            
            $db->beginTransaction();
            
            $stmt = $db->prepare("DELETE FROM questions WHERE job_id = :job_id");
            $stmt->bindParam(':job_id', $job_id, PDO::PARAM_INT);
            $stmt->execute();
            
            // Yeni şablonu uygula
            $result = applyTemplateToJob($db, $template_id, $job_id);
            if (!$result) {
                throw new Exception("Şablon soruları iş ilanına eklenirken bir hata oluştu.");
            }
            
            $db->commit();
        }
        
        $success = true;
        
        // İş ilanı bilgilerini yeniden yükle
        $stmt = $db->prepare("SELECT * FROM jobs WHERE id = :job_id");
        $stmt->bindParam(':job_id', $job_id, PDO::PARAM_INT);
        $stmt->execute();
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error = "İş ilanı güncellenirken bir hata oluştu: " . $e->getMessage();
    }
}

// İlana ait soru sayısını al
$stmt = $db->prepare("SELECT COUNT(*) FROM questions WHERE job_id = :job_id");
$stmt->bindParam(':job_id', $job_id, PDO::PARAM_INT);
$stmt->execute();
$question_count = $stmt->fetchColumn();

// İlana ait başvuru sayısını al
$stmt = $db->prepare("SELECT COUNT(*) FROM applications WHERE job_id = :job_id");
$stmt->bindParam(':job_id', $job_id, PDO::PARAM_INT);
$stmt->execute();
$application_count = $stmt->fetchColumn();
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>İş İlanı Düzenle - İş Başvuru Sistemi</title>
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
                <ul class="navbar-nav">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <img src="https://via.placeholder.com/32" alt="User" class="rounded-circle me-2" width="32" height="32">
                            <span>Admin</span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                            <li><a class="dropdown-item" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>Çıkış Yap</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1>İş İlanı Düzenle</h1>
            <div>
                <a href="manage-jobs.php" class="btn btn-secondary">İş İlanlarına Dön</a>
                <?php if ($question_count > 0): ?>
                    <a href="manage-job-questions.php?job_id=<?= $job_id ?>" class="btn btn-info ms-2">Soruları Düzenle (<?= $question_count ?>)</a>
                <?php endif; ?>
                <?php if ($application_count > 0): ?>
                    <a href="view-applications.php?job_id=<?= $job_id ?>" class="btn btn-success ms-2">Başvuruları Gör (<?= $application_count ?>)</a>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                İş ilanı başarıyla güncellendi.
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= $error ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <form method="post">
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">İlan Bilgileri</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="title" class="form-label">İlan Başlığı *</label>
                        <input type="text" class="form-control" id="title" name="title" value="<?= htmlspecialchars($job['title']) ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="description" class="form-label">İlan Açıklaması *</label>
                        <textarea class="form-control" id="description" name="description" rows="5" required><?= htmlspecialchars($job['description']) ?></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="location" class="form-label">Lokasyon *</label>
                            <input type="text" class="form-control" id="location" name="location" value="<?= htmlspecialchars($job['location']) ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="status" class="form-label">Durum *</label>
                            <select class="form-select" id="status" name="status" required>
                                <option value="active" <?= ($job['status'] == 'active') ? 'selected' : '' ?>>Aktif</option>
                                <option value="inactive" <?= ($job['status'] == 'inactive') ? 'selected' : '' ?>>Pasif</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="card mb-4">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0">Soru Şablonu</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="template_id" class="form-label">Soru Şablonu</label>
                        <select class="form-select" id="template_id" name="template_id">
                            <option value="">Şablon Seçilmedi</option>
                            <?php foreach ($templates as $template): ?>
                                <option value="<?= $template['id'] ?>" <?= ($template['id'] == $job['template_id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($template['template_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <?php if ($question_count > 0): ?>
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="apply_template" name="apply_template" value="1">
                        <label class="form-check-label" for="apply_template">
                            <strong class="text-danger">Dikkat:</strong> Şablonu yeniden uygula (mevcut <?= $question_count ?> soru silinecek)
                        </label>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-warning">
                        Bu iş ilanı için henüz soru bulunmamaktadır. Şablon seçip kaydettiğinizde sorular otomatik olarak eklenecektir.
                    </div>
                    <input type="hidden" name="apply_template" value="1">
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="text-center mb-4">
                <button type="submit" class="btn btn-primary btn-lg">Değişiklikleri Kaydet</button>
                <a href="manage-jobs.php" class="btn btn-secondary btn-lg ms-2">İptal</a>
            </div>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>