<?php
session_start();

// Oturum kontrolü
if (!isset($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit;
}

require_once '../config/db.php';

$success_message = '';
$error_message = '';

// Form gönderildiğinde iş ilanını oluştur
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Form verilerini al
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $location = trim($_POST['location']);
    $status = $_POST['status'];
    $template_id = !empty($_POST['template_id']) ? (int)$_POST['template_id'] : null;

    // Basit validasyon
    if (empty($title) || empty($description) || empty($location)) {
        $error_message = "Lütfen zorunlu alanları doldurun.";
    } else {
        try {
            $db->beginTransaction();

            // İş ilanını ekle
            $stmt = $db->prepare("INSERT INTO jobs (title, description, location, status, template_id) VALUES (:title, :description, :location, :status, :template_id)");
            $stmt->bindParam(':title', $title);
            $stmt->bindParam(':description', $description);
            $stmt->bindParam(':location', $location);
            $stmt->bindParam(':status', $status);
            $stmt->bindParam(':template_id', $template_id);
            
            if ($stmt->execute()) {
                $job_id = $db->lastInsertId();
                
                // Şablon seçildiyse şablondan soruları kopyala
                if (!empty($template_id)) {
                    // Şablona ait soruları al
                    $template_questions_stmt = $db->prepare("
                        SELECT question_text, question_type, sort_order 
                        FROM template_questions 
                        WHERE template_id = :template_id
                        ORDER BY sort_order ASC
                    ");
                    $template_questions_stmt->bindParam(':template_id', $template_id);
                    $template_questions_stmt->execute();
                    $template_questions = $template_questions_stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    foreach ($template_questions as $template_question) {
                        // İş ilanına soru ekle
                        $question_stmt = $db->prepare("
                            INSERT INTO questions (job_id, question_text, question_type, template_id) 
                            VALUES (:job_id, :question_text, :question_type, :template_id)
                        ");
                        $question_stmt->bindParam(':job_id', $job_id);
                        $question_stmt->bindParam(':question_text', $template_question['question_text']);
                        $question_stmt->bindParam(':question_type', $template_question['question_type']);
                        $question_stmt->bindParam(':template_id', $template_id);
                        $question_stmt->execute();
                        
                        $question_id = $db->lastInsertId();
                        
                        // Çoktan seçmeli soru ise şablondan seçenekleri kopyala
                        if ($template_question['question_type'] === 'multiple_choice') {
                            // Şablon sorusunun ID'sini al
                            $template_question_stmt = $db->prepare("
                                SELECT id FROM template_questions 
                                WHERE template_id = :template_id 
                                AND question_text = :question_text
                                AND question_type = 'multiple_choice'
                                LIMIT 1
                            ");
                            $template_question_stmt->bindParam(':template_id', $template_id);
                            $template_question_stmt->bindParam(':question_text', $template_question['question_text']);
                            $template_question_stmt->execute();
                            $template_question_id = $template_question_stmt->fetchColumn();
                            
                            if ($template_question_id) {
                                // Şablon sorusunun seçeneklerini al
                                $options_stmt = $db->prepare("
                                    SELECT option_text, is_correct 
                                    FROM template_options 
                                    WHERE template_question_id = :template_question_id
                                ");
                                $options_stmt->bindParam(':template_question_id', $template_question_id);
                                $options_stmt->execute();
                                $options = $options_stmt->fetchAll(PDO::FETCH_ASSOC);
                                
                                // Seçenekleri işlemi yeni soruya ekle
                                foreach ($options as $option) {
                                    $option_stmt = $db->prepare("
                                        INSERT INTO options (question_id, option_text, is_correct) 
                                        VALUES (:question_id, :option_text, :is_correct)
                                    ");
                                    $option_stmt->bindParam(':question_id', $question_id);
                                    $option_stmt->bindParam(':option_text', $option['option_text']);
                                    $option_stmt->bindParam(':is_correct', $option['is_correct']);
                                    $option_stmt->execute();
                                }
                            }
                        }
                    }
                }
                
                $db->commit();
                $success_message = "İş ilanı başarıyla oluşturuldu.";
                
                // İş ilan listeleme sayfasına yönlendir
                header("Location: manage-jobs.php?success=created");
                exit;
            } else {
                throw new Exception("İlan oluşturulurken bir hata oluştu.");
            }
        } catch (Exception $e) {
            $db->rollBack();
            $error_message = "Hata: " . $e->getMessage();
        }
    }
}

// Şablonları getir
$stmt = $db->query("SELECT id, template_name, description FROM question_templates ORDER BY template_name");
$templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yeni İş İlanı Oluştur | İş Başvuru Sistemi</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
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
        
        .required-label::after {
            content: " *";
            color: var(--danger);
        }
        
        .form-text {
            color: var(--secondary);
        }
        
        .badge-pill {
            border-radius: 50px;
            padding: 0.35em 0.65em;
            font-size: 0.75em;
            font-weight: 600;
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
        
        /* Quill editor styles */
        .ql-container {
            min-height: 200px;
            max-height: 400px;
            overflow-y: auto;
            border-bottom-left-radius: 5px;
            border-bottom-right-radius: 5px;
        }
        
        .ql-toolbar {
            border-top-left-radius: 5px;
            border-top-right-radius: 5px;
            border-color: #e2e8f0;
        }
        
        .ql-editor:focus {
            border-color: var(--primary);
        }
        
        .template-card {
            cursor: pointer;
            border: 1px solid var(--card-border);
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 1rem;
            transition: all 0.2s ease;
        }
        
        .template-card:hover {
            border-color: var(--primary);
            background-color: rgba(67, 97, 238, 0.05);
        }
        
        .template-card.selected {
            border-color: var(--primary);
            background-color: rgba(67, 97, 238, 0.1);
        }
        
        .template-card .template-title {
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .template-card .template-desc {
            color: var(--secondary);
            margin-bottom: 0;
            font-size: 0.85rem;
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
                    <h1 class="page-title">Yeni İş İlanı Oluştur</h1>
                    <p class="page-subtitle">Sisteme yeni bir iş pozisyonu tanımla</p>
                </div>
                <div class="col-md-6 text-md-end">
                    <a href="manage-jobs.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-1"></i> İlanlara Dön
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="container mb-5">
        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success alert-modern alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i> <?= $success_message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger alert-modern alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= $error_message ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <form method="post">
            <div class="row">
                <div class="col-lg-8">
                    <!-- İş İlanı Bilgileri Kartı -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title"><i class="bi bi-info-circle me-2"></i>İş İlanı Bilgileri</h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label for="title" class="form-label required-label">İlan Başlığı</label>
                                <input type="text" class="form-control" id="title" name="title" required>
                            </div>
                            
                            <div class="mb-4">
                                <label for="description" class="form-label required-label">İş Tanımı</label>
                                <div id="editor"></div>
                                <textarea name="description" id="description" style="display:none"></textarea>
                                <div class="form-text">
                                    <i class="bi bi-info-circle me-1"></i> İş tanımını, gereksinimleri ve diğer önemli bilgileri ekleyin.
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="location" class="form-label required-label">Lokasyon</label>
                                <input type="text" class="form-control" id="location" name="location" required placeholder="Örn: İstanbul, Ankara, Remote...">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Şablon Seçimi -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title"><i class="bi bi-file-earmark-text me-2"></i>Soru Şablonu Seçimi</h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($templates)): ?>
                                <div class="alert alert-info mb-0">
                                    <i class="bi bi-info-circle me-2"></i> Henüz soru şablonu oluşturulmamış. 
                                    <a href="manage-templates.php">Şablonlar sayfasından</a> yeni bir şablon oluşturabilirsiniz.
                                </div>
                            <?php else: ?>
                                <p class="form-text">Başvuru formunda adaylara sorulacak soruları içeren bir şablon seçebilirsiniz.</p>
                                
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="radio" name="template_option" id="no_template" value="none" checked>
                                    <label class="form-check-label" for="no_template">
                                        Şablon Kullanma
                                    </label>
                                </div>
                                
                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="radio" name="template_option" id="use_template" value="use">
                                    <label class="form-check-label" for="use_template">
                                        Şablon Seç
                                    </label>
                                </div>
                                
                                <div id="templateContainer" class="mt-3 d-none">
                                    <input type="hidden" name="template_id" id="template_id" value="">
                                    
                                    <?php foreach ($templates as $template): ?>
                                        <div class="template-card" data-id="<?= $template['id'] ?>">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <h6 class="template-title">
                                                        <i class="bi bi-file-earmark-text me-1 text-primary"></i> 
                                                        <?= htmlspecialchars($template['template_name']) ?>
                                                    </h6>
                                                    <p class="template-desc">
                                                        <?= htmlspecialchars($template['description'] ?? 'Açıklama bulunmamaktadır.') ?>
                                                    </p>
                                                </div>
                                                <div class="template-select">
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="radio" name="template_radio" value="<?= $template['id'] ?>">
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-4">
                    <!-- Yayınlama Durumu -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title"><i class="bi bi-gear me-2"></i>Yayınlama Seçenekleri</h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label for="status" class="form-label">Durum</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="active">Aktif - Hemen Yayınla</option>
                                    <option value="inactive">Pasif - Taslak Olarak Kaydet</option>
                                </select>
                                <div class="form-text">
                                    <i class="bi bi-info-circle me-1"></i> Aktif ilanlar anasayfada görüntülenir ve başvurulara açıktır.
                                </div>
                            </div>

                            <hr class="my-4">

                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-check-lg me-1"></i> İlanı Oluştur
                                </button>
                                <a href="manage-jobs.php" class="btn btn-outline-secondary">
                                    <i class="bi bi-x-lg me-1"></i> İptal
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Yardım Kartı -->
                    <div class="card bg-light">
                        <div class="card-body">
                            <h5 class="card-title">
                                <i class="bi bi-question-circle me-2 text-primary"></i>
                                Yardım
                            </h5>
                            <ul class="list-group list-group-flush bg-transparent">
                                <li class="list-group-item bg-transparent border-0 ps-0">
                                    <i class="bi bi-check-circle-fill text-success me-2"></i>
                                    İlanınızı oluşturduktan sonra sorular ekleyebilirsiniz.
                                </li>
                                <li class="list-group-item bg-transparent border-0 ps-0">
                                    <i class="bi bi-check-circle-fill text-success me-2"></i>
                                    İş ilanı oluşturulduktan sonra da düzenleme yapabilirsiniz.
                                </li>
                                <li class="list-group-item bg-transparent border-0 ps-0">
                                    <i class="bi bi-check-circle-fill text-success me-2"></i>
                                    Şablon seçimi opsiyoneldir. Şablon seçmezseniz, daha sonra manuel olarak soru ekleyebilirsiniz.
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.quilljs.com/1.3.6/quill.min.js"></script>
    <script>
        // Quill zengin metin editörü
        var quill = new Quill('#editor', {
            theme: 'snow',
            placeholder: 'İş ilanı açıklaması yazın...',
            modules: {
                toolbar: [
                    [{ 'header': [1, 2, 3, false] }],
                    ['bold', 'italic', 'underline', 'strike'],
                    [{ 'list': 'ordered' }, { 'list': 'bullet' }],
                    ['link']
                ]
            }
        });
        
        // Form gönderilirken Quill içeriğini gizli textarea'ya aktar
        document.querySelector('form').addEventListener('submit', function() {
            var description = document.querySelector('#description');
            description.value = quill.root.innerHTML;
        });
        
        // Şablon seçimi
        const templateOption = document.querySelectorAll('input[name="template_option"]');
        const templateContainer = document.getElementById('templateContainer');
        const templateId = document.getElementById('template_id');
        const templateCards = document.querySelectorAll('.template-card');
        const templateRadios = document.querySelectorAll('input[name="template_radio"]');
        
        // Şablon seçimi radio button'ları
        templateOption.forEach(option => {
            option.addEventListener('change', function() {
                if (this.value === 'use') {
                    templateContainer.classList.remove('d-none');
                } else {
                    templateContainer.classList.add('d-none');
                    templateId.value = '';
                    // Şablon seçimini temizle
                    templateRadios.forEach(radio => {
                        radio.checked = false;
                    });
                    templateCards.forEach(card => {
                        card.classList.remove('selected');
                    });
                }
            });
        });
        
        // Template card ve radio button'larını birbirine bağla
        templateCards.forEach(card => {
            card.addEventListener('click', function() {
                const id = this.dataset.id;
                templateId.value = id;
                
                // Tüm radio'ları temizle
                templateRadios.forEach(radio => {
                    radio.checked = false;
                });
                
                // Tüm kartları temizle
                templateCards.forEach(c => {
                    c.classList.remove('selected');
                });
                
                // Seçilen kartı işaretle
                this.classList.add('selected');
                
                // İlgili radio'yu işaretle
                const radio = document.querySelector(`input[name="template_radio"][value="${id}"]`);
                if (radio) {
                    radio.checked = true;
                }
                
                // Şablon kullanma seçeneğini aktif et
                document.getElementById('use_template').checked = true;
            });
        });
        
        // Radio button tıklandığında ilgili kartı seç
        templateRadios.forEach(radio => {
            radio.addEventListener('change', function() {
                const id = this.value;
                templateId.value = id;
                
                // Tüm kartları temizle
                templateCards.forEach(c => {
                    c.classList.remove('selected');
                });
                
                // Seçilen kartı işaretle
                const selectedCard = document.querySelector(`.template-card[data-id="${id}"]`);
                if (selectedCard) {
                    selectedCard.classList.add('selected');
                }
            });
        });
    </script>
</body>
</html>