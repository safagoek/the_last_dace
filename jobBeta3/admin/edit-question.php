<?php
session_start();

// Oturum kontrolü
if (!isset($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit;
}

require_once '../config/db.php';

// Soru ve iş ilanı ID'si kontrolü
$question_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$job_id = isset($_GET['job_id']) ? (int)$_GET['job_id'] : 0;

if ($question_id <= 0 || $job_id <= 0) {
    header('Location: manage-jobs.php');
    exit;
}

// Soruyu al
$stmt = $db->prepare("SELECT * FROM questions WHERE id = :question_id AND job_id = :job_id");
$stmt->bindParam(':question_id', $question_id, PDO::PARAM_INT);
$stmt->bindParam(':job_id', $job_id, PDO::PARAM_INT);
$stmt->execute();
$question = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$question) {
    header('Location: manage-job-questions.php?job_id=' . $job_id);
    exit;
}

// İş ilanı bilgilerini al
$stmt = $db->prepare("SELECT * FROM jobs WHERE id = :job_id");
$stmt->bindParam(':job_id', $job_id, PDO::PARAM_INT);
$stmt->execute();
$job = $stmt->fetch(PDO::FETCH_ASSOC);

// Soru şıklarını al (çoktan seçmeli ise)
$options = [];
if ($question['question_type'] == 'multiple_choice') {
    $stmt = $db->prepare("SELECT * FROM options WHERE question_id = :question_id ORDER BY id");
    $stmt->bindParam(':question_id', $question_id, PDO::PARAM_INT);
    $stmt->execute();
    $options = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$success = '';
$error = '';

// Form gönderildiğinde
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $db->beginTransaction();
        
        $question_text = trim($_POST['question_text']);
        
        if (empty($question_text)) {
            throw new Exception("Soru metni boş olamaz.");
        }
        
        // Soru metnini güncelle
        $stmt = $db->prepare("UPDATE questions SET question_text = :question_text WHERE id = :question_id");
        $stmt->bindParam(':question_text', $question_text);
        $stmt->bindParam(':question_id', $question_id, PDO::PARAM_INT);
        $stmt->execute();
        
        // Çoktan seçmeli soru ise şıkları güncelle
        if ($question['question_type'] == 'multiple_choice') {
            // Mevcut şıkları temizle
            $stmt = $db->prepare("DELETE FROM options WHERE question_id = :question_id");
            $stmt->bindParam(':question_id', $question_id, PDO::PARAM_INT);
            $stmt->execute();
            
            // Yeni şıkları ekle
            if (isset($_POST['options']) && is_array($_POST['options'])) {
                $correct_option = isset($_POST['correct_option']) ? (int)$_POST['correct_option'] : -1;
                
                if ($correct_option < 0 || $correct_option >= count($_POST['options'])) {
                    throw new Exception("Lütfen doğru cevabı işaretleyin.");
                }
                
                foreach ($_POST['options'] as $index => $option_text) {
                    $option_text = trim($option_text);
                    if (!empty($option_text)) {
                        $is_correct = ($index == $correct_option) ? 1 : 0;
                        
                        $stmt = $db->prepare("INSERT INTO options (question_id, option_text, is_correct) 
                                             VALUES (:question_id, :option_text, :is_correct)");
                        $stmt->bindParam(':question_id', $question_id, PDO::PARAM_INT);
                        $stmt->bindParam(':option_text', $option_text);
                        $stmt->bindParam(':is_correct', $is_correct, PDO::PARAM_BOOL);
                        $stmt->execute();
                    }
                }
            } else {
                throw new Exception("En az 2 şık eklemelisiniz.");
            }
        }
        
        $db->commit();
        $success = "Soru başarıyla güncellendi.";
        
        // Güncellenen verileri yeniden yükleme
        $stmt = $db->prepare("SELECT * FROM questions WHERE id = :question_id");
        $stmt->bindParam(':question_id', $question_id, PDO::PARAM_INT);
        $stmt->execute();
        $question = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($question['question_type'] == 'multiple_choice') {
            $stmt = $db->prepare("SELECT * FROM options WHERE question_id = :question_id ORDER BY id");
            $stmt->bindParam(':question_id', $question_id, PDO::PARAM_INT);
            $stmt->execute();
            $options = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Soru Düzenle - İş Başvuru Sistemi</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
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
        
        .correct-option {
            background-color: #d1e7dd;
            border-color: #badbcc;
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
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1>Soru Düzenle</h1>
            <a href="manage-job-questions.php?job_id=<?= $job_id ?>" class="btn btn-secondary">Sorulara Dön</a>
        </div>
        
        <div class="alert alert-info mb-4">
            <h4 class="alert-heading"><?= htmlspecialchars($job['title']) ?></h4>
            <p class="mb-0">
                Soru Tipi: 
                <strong>
                    <?= ($question['question_type'] == 'multiple_choice') ? 'Çoktan Seçmeli' : 'Açık Uçlu' ?>
                </strong>
            </p>
        </div>
        
        <?php if (!empty($success)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?= $success ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= $error ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">Soru Bilgilerini Düzenle</h5>
            </div>
            <div class="card-body">
                <form method="post" id="questionForm">
                    <div class="mb-3">
                        <label for="question_text" class="form-label">Soru Metni *</label>
                        <textarea class="form-control" id="question_text" name="question_text" rows="3" required><?= htmlspecialchars($question['question_text']) ?></textarea>
                    </div>
                    
                    <?php if ($question['question_type'] == 'multiple_choice'): ?>
                        <div id="optionsSection">
                            <div class="mb-3">
                                <label class="form-label">Şıklar (En az 2 şık eklemelisiniz) *</label>
                                <p class="text-muted small">Doğru cevabı radyo butonuyla işaretleyin</p>
                                
                                <div id="optionsContainer">
                                    <?php 
                                    $correct_option_index = -1;
                                    foreach ($options as $index => $option): 
                                        if ($option['is_correct']) {
                                            $correct_option_index = $index;
                                        }
                                    ?>
                                        <div class="input-group mb-2">
                                            <div class="input-group-text">
                                                <input type="radio" name="correct_option" value="<?= $index ?>" <?= ($option['is_correct']) ? 'checked' : '' ?> class="form-check-input mt-0">
                                            </div>
                                            <input type="text" class="form-control <?= ($option['is_correct']) ? 'correct-option' : '' ?>" name="options[]" placeholder="Şık <?= $index + 1 ?>" value="<?= htmlspecialchars($option['option_text']) ?>" required>
                                            <?php if (count($options) > 2): ?>
                                                <button class="btn btn-outline-danger" type="button" onclick="this.parentNode.remove(); updateOptionIndices();">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                
                                <button type="button" class="btn btn-sm btn-outline-secondary mt-2" onclick="addOption()">
                                    <i class="bi bi-plus-circle"></i> Şık Ekle
                                </button>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info">
                            <p>Bu soru <strong>Açık Uçlu</strong> olduğu için şık ayarlama seçeneği bulunmamaktadır.</p>
                            <p>Adaylar bu soruya metin girerek veya dosya yükleyerek cevap vereceklerdir.</p>
                        </div>
                    <?php endif; ?>
                    
                    <div class="text-end">
                        <button type="submit" class="btn btn-primary">Değişiklikleri Kaydet</button>
                        <a href="manage-job-questions.php?job_id=<?= $job_id ?>" class="btn btn-secondary ms-2">İptal</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Başlangıçta varolan şık sayısı
        let optionCount = <?= count($options) ?>;
        
        function addOption() {
            const container = document.getElementById('optionsContainer');
            const div = document.createElement('div');
            div.className = 'input-group mb-2';
            div.innerHTML = `
                <div class="input-group-text">
                    <input type="radio" name="correct_option" value="${optionCount}" class="form-check-input mt-0">
                </div>
                <input type="text" class="form-control" name="options[]" placeholder="Şık ${optionCount + 1}" required>
                <button class="btn btn-outline-danger" type="button" onclick="this.parentNode.remove(); updateOptionIndices();">
                    <i class="bi bi-trash"></i>
                </button>
            `;
            container.appendChild(div);
            optionCount++;
            updateOptionIndices();
        }
        
        function updateOptionIndices() {
            // Radio butonların değerlerini güncelle
            const container = document.getElementById('optionsContainer');
            const options = container.querySelectorAll('.input-group');
            
            options.forEach((option, index) => {
                const radio = option.querySelector('input[type="radio"]');
                radio.value = index;
                
                const input = option.querySelector('input[type="text"]');
                input.placeholder = `Şık ${index + 1}`;
                
                // Eğer bu radyo buton seçiliyse, yeni indeksle doğru şık hidden input'u güncelle
                if (radio.checked) {
                    const correctOptionInput = document.querySelector('input[name="correct_option"]');
                    if (correctOptionInput) {
                        correctOptionInput.value = index;
                    }
                }
            });
        }
        
        // Form gönderildiğinde şık kontrolü
        document.getElementById('questionForm').addEventListener('submit', function(e) {
            <?php if ($question['question_type'] == 'multiple_choice'): ?>
            const options = document.querySelectorAll('input[name="options[]"]');
            let filledOptions = 0;
            
            options.forEach(option => {
                if (option.value.trim() !== '') {
                    filledOptions++;
                }
            });
            
            if (filledOptions < 2) {
                e.preventDefault();
                alert('En az 2 şık eklemelisiniz.');
                return false;
            }
            
            // Doğru cevap işaretlenmiş mi kontrol et
            const correctOption = document.querySelector('input[name="correct_option"]:checked');
            if (!correctOption) {
                e.preventDefault();
                alert('Lütfen doğru cevabı işaretleyin.');
                return false;
            }
            <?php endif; ?>
        });
        
        // Radio butonlarına tıklandığında CSS class'ını güncelle
        const radioButtons = document.querySelectorAll('input[name="correct_option"]');
        radioButtons.forEach(radio => {
            radio.addEventListener('change', function() {
                // Tüm şıkların arka plan renklerini sıfırla
                const allOptions = document.querySelectorAll('input[name="options[]"]');
                allOptions.forEach(option => {
                    option.classList.remove('correct-option');
                });
                
                // Seçilen şıkkın arka plan rengini ayarla
                if (this.checked) {
                    const inputField = this.closest('.input-group').querySelector('input[type="text"]');
                    inputField.classList.add('correct-option');
                }
            });
        });
    </script>
</body>
</html>