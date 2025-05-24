<?php
// Output buffering başlat
ob_start();

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit;
}

require_once '../config/db.php';

// Düzenlenecek şablon ID'sini alma
$template_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($template_id <= 0) {
    header('Location: manage-templates.php');
    exit;
}

// Success ve error mesajlarını saklayacak değişkenler
$success = '';
$error = '';

// Şablonu veritabanından al
try {
    $stmt = $db->prepare("SELECT * FROM question_templates WHERE id = :id");
    $stmt->bindParam(':id', $template_id);
    $stmt->execute();
    $template = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$template) {
        header('Location: manage-templates.php?error=' . urlencode('Şablon bulunamadı'));
        exit;
    }
} catch (PDOException $e) {
    $error = "Şablon yüklenirken bir hata oluştu: " . $e->getMessage();
    header('Location: manage-templates.php?error=' . urlencode($error));
    exit;
}

// Şablona ait soruları al - sort_order/order_number sütunu kullanarak sırala
try {
    // Önce sort_order sütununu kontrol et, varsa kullan
    $checkColumnStmt = $db->prepare("SHOW COLUMNS FROM template_questions LIKE 'sort_order'");
    $checkColumnStmt->execute();
    
    if ($checkColumnStmt->rowCount() > 0) {
        $stmt = $db->prepare("SELECT * FROM template_questions WHERE template_id = :template_id ORDER BY sort_order ASC");
    } else {
        // sort_order yoksa order_number kullan
        $stmt = $db->prepare("SELECT * FROM template_questions WHERE template_id = :template_id ORDER BY order_number ASC");
    }
    
    $stmt->bindParam(':template_id', $template_id);
    $stmt->execute();
    $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Şablon soruları yüklenirken bir hata oluştu: " . $e->getMessage();
    $questions = [];
}

// Her soru için seçenekleri al
$question_options = [];
foreach ($questions as $question) {
    if ($question['question_type'] == 'multiple_choice') {
        try {
            $stmt = $db->prepare("SELECT * FROM template_options WHERE template_question_id = :question_id");
            $stmt->bindParam(':question_id', $question['id']);
            $stmt->execute();
            $question_options[$question['id']] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $error = "Soru seçenekleri yüklenirken bir hata oluştu: " . $e->getMessage();
            $question_options[$question['id']] = [];
        }
    }
}

// Şablon güncelleme işlemi
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    
    if (empty($title)) {
        $error = "Lütfen şablon başlığını giriniz.";
    } else {
        // Şablonu güncelle
        try {
            $db->beginTransaction();
            
            // Ana şablon bilgilerini güncelle
            $stmt = $db->prepare("UPDATE question_templates SET template_name = :title, description = :description WHERE id = :id");
            $stmt->bindParam(':title', $title);
            $stmt->bindParam(':description', $description);
            $stmt->bindParam(':id', $template_id);
            $stmt->execute();
            
            // Önce eski soruları ve seçenekleri temizle
            $stmt = $db->prepare("DELETE FROM template_options WHERE template_question_id IN (SELECT id FROM template_questions WHERE template_id = :template_id)");
            $stmt->bindParam(':template_id', $template_id);
            $stmt->execute();
            
            $stmt = $db->prepare("DELETE FROM template_questions WHERE template_id = :template_id");
            $stmt->bindParam(':template_id', $template_id);
            $stmt->execute();
            
            // Yeni soruları ekle
            if (isset($_POST['questions']) && is_array($_POST['questions'])) {
                $order = 1;
                foreach ($_POST['questions'] as $question_key => $question) {
                    $question_text = trim($question['text']);
                    $question_type = $question['type'];
                    $is_required = isset($question['required']) ? 1 : 0;
                    
                    if (!empty($question_text)) {
                        // Hangi sütunları kullanacağımızı belirle
                        $checkColumnStmt = $db->prepare("SHOW COLUMNS FROM template_questions LIKE 'sort_order'");
                        $checkColumnStmt->execute();
                        
                        if ($checkColumnStmt->rowCount() > 0) {
                            // sort_order sütunu var
                            $stmt = $db->prepare("INSERT INTO template_questions (template_id, question_text, question_type, sort_order) VALUES (:template_id, :question_text, :question_type, :order)");
                        } else {
                            // order_number sütunu kullan
                            $stmt = $db->prepare("INSERT INTO template_questions (template_id, question_text, question_type, order_number) VALUES (:template_id, :question_text, :question_type, :order)");
                        }
                        
                        $stmt->bindParam(':template_id', $template_id);
                        $stmt->bindParam(':question_text', $question_text);
                        $stmt->bindParam(':question_type', $question_type);
                        $stmt->bindParam(':order', $order);
                        $stmt->execute();
                        
                        $question_id = $db->lastInsertId();
                        
                        // Çoktan seçmeli sorular için seçenekleri ekle
                        if ($question_type === 'multiple_choice' && isset($question['options']) && is_array($question['options'])) {
                            foreach ($question['options'] as $option_key => $option) {
                                $option_text = trim($option['text']);
                                $is_correct = isset($option['correct']) ? 1 : 0;
                                
                                if (!empty($option_text)) {
                                    $stmt = $db->prepare("INSERT INTO template_options (template_question_id, option_text, is_correct) VALUES (:question_id, :option_text, :is_correct)");
                                    $stmt->bindParam(':question_id', $question_id);
                                    $stmt->bindParam(':option_text', $option_text);
                                    $stmt->bindParam(':is_correct', $is_correct);
                                    $stmt->execute();
                                }
                            }
                        }
                        
                        $order++;
                    }
                }
            }
            
            $db->commit();
            $success = "Şablon başarıyla güncellendi!";
            
            // Başarılı güncelleme sonrası yeniden yönlendirme
            header("Location: manage-templates.php?success=" . urlencode($success));
            exit;
        } catch (PDOException $e) {
            $db->rollBack();
            $error = "Şablon güncellenirken bir hata oluştu: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Şablon Düzenle | İş Başvuru Sistemi</title>
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
            transition: box-shadow 0.3s;
        }
        
        .card:hover {
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
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
        
        .btn {
            border-radius: 5px;
            padding: 0.5rem 1.25rem;
            font-weight: 500;
            transition: all 0.2s;
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
        
        .required-label::after {
            content: " *";
            color: var(--danger);
        }
        
        .form-text {
            color: var(--secondary);
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
        
        /* Soru kartları */
        .question-card {
            border-radius: 10px;
            margin-bottom: 1rem;
            background-color: #fff;
            border: 1px solid var(--card-border);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .question-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
        }
        
        .question-card.multiple-choice-card {
            border-left: 4px solid var(--info);
        }
        
        .question-card.open-ended-card {
            border-left: 4px solid var(--success);
        }
        
        .question-card.file-upload-card {
            border-left: 4px solid var(--warning);
        }
        
        .drag-handle {
            cursor: move;
            color: #9ca3af;
            margin-right: 10px;
        }
        
        .question-type-badge {
            display: inline-block;
            padding: 0.35em 0.65em;
            font-size: 0.75em;
            font-weight: 600;
            border-radius: 50px;
        }
        
        .badge-multiple-choice {
            background-color: rgba(52, 152, 219, 0.15);
            color: var(--info);
        }
        
        .badge-open-ended {
            background-color: rgba(46, 204, 113, 0.15);
            color: var(--success);
        }
        
        .badge-file-upload {
            background-color: rgba(243, 156, 18, 0.15);
            color: var(--warning);
        }
        
        .required-badge {
            background-color: rgba(231, 76, 60, 0.15);
            color: var(--danger);
        }
        
        /* Seçenekler */
        .option-item {
            background-color: #f9fafb;
            border-radius: 8px;
            padding: 0.75rem;
            margin-bottom: 0.75rem;
            position: relative;
            border: 1px solid #eaedf1;
        }
        
        .option-item.correct {
            background-color: rgba(46, 204, 113, 0.1);
            border: 1px solid rgba(46, 204, 113, 0.3);
            border-left: 3px solid var(--success);
        }
        
        .option-item:hover {
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }
        
        /* Önizleme */
        .preview-section {
            position: sticky;
            top: 20px;
        }
        
        .preview-form-control {
            background-color: #f9fafb;
            border: 1px dashed #cbd5e0;
            cursor: not-allowed;
        }
        
        .preview-title {
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 0.5rem;
        }
        
        .preview-question {
            margin-bottom: 1rem;
            padding: 0.75rem;
            border-radius: 8px;
            background-color: #f9fafb;
            border-left: 3px solid #cbd5e0;
        }
        
        .preview-question-text {
            font-weight: 500;
            margin-bottom: 0.5rem;
        }
        
        /* Boş durum */
        .empty-state {
            padding: 3rem 1.5rem;
            text-align: center;
            background-color: #f9fafb;
            border: 2px dashed #e2e8f0;
            border-radius: 10px;
        }
        
        .empty-state-icon {
            font-size: 2.5rem;
            color: #cbd5e0;
            margin-bottom: 1rem;
        }
        
        /* Animasyonlar */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .fade-in {
            animation: fadeIn 0.3s ease-out;
        }
        
        /* Sürükle bırak stilleri */
        .sortable-ghost {
            opacity: 0.5;
            background-color: #f0f4f9;
        }
    </style>
</head>
<body>    <nav class="navbar navbar-expand-lg bg-white shadow-sm sticky-top">
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
                    </li>                </ul>
                
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
                    <h1 class="page-title">Şablonu Düzenle</h1>
                    <p class="page-subtitle">İş başvurularında kullanılacak soru şablonunu güncelleyin</p>
                </div>
                <div class="col-md-6 text-md-end">
                    <a href="manage-templates.php" class="btn btn-outline-secondary me-2">
                        <i class="bi bi-arrow-left me-1"></i> Şablonlara Dön
                    </a>
                    <button type="button" class="btn btn-primary" id="saveTemplateBtn">
                        <i class="bi bi-save me-1"></i> Değişiklikleri Kaydet
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="container mb-5">
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-modern alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= $error ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <form method="post" action="" id="templateForm">
            <div class="row">
                <!-- Sol Kolon - Şablon Düzenleme Alanı -->
                <div class="col-lg-8">
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="card-title"><i class="bi bi-info-circle me-2"></i>Şablon Bilgileri</h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label for="title" class="form-label required-label">Şablon Adı</label>
                                <input type="text" class="form-control" id="title" name="title" required 
                                       value="<?= htmlspecialchars($template['template_name']) ?>">
                                <div class="form-text">Şablonunuzu tanımlayan bir isim girin.</div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="description" class="form-label">Açıklama</label>
                                <textarea class="form-control" id="description" name="description" rows="3"><?= htmlspecialchars($template['description'] ?? '') ?></textarea>
                                <div class="form-text">Şablonun kullanım amacını açıklayın (isteğe bağlı).</div>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="card-title"><i class="bi bi-list-check me-2"></i>Şablon Soruları</h5>
                            <div class="dropdown">
                                <button class="btn btn-sm btn-primary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                    <i class="bi bi-plus-lg me-1"></i> Soru Ekle
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li>
                                        <a class="dropdown-item" href="#" onclick="addQuestion('multiple_choice')">
                                            <i class="bi bi-list-ul me-2 text-info"></i> Çoktan Seçmeli Soru
                                        </a>
                                    </li>
                                    <li>
                                        <a class="dropdown-item" href="#" onclick="addQuestion('open_ended')">
                                            <i class="bi bi-textarea-t me-2 text-success"></i> Açık Uçlu Soru
                                        </a>
                                    </li>
                                    <li>
                                        <a class="dropdown-item" href="#" onclick="addQuestion('file_upload')">
                                            <i class="bi bi-file-earmark-arrow-up me-2 text-warning"></i> Dosya Yükleme Sorusu
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        </div>
                        <div class="card-body">
                            <div id="questionsContainer">
                                <?php if (empty($questions)): ?>
                                    <!-- Boş durum göstergesi -->
                                    <div class="empty-state" id="emptyQuestionsState">
                                        <div class="empty-state-icon">
                                            <i class="bi bi-question-circle"></i>
                                        </div>
                                        <h4>Henüz soru eklenmedi</h4>
                                        <p class="text-muted">Şablonunuza soru eklemek için "Soru Ekle" butonunu kullanın.</p>
                                    </div>
                                <?php else: ?>
                                    <!-- Mevcut sorular -->
                                    <?php foreach ($questions as $index => $question): ?>
                                        <?php
                                            $questionId = 'question_' . $index;
                                            $typeText = $question['question_type'] === 'multiple_choice' ? 'Çoktan Seçmeli' : 
                                                       ($question['question_type'] === 'open_ended' ? 'Açık Uçlu' : 'Dosya Yükleme');
                                            $typeClass = $question['question_type'] === 'multiple_choice' ? 'multiple-choice-card' : 
                                                        ($question['question_type'] === 'open_ended' ? 'open-ended-card' : 'file-upload-card');
                                            $typeBadgeClass = $question['question_type'] === 'multiple_choice' ? 'badge-multiple-choice' : 
                                                            ($question['question_type'] === 'open_ended' ? 'badge-open-ended' : 'badge-file-upload');
                                                            
                                            // is_required sütunu olup olmadığını kontrol et
                                            $checkColumnStmt = $db->prepare("SHOW COLUMNS FROM template_questions LIKE 'is_required'");
                                            $checkColumnStmt->execute();
                                            $isRequiredExists = ($checkColumnStmt->rowCount() > 0);
                                            
                                            // is_required değeri
                                            $is_required = $isRequiredExists ? $question['is_required'] : true;
                                        ?>
                                        <div class="card question-card <?= $typeClass ?>" id="<?= $questionId ?>">
                                            <div class="card-header d-flex justify-content-between align-items-center">
                                                <div>
                                                    <i class="bi bi-grip-vertical drag-handle"></i>
                                                    <span class="question-type-badge <?= $typeBadgeClass ?>"><?= $typeText ?></span>
                                                    <span class="badge required-badge ms-2" id="<?= $questionId ?>_required_badge" 
                                                          style="<?= $isRequiredExists && !$is_required ? 'display: none;' : '' ?>">Zorunlu</span>
                                                </div>
                                                <div>
                                                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeQuestion('<?= $questionId ?>')">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </div>
                                            </div>
                                            <div class="card-body">
                                                <div class="mb-3">
                                                    <label for="<?= $questionId ?>_text" class="form-label">Soru Metni</label>
                                                    <input type="text" class="form-control" id="<?= $questionId ?>_text" 
                                                           name="questions[<?= $index ?>][text]" 
                                                           placeholder="Sorunuzu buraya yazın..." 
                                                           value="<?= htmlspecialchars($question['question_text']) ?>" 
                                                           onchange="updatePreview()">
                                                </div>
                                                <?php if ($isRequiredExists): ?>
                                                <div class="mb-3">
                                                    <div class="form-check form-switch">
                                                        <input class="form-check-input" type="checkbox" id="<?= $questionId ?>_required" 
                                                               name="questions[<?= $index ?>][required]" 
                                                               <?= $is_required ? 'checked' : '' ?> 
                                                               onchange="toggleRequired('<?= $questionId ?>')">
                                                        <label class="form-check-label" for="<?= $questionId ?>_required">Bu soru zorunlu</label>
                                                    </div>
                                                </div>
                                                <?php endif; ?>
                                                <input type="hidden" name="questions[<?= $index ?>][type]" value="<?= $question['question_type'] ?>">
                                                
                                                <?php if ($question['question_type'] === 'multiple_choice'): ?>
                                                    <div class="mb-3">
                                                        <label class="form-label">Cevap Seçenekleri</label>
                                                        <div id="<?= $questionId ?>_options" class="mb-3">
                                                            <!-- Mevcut seçenekler -->
                                                            <?php if (isset($question_options[$question['id']])): ?>
                                                                <?php foreach ($question_options[$question['id']] as $option_index => $option): ?>
                                                                    <div class="option-item <?= $option['is_correct'] ? 'correct' : '' ?>" 
                                                                         id="<?= $questionId ?>_option_<?= $option_index ?>">
                                                                        <div class="row">
                                                                            <div class="col">
                                                                                <div class="input-group">
                                                                                    <input type="text" class="form-control" 
                                                                                           name="questions[<?= $index ?>][options][<?= $option_index ?>][text]" 
                                                                                           placeholder="Seçenek <?= $option_index + 1 ?>" 
                                                                                           value="<?= htmlspecialchars($option['option_text']) ?>" 
                                                                                           onchange="updatePreview()">
                                                                                    <div class="input-group-text">
                                                                                        <div class="form-check">
                                                                                            <input class="form-check-input" type="radio" 
                                                                                                   name="questions[<?= $index ?>][options_correct]" 
                                                                                                   value="<?= $option_index ?>" 
                                                                                                   id="<?= $questionId ?>_option_<?= $option_index ?>_correct"
                                                                                                   <?= $option['is_correct'] ? 'checked' : '' ?>
                                                                                                   onclick="setCorrectOption('<?= $questionId ?>', <?= $option_index ?>)">
                                                                                            <label class="form-check-label" 
                                                                                                   for="<?= $questionId ?>_option_<?= $option_index ?>_correct">
                                                                                                Doğru
                                                                                            </label>
                                                                                            <input type="hidden" 
                                                                                                   name="questions[<?= $index ?>][options][<?= $option_index ?>][correct]" 
                                                                                                   value="<?= $option['is_correct'] ? '1' : '0' ?>" 
                                                                                                   id="<?= $questionId ?>_option_<?= $option_index ?>_correct_input">
                                                                                        </div>
                                                                                    </div>
                                                                                </div>
                                                                            </div>
                                                                            <div class="col-auto">
                                                                                <button type="button" class="btn btn-sm btn-outline-danger remove-option" 
                                                                                        onclick="removeOption('<?= $questionId ?>_option_<?= $option_index ?>')">
                                                                                    <i class="bi bi-x-lg"></i>
                                                                                </button>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                <?php endforeach; ?>
                                                            <?php endif; ?>
                                                        </div>
                                                        <button type="button" class="btn btn-sm btn-outline-primary" 
                                                                onclick="addOption('<?= $questionId ?>', <?= $index ?>)">
                                                            <i class="bi bi-plus-lg me-1"></i> Seçenek Ekle
                                                        </button>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-footer text-center">
                            <button type="button" class="btn btn-outline-primary" data-bs-toggle="dropdown">
                                <i class="bi bi-plus-lg me-1"></i> Soru Ekle
                            </button>
                            <ul class="dropdown-menu">
                                <li>
                                    <a class="dropdown-item" href="#" onclick="addQuestion('multiple_choice')">
                                        <i class="bi bi-list-ul me-2 text-info"></i> Çoktan Seçmeli Soru
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item" href="#" onclick="addQuestion('open_ended')">
                                        <i class="bi bi-textarea-t me-2 text-success"></i> Açık Uçlu Soru
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item" href="#" onclick="addQuestion('file_upload')">
                                        <i class="bi bi-file-earmark-arrow-up me-2 text-warning"></i> Dosya Yükleme Sorusu
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Sağ Kolon - Önizleme -->
                <div class="col-lg-4">
                    <div class="preview-section">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title"><i class="bi bi-eye me-2"></i>Şablon Önizleme</h5>
                            </div>
                            <div class="card-body">
                                <div id="templatePreview">
                                    <!-- Önizleme JavaScript ile doldurulacak -->
                                    <div class="text-center py-4">
                                        <i class="bi bi-eye-fill text-muted fs-1"></i>
                                        <h5 class="mt-3">Önizleme</h5>
                                        <p class="text-muted">Şablon bilgilerini güncelledikçe burada göreceksiniz.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="card mt-4">
                            <div class="card-header">
                                <h5 class="card-title"><i class="bi bi-info-circle me-2"></i>Yardım</h5>
                            </div>
                            <div class="card-body">
                                <ul class="list-unstyled mb-0">
                                    <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i> Soruları sürükleyerek sıralayabilirsiniz.</li>
                                    <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i> Çoktan seçmeli sorularda doğru cevabı işaretlemeyi unutmayın.</li>
                                    <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i> Şablonunuzu kaydettikten sonra iş ilanlarında kullanabilirsiniz.</li>
                                    <li><i class="bi bi-check-circle-fill text-success me-2"></i> En az bir soru eklemelisiniz.</li>
                                </ul>
                            </div>
                        </div>
                        
                        <div class="d-grid gap-2 mt-4">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save me-1"></i> Değişiklikleri Kaydet
                            </button>
                            <a href="manage-templates.php" class="btn btn-outline-secondary">
                                <i class="bi bi-x-lg me-1"></i> İptal
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.14.0/Sortable.min.js"></script>
    <script>
        let questionCounter = <?= count($questions) ?>;
        
        // Sayfa yüklendiğinde
        document.addEventListener('DOMContentLoaded', function() {
            // Formun submit edilmesi
            document.getElementById('saveTemplateBtn').addEventListener('click', function() {
                document.getElementById('templateForm').submit();
            });
            
            // Sıralama için Sortable.js
            new Sortable(document.getElementById('questionsContainer'), {
                handle: '.drag-handle',
                animation: 150,
                ghostClass: 'sortable-ghost',
                onEnd: function() {
                    // Soruların sırasını güncelle
                    updateQuestionIndices();
                    updatePreview();
                }
            });
            
            // İlk önizlemeyi oluştur
            updatePreview();
        });
        
        // Yeni soru ekle
        function addQuestion(type) {
            const questionsContainer = document.getElementById('questionsContainer');
            const emptyState = document.getElementById('emptyQuestionsState');
            
            if (emptyState) {
                emptyState.remove();
            }
            
            const questionId = 'question_' + questionCounter;
            const typeText = type === 'multiple_choice' ? 'Çoktan Seçmeli' : (type === 'open_ended' ? 'Açık Uçlu' : 'Dosya Yükleme');
            const typeClass = type === 'multiple_choice' ? 'multiple-choice-card' : (type === 'open_ended' ? 'open-ended-card' : 'file-upload-card');
            const typeBadgeClass = type === 'multiple_choice' ? 'badge-multiple-choice' : (type === 'open_ended' ? 'badge-open-ended' : 'badge-file-upload');
            
            const questionCard = document.createElement('div');
            questionCard.className = `card question-card ${typeClass} fade-in`;
            questionCard.id = questionId;
            
            questionCard.innerHTML = `
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <i class="bi bi-grip-vertical drag-handle"></i>
                        <span class="question-type-badge ${typeBadgeClass}">${typeText}</span>
                        <span class="badge required-badge ms-2" id="${questionId}_required_badge">Zorunlu</span>
                    </div>
                    <div>
                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeQuestion('${questionId}')">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="${questionId}_text" class="form-label">Soru Metni</label>
                        <input type="text" class="form-control" id="${questionId}_text" name="questions[${questionCounter}][text]" 
                               placeholder="Sorunuzu buraya yazın..." onchange="updatePreview()">
                    </div>
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="${questionId}_required" name="questions[${questionCounter}][required]" checked 
                                   onchange="toggleRequired('${questionId}')">
                            <label class="form-check-label" for="${questionId}_required">Bu soru zorunlu</label>
                        </div>
                    </div>
                    <input type="hidden" name="questions[${questionCounter}][type]" value="${type}">
                    
                    ${type === 'multiple_choice' ? `
                        <div class="mb-3">
                            <label class="form-label">Cevap Seçenekleri</label>
                            <div id="${questionId}_options" class="mb-3">
                                <!-- Seçenekler burada eklenecek -->
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="addOption('${questionId}', ${questionCounter})">
                                <i class="bi bi-plus-lg me-1"></i> Seçenek Ekle
                            </button>
                        </div>
                    ` : ''}
                </div>
            `;
            
            questionsContainer.appendChild(questionCard);
            
            // Çoktan seçmeli soru ise, varsayılan olarak 2 seçenek ekle
            if (type === 'multiple_choice') {
                addOption(questionId, questionCounter);
                addOption(questionId, questionCounter);
            }
            
            questionCounter++;
            updatePreview();
        }
        
        // Soruların indekslerini güncelle
        function updateQuestionIndices() {
            const questionsContainer = document.getElementById('questionsContainer');
            const questions = questionsContainer.querySelectorAll('.question-card');
            
            questions.forEach((question, index) => {
                const questionId = question.id;
                const questionType = question.querySelector('input[name^="questions["][name$="][type]"]').value;
                const questionText = question.querySelector(`#${questionId}_text`);
                
                // Soru metninin name özelliğini güncelle
                questionText.name = `questions[${index}][text]`;
                
                // Soru tipinin name özelliğini güncelle
                question.querySelector('input[name^="questions["][name$="][type]"]').name = `questions[${index}][type]`;
                
                // Zorunlu durumunun name özelliğini güncelle
                const requiredCheckbox = question.querySelector(`#${questionId}_required`);
                if (requiredCheckbox) {
                    requiredCheckbox.name = `questions[${index}][required]`;
                }
                
                // Seçeneklerin name özelliklerini güncelle (çoktan seçmeli sorular için)
                if (questionType === 'multiple_choice') {
                    const optionsContainer = question.querySelector(`#${questionId}_options`);
                    const options = optionsContainer.querySelectorAll('.option-item');
                    
                    options.forEach((option, optIndex) => {
                        const optionText = option.querySelector('input[type="text"]');
                        if (optionText) {
                            optionText.name = `questions[${index}][options][${optIndex}][text]`;
                        }
                        
                        const optionCorrectRadio = option.querySelector('input[type="radio"]');
                        if (optionCorrectRadio) {
                            optionCorrectRadio.name = `questions[${index}][options_correct]`;
                            optionCorrectRadio.value = optIndex;
                        }
                        
                        const optionCorrectInput = option.querySelector('input[type="hidden"]');
                        if (optionCorrectInput) {
                            optionCorrectInput.name = `questions[${index}][options][${optIndex}][correct]`;
                        }
                    });
                }
            });
        }
        
        // Seçenek ekle
        function addOption(questionId, questionIndex) {
            const optionsContainer = document.getElementById(`${questionId}_options`);
            const optionCount = optionsContainer.children.length;
            
            const optionItem = document.createElement('div');
            optionItem.className = 'option-item';
            optionItem.id = `${questionId}_option_${optionCount}`;
            
            optionItem.innerHTML = `
                <div class="row">
                    <div class="col">
                        <div class="input-group">
                            <input type="text" class="form-control" name="questions[${questionIndex}][options][${optionCount}][text]" 
                                   placeholder="Seçenek ${optionCount + 1}" onchange="updatePreview()">
                            <div class="input-group-text">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="questions[${questionIndex}][options_correct]" 
                                          value="${optionCount}" id="${questionId}_option_${optionCount}_correct"
                                          onclick="setCorrectOption('${questionId}', ${optionCount})">
                                    <label class="form-check-label" for="${questionId}_option_${optionCount}_correct">
                                        Doğru
                                    </label>
                                    <input type="hidden" name="questions[${questionIndex}][options][${optionCount}][correct]" 
                                          value="0" id="${questionId}_option_${optionCount}_correct_input">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-auto">
                        <button type="button" class="btn btn-sm btn-outline-danger" 
                                onclick="removeOption('${questionId}_option_${optionCount}')">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                </div>
            `;
            
            optionsContainer.appendChild(optionItem);
            updatePreview();
        }
        
        // Doğru seçeneği ayarla
        function setCorrectOption(questionId, optionIndex) {
            const optionsContainer = document.getElementById(`${questionId}_options`);
            const options = optionsContainer.querySelectorAll('.option-item');
            
            // Tüm seçeneklerin doğru işaretini kaldır
            options.forEach((option, index) => {
                const correctInput = document.getElementById(`${questionId}_option_${index}_correct_input`);
                const optionItem = document.getElementById(`${questionId}_option_${index}`);
                if (correctInput) {
                    correctInput.value = '0';
                    optionItem.classList.remove('correct');
                }
            });
            
            // Seçilen seçeneği doğru işaretle
            const correctInput = document.getElementById(`${questionId}_option_${optionIndex}_correct_input`);
            const optionItem = document.getElementById(`${questionId}_option_${optionIndex}`);
            if (correctInput) {
                correctInput.value = '1';
                optionItem.classList.add('correct');
            }
            
            updatePreview();
        }
        
        // Seçeneği kaldır
        function removeOption(optionId) {
            const option = document.getElementById(optionId);
            if (option) {
                option.remove();
                updatePreview();
            }
        }
        
        // Soruyu kaldır
        function removeQuestion(questionId) {
            if (confirm('Bu soruyu silmek istediğinizden emin misiniz?')) {
                const question = document.getElementById(questionId);
                if (question) {
                    question.remove();
                    updatePreview();
                    updateQuestionIndices();
                    
                    // Eğer hiç soru kalmadıysa, boş durum mesajını göster
                    const questionsContainer = document.getElementById('questionsContainer');
                    if (questionsContainer.children.length === 0) {
                        const emptyState = document.createElement('div');
                        emptyState.className = 'empty-state';
                        emptyState.id = 'emptyQuestionsState';
                        emptyState.innerHTML = `
                            <div class="empty-state-icon">
                                <i class="bi bi-question-circle"></i>
                            </div>
                            <h4>Henüz soru eklenmedi</h4>
                            <p class="text-muted">Şablonunuza soru eklemek için "Soru Ekle" butonunu kullanın.</p>
                        `;
                        questionsContainer.appendChild(emptyState);
                    }
                }
            }
        }
        
        // Zorunlu durumunu değiştir
        function toggleRequired(questionId) {
            const requiredCheckbox = document.getElementById(`${questionId}_required`);
            const requiredBadge = document.getElementById(`${questionId}_required_badge`);
            
            if (requiredBadge) {
                requiredBadge.style.display = requiredCheckbox.checked ? 'inline-block' : 'none';
            }
            
            updatePreview();
        }
        
        // Önizlemeyi güncelle
        function updatePreview() {
            const previewContainer = document.getElementById('templatePreview');
            const questionsContainer = document.getElementById('questionsContainer');
            const questions = questionsContainer.querySelectorAll('.question-card');
            const title = document.getElementById('title').value;
            const description = document.getElementById('description').value;
            
            if (questions.length === 0 || !title) {
                previewContainer.innerHTML = `
                    <div class="text-center py-4">
                        <i class="bi bi-eye-fill text-muted fs-1"></i>
                        <h5 class="mt-3">Önizleme</h5>
                        <p class="text-muted">Şablon başlığı ve sorular ekleyin.</p>
                    </div>
                `;
                return;
            }
            
            let previewHtml = `
                <div class="preview-title">${title}</div>
                ${description ? `<p class="text-muted mb-3 small">${description}</p>` : ''}
            `;
            
            questions.forEach((question, index) => {
                const questionId = question.id;
                const questionText = document.getElementById(`${questionId}_text`).value || 'Soru metni';
                const questionType = question.querySelector('input[name^="questions["][name$="][type]"]').value;
                
                const requiredInput = document.getElementById(`${questionId}_required`);
                const isRequired = requiredInput ? requiredInput.checked : true;
                
                previewHtml += `
                <div class="preview-question">
                    <div class="preview-question-text">
                        ${index + 1}. ${questionText} ${isRequired ? '<span class="text-danger">*</span>' : ''}
                    </div>
                `;
                
                if (questionType === 'multiple_choice') {
                    const optionsContainer = document.getElementById(`${questionId}_options`);
                    const options = optionsContainer.querySelectorAll('.option-item');
                    
                    options.forEach((option, optIndex) => {
                        const optionInput = option.querySelector('input[type="text"]');
                        const isCorrect = option.classList.contains('correct');
                        const optionText = optionInput ? optionInput.value : `Seçenek ${optIndex + 1}`;
                        
                        previewHtml += `
                            <div class="form-check ${isCorrect ? 'text-success' : ''}">
                                <input class="form-check-input" type="radio" disabled>
                                <label class="form-check-label">${optionText}</label>
                                ${isCorrect ? '<i class="bi bi-check-circle-fill text-success ms-1"></i>' : ''}
                            </div>
                        `;
                    });
                } else if (questionType === 'open_ended') {
                    previewHtml += `<textarea class="form-control form-control-sm preview-form-control" disabled placeholder="Açık uçlu cevap alanı..."></textarea>`;
                } else if (questionType === 'file_upload') {
                    previewHtml += `
                        <div class="input-group input-group-sm">
                            <input type="text" class="form-control preview-form-control" disabled placeholder="Dosya seçilmedi">
                            <button class="btn btn-outline-secondary" type="button" disabled>Gözat</button>
                        </div>
                        <div class="form-text">Desteklenen dosya türleri: PDF, DOC, DOCX</div>
                    `;
                }
                
                previewHtml += `</div>`;
            });
            
            previewContainer.innerHTML = previewHtml;
        }
    </script>
</body>
</html>

<?php
// Output buffer içeriğini gönder ve buffer'ı temizle
ob_end_flush();
?>