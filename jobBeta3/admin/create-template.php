<?php
// Output buffering başlat
ob_start();

session_start();

// Oturum kontrolü
if (!isset($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit;
}

require_once '../config/db.php';

$success_message = '';
$error_message = '';

// Form gönderildiğinde
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $template_name = trim($_POST['template_name']);
    $category = trim($_POST['category']);
    $description = trim($_POST['description']);
    
    // Soru verileri
    $questions = isset($_POST['questions']) ? $_POST['questions'] : [];
    $question_types = isset($_POST['question_types']) ? $_POST['question_types'] : [];
    $options = isset($_POST['options']) ? $_POST['options'] : [];
    $correct_options = isset($_POST['correct_options']) ? $_POST['correct_options'] : [];
    
    // Validasyon
    if (empty($template_name)) {
        $error_message = "Şablon adı boş olamaz.";
    } elseif (empty($questions)) {
        $error_message = "En az bir soru eklemelisiniz.";
    } else {
        try {
            $db->beginTransaction();
            
            // Şablon oluştur
            $stmt = $db->prepare("INSERT INTO question_templates (template_name, category, description) VALUES (:template_name, :category, :description)");
            $stmt->bindParam(':template_name', $template_name);
            $stmt->bindParam(':category', $category);
            $stmt->bindParam(':description', $description);
            $stmt->execute();
            
            $template_id = $db->lastInsertId();
            
            // Soruları ekle
            foreach ($questions as $index => $question_text) {
                if (empty(trim($question_text))) continue;
                
                $question_type = $question_types[$index];
                $sort_order = $index + 1;
                
                $stmt = $db->prepare("INSERT INTO template_questions (template_id, question_text, question_type, sort_order) VALUES (:template_id, :question_text, :question_type, :sort_order)");
                $stmt->bindParam(':template_id', $template_id);
                $stmt->bindParam(':question_text', $question_text);
                $stmt->bindParam(':question_type', $question_type);
                $stmt->bindParam(':sort_order', $sort_order);
                $stmt->execute();
                
                $question_id = $db->lastInsertId();
                
                // Çoktan seçmeli soru ise seçenekleri ekle
                if ($question_type === 'multiple_choice' && isset($options[$index])) {
                    $question_options = $options[$index];
                    $correct_option = isset($correct_options[$index]) ? (int)$correct_options[$index] : 0;
                    
                    foreach ($question_options as $option_index => $option_text) {
                        if (empty(trim($option_text))) continue;
                        
                        $is_correct = ($option_index == $correct_option) ? 1 : 0;
                        
                        $stmt = $db->prepare("INSERT INTO template_options (template_question_id, option_text, is_correct) VALUES (:question_id, :option_text, :is_correct)");
                        $stmt->bindParam(':question_id', $question_id);
                        $stmt->bindParam(':option_text', $option_text);
                        $stmt->bindParam(':is_correct', $is_correct);
                        $stmt->execute();
                    }
                }
            }
            
            $db->commit();
            $success_message = "Şablon başarıyla oluşturuldu.";
            
            // Şablon listesine yönlendir
            header("Location: manage-templates.php?success=created");
            exit;
            
        } catch (Exception $e) {
            $db->rollBack();
            $error_message = "Hata: " . $e->getMessage();
        }
    }
}

// Kategorileri getir
$stmt = $db->query("SELECT DISTINCT category FROM question_templates WHERE category IS NOT NULL AND category != '' ORDER BY category");
$categories = $stmt->fetchAll(PDO::FETCH_COLUMN);
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yeni Şablon Oluştur | İş Başvuru Sistemi</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.css" rel="stylesheet" />
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
        
        /* Question card styles */
        .question-card {
            background-color: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 10px;
            padding: 1.25rem;
            margin-bottom: 1rem;
            position: relative;
        }
        
        .question-card.dragging {
            opacity: 0.5;
            cursor: move;
        }
        
        .question-handle {
            cursor: move;
            color: var(--secondary);
            margin-right: 0.5rem;
        }
        
        .question-number {
            position: absolute;
            left: -10px;
            top: -10px;
            width: 24px;
            height: 24px;
            background-color: var(--primary);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .option-row {
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
        }
        
        .option-radio {
            margin-right: 0.5rem;
        }
        
        .option-input {
            flex-grow: 1;
        }
        
        .option-remove {
            margin-left: 0.5rem;
            color: var(--danger);
            cursor: pointer;
        }
        
        .add-option-btn {
            background-color: rgba(67, 97, 238, 0.1);
            border: 1px dashed var(--primary);
            color: var(--primary);
            border-radius: 5px;
            padding: 0.5rem;
            cursor: pointer;
            text-align: center;
            margin-top: 0.5rem;
        }
        
        .add-option-btn:hover {
            background-color: rgba(67, 97, 238, 0.15);
        }
        
        .add-question-card {
            background-color: rgba(67, 97, 238, 0.05);
            border: 2px dashed rgba(67, 97, 238, 0.3);
            border-radius: 10px;
            padding: 1.5rem;
            text-align: center;
            cursor: pointer;
            margin-bottom: 1.5rem;
            transition: all 0.2s;
        }
        
        .add-question-card:hover {
            background-color: rgba(67, 97, 238, 0.1);
            border-color: rgba(67, 97, 238, 0.5);
        }
        
        .add-question-icon {
            font-size: 2rem;
            color: var(--primary);
            margin-bottom: 0.5rem;
        }
        
        .question-type-selector {
            display: flex;
            background-color: var(--light);
            border-radius: 5px;
            padding: 0.25rem;
            margin-bottom: 1rem;
        }
        
        .question-type-option {
            flex: 1;
            padding: 0.5rem;
            text-align: center;
            cursor: pointer;
            border-radius: 4px;
            font-weight: 500;
            transition: all 0.2s;
        }
        
        .question-type-option.selected {
            background-color: white;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            color: var(--primary);
        }
        
        .question-type-option:hover:not(.selected) {
            background-color: rgba(255, 255, 255, 0.5);
        }
        
        .form-check-input:checked {
            background-color: var(--primary);
            border-color: var(--primary);
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
                    <h1 class="page-title">Yeni Şablon Oluştur</h1>
                    <p class="page-subtitle">İş ilanlarında kullanılacak bir soru şablonu oluşturun</p>
                </div>
                <div class="col-md-6 text-md-end">
                    <a href="manage-templates.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-1"></i> Şablonlara Dön
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

        <form method="post" id="templateForm">
            <div class="row">
                <div class="col-lg-8">
                    <!-- Şablon Bilgileri Kartı -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title"><i class="bi bi-info-circle me-2"></i>Şablon Bilgileri</h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label for="template_name" class="form-label required-label">Şablon Adı</label>
                                <input type="text" class="form-control" id="template_name" name="template_name" required>
                                <div class="form-text">Şablonunuzu tanımlayan kısa bir isim girin.</div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="category" class="form-label">Kategori</label>
                                <div class="input-group">
                                    <input type="text" class="form-control" list="categoryOptions" id="category" name="category" placeholder="Kategori seçin veya yeni kategori girin">
                                    <datalist id="categoryOptions">
                                        <?php foreach ($categories as $cat): ?>
                                            <option value="<?= htmlspecialchars($cat) ?>">
                                        <?php endforeach; ?>
                                    </datalist>
                                </div>
                                <div class="form-text">Şablonları gruplandırmak için kategori belirtebilirsiniz.</div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="description" class="form-label">Açıklama</label>
                                <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                                <div class="form-text">Şablonun hangi tür pozisyonlar için uygun olduğunu açıklayın.</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Sorular Bölümü -->
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="card-title"><i class="bi bi-question-circle me-2"></i>Şablon Soruları</h5>
                            <span class="badge bg-primary" id="questionCount">0 Soru</span>
                        </div>
                        <div class="card-body">
                            <p class="form-text mb-3">
                                <i class="bi bi-info-circle me-1"></i> İş başvurusu sırasında adaylara sorulacak soruları ekleyin. 
                                Soruları sürükleyerek sıralayabilirsiniz.
                            </p>
                            
                            <div id="questionsContainer">
                                <!-- Sorular burada dinamik olarak listelenecek -->
                            </div>
                            
                            <div class="add-question-card" id="addQuestionBtn">
                                <div class="add-question-icon">
                                    <i class="bi bi-plus-circle"></i>
                                </div>
                                <h5>Yeni Soru Ekle</h5>
                                <p class="text-muted mb-0">Çoktan seçmeli veya açık uçlu soru ekle</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-4">
                    <!-- Kaydetme Kartı -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title"><i class="bi bi-save me-2"></i>Kaydet</h5>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary" id="saveBtn">
                                    <i class="bi bi-check-lg me-1"></i> Şablonu Oluştur
                                </button>
                                <a href="manage-templates.php" class="btn btn-outline-secondary">
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
                                    Çoktan seçmeli sorularda doğru cevabı işaretlemeyi unutmayın.
                                </li>
                                <li class="list-group-item bg-transparent border-0 ps-0">
                                    <i class="bi bi-check-circle-fill text-success me-2"></i>
                                    Açık uçlu sorular için adayların metin cevapları veya dosya yüklemesi beklenecektir.
                                </li>
                                <li class="list-group-item bg-transparent border-0 ps-0">
                                    <i class="bi bi-check-circle-fill text-success me-2"></i>
                                    Soruları sürükleyip bırakarak sıralayabilirsiniz.
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
    
    <!-- Yeni Soru Ekleme Modalı -->
    <div class="modal fade" id="addQuestionModal" tabindex="-1" aria-labelledby="addQuestionModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="addQuestionModalLabel">Yeni Soru Ekle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="questionText" class="form-label required-label">Soru Metni</label>
                        <textarea class="form-control" id="questionText" rows="3" placeholder="Sorunuzu buraya yazın"></textarea>
                    </div>
                    
                    <div class="question-type-selector">
                        <div class="question-type-option selected" data-type="multiple_choice">
                            <i class="bi bi-list-check me-1"></i> Çoktan Seçmeli
                        </div>
                        <div class="question-type-option" data-type="open_ended">
                            <i class="bi bi-textarea-t me-1"></i> Açık Uçlu
                        </div>
                    </div>
                    
                    <div id="optionsContainer">
                        <p class="mb-2">Seçenekler (doğru cevabı işaretleyin)</p>
                        <div class="option-row">
                            <input type="radio" name="correctOption" class="option-radio form-check-input" checked>
                            <input type="text" class="form-control option-input" placeholder="Seçenek 1">
                            <span class="option-remove"><i class="bi bi-x-circle"></i></span>
                        </div>
                        <div class="option-row">
                            <input type="radio" name="correctOption" class="option-radio form-check-input">
                            <input type="text" class="form-control option-input" placeholder="Seçenek 2">
                            <span class="option-remove"><i class="bi bi-x-circle"></i></span>
                        </div>
                        
                        <div class="add-option-btn" id="addOptionBtn">
                            <i class="bi bi-plus-circle me-1"></i> Yeni Seçenek Ekle
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="button" class="btn btn-primary" id="saveQuestionBtn">Soruyu Ekle</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
    <script>
        // Global değişkenler
        let questionCounter = 0;
        let currentEditingQuestionId = null;
        
        // DOM elementleri
        const questionsContainer = document.getElementById('questionsContainer');
        const addQuestionBtn = document.getElementById('addQuestionBtn');
        const addQuestionModal = new bootstrap.Modal(document.getElementById('addQuestionModal'));
        const questionText = document.getElementById('questionText');
        const questionTypeOptions = document.querySelectorAll('.question-type-option');
        const optionsContainer = document.getElementById('optionsContainer');
        const addOptionBtn = document.getElementById('addOptionBtn');
        const saveQuestionBtn = document.getElementById('saveQuestionBtn');
        const questionCountEl = document.getElementById('questionCount');
        const saveBtn = document.getElementById('saveBtn');
        
        // Sortable - sürükle & bırak özelliği
        new Sortable(questionsContainer, {
            animation: 150,
            handle: '.question-handle',
            ghostClass: 'dragging',
            onEnd: updateQuestionNumbers
        });
        
        // Yeni soru ekle butonuna tıklama
        addQuestionBtn.addEventListener('click', () => {
            resetModal();
            currentEditingQuestionId = null;
            addQuestionModal.show();
        });
        
        // Soru tipini değiştirme
        questionTypeOptions.forEach(option => {
            option.addEventListener('click', () => {
                // Aktif sınıfı kaldır
                questionTypeOptions.forEach(o => o.classList.remove('selected'));
                
                // Bu seçeneği aktif yap
                option.classList.add('selected');
                
                // Çoktan seçmeli için seçenek containerını göster/gizle
                const questionType = option.getAttribute('data-type');
                optionsContainer.style.display = questionType === 'multiple_choice' ? 'block' : 'none';
            });
        });
        
        // Yeni seçenek ekleme
        addOptionBtn.addEventListener('click', addNewOption);
        
        // Soruyu kaydetme
        saveQuestionBtn.addEventListener('click', saveQuestion);
        
        // Sorulardaki olayları yönetme (silme, düzenleme)
        questionsContainer.addEventListener('click', (e) => {
            // Eğer silme butonuna tıklandıysa
            if (e.target.classList.contains('remove-question') || e.target.closest('.remove-question')) {
                const questionCard = e.target.closest('.question-card');
                if (questionCard) {
                    questionCard.remove();
                    updateQuestionNumbers();
                    updateQuestionCount();
                }
            }
            
            // Eğer düzenleme butonuna tıklandıysa
            if (e.target.classList.contains('edit-question') || e.target.closest('.edit-question')) {
                const questionCard = e.target.closest('.question-card');
                if (questionCard) {
                    editQuestion(questionCard);
                }
            }
            
            // Seçenek silme butonuna tıklandıysa
            if (e.target.classList.contains('option-remove') || e.target.closest('.option-remove')) {
                const optionRow = e.target.closest('.option-row');
                if (optionRow) {
                    optionRow.remove();
                }
            }
        });
        
        // Formun gönderilmesini kontrol et
        document.getElementById('templateForm').addEventListener('submit', (e) => {
            const questions = questionsContainer.querySelectorAll('.question-card');
            
            if (questions.length === 0) {
                e.preventDefault();
                alert('Lütfen en az bir soru ekleyin.');
            }
        });
        
        // Yeni seçenek ekleme fonksiyonu
        function addNewOption() {
            const optionRow = document.createElement('div');
            optionRow.className = 'option-row';
            
            const optionIndex = optionsContainer.querySelectorAll('.option-row').length + 1;
            
            optionRow.innerHTML = `
                <input type="radio" name="correctOption" class="option-radio form-check-input">
                <input type="text" class="form-control option-input" placeholder="Seçenek ${optionIndex}">
                <span class="option-remove"><i class="bi bi-x-circle"></i></span>
            `;
            
            // Seçenek ekle butonundan önce ekle
            optionsContainer.insertBefore(optionRow, addOptionBtn);
            
            // Yeni eklenen seçeneğin silme butonuna event listener ekle
            const removeBtn = optionRow.querySelector('.option-remove');
            removeBtn.addEventListener('click', () => {
                optionRow.remove();
            });
        }
        
        // Soruyu kaydetme fonksiyonu
        function saveQuestion() {
            const question = questionText.value.trim();
            if (!question) {
                alert('Lütfen soru metnini giriniz.');
                return;
            }
            
            // Soru tipi
            const selectedTypeEl = document.querySelector('.question-type-option.selected');
            const questionType = selectedTypeEl.getAttribute('data-type');
            
            // Eğer düzenleme modundaysa mevcut soruyu güncelle, değilse yeni ekle
            if (currentEditingQuestionId !== null) {
                updateExistingQuestion(currentEditingQuestionId, question, questionType);
            } else {
                addNewQuestionToContainer(question, questionType);
            }
            
            updateQuestionCount();
            addQuestionModal.hide();
        }
        
        // Yeni soru ekleme fonksiyonu
        function addNewQuestionToContainer(question, questionType) {
            questionCounter++;
            
            const questionCard = document.createElement('div');
            questionCard.className = 'question-card';
            questionCard.dataset.id = Date.now().toString(); // Benzersiz ID
            
            let optionsHTML = '';
            let hiddenInputs = '';
            
            // Eğer çoktan seçmeli soruysa seçenekleri ekle
            if (questionType === 'multiple_choice') {
                const options = [];
                const optionRows = optionsContainer.querySelectorAll('.option-row');
                let correctOptionIndex = -1;
                
                optionRows.forEach((row, index) => {
                    const optionText = row.querySelector('.option-input').value.trim();
                    const isCorrect = row.querySelector('.option-radio').checked;
                    
                    if (optionText) {
                        options.push(optionText);
                        
                        // Doğru seçeneğin indexini kaydet
                        if (isCorrect) {
                            correctOptionIndex = index;
                        }
                        
                        // Seçenek için gizli input ekle
                        hiddenInputs += `<input type="hidden" name="options[${questionCounter-1}][]" value="${htmlEscape(optionText)}">`;
                    }
                });
                
                // Doğru cevap için gizli input
                if (correctOptionIndex !== -1) {
                    hiddenInputs += `<input type="hidden" name="correct_options[${questionCounter-1}]" value="${correctOptionIndex}">`;
                }
                
                // Seçenekleri HTML olarak hazırla
                optionsHTML = '<div class="mt-3 mb-2"><strong>Seçenekler:</strong></div>';
                optionsHTML += '<ol class="ps-3">';
                
                options.forEach((option, index) => {
                    const isCorrectClass = index === correctOptionIndex ? 'text-success fw-bold' : '';
                    const correctBadge = index === correctOptionIndex ? '<span class="badge bg-success ms-2">Doğru Cevap</span>' : '';
                    optionsHTML += `<li class="${isCorrectClass}">${htmlEscape(option)}${correctBadge}</li>`;
                });
                
                optionsHTML += '</ol>';
            }
            
            questionCard.innerHTML = `
                <div class="question-number">${questionCounter}</div>
                <div class="d-flex justify-content-between mb-2">
                    <div class="d-flex align-items-center">
                        <span class="question-handle me-2"><i class="bi bi-grip-vertical"></i></span>
                        <span class="badge bg-${questionType === 'multiple_choice' ? 'primary' : 'info'} me-2">
                            ${questionType === 'multiple_choice' ? 'Çoktan Seçmeli' : 'Açık Uçlu'}
                        </span>
                    </div>
                    <div>
                        <button type="button" class="btn btn-sm btn-outline-primary edit-question me-1">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger remove-question">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </div>
                <div class="question-text mb-2">${htmlEscape(question)}</div>
                ${optionsHTML}
                <input type="hidden" name="questions[${questionCounter-1}]" value="${htmlEscape(question)}">
                <input type="hidden" name="question_types[${questionCounter-1}]" value="${questionType}">
                ${hiddenInputs}
            `;
            
            questionsContainer.appendChild(questionCard);
        }
        
        // Mevcut soruyu güncelleme
        function updateExistingQuestion(questionId, question, questionType) {
            const questionCard = document.querySelector(`.question-card[data-id="${questionId}"]`);
            if (!questionCard) return;
            
            // Soru metnini güncelle
            questionCard.querySelector('.question-text').innerText = question;
            
            // Soru tipi badge'ini güncelle
            const badge = questionCard.querySelector('.badge');
            badge.className = `badge bg-${questionType === 'multiple_choice' ? 'primary' : 'info'} me-2`;
            badge.innerText = questionType === 'multiple_choice' ? 'Çoktan Seçmeli' : 'Açık Uçlu';
            
            // Gizli inputları ve diğer verileri güncelle
            // Burada form index numarası için daha karmaşık bir güncelleme gerekebilir
            
            // Şimdilik basit bir şekilde tüm kartı yeniden oluştur
            const index = Array.from(questionsContainer.children).indexOf(questionCard);
            questionCard.remove();
            
            addNewQuestionToContainer(question, questionType);
            
            // Yeni eklenen kartı doğru pozisyona taşı
            const newCard = questionsContainer.lastElementChild;
            if (index >= 0 && index < questionsContainer.children.length - 1) {
                questionsContainer.insertBefore(newCard, questionsContainer.children[index]);
            }
            
            updateQuestionNumbers();
        }
        
        // Soru düzenleme
        function editQuestion(questionCard) {
            // Düzenleme için soru ID'sini kaydet
            currentEditingQuestionId = questionCard.dataset.id;
            
            // Soru metnini al
            const questionTextValue = questionCard.querySelector('.question-text').innerText;
            questionText.value = questionTextValue;
            
            // Soru tipini belirle
            const badge = questionCard.querySelector('.badge');
            const questionType = badge.innerText.includes('Çoktan Seçmeli') ? 'multiple_choice' : 'open_ended';
            
            // Soru tipi seçeneğini güncelle
            questionTypeOptions.forEach(option => {
                option.classList.remove('selected');
                if (option.getAttribute('data-type') === questionType) {
                    option.classList.add('selected');
                }
            });
            
            // Seçenekleri temizle ve yeniden yükle
            const optionRows = optionsContainer.querySelectorAll('.option-row');
            optionRows.forEach(row => row.remove());
            
            // Çoktan seçmeli ise seçenekleri göster ve doldur
            if (questionType === 'multiple_choice') {
                optionsContainer.style.display = 'block';
                
                const optionsList = questionCard.querySelectorAll('ol li');
                optionsList.forEach((optionItem, index) => {
                    const isCorrect = optionItem.classList.contains('fw-bold');
                    const optionText = optionItem.innerText.replace('Doğru Cevap', '').trim();
                    
                    addOptionWithValue(optionText, isCorrect);
                });
            } else {
                optionsContainer.style.display = 'none';
            }
            
            addQuestionModal.show();
        }
        
        // Belirli değerlerle seçenek ekle
        function addOptionWithValue(text, isCorrect) {
            const optionRow = document.createElement('div');
            optionRow.className = 'option-row';
            
            optionRow.innerHTML = `
                <input type="radio" name="correctOption" class="option-radio form-check-input" ${isCorrect ? 'checked' : ''}>
                <input type="text" class="form-control option-input" value="${htmlEscape(text)}">
                <span class="option-remove"><i class="bi bi-x-circle"></i></span>
            `;
            
            // Seçenek ekle butonundan önce ekle
            optionsContainer.insertBefore(optionRow, addOptionBtn);
            
            // Yeni eklenen seçeneğin silme butonuna event listener ekle
            const removeBtn = optionRow.querySelector('.option-remove');
            removeBtn.addEventListener('click', () => {
                optionRow.remove();
            });
        }
        
        // Modal içeriğini sıfırlama
        function resetModal() {
            questionText.value = '';
            
            // Çoktan seçmeli soru tipini aktif et
            questionTypeOptions.forEach(option => {
                option.classList.remove('selected');
                if (option.getAttribute('data-type') === 'multiple_choice') {
                    option.classList.add('selected');
                }
            });
            
            // Seçenekleri temizle ve varsayılan iki seçenek ekle
            const optionRows = optionsContainer.querySelectorAll('.option-row');
            optionRows.forEach(row => row.remove());
            
            // İki varsayılan seçenek ekle
            addOptionWithValue('', true);
            addOptionWithValue('', false);
            
            // Seçenek container'ı göster
            optionsContainer.style.display = 'block';
        }
        
        // Soru numaralarını güncelle
        function updateQuestionNumbers() {
            const questions = questionsContainer.querySelectorAll('.question-card');
            questions.forEach((question, index) => {
                const numberElement = question.querySelector('.question-number');
                if (numberElement) {
                    numberElement.innerText = index + 1;
                }
            });
        }
        
        // Soru sayısını güncelle
        function updateQuestionCount() {
            const count = questionsContainer.querySelectorAll('.question-card').length;
            questionCountEl.innerText = `${count} Soru`;
            
            // Kaydet butonunu aktifleştir veya devre dışı bırak
            saveBtn.disabled = count === 0;
        }
        
        // HTML özel karakterlerini escape et
        function htmlEscape(str) {
            return str
                .replace(/&/g, '&amp;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');
        }
        
        // Sayfa yüklendiğinde soru sayısını güncelle
        document.addEventListener('DOMContentLoaded', () => {
            updateQuestionCount();
        });
    </script>
</body>
</html>

<?php
// Output buffer içeriğini gönder ve buffer'ı temizle
ob_end_flush();
?>