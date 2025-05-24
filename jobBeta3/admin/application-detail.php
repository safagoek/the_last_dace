<?php
// Output buffering başlat - header yönlendirme sorununu çözer
ob_start();

session_start();

// Oturum kontrolü
if (!isset($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit;
}

require_once '../config/db.php';

// Başvuru ID kontrolü
$application_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($application_id <= 0) {
    header('Location: manage-applications.php');
    exit;
}

// Veritabanında gerekli sütunların varlığını kontrol et
try {
    // admin_note sütununu kontrol et
    $columnCheckStmt = $db->prepare("SHOW COLUMNS FROM applications LIKE 'admin_note'");
    $columnCheckStmt->execute();
    if ($columnCheckStmt->rowCount() == 0) {
        $db->exec("ALTER TABLE applications ADD COLUMN admin_note TEXT NULL");
    }
    
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

// Başvuru bilgilerini al
$stmt = $db->prepare("SELECT a.*, j.title as job_title, j.location as job_location 
                     FROM applications a 
                     JOIN jobs j ON a.job_id = j.id 
                     WHERE a.id = :application_id");
$stmt->bindParam(':application_id', $application_id, PDO::PARAM_INT);
$stmt->execute();
$application = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$application) {
    header('Location: manage-applications.php');
    exit;
}

// Başvuruya ait cevapları al
$stmt = $db->prepare("SELECT aa.*, q.question_text, q.question_type 
                     FROM application_answers aa
                     JOIN questions q ON aa.question_id = q.id
                     WHERE aa.application_id = :application_id
                     ORDER BY q.id");
$stmt->bindParam(':application_id', $application_id, PDO::PARAM_INT);
$stmt->execute();
$answers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Çoktan seçmeli sorular için seçilen ve doğru şıkları al
$option_details = [];
foreach ($answers as $answer) {
    if ($answer['question_type'] == 'multiple_choice' && !empty($answer['option_id'])) {
        $stmt = $db->prepare("SELECT o.option_text, o.is_correct 
                             FROM options o 
                             WHERE o.id = :option_id");
        $stmt->bindParam(':option_id', $answer['option_id'], PDO::PARAM_INT);
        $stmt->execute();
        $option_details[$answer['id']] = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

$success = '';
$error = '';

// Form işlemleri
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Not ekleme/güncelleme işlemi
    if (isset($_POST['action']) && $_POST['action'] === 'update_note') {
        $note = trim($_POST['note']);
        
        $stmt = $db->prepare("UPDATE applications SET admin_note = :note WHERE id = :application_id");
        $stmt->bindParam(':note', $note);
        $stmt->bindParam(':application_id', $application_id, PDO::PARAM_INT);
        
        if ($stmt->execute()) {
            $application['admin_note'] = $note;
            $success = "Not başarıyla kaydedildi.";
        } else {
            $error = "Not kaydedilirken bir hata oluştu.";
        }
    }
    
    // Başvuru durum güncelleme işlemi
    if (isset($_POST['action']) && $_POST['action'] === 'update_status') {
        $status = $_POST['status'];
        
        $stmt = $db->prepare("UPDATE applications SET status = :status WHERE id = :application_id");
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':application_id', $application_id, PDO::PARAM_INT);
        
        if ($stmt->execute()) {
            $application['status'] = $status;
            $success = "Başvuru durumu başarıyla güncellendi.";
        } else {
            $error = "Durum güncellenirken bir hata oluştu.";
        }
    }
    
    // Skor güncelleme işlemi
    if (isset($_POST['action']) && $_POST['action'] === 'update_score') {
        $score = (int)$_POST['score'];
        
        $stmt = $db->prepare("UPDATE applications SET score = :score WHERE id = :application_id");
        $stmt->bindParam(':score', $score);
        $stmt->bindParam(':application_id', $application_id, PDO::PARAM_INT);
        
        if ($stmt->execute()) {
            $application['score'] = $score;
            $success = "Skor başarıyla güncellendi.";
        } else {
            $error = "Skor güncellenirken bir hata oluştu.";
        }
    }
    
    // CV puanı güncelleme işlemi
    if (isset($_POST['action']) && $_POST['action'] === 'update_cv_score') {
        $cv_score = (int)$_POST['cv_score'];
        
        // Skor aralığını kontrol et
        if ($cv_score < 0) $cv_score = 0;
        if ($cv_score > 100) $cv_score = 100;
        
        $stmt = $db->prepare("UPDATE applications SET cv_score = :cv_score WHERE id = :application_id");
        $stmt->bindParam(':cv_score', $cv_score);
        $stmt->bindParam(':application_id', $application_id, PDO::PARAM_INT);
        
        if ($stmt->execute()) {
            $application['cv_score'] = $cv_score;
            $success = "CV puanı başarıyla güncellendi.";
        } else {
            $error = "CV puanı güncellenirken bir hata oluştu.";
        }
    }
    
    // Açık uçlu soru puanı güncelleme işlemi
    if (isset($_POST['action']) && $_POST['action'] === 'update_answer_score') {
        $answer_id = (int)$_POST['answer_id'];
        $answer_score = (int)$_POST['answer_score'];
        
        // Skor aralığını kontrol et
        if ($answer_score < 0) $answer_score = 0;
        if ($answer_score > 100) $answer_score = 100;
        
        $stmt = $db->prepare("UPDATE application_answers SET answer_score = :answer_score WHERE id = :answer_id AND application_id = :application_id");
        $stmt->bindParam(':answer_score', $answer_score);
        $stmt->bindParam(':answer_id', $answer_id);
        $stmt->bindParam(':application_id', $application_id);
        
        if ($stmt->execute()) {
            // Yanıtları tekrar yükle
            $stmt = $db->prepare("SELECT aa.*, q.question_text, q.question_type 
                                FROM application_answers aa
                                JOIN questions q ON aa.question_id = q.id
                                WHERE aa.application_id = :application_id
                                ORDER BY q.id");
            $stmt->bindParam(':application_id', $application_id, PDO::PARAM_INT);
            $stmt->execute();
            $answers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $success = "Yanıt puanı başarıyla güncellendi.";
        } else {
            $error = "Yanıt puanı güncellenirken bir hata oluştu.";
        }
    }
    
    // Toplam puan hesaplayıp güncelleme
    if (isset($_POST['action']) && $_POST['action'] === 'calculate_total_score') {
        try {
            // Çoktan seçmeli soru puanları (mevcut score)
            $multiple_choice_score = $application['score'] ?? 0;
            
            // CV puanı
            $cv_score = $application['cv_score'] ?? 0;
            
            // Açık uçlu soruların puanlarını topla
            $stmt = $db->prepare("SELECT SUM(answer_score) as total_answer_score 
                                FROM application_answers 
                                WHERE application_id = :application_id 
                                AND question_type = 'open_ended'");
            $stmt->bindParam(':application_id', $application_id, PDO::PARAM_INT);
            $stmt->execute();
            $answer_scores = $stmt->fetch(PDO::FETCH_ASSOC);
            $answer_score_total = $answer_scores['total_answer_score'] ?? 0;
            
            // Toplam puanı hesapla
            $total_score = $multiple_choice_score + $cv_score + $answer_score_total;
            
            // Güncelle
            $stmt = $db->prepare("UPDATE applications SET total_score = :total_score WHERE id = :application_id");
            $stmt->bindParam(':total_score', $total_score);
            $stmt->bindParam(':application_id', $application_id);
            
            if ($stmt->execute()) {
                $success = "Toplam puan hesaplandı ve güncellendi: " . $total_score;
            } else {
                $error = "Toplam puan güncellenirken bir hata oluştu.";
            }
        } catch (Exception $e) {
            $error = "Toplam puan hesaplanırken bir hata oluştu: " . $e->getMessage();
        }
    }
}

// Durum sınıfları
$status_classes = [
    'new' => 'bg-primary',
    'reviewing' => 'bg-info',
    'interview' => 'bg-warning',
    'accepted' => 'bg-success',
    'rejected' => 'bg-danger',
    'completed' => 'bg-secondary',
];

// Durum metinleri
$status_texts = [
    'new' => 'Yeni Başvuru',
    'reviewing' => 'İnceleniyor',
    'interview' => 'Mülakata Çağrıldı',
    'accepted' => 'Kabul Edildi',
    'rejected' => 'Reddedildi',
    'completed' => 'Tamamlandı',
];

// Başvuru tarihi için varsayılan değer
$application_date = isset($application['created_at']) ? $application['created_at'] : date('Y-m-d H:i:s');

// Başvurunun güncelleme tarihi için varsayılan değer
$updated_date = isset($application['updated_at']) ? $application['updated_at'] : $application_date;
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Başvuru Detayları | İş Başvuru Sistemi</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <!-- PDF.js kütüphanesi -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.11.338/pdf.min.js"></script>
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
        
        .tab-pane {
            padding: 20px 0;
        }
        
        .answer-card {
            margin-bottom: 20px;
            border-radius: 10px;
            border: 1px solid var(--card-border);
            transition: all 0.3s ease;
        }
        
        .answer-card:hover {
            box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
        }
        
        .answer-card.correct {
            border-left: 4px solid var(--success);
        }
        
        .answer-card.incorrect {
            border-left: 4px solid var(--danger);
        }
        
        .pdf-container {
            border: 1px solid #dee2e6;
            border-radius: 0.25rem;
            overflow: hidden;
            height: 600px;
            width: 100%;
        }

        .profile-section {
            background-color: #fff;
            border-radius: 10px;
            padding: 20px;
            box-shadow: var(--card-shadow);
            border: 1px solid var(--card-border);
        }
        
        .profile-img {
            width: 120px;
            height: 120px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background-color: var(--primary);
            color: white;
            font-size: 2.5rem;
            font-weight: 700;
            margin: 0 auto 1rem;
        }

        .stat-card {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 15px;
            color: white;
        }

        .note-area {
            min-height: 100px;
            font-size: 0.95rem;
        }
        
        .cv-preview {
            height: 800px;
            width: 100%;
            border: 1px solid #dee2e6;
            border-radius: 0.25rem;
        }

        .timeline {
            position: relative;
            padding-left: 35px;
        }
        
        .timeline::before {
            content: '';
            position: absolute;
            left: 10px;
            top: 0;
            height: 100%;
            width: 2px;
            background-color: var(--light);
        }
        
        .timeline-item {
            position: relative;
            padding-bottom: 30px;
        }
        
        .timeline-dot {
            position: absolute;
            left: -32px;
            top: 0;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background-color: var(--primary);
            border: 3px solid white;
            z-index: 1;
        }

        .score-range {
            height: 10px;
            border-radius: 5px;
        }
        
        .score-value {
            font-size: 1.5rem;
            font-weight: bold;
        }

        .score-card {
            transition: all 0.3s ease;
        }
        
        .score-card:hover {
            background-color: var(--light);
        }
        
        .info-label {
            color: var(--secondary);
            font-weight: 500;
            margin-bottom: 0.25rem;
        }
        
        .info-value {
            font-weight: 500;
            margin-bottom: 0.75rem;
            color: var(--dark);
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
        
        .form-label {
            font-weight: 500;
            margin-bottom: 0.5rem;
            color: var(--dark);
        }
        
        .form-control, .form-select {
            border-color: #e2e8f0;
            border-radius: 5px;
            padding: 0.6rem 0.75rem;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 0.25rem rgba(67, 97, 238, 0.25);
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

        @media print {
            .no-print {
                display: none !important;
            }
            .card {
                border: 1px solid #ddd !important;
            }
            .container {
                width: 100% !important;
                max-width: none !important;
            }
        }
    </style>
</head>
<body>
    <!-- Admin Navbar -->
    <nav class="navbar navbar-expand-lg bg-white shadow-sm sticky-top no-print">
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
                    </li>
                    <li class="nav-item">
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
                    </li>

                    <!-- Analitik bölümünü kaldır -->
                </ul>

                <!-- Right side navigation -->
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

    <div class="page-header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb mb-1">
                            <li class="breadcrumb-item"><a href="manage-applications.php">Başvurular</a></li>
                            <li class="breadcrumb-item active" aria-current="page">Başvuru #<?= $application_id ?></li>
                        </ol>
                    </nav>
                    <h1 class="page-title"><?= htmlspecialchars($application['first_name'] . ' ' . $application['last_name']) ?></h1>
                    <p class="page-subtitle mb-0">
                        <?= htmlspecialchars($application['job_title']) ?> - <?= htmlspecialchars($application['job_location']) ?>
                    </p>
                </div>
                <div class="col-md-6 text-md-end">
                    <div class="d-flex justify-content-md-end align-items-center">
                        <?php $statusClass = isset($status_classes[$application['status']]) ? $status_classes[$application['status']] : 'bg-secondary'; ?>
                        <span class="badge <?= $statusClass ?> me-3">
                            <?= isset($status_texts[$application['status']]) ? $status_texts[$application['status']] : 'Belirsiz' ?>
                        </span>
                        <a href="manage-applications.php" class="btn btn-outline-secondary me-2">
                            <i class="bi bi-arrow-left me-1"></i> Geri
                        </a>
                        <div class="dropdown">
                            <button class="btn btn-primary dropdown-toggle" type="button" id="dropdownMenuButton" data-bs-toggle="dropdown">
                                <i class="bi bi-gear me-1"></i> İşlemler
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="dropdownMenuButton">
                                <li><a class="dropdown-item" href="#status-modal" data-bs-toggle="modal"><i class="bi bi-tag me-2"></i> Durumu Güncelle</a></li>
                                <li><a class="dropdown-item" href="#score-modal" data-bs-toggle="modal"><i class="bi bi-star me-2"></i> Test Skorunu Güncelle</a></li>
                                <li><a class="dropdown-item" href="#" onclick="calculateTotalScore()"><i class="bi bi-calculator me-2"></i> Toplam Skoru Hesapla</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="mailto:<?= htmlspecialchars($application['email']) ?>"><i class="bi bi-envelope me-2"></i> Email Gönder</a></li>
                                <li><a class="dropdown-item" href="#" onclick="printPage()"><i class="bi bi-printer me-2"></i> Yazdır</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item text-danger" href="#" onclick="confirmDelete(<?= $application_id ?>)"><i class="bi bi-trash me-2"></i> Başvuruyu Sil</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="container mb-5">
        <?php if (!empty($success)): ?>
            <div class="alert alert-success alert-modern alert-dismissible fade show no-print" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i> <?= $success ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-modern alert-dismissible fade show no-print" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= $error ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <div class="row">
            <!-- Sol Kolon: Başvuran Bilgileri -->
            <div class="col-lg-4 mb-4">
                <div class="profile-section mb-4">
                    <div class="text-center mb-4">
                        <div class="profile-img">
                            <?= strtoupper(substr($application['first_name'], 0, 1) . substr($application['last_name'], 0, 1)) ?>
                        </div>
                        <h3 class="mt-3 mb-0"><?= htmlspecialchars($application['first_name'] . ' ' . $application['last_name']) ?></h3>
                        <p class="text-muted"><?= htmlspecialchars($application['job_title']) ?></p>
                        
                        <div class="d-flex justify-content-center mt-2">
                            <?php $statusClass = isset($status_classes[$application['status']]) ? $status_classes[$application['status']] : 'bg-secondary'; ?>
                            <span class="badge <?= $statusClass ?> status-badge">
                                <?= isset($status_texts[$application['status']]) ? $status_texts[$application['status']] : 'Belirsiz' ?>
                            </span>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-6">
                            <div class="card p-3 text-center">
                                <h6 class="text-muted mb-1">Test Skoru</h6>
                                <h3 class="mb-0 <?= ($application['score'] ?? 0) >= 70 ? 'text-success' : (($application['score'] ?? 0) >= 40 ? 'text-warning' : 'text-danger') ?>"><?= $application['score'] ?? 0 ?></h3>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="card p-3 text-center">
                                <h6 class="text-muted mb-1">CV Skoru</h6>
                                <h3 class="mb-0 <?= ($application['cv_score'] ?? 0) >= 70 ? 'text-success' : (($application['cv_score'] ?? 0) >= 40 ? 'text-warning' : 'text-danger') ?>"><?= $application['cv_score'] ?? 0 ?></h3>
                            </div>
                        </div>
                    </div>

                    <div class="list-group list-group-flush border-top border-bottom py-2 mb-3">
                        <div class="list-group-item border-0 d-flex justify-content-between align-items-center px-0">
                            <span><i class="bi bi-envelope me-2"></i> Email</span>
                            <span class="text-muted"><?= htmlspecialchars($application['email']) ?></span>
                        </div>
                        <div class="list-group-item border-0 d-flex justify-content-between align-items-center px-0">
                            <span><i class="bi bi-telephone me-2"></i> Telefon</span>
                            <span class="text-muted"><?= htmlspecialchars($application['phone']) ?></span>
                        </div>
                        <div class="list-group-item border-0 d-flex justify-content-between align-items-center px-0">
                            <span><i class="bi bi-geo-alt me-2"></i> Şehir</span>
                            <span class="text-muted"><?= htmlspecialchars($application['city'] ?? 'Belirtilmemiş') ?></span>
                        </div>
                        <div class="list-group-item border-0 d-flex justify-content-between align-items-center px-0">
                            <span><i class="bi bi-gender-ambiguous me-2"></i> Cinsiyet</span>
                            <span class="text-muted"><?= htmlspecialchars(($application['gender'] == 'male') ? 'Erkek' : (($application['gender'] == 'female') ? 'Kadın' : 'Diğer')) ?></span>
                        </div>
                        <div class="list-group-item border-0 d-flex justify-content-between align-items-center px-0">
                            <span><i class="bi bi-calendar me-2"></i> Yaş</span>
                            <span class="text-muted"><?= htmlspecialchars($application['age'] ?? 'Belirtilmemiş') ?></span>
                        </div>
                        <div class="list-group-item border-0 d-flex justify-content-between align-items-center px-0">
    <span><i class="bi bi-briefcase me-2"></i> Deneyim</span>
    <span class="text-muted">
        <?php
        $experience = $application['experience'] ?? 0;
        if ($experience == 0) {
            echo "Deneyimsiz";
        } elseif ($experience < 11) {
            echo $experience . " yıl";
        } elseif ($experience == 11) {
            echo "11-15 yıl";
        } elseif ($experience == 16) {
            echo "16-20 yıl";
        } elseif ($experience >= 21) {
            echo "20+ yıl";
        } else {
            echo "Belirtilmemiş";
        }
        ?>
    </span>
</div>
                        <div class="list-group-item border-0 d-flex justify-content-between align-items-center px-0">
                            <span><i class="bi bi-cash-stack me-2"></i> Maaş Beklentisi</span>
                            <span class="text-muted"><?= number_format($application['salary_expectation'] ?? 0, 0, ',', '.') ?> ₺</span>
                        </div>
                        <div class="list-group-item border-0 d-flex justify-content-between align-items-center px-0">
                            <span><i class="bi bi-calendar-check me-2"></i> Başvuru Tarihi</span>
                            <span class="text-muted"><?= date('d.m.Y H:i', strtotime($application_date)) ?></span>
                        </div>
                    </div>
                </div>
                
                <!-- CV Puanlama Bölümü -->
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title"><i class="bi bi-file-earmark-person me-2"></i>CV Değerlendirmesi</h5>
                    </div>
                    <div class="card-body">
                        <form method="post" action="" class="no-print">
                            <input type="hidden" name="action" value="update_cv_score">
                            <div class="mb-3">
                                <label for="cv_score" class="form-label">CV Puanı (0-100)</label>
                                <input type="range" class="form-range" id="cv_score" name="cv_score" 
                                      min="0" max="100" step="5" value="<?= $application['cv_score'] ?? 0 ?>">
                                <div class="d-flex justify-content-between">
                                    <span class="text-muted">0</span>
                                    <span id="cv_score_value" class="badge bg-primary"><?= $application['cv_score'] ?? 0 ?></span>
                                    <span class="text-muted">100</span>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">CV Puanını Kaydet</button>
                        </form>
                        
                        <div class="d-print-block d-none">
                            <div class="row align-items-center">
                                <div class="col-4">CV Skoru:</div>
                                <div class="col-8">
                                    <div class="progress">
                                        <div class="progress-bar bg-info" role="progressbar" 
                                             style="width: <?= ($application['cv_score'] ?? 0) ?>%;" 
                                             aria-valuenow="<?= $application['cv_score'] ?? 0 ?>" 
                                             aria-valuemin="0" aria-valuemax="100">
                                            <?= $application['cv_score'] ?? 0 ?> / 100
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Notlar Bölümü -->
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title"><i class="bi bi-journal-text me-2"></i>Notlar</h5>
                        <button class="btn btn-sm btn-outline-primary no-print" data-bs-toggle="modal" data-bs-target="#noteModal">
                            <i class="bi bi-pencil"></i> Düzenle
                        </button>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($application['admin_note'])): ?>
                            <p class="mb-0"><?= nl2br(htmlspecialchars($application['admin_note'])) ?></p>
                        <?php else: ?>
                            <p class="text-muted mb-0">Bu başvuru için henüz not eklenmemiş.</p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- CV Bölümü -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title"><i class="bi bi-file-earmark-text me-2"></i>CV</h5>
                    </div>
                    <div class="card-body text-center">
                        <?php if (!empty($application['cv_path'])): ?>
                            <div class="mb-3 no-print">
                                <button class="btn btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#cvPreviewCollapse">
                                    <i class="bi bi-eye me-1"></i> CV'yi Görüntüle
                                </button>
                                <a href="../<?= htmlspecialchars($application['cv_path']) ?>" class="btn btn-outline-secondary ms-2" download>
                                    <i class="bi bi-download me-1"></i> İndir
                                </a>
                            </div>
                            <div class="collapse" id="cvPreviewCollapse">
                                <iframe src="../<?= htmlspecialchars($application['cv_path']) ?>" class="cv-preview"></iframe>
                            </div>
                        <?php else: ?>
                            <p class="text-muted mb-0">CV bulunamadı.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Sağ Kolon: Sekmeler (Cevaplar, Zaman Çizelgesi, vb.) -->
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header bg-white no-print">
                        <ul class="nav nav-tabs card-header-tabs" id="myTab" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="answers-tab" data-bs-toggle="tab" data-bs-target="#answers" type="button" role="tab">
                                    <i class="bi bi-question-circle me-1"></i> Cevaplar
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="scoring-tab" data-bs-toggle="tab" data-bs-target="#scoring" type="button" role="tab">
                                    <i class="bi bi-bar-chart-line me-1"></i> Puanlama
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="timeline-tab" data-bs-toggle="tab" data-bs-target="#timeline" type="button" role="tab">
                                    <i class="bi bi-clock-history me-1"></i> Zaman Çizelgesi
                                </button>
                            </li>
                        </ul>
                    </div>
                    <div class="card-body">
                        <div class="tab-content" id="myTabContent">
                            <!-- Cevaplar Sekmesi -->
                            <div class="tab-pane fade show active" id="answers" role="tabpanel" aria-labelledby="answers-tab">
                                <?php if (empty($answers)): ?>
                                    <div class="alert alert-info">
                                        <i class="bi bi-info-circle me-2"></i> Bu başvuru için yanıtlanmış soru bulunmamaktadır.
                                    </div>
                                <?php else: ?>
                                    <h4 class="mb-4">Soru Yanıtları</h4>
                                    <?php foreach ($answers as $index => $answer): ?>
                                        <?php 
                                        $cardClass = 'answer-card';
                                        if ($answer['question_type'] == 'multiple_choice' && isset($option_details[$answer['id']])) {
                                            $cardClass .= $option_details[$answer['id']]['is_correct'] ? ' correct' : ' incorrect';
                                        }
                                        ?>
                                        <div class="<?= $cardClass ?>">
                                            <div class="card-header d-flex justify-content-between align-items-center">
                                                <h5 class="mb-0">
                                                    <?= ($index + 1) . '. ' . htmlspecialchars($answer['question_text']) ?>
                                                </h5>
                                                <span class="badge <?= ($answer['question_type'] == 'multiple_choice') ? 'bg-primary' : 'bg-success' ?>">
                                                    <?= ($answer['question_type'] == 'multiple_choice') ? 'Çoktan Seçmeli' : 'Açık Uçlu' ?>
                                                </span>
                                            </div>
                                            <div class="card-body">
                                                <?php if ($answer['question_type'] == 'multiple_choice'): ?>
                                                    <?php if (isset($option_details[$answer['id']])): ?>
                                                        <div class="d-flex justify-content-between align-items-center">
                                                            <p class="mb-0">
                                                                <strong>Seçilen Cevap:</strong> <?= htmlspecialchars($option_details[$answer['id']]['option_text']) ?>
                                                            </p>
                                                            <?php if ($option_details[$answer['id']]['is_correct']): ?>
                                                                <span class="badge bg-success">
                                                                    <i class="bi bi-check-lg me-1"></i> Doğru Cevap
                                                                </span>
                                                            <?php else: ?>
                                                                <span class="badge bg-danger">
                                                                    <i class="bi bi-x-lg me-1"></i> Yanlış Cevap
                                                                </span>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <?php if (!empty($answer['answer_text'])): ?>
                                                        <div class="mb-3">
                                                            <h6>Metin Cevap:</h6>
                                                            <p class="mb-0 bg-light p-3 rounded"><?= nl2br(htmlspecialchars($answer['answer_text'])) ?></p>
                                                        </div>
                                                    <?php endif; ?>
                                                    
                                                    <?php if (!empty($answer['answer_file_path'])): ?>
                                                        <hr>
                                                        <h6>PDF Cevap:</h6>
                                                        <div class="d-flex mb-3 no-print">
                                                            <button type="button" class="btn btn-sm btn-outline-primary me-2" 
                                                                    data-bs-toggle="collapse" data-bs-target="#pdfViewer_<?= $answer['id'] ?>">
                                                                <i class="bi bi-eye"></i> PDF'i Görüntüle
                                                            </button>
                                                            <a href="../<?= htmlspecialchars($answer['answer_file_path']) ?>" 
                                                               class="btn btn-sm btn-outline-secondary" download>
                                                                <i class="bi bi-download"></i> İndir
                                                            </a>
                                                        </div>
                                                        
                                                        <div class="collapse mt-3" id="pdfViewer_<?= $answer['id'] ?>">
                                                            <div class="pdf-container">
                                                                <iframe src="../<?= htmlspecialchars($answer['answer_file_path']) ?>" 
                                                                        width="100%" height="600" style="border: none;"></iframe>
                                                            </div>
                                                        </div>
                                                        
                                                        <!-- Yazdırma görünümü için küçük önizleme -->
                                                        <div class="d-none d-print-block">
                                                            <p class="mb-0"><strong>PDF Yanıtı:</strong> <?= htmlspecialchars(basename($answer['answer_file_path'])) ?></p>
                                                            <p class="text-muted small">[PDF dosyası elektronik ortamda görüntülenebilir]</p>
                                                        </div>
                                                    <?php endif; ?>
                                                    
                                                    <!-- Açık uçlu soru puanlama bölümü -->
                                                    <div class="mt-4 pt-3 border-top no-print">
                                                        <form method="post" class="d-flex align-items-center">
                                                            <input type="hidden" name="action" value="update_answer_score">
                                                            <input type="hidden" name="answer_id" value="<?= $answer['id'] ?>">
                                                            <div class="me-3 flex-grow-1">
                                                                <label for="answer_score_<?= $answer['id'] ?>" class="form-label">
                                                                    Cevap Puanı (0-100)
                                                                </label>
                                                                <input type="range" class="form-range" 
                                                                       id="answer_score_<?= $answer['id'] ?>" 
                                                                       name="answer_score" 
                                                                       min="0" max="100" step="5"
                                                                       value="<?= $answer['answer_score'] ?? 0 ?>">
                                                                <div class="d-flex justify-content-between">
                                                                    <span class="text-muted">0</span>
                                                                    <span id="answer_score_value_<?= $answer['id'] ?>" class="badge bg-success">
                                                                        <?= $answer['answer_score'] ?? 0 ?>
                                                                    </span>
                                                                    <span class="text-muted">100</span>
                                                                </div>
                                                            </div>
                                                            <div>
                                                                <button type="submit" class="btn btn-primary">
                                                                    <i class="bi bi-save"></i> Kaydet
                                                                </button>
                                                            </div>
                                                        </form>
                                                    </div>
                                                    
                                                    <script>
                                                    // Her bir açık uçlu soru için puan slider'ını ayarla
                                                    document.getElementById('answer_score_<?= $answer['id'] ?>').addEventListener('input', function() {
                                                        document.getElementById('answer_score_value_<?= $answer['id'] ?>').textContent = this.value;
                                                    });
                                                    </script>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Puanlama Sekmesi -->
                            <div class="tab-pane fade" id="scoring" role="tabpanel" aria-labelledby="scoring-tab">
                                <div class="d-flex justify-content-between align-items-center mb-4">
                                    <h4>Puanlama Özeti</h4>
                                    <form method="post" class="no-print">
                                        <input type="hidden" name="action" value="calculate_total_score">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-calculator me-1"></i> Toplam Skoru Hesapla
                                        </button>
                                    </form>
                                </div>
                                
                                <div class="card mb-4 score-card">
                                    <div class="card-body">
                                        <div class="row align-items-center">
                                            <div class="col-md-5">
                                                <h5>Test Puanı</h5>
                                                <p class="text-muted">Otomatik hesaplanmış test puanı</p>
                                            </div>
                                            <div class="col-md-5">
                                                <div class="progress score-range mb-2">
                                                    <div class="progress-bar bg-primary" role="progressbar" 
                                                         style="width: <?= ($application['score'] ?? 0) ?>%;" 
                                                         aria-valuenow="<?= $application['score'] ?? 0 ?>" 
                                                         aria-valuemin="0" aria-valuemax="100"></div>
                                                </div>
                                                <div class="d-flex justify-content-between small">
                                                    <span class="text-muted">0</span>
                                                    <span class="text-muted">100</span>
                                                </div>
                                            </div>
                                            <div class="col-md-2 text-end">
                                                <span class="score-value"><?= $application['score'] ?? 0 ?></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="card mb-4 score-card">
                                    <div class="card-body">
                                        <div class="row align-items-center">
                                            <div class="col-md-5">
                                                <h5>CV Puanı</h5>
                                                <p class="text-muted">Değerlendiricinin verdiği CV puanı</p>
                                            </div>
                                            <div class="col-md-5">
                                                <div class="progress score-range mb-2">
                                                    <div class="progress-bar bg-info" role="progressbar" 
                                                         style="width: <?= ($application['cv_score'] ?? 0) ?>%;" 
                                                         aria-valuenow="<?= $application['cv_score'] ?? 0 ?>" 
                                                         aria-valuemin="0" aria-valuemax="100"></div>
                                                </div>
                                                <div class="d-flex justify-content-between small">
                                                    <span class="text-muted">0</span>
                                                    <span class="text-muted">100</span>
                                                </div>
                                            </div>
                                            <div class="col-md-2 text-end">
                                                <span class="score-value"><?= $application['cv_score'] ?? 0 ?></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <?php
                                // Açık uçlu soruların puanlarını hesapla
                                $open_ended_total = 0;
                                $open_ended_count = 0;
                                $open_ended_max = 0;
                                
                                foreach ($answers as $answer) {
                                    if ($answer['question_type'] == 'open_ended') {
                                        $open_ended_total += ($answer['answer_score'] ?? 0);
                                        $open_ended_count++;
                                        $open_ended_max = 100; // Her açık uçlu soru max 100 puan olabilir
                                    }
                                }
                                
                                // Ortalama açık uçlu soru puanı
                                $open_ended_avg = $open_ended_count > 0 ? round($open_ended_total / $open_ended_count) : 0;
                                ?>
                                
                                <div class="card mb-4 score-card">
                                    <div class="card-body">
                                        <div class="row align-items-center">
                                            <div class="col-md-5">
                                                <h5>Açık Uçlu Soru Puanları</h5>
                                                <p class="text-muted"><?= $open_ended_count ?> açık uçlu soru değerlendirildi</p>
                                            </div>
                                            <div class="col-md-5">
                                                <div class="progress score-range mb-2">
                                                    <div class="progress-bar bg-success" role="progressbar" 
                                                         style="width: <?= $open_ended_avg ?>%;" 
                                                         aria-valuenow="<?= $open_ended_avg ?>" 
                                                         aria-valuemin="0" aria-valuemax="100"></div>
                                                </div>
                                                <div class="d-flex justify-content-between small">
                                                    <span class="text-muted">0</span>
                                                    <span class="text-muted">100</span>
                                                </div>
                                            </div>
                                            <div class="col-md-2 text-end">
                                                <span class="score-value"><?= $open_ended_total ?></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <?php
                                // Toplam skor hesaplaması (basitleştirilmiş örnek)
                                $total_score = ($application['score'] ?? 0) + 
                                             ($application['cv_score'] ?? 0) + 
                                             $open_ended_total;
                                
                                // Toplam puanın maksimumu (test + cv + açık uçlu sorular)
                                $total_max = 100 + 100 + ($open_ended_count * 100);
                                $total_percentage = $total_max > 0 ? round(($total_score / $total_max) * 100) : 0;
                                ?>
                                
                                <div class="card score-card border-primary">
                                    <div class="card-header bg-primary text-white">
                                        <h5 class="mb-0">Toplam Puan</h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="row align-items-center">
                                            <div class="col-md-5">
                                                <h4>Genel Değerlendirme</h4>
                                                <p class="text-muted">Test + CV + Açık Uçlu Soruların Toplamı</p>
                                            </div>
                                            <div class="col-md-5">
                                                <div class="progress score-range mb-2" style="height: 15px;">
                                                    <div class="progress-bar bg-primary" role="progressbar" 
                                                         style="width: <?= $total_percentage ?>%;" 
                                                         aria-valuenow="<?= $total_score ?>" 
                                                         aria-valuemin="0" aria-valuemax="<?= $total_max ?>"></div>
                                                </div>
                                                <div class="d-flex justify-content-between">
                                                    <span class="text-muted">0</span>
                                                    <span class="text-muted">Maks: <?= $total_max ?></span>
                                                </div>
                                            </div>
                                            <div class="col-md-2 text-end">
                                                <span class="score-value fs-3"><?= $total_score ?></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Zaman Çizelgesi Sekmesi -->
                            <div class="tab-pane fade" id="timeline" role="tabpanel" aria-labelledby="timeline-tab">
                                <h4 class="mb-4">Başvuru Süreci</h4>
                                
                                <div class="timeline">
                                    <div class="timeline-item">
                                        <div class="timeline-dot"></div>
                                        <div class="card">
                                            <div class="card-body">
                                                <h5 class="card-title">Başvuru Alındı</h5>
                                                <p class="card-text mb-1">
                                                    <?= date('d.m.Y H:i', strtotime($application_date)) ?>
                                                </p>
                                                <span class="badge bg-primary">Başvuru Oluşturuldu</span>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <?php if ($application['status'] != 'new'): ?>
                                    <div class="timeline-item">
                                        <div class="timeline-dot"></div>
                                        <div class="card">
                                            <div class="card-body">
                                                <h5 class="card-title">Sorular Yanıtlandı</h5>
                                                <p class="card-text mb-1">
                                                    <?= date('d.m.Y H:i', strtotime($updated_date)) ?>
                                                </p>
                                                <span class="badge bg-info">Başvuru Tamamlandı</span>
                                                <?php if (isset($application['score']) && $application['score'] > 0): ?>
                                                    <p class="mt-2 mb-0">
                                                        <strong>Test Skoru:</strong> <?= $application['score'] ?> puan
                                                    </p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if (in_array($application['status'], ['reviewing', 'interview', 'accepted', 'rejected'])): ?>
                                    <div class="timeline-item">
                                        <div class="timeline-dot"></div>
                                        <div class="card">
                                            <div class="card-body">
                                                <h5 class="card-title">Değerlendirme</h5>
                                                <p class="card-text mb-1">
                                                    <?= date('d.m.Y') ?>
                                                </p>
                                                <span class="badge bg-warning">İnceleme Aşaması</span>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if (in_array($application['status'], ['interview', 'accepted', 'rejected'])): ?>
                                    <div class="timeline-item">
                                        <div class="timeline-dot"></div>
                                        <div class="card">
                                            <div class="card-body">
                                                <h5 class="card-title">Mülakat</h5>
                                                <p class="card-text mb-1">
                                                    <?= date('d.m.Y', strtotime('+5 days')) ?> (Planlanan)
                                                </p>
                                                <span class="badge bg-info">Görüşme</span>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if (in_array($application['status'], ['accepted', 'rejected'])): ?>
                                    <div class="timeline-item">
                                        <div class="timeline-dot"></div>
                                        <div class="card">
                                            <div class="card-body">
                                                <h5 class="card-title">Karar</h5>
                                                <p class="card-text mb-1">
                                                    <?= date('d.m.Y', strtotime('+7 days')) ?>
                                                </p>
                                                <span class="badge <?= ($application['status'] == 'accepted') ? 'bg-success' : 'bg-danger' ?>">
                                                    <?= ($application['status'] == 'accepted') ? 'Kabul Edildi' : 'Reddedildi' ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Not Ekleme/Düzenleme Modal -->
    <div class="modal fade" id="noteModal" tabindex="-1" aria-labelledby="noteModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="noteModalLabel">Başvuru Notu</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post">
                    <input type="hidden" name="action" value="update_note">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="note" class="form-label">Not</label>
                            <textarea class="form-control note-area" id="note" name="note" rows="5"><?= htmlspecialchars($application['admin_note'] ?? '') ?></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-primary">Kaydet</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Durum Güncelleme Modal -->
    <div class="modal fade" id="status-modal" tabindex="-1" aria-labelledby="statusModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="statusModalLabel">Başvuru Durumunu Güncelle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post">
                    <input type="hidden" name="action" value="update_status">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="status" class="form-label">Başvuru Durumu</label>
                            <select class="form-select" id="status" name="status">
                                <option value="new" <?= ($application['status'] == 'new') ? 'selected' : '' ?>>Yeni Başvuru</option>
                                <option value="reviewing" <?= ($application['status'] == 'reviewing') ? 'selected' : '' ?>>İnceleniyor</option>
                                <option value="interview" <?= ($application['status'] == 'interview') ? 'selected' : '' ?>>Mülakata Çağrıldı</option>
                                <option value="accepted" <?= ($application['status'] == 'accepted') ? 'selected' : '' ?>>Kabul Edildi</option>
                                <option value="rejected" <?= ($application['status'] == 'rejected') ? 'selected' : '' ?>>Reddedildi</option>
                                <option value="completed" <?= ($application['status'] == 'completed') ? 'selected' : '' ?>>Tamamlandı</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-primary">Kaydet</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Test Skoru Güncelleme Modal -->
    <div class="modal fade" id="score-modal" tabindex="-1" aria-labelledby="scoreModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="scoreModalLabel">Test Skorunu Güncelle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post">
                    <input type="hidden" name="action" value="update_score">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="score" class="form-label">Test Skoru</label>
                            <input type="number" class="form-control" id="score" name="score" min="0" max="100" value="<?= $application['score'] ?? 0 ?>">
                            <div class="form-text">Çoktan seçmeli test puanı (0-100)</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-primary">Kaydet</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // PDF görüntüleyici için PDF.js yapılandırması
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.11.338/pdf.worker.min.js';
        
        // CV puan slider'ı için anlık değeri gösterme
        document.getElementById('cv_score').addEventListener('input', function() {
            document.getElementById('cv_score_value').textContent = this.value;
        });
        
        // Sayfa yazdırma fonksiyonu
        function printPage() {
            window.print();
        }
        
        // Başvuru silme onay fonksiyonu
        function confirmDelete(id) {
            if (confirm('Bu başvuruyu silmek istediğinizden emin misiniz? Bu işlem geri alınamaz!')) {
                window.location.href = 'delete-application.php?id=' + id;
            }
        }
        
        // Toplam skoru hesaplama
        function calculateTotalScore() {
            document.querySelector('form[action="calculate_total_score"]').submit();
        }
        
        // Tooltips'i etkinleştir
        document.addEventListener('DOMContentLoaded', function() {
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            var tooltipList = tooltipTriggerList.map(function(tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
            
            // İlk başta aktif sekmeyi göster
            var tabEl = document.querySelector('#myTab button[data-bs-target="#answers"]');
            var tab = new bootstrap.Tab(tabEl);
            tab.show();
        });
    </script>
</body>
</html>

<?php
// Output buffer içeriğini gönder ve buffer'ı temizle
ob_end_flush();
?>