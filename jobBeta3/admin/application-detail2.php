<?php
// Veritabanı bağlantısı
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "job_application_system_db";

// Bağlantı oluştur
$conn = new mysqli($servername, $username, $password, $dbname);

// Bağlantıyı kontrol et
if ($conn->connect_error) {
    die("Bağlantı hatası: " . $conn->connect_error);
}

// URL'den uygulama ID'sini al
$application_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Tüm adayları al (listelemek için)
$sql_all = "SELECT a.*, j.title as job_title 
            FROM applications a 
            JOIN jobs j ON a.job_id = j.id 
            ORDER BY a.created_at DESC";
$all_applications = $conn->query($sql_all);

// İş ilanlarını al
$sql_jobs = "SELECT id, title FROM jobs ORDER BY title";
$jobs_result = $conn->query($sql_jobs);

// Eğer belirli bir aday seçilmişse detaylarını al
if ($application_id > 0) {
    // Başvuru detaylarını al
    $sql = "SELECT a.*, j.title as job_title 
            FROM applications a 
            JOIN jobs j ON a.job_id = j.id 
            WHERE a.id = $application_id";
    $result = $conn->query($sql);

    if ($result->num_rows == 0) {
        die("Başvuru bulunamadı");
    }

    $application = $result->fetch_assoc();

    // Başvuru cevaplarını al
    $sql = "SELECT aa.*, q.question_text, q.question_type, o.option_text, o.is_correct 
            FROM application_answers aa 
            JOIN questions q ON aa.question_id = q.id 
            LEFT JOIN options o ON aa.option_id = o.id 
            WHERE aa.application_id = $application_id 
            ORDER BY q.id";
    $answers_result = $conn->query($sql);

    // Toplam puanı hesapla
    $sql = "SELECT SUM(answer_score) as total_answer_score, COUNT(*) as total_questions 
            FROM application_answers 
            WHERE application_id = $application_id AND answer_score > 0";
    $score_result = $conn->query($sql);
    $score_data = $score_result->fetch_assoc();

    $total_answer_score = $score_data['total_answer_score'] ?? 0;
    $total_questions_scored = $score_data['total_questions'] ?? 0;
    $avg_answer_score = $total_questions_scored > 0 ? $total_answer_score / $total_questions_scored : 0;

    // Doğru çoktan seçmeli cevap sayısını hesapla
    $sql = "SELECT COUNT(*) as correct_count 
            FROM application_answers aa 
            JOIN options o ON aa.option_id = o.id 
            WHERE aa.application_id = $application_id AND o.is_correct = 1";
    $correct_result = $conn->query($sql);
    $correct_data = $correct_result->fetch_assoc();
    $correct_answers = $correct_data['correct_count'];

    // Toplam çoktan seçmeli soruları al
    $sql = "SELECT COUNT(*) as total_mc 
            FROM application_answers aa 
            JOIN questions q ON aa.question_id = q.id 
            WHERE aa.application_id = $application_id AND q.question_type = 'multiple_choice'";
    $mc_result = $conn->query($sql);
    $mc_data = $mc_result->fetch_assoc();
    $total_mc_questions = $mc_data['total_mc'];

    // Genel puanı hesapla
    $mc_weight = 0.7; // Çoktan seçmeli için %70 ağırlık
    $open_weight = 0.3; // Açık uçlu için %30 ağırlık

    $mc_score = $total_mc_questions > 0 ? ($correct_answers / $total_mc_questions) * 100 : 0;
    $open_score = $avg_answer_score;

    $overall_score = ($mc_score * $mc_weight) + ($open_score * $open_weight);
}

// Form işlemleri
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CV puanı güncelleme
    if (isset($_POST['action']) && $_POST['action'] === 'update_cv_score') {
        $cv_score = (int)$_POST['cv_score'];
        if ($cv_score < 0) $cv_score = 0;
        if ($cv_score > 100) $cv_score = 100;
        
        $stmt = $conn->prepare("UPDATE applications SET cv_score = ? WHERE id = ?");
        $stmt->bind_param("ii", $cv_score, $application_id);
        
        if ($stmt->execute()) {
            $application['cv_score'] = $cv_score;
            $success = "CV puanı başarıyla güncellendi.";
        } else {
            $error = "CV puanı güncellenirken hata oluştu.";
        }
    }
    
    // Durum güncelleme
    if (isset($_POST['action']) && $_POST['action'] === 'update_status') {
        $new_status = $_POST['status'];
        $allowed_statuses = ['new', 'reviewed', 'shortlisted', 'interviewed', 'hired', 'rejected'];
        
        if (in_array($new_status, $allowed_statuses)) {
            $stmt = $conn->prepare("UPDATE applications SET status = ? WHERE id = ?");
            $stmt->bind_param("si", $new_status, $application_id);
            
            if ($stmt->execute()) {
                $application['status'] = $new_status;
                $success = "Durum başarıyla güncellendi.";
            } else {
                $error = "Durum güncellenirken hata oluştu.";
            }
        }
    }
    
    // E-posta gönderme - artık mailto kullanılıyor, server-side gönderim kaldırıldı
    
    // Hızlı not ekleme
    if (isset($_POST['action']) && $_POST['action'] === 'quick_note') {
        $quick_note = trim($_POST['quick_note']);
        $current_note = $application['admin_note'] ?? '';
        $timestamp = date('d.m.Y H:i');
        $new_note = $current_note . "\n\n[$timestamp] $quick_note";
        
        $stmt = $conn->prepare("UPDATE applications SET admin_note = ? WHERE id = ?");
        $stmt->bind_param("si", $new_note, $application_id);
        
        if ($stmt->execute()) {
            $application['admin_note'] = $new_note;
            $success = "Not başarıyla eklendi.";
        } else {
            $error = "Not eklenirken hata oluştu.";
        }
    }
    
    // Açık uçlu yanıt puanı güncelleme
    if (isset($_POST['action']) && $_POST['action'] === 'update_answer_score') {
        $answer_id = (int)$_POST['answer_id'];
        $answer_score = (int)$_POST['answer_score'];
        
        if ($answer_score < 0) $answer_score = 0;
        if ($answer_score > 100) $answer_score = 100;
        
        $stmt = $conn->prepare("UPDATE application_answers SET answer_score = ? WHERE id = ?");
        $stmt->bind_param("ii", $answer_score, $answer_id);
        
        if ($stmt->execute()) {
            // Yanıtları yeniden yükle
            $sql = "SELECT aa.*, q.question_text, q.question_type, o.option_text, o.is_correct 
                   FROM application_answers aa 
                   JOIN questions q ON aa.question_id = q.id 
                   LEFT JOIN options o ON aa.option_id = o.id 
                   WHERE aa.application_id = $application_id 
                   ORDER BY q.id";
            $answers_result = $conn->query($sql);
            $success = "Yanıt puanı başarıyla güncellendi.";
        } else {
            $error = "Yanıt puanı güncellenirken hata oluştu.";
        }
    }
    
    // Not güncelleme
    if (isset($_POST['action']) && $_POST['action'] === 'update_note') {
        $admin_note = $_POST['admin_note'];
        
        $stmt = $conn->prepare("UPDATE applications SET admin_note = ? WHERE id = ?");
        $stmt->bind_param("si", $admin_note, $application_id);
        
        if ($stmt->execute()) {
            $application['admin_note'] = $admin_note;
            $success = "Not başarıyla kaydedildi.";
        } else {
            $error = "Not kaydedilirken hata oluştu.";
        }
    }
}

// Aktif istatistikleri hesapla
$new_applications = 0;
$reviewed_applications = 0;
$avg_cv_score = 0;
$total_cv_scores = 0;

if ($all_applications->num_rows > 0) {
    $all_applications->data_seek(0);
    while ($app = $all_applications->fetch_assoc()) {
        if ($app['status'] == 'new') {
            $new_applications++;
        } else {
            $reviewed_applications++;
        }
        
        if ($app['cv_score'] > 0) {
            $avg_cv_score += $app['cv_score'];
            $total_cv_scores++;
        }
    }
    
    if ($total_cv_scores > 0) {
        $avg_cv_score = round($avg_cv_score / $total_cv_scores);
    }
    
    // Verileri başa sar
    $all_applications->data_seek(0);
}

$total_applications = $new_applications + $reviewed_applications;
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $application_id > 0 ? "Aday Detayı" : "Aday Değerlendirme"; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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
            --chart-grid: rgba(0, 0, 0, 0.05);
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
        
        .breadcrumb {
            background-color: transparent;
            padding: 0;
            margin-bottom: 0;
            font-size: 0.9rem;
        }
        
        .breadcrumb-item + .breadcrumb-item::before {
            content: "›";
            color: var(--secondary);
            font-weight: 600;
        }
        
        .breadcrumb-item a {
            color: var(--secondary);
            text-decoration: none;
        }
        
        .breadcrumb-item a:hover {
            color: var(--primary);
        }
        
        .breadcrumb-item.active {
            color: var(--primary);
            font-weight: 500;
        }
        
        .date-filter-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.5rem 1rem;
            background-color: var(--light);
            border-radius: 20px;
            color: var(--secondary);
            font-size: 0.85rem;
            font-weight: 500;
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
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .card:hover {
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
        }
        
        .card-header {
            background-color: transparent;
            border-bottom: 1px solid var(--card-border);
            padding: 1rem 1.25rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .card-title {
            margin-bottom: 0;
            font-size: 1rem;
            font-weight: 600;
            color: #333;
        }
        
        .card-body {
            padding: 1.25rem;
        }
        
        /* Stat Cards */
        .stat-card {
            position: relative;
            padding: 1.25rem;
            background-color: #fff;
            border-radius: 10px;
            border-left: 4px solid var(--primary);
            box-shadow: var(--card-shadow);
            margin-bottom: 1.5rem;
            height: 100%;
        }
        
        .stat-primary {
            border-color: var(--primary);
        }
        
        .stat-success {
            border-color: var(--success);
        }
        
        .stat-info {
            border-color: var(--info);
        }
        
        .stat-warning {
            border-color: var(--warning);
        }
        
        .stat-danger {
            border-color: var(--danger);
        }
        
        .stat-card .stat-icon {
            width: 48px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            border-radius: 10px;
            position: absolute;
            top: 1.25rem;
            right: 1.25rem;
            opacity: 0.15;
        }
        
        .stat-card .stat-title {
            font-size: 0.875rem;
            color: var(--secondary);
            margin-bottom: 0.5rem;
            font-weight: 500;
        }
        
        .stat-card .stat-value {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .stat-card .stat-desc {
            font-size: 0.8rem;
            color: var(--secondary);
        }
        
        .stat-card.stat-primary .stat-icon {
            color: var(--primary);
        }
        
        .stat-card.stat-success .stat-icon {
            color: var(--success);
        }
        
        .stat-card.stat-info .stat-icon {
            color: var(--info);
        }
        
        .stat-card.stat-warning .stat-icon {
            color: var(--warning);
        }
        
        .stat-card.stat-danger .stat-icon {
            color: var(--danger);
        }

        /* Progress bar */
        .progress-bar-container {
            margin-bottom: 1rem;
        }
        
        .progress-bar-label {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
        }
        
        .progress-bar-label .label {
            font-size: 0.85rem;
            color: var(--dark);
        }
        
        .progress-bar-label .value {
            font-size: 0.85rem;
            color: var(--secondary);
        }
        
        .progress {
            height: 8px;
            border-radius: 4px;
            background-color: #f0f0f0;
        }
        
        .progress-bar {
            border-radius: 4px;
        }

        /* Tables */
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
            padding: 0.75rem;
        }
        
        .table-hover tbody tr:hover {
            background-color: rgba(0, 0, 0, 0.015);
        }

        /* Badges */
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

        /* Applicant card */
        .applicant-card {
            background-color: #fff;
            border-radius: 10px;
            box-shadow: var(--card-shadow);
            margin-bottom: 1rem;
            border: 1px solid var(--card-border);
            transition: all 0.2s;
            cursor: pointer;
        }
        
        .applicant-card:hover {
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            border-color: var(--primary);
        }

        /* Avatar */
        .avatar {
            width: 45px;
            height: 45px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            background-color: rgba(67, 97, 238, 0.1);
            color: var(--primary);
            font-weight: 600;
            font-size: 1rem;
        }

        /* Score badge */
        .score-badge {
            width: 45px;
            height: 45px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            background-color: rgba(52, 152, 219, 0.1);
            color: var(--info);
            font-weight: 700;
            font-size: 1.1rem;
        }

        .score-badge.score-good {
            background-color: rgba(46, 204, 113, 0.1);
            color: var(--success);
        }

        .score-badge.score-medium {
            background-color: rgba(243, 156, 18, 0.1);
            color: var(--warning);
        }

        .score-badge.score-poor {
            background-color: rgba(231, 76, 60, 0.1);
            color: var(--danger);
        }

        /* Info row */
        .info-row {
            margin-bottom: 1rem;
            display: flex;
            flex-wrap: wrap;
        }

        .info-label {
            color: var(--secondary);
            min-width: 140px;
            font-weight: 500;
            font-size: 0.85rem;
        }

        .info-value {
            font-weight: 600;
            font-size: 0.9rem;
        }

        /* Search input */
        .search-input {
            padding: 0.75rem 1rem;
            border-radius: 8px;
            border: 1px solid var(--card-border);
            width: 100%;
            background-color: white;
            box-shadow: var(--card-shadow);
        }

        .search-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(67, 97, 238, 0.1);
        }

        /* Buttons */
        .btn {
            border-radius: 8px;
            font-weight: 500;
            padding: 0.5rem 1.25rem;
            transition: all 0.2s;
        }

        .btn-primary {
            background-color: var(--primary);
            border-color: var(--primary);
        }

        .btn-primary:hover {
            background-color: var(--primary-hover);
            border-color: var(--primary-hover);
            box-shadow: 0 4px 10px rgba(67, 97, 238, 0.2);
        }

        .btn-outline-primary {
            color: var(--primary);
            border-color: var(--primary);
        }

        .btn-outline-primary:hover {
            background-color: var(--primary);
            border-color: var(--primary);
            color: white;
        }

        /* Forms */
        .form-control {
            padding: 0.75rem 1rem;
            border-radius: 8px;
            border: 1px solid var(--card-border);
            font-size: 0.9rem;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(67, 97, 238, 0.1);
        }

        /* Alerts */
        .alert {
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            border: none;
        }

        .alert-success {
            background-color: rgba(46, 204, 113, 0.1);
            color: var(--success);
        }

        .alert-danger {
            background-color: rgba(231, 76, 60, 0.1);
            color: var(--danger);
        }

        /* Modal */
        .modal-content {
            border-radius: 12px;
            border: none;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }

        .modal-header {
            background-color: #fff;
            border-bottom: 1px solid var(--card-border);
            padding: 1.25rem;
        }

        .modal-footer {
            background-color: #fff;
            border-top: 1px solid var(--card-border);
            padding: 1.25rem;
        }

        .modal-title {
            font-weight: 600;
        }

        /* PDF Viewer Styles */
        .pdf-viewer {
            width: 100%;
            min-height: 600px;
            border: 1px solid var(--card-border);
            border-radius: 8px;
        }
        
        .file-actions {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }
        
        .quick-actions {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .quick-actions .btn {
            margin: 0.25rem;
        }
        
        .status-selector {
            min-width: 150px;
        }
        
        .email-templates {
            margin-top: 1rem;
        }
        
        .template-btn {
            margin: 0.25rem;
            font-size: 0.8rem;
        }
        
        /* Enhanced navbar styles */
        .navbar {
            border-bottom: 1px solid var(--card-border);
        }
        
        .navbar .dropdown-menu {
            border: none;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            border-radius: 8px;
        }
        
        .navbar .dropdown-item {
            padding: 0.5rem 1rem;
            color: #666;
            transition: all 0.2s;
        }
        
        .navbar .dropdown-item:hover {
            background-color: var(--light);
            color: var(--primary);
        }
        
        .navbar .dropdown-item i {
            width: 20px;
            text-align: center;
        }
        
        /* File preview enhancements */
        .file-preview-container {
            position: relative;
            background-color: #f8f9fa;
            border: 2px dashed #dee2e6;
            border-radius: 8px;
            padding: 2rem;
            text-align: center;
            margin-bottom: 1rem;
        }
        
        .file-preview-container:hover {
            border-color: var(--primary);
            background-color: rgba(67, 97, 238, 0.05);
        }
        
        .file-icon {
            font-size: 3rem;
            color: var(--secondary);
            margin-bottom: 1rem;
        }
        
        /* Status indicators */
        .status-indicator {
            position: relative;
            display: inline-block;
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.8rem;
        }
        
        .status-new { background-color: rgba(52, 152, 219, 0.1); color: #3498db; }
        .status-reviewed { background-color: rgba(46, 204, 113, 0.1); color: #2ecc71; }
        .status-shortlisted { background-color: rgba(155, 89, 182, 0.1); color: #9b59b6; }
        .status-interviewed { background-color: rgba(243, 156, 18, 0.1); color: #f39c12; }
        .status-hired { background-color: rgba(39, 174, 96, 0.1); color: #27ae60; }
        .status-rejected { background-color: rgba(231, 76, 60, 0.1); color: #e74c3c; }
        
        /* Loading states */
        .loading {
            opacity: 0.6;
            pointer-events: none;
        }
        
        .loading::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 20px;
            height: 20px;
            margin: -10px 0 0 -10px;
            border: 2px solid var(--primary);
            border-top: 2px solid transparent;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Responsive improvements */
        @media (max-width: 768px) {
            .file-actions {
                flex-direction: column;
                gap: 0.25rem;
            }
            
            .quick-actions {
                padding: 0.75rem;
            }
            
            .quick-actions .d-flex {
                flex-direction: column;
                gap: 1rem;
            }
            
            .status-selector {
                min-width: auto;
                width: 100%;
            }
        }
        
        /* Enhanced tooltips */
        .tooltip-info {
            position: relative;
            cursor: help;
        }
        
        .tooltip-info:hover::after {
            content: attr(data-tooltip);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            background-color: #333;
            color: white;
            padding: 0.5rem;
            border-radius: 4px;
            font-size: 0.8rem;
            white-space: nowrap;
            z-index: 1000;
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
                            <li><a class="dropdown-item" href="application-detail2.php"><i class="bi bi-search me-2"></i>Aday Değerlendirme</a></li>
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
                    <!-- Candidate Actions (only when viewing specific application) -->
                    <?php if ($application_id > 0): ?>
                    <li class="nav-item dropdown me-2">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-person-gear me-1"></i>Aday İşlemleri
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#emailModal"><i class="bi bi-envelope me-2"></i>E-posta Gönder</a></li>
                            <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#quickNoteModal"><i class="bi bi-sticky-note me-2"></i>Hızlı Not</a></li>
                            <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#cvScoreModal"><i class="bi bi-star me-2"></i>CV Puanla</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="../<?php echo htmlspecialchars($application['cv_path']); ?>" target="_blank"><i class="bi bi-download me-2"></i>CV İndir</a></li>
                        </ul>
                    </li>
                    <?php endif; ?>

                    <!-- User menu -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" role="button" data-bs-toggle="dropdown">
                            <div class="rounded-circle bg-primary d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">
                                <i class="bi bi-person-fill text-white"></i>
                            </div>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>Çıkış Yap</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Page Header -->
    <div class="page-header">
        <div class="container">
            <?php if ($application_id > 0): ?>
                <!-- Breadcrumb -->
                <nav aria-label="breadcrumb" class="mb-3">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="dashboard.php"><i class="fas fa-home"></i></a></li>
                        <li class="breadcrumb-item"><a href="application-detail2.php">Aday Değerlendirme</a></li>
                        <li class="breadcrumb-item active" aria-current="page">
                            <?php echo htmlspecialchars($application['first_name'] . ' ' . $application['last_name']); ?>
                        </li>
                    </ol>
                </nav>
                
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h1 class="page-title">
                            <?php echo htmlspecialchars($application['first_name'] . ' ' . $application['last_name']); ?>
                            <span class="status-indicator status-<?php echo $application['status']; ?>">
                                <?php 
                                $status_labels = [
                                    'new' => 'Yeni Başvuru',
                                    'reviewed' => 'İncelendi',
                                    'shortlisted' => 'Kısa Liste',
                                    'interviewed' => 'Mülakat Yapıldı',
                                    'hired' => 'İşe Alındı',
                                    'rejected' => 'Reddedildi'
                                ];
                                echo $status_labels[$application['status']] ?? 'Bilinmeyen';
                                ?>
                            </span>
                        </h1>
                        <p class="page-subtitle">
                            <?php echo htmlspecialchars($application['job_title']); ?> pozisyonu için başvuru
                            <span class="text-muted ms-2">
                                <i class="fas fa-calendar me-1"></i>
                                <?php echo date('d.m.Y H:i', strtotime($application['created_at'])); ?>
                            </span>
                        </p>
                    </div>
                    <div class="d-flex gap-2">
                        <a href="application-detail2.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-1"></i>Geri Dön
                        </a>
                        <div class="dropdown">
                            <button class="btn btn-primary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                <i class="fas fa-cog me-1"></i>İşlemler
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#emailModal">
                                    <i class="fas fa-envelope me-2"></i>E-posta Gönder
                                </a></li>
                                <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#quickNoteModal">
                                    <i class="fas fa-sticky-note me-2"></i>Hızlı Not
                                </a></li>
                                <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#cvScoreModal">
                                    <i class="fas fa-star me-2"></i>CV Puanla
                                </a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="../<?php echo htmlspecialchars($application['cv_path']); ?>" target="_blank">
                                    <i class="fas fa-download me-2"></i>CV İndir
                                </a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <!-- Breadcrumb -->
                <nav aria-label="breadcrumb" class="mb-3">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="dashboard.php"><i class="fas fa-home"></i></a></li>
                        <li class="breadcrumb-item active" aria-current="page">Aday Değerlendirme</li>
                    </ol>
                </nav>
                
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h1 class="page-title">Aday Değerlendirme</h1>
                        <p class="page-subtitle">Tüm başvuruları yönetin ve değerlendirin</p>
                    </div>
                    <div class="d-flex gap-2">
                        <span class="date-filter-badge">
                            <i class="fas fa-calendar me-2"></i>Son 30 gün
                        </span>
                        <div class="dropdown">
                            <button class="btn btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                <i class="fas fa-filter me-1"></i>Filtreler
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="#" onclick="filterByStatus('all')">Tüm Durumlar</a></li>
                                <li><a class="dropdown-item" href="#" onclick="filterByStatus('new')">Yeni Başvurular</a></li>
                                <li><a class="dropdown-item" href="#" onclick="filterByStatus('reviewed')">İncelenenler</a></li>
                                <li><a class="dropdown-item" href="#" onclick="filterByStatus('shortlisted')">Kısa Liste</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="container">
        <?php if (!empty($success)): ?>
            <div class="alert alert-success" role="alert">
                <i class="fas fa-check-circle me-2"></i> <?php echo $success; ?>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i> <?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($application_id > 0): ?>
            <!-- ADAY DETAY GÖRÜNÜMÜ -->
            <!-- Kişisel Bilgiler ve Not -->
            <div class="row">
                <!-- Quick Actions Panel -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="quick-actions">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="mb-0"><i class="fas fa-bolt me-2"></i>Hızlı İşlemler</h6>
                            <div class="d-flex gap-2">
                                <select class="form-select status-selector" onchange="updateStatus(this.value)">
                                    <option value="">Durum Güncelle</option>
                                    <option value="new" <?php echo $application['status'] == 'new' ? 'selected' : ''; ?>>Yeni</option>
                                    <option value="reviewed" <?php echo $application['status'] == 'reviewed' ? 'selected' : ''; ?>>İncelendi</option>
                                    <option value="shortlisted" <?php echo $application['status'] == 'shortlisted' ? 'selected' : ''; ?>>Kısa Liste</option>
                                    <option value="interviewed" <?php echo $application['status'] == 'interviewed' ? 'selected' : ''; ?>>Mülakat Yapıldı</option>
                                    <option value="hired" <?php echo $application['status'] == 'hired' ? 'selected' : ''; ?>>İşe Alındı</option>
                                    <option value="rejected" <?php echo $application['status'] == 'rejected' ? 'selected' : ''; ?>>Reddedildi</option>
                                </select>
                                <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#emailModal">
                                    <i class="fas fa-envelope me-1"></i>E-posta Gönder
                                </button>
                                <button class="btn btn-outline-success btn-sm" data-bs-toggle="modal" data-bs-target="#quickNoteModal">
                                    <i class="fas fa-sticky-note me-1"></i>Hızlı Not
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Kişisel Bilgiler -->
                <div class="col-lg-8 mb-4">
                    <div class="card">
                        <div class="card-header">
                            <span class="card-title"><i class="fas fa-user me-2"></i>Kişisel Bilgiler</span>
                            <div class="file-actions">
                                <button class="btn btn-outline-primary btn-sm" onclick="viewPDF('../<?php echo htmlspecialchars($application['cv_path']); ?>', 'CV')">
                                    <i class="fas fa-eye me-1"></i>CV Görüntüle
                                </button>
                                <a href="../<?php echo htmlspecialchars($application['cv_path']); ?>" class="btn btn-primary btn-sm" target="_blank">
                                    <i class="fas fa-file-download me-1"></i>CV İndir
                                </a>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="info-row">
                                        <div class="info-label">E-posta:</div>
                                        <div class="info-value"><?php echo htmlspecialchars($application['email']); ?></div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Telefon:</div>
                                        <div class="info-value"><?php echo htmlspecialchars($application['phone']); ?></div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Cinsiyet:</div>
                                        <div class="info-value">
                                            <?php echo $application['gender'] == 'male' ? 'Erkek' : ($application['gender'] == 'female' ? 'Kadın' : 'Diğer'); ?>
                                        </div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Yaş:</div>
                                        <div class="info-value"><?php echo htmlspecialchars($application['age']); ?></div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="info-row">
                                        <div class="info-label">Şehir:</div>
                                        <div class="info-value"><?php echo htmlspecialchars($application['city']); ?></div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Eğitim:</div>
                                        <div class="info-value"><?php echo htmlspecialchars($application['education']); ?></div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Deneyim:</div>
                                        <div class="info-value"><?php echo $application['experience'] ?? 0; ?> yıl</div>
                                    </div>
                                    <div class="info-row">
                                        <div class="info-label">Maaş Beklentisi:</div>
                                        <div class="info-value"><?php echo number_format($application['salary_expectation'], 0, ',', '.'); ?> ₺</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Notlar -->
                <div class="col-lg-4 mb-4">
                    <div class="card">
                        <div class="card-header">
                            <span class="card-title"><i class="fas fa-sticky-note me-2"></i>Notlar</span>
                            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#noteModal">
                                <i class="fas fa-edit me-1"></i>Düzenle
                            </button>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($application['admin_note'])): ?>
                                <?php echo nl2br(htmlspecialchars($application['admin_note'])); ?>
                            <?php else: ?>
                                <p class="text-muted text-center">Henüz not eklenmemiş</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Performans Özeti -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <span class="card-title"><i class="fas fa-chart-bar me-2"></i>Performans Özeti</span>
                            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#cvScoreModal">
                                <i class="fas fa-star me-1"></i>CV Puanla
                            </button>
                        </div>
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col-md-3 mb-4 mb-md-0 text-center">
                                    <?php
                                        $score_class = 'score-badge';
                                        if ($overall_score >= 80) {
                                            $score_class .= ' score-good';
                                            $score_text = 'Çok iyi';
                                        } else if ($overall_score >= 50) {
                                            $score_class .= ' score-medium';
                                            $score_text = 'Orta';
                                        } else {
                                            $score_class .= ' score-poor';
                                            $score_text = 'Geliştirilebilir';
                                        }
                                    ?>
                                    <div class="score-badge" style="width: 100px; height: 100px; font-size: 2rem; margin: 0 auto;">
                                        <?php echo round($overall_score); ?>
                                    </div>
                                    <p class="mt-2 mb-0 fw-semibold"><?php echo $score_text; ?></p>
                                    <p class="text-muted small">Genel Puan</p>
                                </div>
                                <div class="col-md-9">
                                    <div class="progress-bar-container">
                                        <div class="progress-bar-label">
                                            <span class="label">Test Puanı</span>
                                            <span class="value"><?php echo round($mc_score); ?>%</span>
                                        </div>
                                        <div class="progress">
                                            <div class="progress-bar bg-primary" style="width: <?php echo $mc_score; ?>%"></div>
                                        </div>
                                        <div class="text-end text-muted small mt-1">
                                            <?php echo $correct_answers; ?> doğru / <?php echo $total_mc_questions; ?> soru
                                        </div>
                                    </div>
                                    
                                    <div class="progress-bar-container">
                                        <div class="progress-bar-label">
                                            <span class="label">Açık Uçlu Sorular</span>
                                            <span class="value"><?php echo round($open_score); ?>%</span>
                                        </div>
                                        <div class="progress">
                                            <div class="progress-bar bg-success" style="width: <?php echo $open_score; ?>%"></div>
                                        </div>
                                    </div>
                                    
                                    <div class="progress-bar-container">
                                        <div class="progress-bar-label">
                                            <span class="label">CV Puanı</span>
                                            <span class="value"><?php echo $application['cv_score']; ?>%</span>
                                        </div>
                                        <div class="progress">
                                            <div class="progress-bar bg-info" style="width: <?php echo $application['cv_score']; ?>%"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Test Cevapları -->
            <div class="card mb-4">
                <div class="card-header">
                    <span class="card-title"><i class="fas fa-list-check me-2"></i>Test Cevapları</span>
                </div>
                <div class="card-body">
                    <?php if ($answers_result->num_rows > 0): ?>
                        <div class="mb-4">
                            <h5 class="fw-semibold mb-3">Çoktan Seçmeli Sorular</h5>
                            
                            <?php
                            $question_counter = 1;
                            $answers_result->data_seek(0);
                            while ($answer = $answers_result->fetch_assoc()):
                                if ($answer['question_type'] == 'multiple_choice'):
                                    $is_correct = $answer['is_correct'] == 1;
                            ?>
                                <div class="card mb-3" style="border-left: 4px solid <?php echo $is_correct ? '#2ecc71' : '#e74c3c'; ?>">
                                    <div class="card-body py-3">
                                        <p class="fw-semibold mb-2"><?php echo $question_counter++; ?>. <?php echo htmlspecialchars($answer['question_text']); ?></p>
                                        <div class="d-flex align-items-center">
                                            <span class="me-2"><?php echo $is_correct ? '<i class="fas fa-check-circle text-success"></i>' : '<i class="fas fa-times-circle text-danger"></i>'; ?></span>
                                            <span><?php echo htmlspecialchars($answer['option_text']); ?></span>
                                            <span class="ms-auto status-badge <?php echo $is_correct ? 'badge-reviewed' : 'badge-new'; ?>">
                                                <?php echo $is_correct ? 'Doğru' : 'Yanlış'; ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            <?php
                                endif;
                            endwhile;
                            ?>
                        </div>
                        
                        <div>
                            <h5 class="fw-semibold mb-3">Açık Uçlu Sorular</h5>
                            
                            <?php
                            $question_counter = 1;
                            $answers_result->data_seek(0);
                            while ($answer = $answers_result->fetch_assoc()):
                                if ($answer['question_type'] == 'open_ended'):
                            ?>
                                <div class="card mb-4">
                                    <div class="card-header">
                                        <span class="card-title"><?php echo $question_counter++; ?>. <?php echo htmlspecialchars($answer['question_text']); ?></span>
                                    </div>
                                    <div class="card-body">
                                        <?php if (!empty($answer['answer_text'])): ?>
                                            <div class="mb-3">
                                                <p class="fw-semibold text-primary mb-2">Cevap:</p>
                                                <div class="p-3 bg-light rounded">
                                                    <?php echo nl2br(htmlspecialchars($answer['answer_text'])); ?>
                                                </div>
                                            </div>
                                        <?php elseif (!empty($answer['answer_file_path'])): ?>
                                            <div class="mb-3">
                                                <p class="fw-semibold text-primary mb-2">Dosya Cevabı:</p>
                                                <div class="file-actions">
                                                    <button class="btn btn-outline-primary btn-sm" onclick="viewPDF('../<?php echo htmlspecialchars($answer['answer_file_path']); ?>', 'Cevap Dosyası')">
                                                        <i class="fas fa-eye me-1"></i>Görüntüle
                                                    </button>
                                                    <a href="../<?php echo htmlspecialchars($answer['answer_file_path']); ?>" class="btn btn-outline-secondary btn-sm" target="_blank">
                                                        <i class="fas fa-file-alt me-1"></i>İndir
                                                    </a>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <p class="text-muted">Bu soru henüz cevaplanmamış.</p>
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($answer['answer_feedback'])): ?>
                                            <div class="mt-3 p-3 bg-light rounded">
                                                <p class="fw-semibold text-primary mb-2">Değerlendirme:</p>
                                                <?php echo nl2br(htmlspecialchars($answer['answer_feedback'])); ?>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div class="mt-4 pt-3 border-top">
                                            <form action="" method="post">
                                                <input type="hidden" name="action" value="update_answer_score">
                                                <input type="hidden" name="answer_id" value="<?php echo $answer['id']; ?>">
                                                <div class="mb-3">
                                                    <div class="progress-bar-label">
                                                        <span class="label">Cevap Puanı</span>
                                                        <span class="value" id="answer_score_display_<?php echo $answer['id']; ?>"><?php echo $answer['answer_score'] ?? 0; ?></span>
                                                    </div>
                                                    <input type="range" class="form-range" min="0" max="100" step="5" 
                                                           id="answer_score_<?php echo $answer['id']; ?>" 
                                                           name="answer_score" value="<?php echo $answer['answer_score'] ?? 0; ?>">
                                                </div>
                                                <div class="text-end">
                                                    <button type="submit" class="btn btn-primary">
                                                        <i class="fas fa-save me-1"></i>Puanı Kaydet
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                
                                <script>
                                    document.getElementById('answer_score_<?php echo $answer['id']; ?>').addEventListener('input', function() {
                                        document.getElementById('answer_score_display_<?php echo $answer['id']; ?>').textContent = this.value;
                                    });
                                </script>
                            <?php
                                endif;
                            endwhile;
                            ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <i class="fas fa-clipboard-list text-muted" style="font-size: 3rem;"></i>
                            <p class="mt-3 text-muted">Bu başvuru için henüz yanıtlanmış soru bulunmamaktadır.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- CV Değerlendirmesi -->
            <div class="card">
                <div class="card-header">
                    <span class="card-title"><i class="fas fa-file-alt me-2"></i>CV Değerlendirmesi</span>
                </div>
                <div class="card-body">
                    <?php if (!empty($application['cv_feedback'])): ?>
                        <?php echo nl2br(htmlspecialchars($application['cv_feedback'])); ?>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <i class="fas fa-file-alt text-muted" style="font-size: 3rem;"></i>
                            <p class="mt-3 text-muted">Bu adayın CV'si için henüz değerlendirme yapılmamıştır.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        <?php else: ?>
            <!-- ADAY SEÇİM BÖLÜMÜ -->
            <!-- İstatistikler -->
            <div class="row mb-4">
                <div class="col-lg-3 col-md-6 mb-4 mb-lg-0">
                    <div class="stat-card stat-primary">
                        <div class="stat-title">Toplam Başvuru</div>
                        <div class="stat-value"><?php echo $total_applications; ?></div>
                        <div class="stat-desc">Son 30 gündeki başvurular</div>
                        <div class="stat-icon">
                            <i class="fas fa-users"></i>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-3 col-md-6 mb-4 mb-lg-0">
                    <div class="stat-card stat-info">
                        <div class="stat-title">Yeni Başvurular</div>
                        <div class="stat-value"><?php echo $new_applications; ?></div>
                        <div class="stat-desc">Değerlendirilmeyi bekleyen</div>
                        <div class="stat-icon">
                            <i class="fas fa-file-alt"></i>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-3 col-md-6 mb-4 mb-md-0">
                    <div class="stat-card stat-success">
                        <div class="stat-title">Değerlendirilmiş</div>
                        <div class="stat-value"><?php echo $reviewed_applications; ?></div>
                        <div class="stat-desc">İncelenmiş başvurular</div>
                        <div class="stat-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-3 col-md-6">
                    <div class="stat-card stat-warning">
                        <div class="stat-title">Ortalama CV Puanı</div>
                        <div class="stat-value"><?php echo $avg_cv_score; ?></div>
                        <div class="stat-desc">Değerlendirilen CV'lerde</div>
                        <div class="stat-icon">
                            <i class="fas fa-star"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Arama ve Filtreleme -->
            <div class="row mb-4">
                <div class="col-md-8 mb-3 mb-md-0">
                    <input type="text" class="search-input" id="searchApplicants" placeholder="Aday ara (isim, pozisyon, e-posta...)">
                </div>
                <div class="col-md-4">
                    <div class="d-flex justify-content-md-end">
                        <div class="dropdown me-2">
                            <button class="btn btn-outline-secondary dropdown-toggle" type="button" id="filterDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fas fa-filter me-1"></i> Filtrele
                            </button>
                            <div class="dropdown-menu p-3" style="width: 250px;">
                                <h6 class="dropdown-header fw-bold">Filtreleme Seçenekleri</h6>
                                <div class="mb-3">
                                    <label class="form-label">Durum</label>
                                    <select class="form-select" id="statusFilter">
                                        <option value="all">Tümü</option>
                                        <option value="new">Yeni Başvurular</option>
                                        <option value="reviewed">İncelenenler</option>
                                    </select>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Pozisyon</label>
                                    <select class="form-select" id="jobFilter">
                                        <option value="all">Tüm Pozisyonlar</option>
                                        <?php 
                                        if ($jobs_result->num_rows > 0) {
                                            $jobs_result->data_seek(0);
                                            while ($job = $jobs_result->fetch_assoc()): 
                                        ?>
                                            <option value="<?php echo $job['id']; ?>"><?php echo htmlspecialchars($job['title']); ?></option>
                                        <?php 
                                            endwhile;
                                        }
                                        ?>
                                    </select>
                                </div>
                                <button class="btn btn-primary btn-sm w-100" id="applyFilters">Uygula</button>
                            </div>
                        </div>
                        <div class="dropdown">
                            <button class="btn btn-outline-secondary dropdown-toggle" type="button" id="sortDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fas fa-sort me-1"></i> Sırala
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="#" data-sort="new">En Yeni</a></li>
                                <li><a class="dropdown-item" href="#" data-sort="old">En Eski</a></li>
                                <li><a class="dropdown-item" href="#" data-sort="name">İsim (A-Z)</a></li>
                                <li><a class="dropdown-item" href="#" data-sort="score-desc">Puan (Yüksek-Düşük)</a></li>
                                <li><a class="dropdown-item" href="#" data-sort="score-asc">Puan (Düşük-Yüksek)</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Adaylar Listesi -->
            <div class="card">
                <div class="card-header">
                    <span class="card-title"><i class="fas fa-users me-2"></i>Adaylar</span>
                </div>
                <div class="card-body p-0">
                    <?php if ($all_applications->num_rows > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Aday</th>
                                        <th>Pozisyon</th>
                                        <th>Durum</th>
                                        <th>Test Puanı</th>
                                        <th>CV Puanı</th>
                                        <th class="text-end">İşlem</th>
                                    </tr>
                                </thead>
                                <tbody id="applicantsList">
                                    <?php while($app = $all_applications->fetch_assoc()): ?>
                                    <tr class="applicant-row" data-name="<?php echo strtolower($app['first_name'] . ' ' . $app['last_name']); ?>" data-status="<?php echo $app['status']; ?>">
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="avatar me-2">
                                                    <?php echo strtoupper(substr($app['first_name'], 0, 1) . substr($app['last_name'], 0, 1)); ?>
                                                </div>
                                                <div>
                                                    <h6 class="mb-0"><?php echo htmlspecialchars($app['first_name'] . ' ' . $app['last_name']); ?></h6>
                                                    <small class="text-muted"><?php echo htmlspecialchars($app['email']); ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($app['job_title']); ?></td>
                                        <td>
                                            <span class="status-badge <?php echo $app['status'] == 'new' ? 'badge-new' : 'badge-reviewed'; ?>">
                                                <?php echo $app['status'] == 'new' ? 'Yeni Başvuru' : 'İncelendi'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php
                                                $score_class = '';
                                                if ($app['score'] >= 80) $score_class = 'text-success';
                                                else if ($app['score'] >= 50) $score_class = 'text-warning';
                                                else $score_class = 'text-danger';
                                            ?>
                                            <span class="fw-semibold <?php echo $score_class; ?>"><?php echo $app['score']; ?></span>
                                        </td>
                                        <td>
                                            <?php
                                                $cv_score_class = '';
                                                if ($app['cv_score'] >= 80) $cv_score_class = 'text-success';
                                                else if ($app['cv_score'] >= 50) $cv_score_class = 'text-warning';
                                                else $cv_score_class = 'text-danger';
                                            ?>
                                            <span class="fw-semibold <?php echo $cv_score_class; ?>"><?php echo $app['cv_score']; ?></span>
                                        </td>
                                        <td class="text-end">
                                            <a href="?id=<?php echo $app['id']; ?>" class="btn btn-sm btn-primary">
                                                <i class="fas fa-eye me-1"></i>Detay
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-5">
                            <i class="fas fa-clipboard-list text-muted mb-3" style="font-size: 3rem;"></i>
                            <h5>Henüz başvuru bulunmuyor</h5>
                            <p class="text-muted">Sistemde aday kaydı bulunmamaktadır.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="text-center py-3 text-muted mt-4">
                <small>© 2025 Aday Değerlendirme Sistemi | safagoek tarafından geliştirildi</small>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Not Ekleme/Düzenleme Modal -->
    <div class="modal fade" id="noteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-sticky-note me-2"></i>Not Ekle/Düzenle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" action="">
                    <input type="hidden" name="action" value="update_note">
                    <div class="modal-body">
                        <div class="mb-0">
                            <label for="admin_note" class="form-label">Not</label>
                            <textarea class="form-control" id="admin_note" name="admin_note" rows="6" placeholder="Aday hakkında notlarınızı buraya ekleyin..."><?php echo htmlspecialchars($application['admin_note'] ?? ''); ?></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-primary">Kaydet</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- CV Puanlama Modal -->
    <div class="modal fade" id="cvScoreModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-file-alt me-2"></i>CV Puanı</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" action="">
                    <input type="hidden" name="action" value="update_cv_score">
                    <div class="modal-body">
                        <div class="mb-0">
                            <div class="progress-bar-label">
                                <span class="label">CV Puanı</span>
                                <span class="value" id="cv_score_display"><?php echo $application['cv_score'] ?? 0; ?></span>
                            </div>
                            <input type="range" class="form-range" min="0" max="100" step="5" 
                                   id="cv_score" name="cv_score" value="<?php echo $application['cv_score'] ?? 0; ?>">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-primary">Kaydet</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- E-posta Gönderme Modal -->
    <div class="modal fade" id="emailModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-envelope me-2"></i>E-posta Gönder</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="email_recipient" class="form-label">Alıcı</label>
                        <input type="email" class="form-control" id="email_recipient" value="<?php echo htmlspecialchars($application['email'] ?? ''); ?>" readonly>
                    </div>
                    <div class="mb-3">
                        <label for="email_subject" class="form-label">Konu</label>
                        <input type="text" class="form-control" id="email_subject" placeholder="E-posta konusunu yazın">
                    </div>
                    <div class="mb-3">
                        <label for="email_message" class="form-label">Mesaj</label>
                        <textarea class="form-control" id="email_message" rows="6" placeholder="E-posta mesajınızı yazın"></textarea>
                    </div>
                    <div class="email-templates">
                        <h6>Hızlı Şablonlar:</h6>
                        <button type="button" class="btn btn-outline-secondary template-btn" onclick="useTemplate('interview')">Mülakat Daveti</button>
                        <button type="button" class="btn btn-outline-secondary template-btn" onclick="useTemplate('rejection')">Red Bildirimi</button>
                        <button type="button" class="btn btn-outline-secondary template-btn" onclick="useTemplate('acceptance')">Kabul Bildirimi</button>
                        <button type="button" class="btn btn-outline-secondary template-btn" onclick="useTemplate('info_request')">Bilgi Talebi</button>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">İptal</button>
                    <button type="button" class="btn btn-primary" onclick="sendEmailViaClient()">
                        <i class="fas fa-external-link-alt me-1"></i>E-posta Uygulamasında Aç
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Hızlı Not Modal -->
    <div class="modal fade" id="quickNoteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-sticky-note me-2"></i>Hızlı Not Ekle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" action="">
                    <input type="hidden" name="action" value="quick_note">
                    <div class="modal-body">
                        <div class="mb-0">
                            <label for="quick_note" class="form-label">Not</label>
                            <textarea class="form-control" id="quick_note" name="quick_note" rows="4" placeholder="Hızlı notunuzu buraya yazın..." required></textarea>
                            <div class="form-text">Bu not mevcut notlara zaman damgası ile eklenecektir.</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-light" data-bs-dismiss="modal">İptal</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-plus me-1"></i>Ekle
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- PDF Görüntüleyici Modal -->
    <div class="modal fade" id="pdfViewerModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-fullscreen">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="pdfViewerTitle"><i class="fas fa-file-pdf me-2"></i>PDF Görüntüleyici</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-0">
                    <iframe id="pdfViewer" class="pdf-viewer" src="" style="width: 100%; height: 80vh; border: none;"></iframe>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Kapat</button>
                    <a id="pdfDownloadBtn" href="" target="_blank" class="btn btn-primary">
                        <i class="fas fa-download me-1"></i>İndir
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // E-posta şablonları
        const emailTemplates = {
            interview: {
                subject: "Mülakat Daveti - <?php echo htmlspecialchars($application['job_title'] ?? ''); ?>",
                message: "Sayın <?php echo htmlspecialchars($application['first_name'] ?? ''); ?> <?php echo htmlspecialchars($application['last_name'] ?? ''); ?>,\n\n<?php echo htmlspecialchars($application['job_title'] ?? ''); ?> pozisyonu için yaptığınız başvuru değerlendirilmiş olup, mülakata davet ediliyorsunuz.\n\nMülakat tarihi ve saati hakkında kısa süre içinde bilgilendirileceksiniz.\n\nTeşekkür ederiz."
            },
            rejection: {
                subject: "Başvuru Değerlendirme Sonucu - <?php echo htmlspecialchars($application['job_title'] ?? ''); ?>",
                message: "Sayın <?php echo htmlspecialchars($application['first_name'] ?? ''); ?> <?php echo htmlspecialchars($application['last_name'] ?? ''); ?>,\n\n<?php echo htmlspecialchars($application['job_title'] ?? ''); ?> pozisyonu için yaptığınız başvuru değerlendirilmiştir.\n\nMaalesef bu sefer profilimizle tam uyuşmamakla birlikte, başvurunuz için teşekkür ederiz.\n\nİleriki fırsatlarda değerlendirme yapabilmek üzere bilgilerinizi sistemimizde saklayacağız.\n\nSaygılarımızla."
            },
            acceptance: {
                subject: "Tebrikler! İşe Alım - <?php echo htmlspecialchars($application['job_title'] ?? ''); ?>",
                message: "Sayın <?php echo htmlspecialchars($application['first_name'] ?? ''); ?> <?php echo htmlspecialchars($application['last_name'] ?? ''); ?>,\n\nTebrikler! <?php echo htmlspecialchars($application['job_title'] ?? ''); ?> pozisyonu için değerlendirme sürecimizde başarılı olduğunuzu bildirmekten mutluluk duyuyoruz.\n\nİşe başlama tarihi ve diğer detaylar hakkında kısa süre içinde İK departmanımız sizinle iletişime geçecektir.\n\nEkibimize katıldığınız için şimdiden hoş geldiniz!"
            },
            info_request: {
                subject: "Ek Bilgi Talebi - <?php echo htmlspecialchars($application['job_title'] ?? ''); ?>",
                message: "Sayın <?php echo htmlspecialchars($application['first_name'] ?? ''); ?> <?php echo htmlspecialchars($application['last_name'] ?? ''); ?>,\n\n<?php echo htmlspecialchars($application['job_title'] ?? ''); ?> pozisyonu için yaptığınız başvuru değerlendirme aşamasındadır.\n\nDeğerlendirme sürecini tamamlayabilmek için bazı ek bilgilere ihtiyaç duymaktayız.\n\nLütfen en kısa sürede bizimle iletişime geçiniz.\n\nTeşekkür ederiz."
            }
        };
        
        // E-posta şablonu kullan
        function useTemplate(templateType) {
            const template = emailTemplates[templateType];
            if (template) {
                document.getElementById('email_subject').value = template.subject;
                document.getElementById('email_message').value = template.message;
            }
        }
        
        // E-posta uygulamasında aç (mailto kullanarak)
        function sendEmailViaClient() {
            const recipient = document.getElementById('email_recipient').value;
            const subject = document.getElementById('email_subject').value;
            const message = document.getElementById('email_message').value;
            
            if (!subject.trim()) {
                alert('Lütfen e-posta konusunu giriniz.');
                return;
            }
            
            if (!message.trim()) {
                alert('Lütfen e-posta mesajını giriniz.');
                return;
            }
            
            // E-posta içeriğini hazırla
            const emailBody = `Sayın <?php echo htmlspecialchars($application['first_name'] ?? ''); ?> <?php echo htmlspecialchars($application['last_name'] ?? ''); ?>,\n\n${message}\n\nİyi günler dileriz,\nİK Departmanı`;
            
            // Mailto linkini oluştur
            const mailtoLink = `mailto:${encodeURIComponent(recipient)}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(emailBody)}`;
            
            // E-posta uygulamasını aç
            window.location.href = mailtoLink;
            
            // Modal'ı kapat
            const modal = bootstrap.Modal.getInstance(document.getElementById('emailModal'));
            if (modal) {
                modal.hide();
            }
            
            // Form alanlarını temizle
            document.getElementById('email_subject').value = '';
            document.getElementById('email_message').value = '';
        }
        
        // PDF görüntüleyici
        function viewPDF(filePath, title) {
            document.getElementById('pdfViewerTitle').innerHTML = '<i class="fas fa-file-pdf me-2"></i>' + title;
            document.getElementById('pdfViewer').src = filePath;
            document.getElementById('pdfDownloadBtn').href = filePath;
            
            const modal = new bootstrap.Modal(document.getElementById('pdfViewerModal'));
            modal.show();
        }
        
        // Durum güncelleme
        function updateStatus(status) {
            if (status && confirm('Adayın durumunu güncellemek istediğinizden emin misiniz?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="status" value="${status}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        // CV puanı için range değerini göster
        document.getElementById('cv_score')?.addEventListener('input', function() {
            document.getElementById('cv_score_display').textContent = this.value;
        });
        
        // Sayfa yüklendiğinde PDF görüntüleyici modal'ını temizle
        document.addEventListener('DOMContentLoaded', function() {
            const pdfModal = document.getElementById('pdfViewerModal');
            if (pdfModal) {
                pdfModal.addEventListener('hidden.bs.modal', function() {
                    document.getElementById('pdfViewer').src = '';
                });
            }
            
            // Form gönderimi sırasında yükleme animasyonu
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function() {
                    const submitBtn = form.querySelector('button[type="submit"]');
                    if (submitBtn) {
                        submitBtn.disabled = true;
                        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Gönderiliyor...';
                    }
                });
            });
        });
        
        // Dosya uzantısına göre icon belirleme
        function getFileIcon(fileName) {
            const extension = fileName.split('.').pop().toLowerCase();
            switch (extension) {
                case 'pdf':
                    return 'fas fa-file-pdf text-danger';
                case 'doc':
                case 'docx':
                    return 'fas fa-file-word text-primary';
                case 'jpg':
                case 'jpeg':
                case 'png':
                case 'gif':
                    return 'fas fa-file-image text-success';
                default:
                    return 'fas fa-file text-secondary';
            }
        }
        
        // Auto-save functionality for notes
        let noteTimeout;
        const noteTextarea = document.getElementById('admin_note');
        if (noteTextarea) {
            noteTextarea.addEventListener('input', function() {
                clearTimeout(noteTimeout);
                noteTimeout = setTimeout(() => {
                    // Auto-save note after 3 seconds of inactivity
                    console.log('Auto-saving note...');
                }, 3000);
            });
        }
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl/Cmd + E for email modal
            if ((e.ctrlKey || e.metaKey) && e.key === 'e') {
                e.preventDefault();
                const emailModal = new bootstrap.Modal(document.getElementById('emailModal'));
                emailModal.show();
            }
            
            // Ctrl/Cmd + N for quick note modal
            if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
                e.preventDefault();
                const noteModal = new bootstrap.Modal(document.getElementById('quickNoteModal'));
                noteModal.show();
            }
        });
        
        // Status change confirmation with animation
        function updateStatus(status) {
            if (!status) return;
            
            const statusNames = {
                'new': 'Yeni',
                'reviewed': 'İncelendi',
                'shortlisted': 'Kısa Liste',
                'interviewed': 'Mülakat Yapıldı',
                'hired': 'İşe Alındı',
                'rejected': 'Reddedildi'
            };
            
            const confirmMessage = `Adayın durumunu "${statusNames[status]}" olarak güncellemek istediğinizden emin misiniz?`;
            
            if (confirm(confirmMessage)) {
                // Add loading state
                const selectElement = document.querySelector('.status-selector');
                selectElement.classList.add('loading');
                
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="status" value="${status}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        // Enhanced email template system with better formatting
        function useTemplate(templateType) {
            const template = emailTemplates[templateType];
            if (template) {
                document.getElementById('email_subject').value = template.subject;
                document.getElementById('email_message').value = template.message;
                
                // Add visual feedback
                const templateBtns = document.querySelectorAll('.template-btn');
                templateBtns.forEach(btn => btn.classList.remove('active'));
                event.target.classList.add('active');
            }
        }
        
        // PDF viewer with error handling
        function viewPDF(filePath, title) {
            if (!filePath) {
                alert('Dosya bulunamadı.');
                return;
            }
            
            document.getElementById('pdfViewerTitle').innerHTML = '<i class="fas fa-file-pdf me-2"></i>' + title;
            
            // Add loading state
            const pdfViewer = document.getElementById('pdfViewer');
            pdfViewer.style.background = 'url("data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNDAiIGhlaWdodD0iNDAiIHZpZXdCb3g9IjAgMCA0MCA0MCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIiBzdHJva2U9IiNjY2MiPjxnIGZpbGw9Im5vbmUiIGZpbGwtcnVsZT0iZXZlbm9kZCI+PGcgdHJhbnNmb3JtPSJ0cmFuc2xhdGUoMSAxKSIgc3Ryb2tlLXdpZHRoPSIyIj48Y2lyY2xlIHN0cm9rZS1vcGFjaXR5PSIuNSIgY3g9IjE4IiBjeT0iMTgiIHI9IjE4Ii8+PHBhdGggZD0ibTM5IDAgLTI0IDI0LTEyLTEyIiBzdHJva2Utb3BhY2l0eT0iLjQiLz48L2c+PC9nPjwvc3ZnPg==") center center no-repeat';
            
            pdfViewer.onload = function() {
                pdfViewer.style.background = 'none';
            };
            
            pdfViewer.onerror = function() {
                pdfViewer.style.background = 'none';
                alert('PDF dosyası yüklenirken hata oluştu.');
            };
            
            pdfViewer.src = filePath + '#toolbar=1';
            document.getElementById('pdfDownloadBtn').href = filePath;
            
            const modal = new bootstrap.Modal(document.getElementById('pdfViewerModal'));
            modal.show();
        }
        
        // Aday arama işlevi
        document.getElementById('searchApplicants')?.addEventListener('keyup', function() {
            const searchTerm = this.value.toLowerCase();
            const applicantRows = document.querySelectorAll('.applicant-row');
            
            applicantRows.forEach(row => {
                const text = row.textContent.toLowerCase();
                if (text.includes(searchTerm)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
        
        // Filtreler
        document.getElementById('applyFilters')?.addEventListener('click', function() {
            const statusFilter = document.getElementById('statusFilter').value;
            const applicantRows = document.querySelectorAll('.applicant-row');
            
            applicantRows.forEach(row => {
                const status = row.dataset.status;
                
                if (statusFilter === 'all' || status === statusFilter) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
        
        // Sırala
        document.querySelectorAll('.dropdown-item[data-sort]').forEach(item => {
            item.addEventListener('click', function(e) {
                e.preventDefault();
                document.getElementById('sortDropdown').textContent = this.textContent;
            });
        });
    </script>
</body>
</html>

<?php
// Veritabanı bağlantısını kapat
$conn->close();
?>