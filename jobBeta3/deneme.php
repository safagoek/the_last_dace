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

// Tüm başvuruları al (aday seçim arayüzü için)
$sql_all_applications = "SELECT a.id, a.first_name, a.last_name, a.email, j.title as job_title 
                        FROM applications a 
                        JOIN jobs j ON a.job_id = j.id
                        ORDER BY a.created_at DESC";
$all_applications_result = $conn->query($sql_all_applications);

// URL'den uygulama ID'sini al veya bir aday seçilmediyse 0 olarak ayarla
$application_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Eğer ID belirlenmişse aday detaylarını görüntüle
if ($application_id > 0) {
    // Başvuru detaylarını al
    $sql = "SELECT a.*, j.title as job_title, j.description as job_description, j.location as job_location 
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
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $application_id > 0 ? htmlspecialchars($application['first_name'] . ' ' . $application['last_name']) . ' - Aday Detayı' : 'Aday Seçimi'; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4361ee;
            --secondary: #3f37c9;
            --success: #4cc9f0;
            --info: #4895ef;
            --warning: #f72585;
            --danger: #e63946;
            --light: #f8f9fa;
            --dark: #212529;
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            background-color: #f0f2f5;
            color: #333;
        }
        
        .navbar-brand {
            font-weight: 700;
            font-size: 1.5rem;
        }
        
        .navbar {
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
        }
        
        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            transition: transform 0.2s, box-shadow 0.2s;
            margin-bottom: 1.5rem;
        }
        
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }
        
        .card-header {
            background-color: #fff;
            border-bottom: 1px solid #edf2f7;
            padding: 1rem 1.5rem;
            font-weight: 600;
            border-top-left-radius: 12px !important;
            border-top-right-radius: 12px !important;
        }
        
        .profile-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            padding: 3rem 0;
            border-radius: 0 0 50% 50% / 15%;
            margin-bottom: 3rem;
        }
        
        .avatar {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            border: 5px solid rgba(255, 255, 255, 0.7);
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.2);
            background-color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 60px;
            color: var(--primary);
        }
        
        .stat-card {
            padding: 1.5rem;
            border-radius: 12px;
            color: white;
        }
        
        .bg-gradient-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
        }
        
        .bg-gradient-success {
            background: linear-gradient(135deg, var(--success) 0%, #13855c 100%);
        }
        
        .bg-gradient-info {
            background: linear-gradient(135deg, var(--info) 0%, #2a96a5 100%);
        }
        
        .bg-gradient-warning {
            background: linear-gradient(135deg, var(--warning) 0%, #b5179e 100%);
        }
        
        .score-circle {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            font-weight: 700;
            margin: 0 auto 1.5rem auto;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            border: 5px solid white;
        }
        
        .score-excellent {
            background: linear-gradient(135deg, #4cc9f0 0%, #4361ee 100%);
            color: white;
        }
        
        .score-good {
            background: linear-gradient(135deg, #4895ef 0%, #3f37c9 100%);
            color: white;
        }
        
        .score-average {
            background: linear-gradient(135deg, #f72585 0%, #b5179e 100%);
            color: white;
        }
        
        .score-poor {
            background: linear-gradient(135deg, #e63946 0%, #d00000 100%);
            color: white;
        }
        
        .feedback-box {
            background-color: #f8f9fa;
            border-left: 5px solid var(--info);
            padding: 1.5rem;
            border-radius: 8px;
            font-size: 0.95rem;
            line-height: 1.6;
        }
        
        .answer-correct {
            color: #4cc9f0;
            font-weight: 600;
        }
        
        .answer-incorrect {
            color: var(--warning);
            font-weight: 600;
        }
        
        .info-icon {
            width: 45px;
            height: 45px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.08);
        }
        
        .gender-icon {
            background-color: #e8f4fd;
            color: #4895ef;
        }
        
        .edu-icon {
            background-color: #e5f8ed;
            color: #4cc9f0;
        }
        
        .exp-icon {
            background-color: #fef5e5;
            color: #f72585;
        }
        
        .loc-icon {
            background-color: #fee5e5;
            color: #e63946;
        }
        
        .age-icon {
            background-color: #e5e5fe;
            color: #3f37c9;
        }
        
        .salary-icon {
            background-color: #f5e5fe;
            color: #b5179e;
        }
        
        .progress {
            height: 8px;
            border-radius: 4px;
        }
        
        .progress-bar {
            border-radius: 4px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            border: none;
            box-shadow: 0 4px 10px rgba(67, 97, 238, 0.3);
            transition: all 0.3s;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(67, 97, 238, 0.4);
        }
        
        .table {
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 0 10px rgba(0,0,0,0.03);
        }
        
        .table thead th {
            background-color: #f8f9fa;
            border-bottom: none;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 1px;
            color: #6c757d;
        }
        
        .applicant-card {
            border-left: 4px solid #4361ee;
            transition: all 0.3s ease;
        }
        
        .applicant-card:hover {
            background-color: #f8f9fc;
            cursor: pointer;
        }
        
        .applicant-card.active {
            border-left-color: #4cc9f0;
            background-color: #f0f8ff;
        }
        
        .search-box {
            position: relative;
            margin-bottom: 20px;
        }
        
        .search-box .form-control {
            padding-left: 2.5rem;
            height: 50px;
            border-radius: 25px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.05);
        }
        
        .search-box .fa-search {
            position: absolute;
            left: 1rem;
            top: 17px;
            color: #6c757d;
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark mb-4">
        <div class="container">
            <a class="navbar-brand" href="#">İK Aday Değerlendirme Sistemi</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link active" href="aday_detay.php">Adaylar</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#">İş İlanları</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#">Raporlar</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#">Çıkış</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container py-4">
        <?php if ($application_id <= 0): ?>
        <!-- Aday Seçim Arayüzü -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Aday Listesi</h5>
                        <div>
                            <button class="btn btn-sm btn-outline-primary me-2">
                                <i class="fas fa-filter me-1"></i> Filtrele
                            </button>
                            <button class="btn btn-sm btn-outline-secondary">
                                <i class="fas fa-download me-1"></i> Dışa Aktar
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="search-box">
                            <i class="fas fa-search"></i>
                            <input type="text" class="form-control" id="searchApplicants" placeholder="Aday ara (isim, pozisyon, e-posta...)">
                        </div>
                        
                        <?php if ($all_applications_result->num_rows > 0): ?>
                            <?php while ($app = $all_applications_result->fetch_assoc()): 
                                // Her aday için veritabanından puan bilgilerini al
                                $sql_score = "SELECT COUNT(*) as correct FROM application_answers aa 
                                             JOIN options o ON aa.option_id = o.id 
                                             WHERE aa.application_id = {$app['id']} AND o.is_correct = 1";
                                $score_res = $conn->query($sql_score);
                                $correct = $score_res->fetch_assoc()['correct'];
                                
                                // CV puanını al
                                $sql_cv = "SELECT cv_score FROM applications WHERE id = {$app['id']}";
                                $cv_res = $conn->query($sql_cv);
                                $cv_score = $cv_res->fetch_assoc()['cv_score'];
                            ?>
                                <a href="?id=<?php echo $app['id']; ?>" class="text-decoration-none">
                                    <div class="applicant-card card mb-2 p-3">
                                        <div class="row align-items-center">
                                            <div class="col-lg-1 col-md-2 text-center mb-3 mb-md-0">
                                                <div class="avatar-sm bg-primary text-white rounded-circle d-inline-flex align-items-center justify-content-center" style="width: 40px; height: 40px; font-size: 16px;">
                                                    <?php echo strtoupper(substr($app['first_name'], 0, 1) . substr($app['last_name'], 0, 1)); ?>
                                                </div>
                                            </div>
                                            <div class="col-lg-3 col-md-3">
                                                <h6 class="mb-0"><?php echo htmlspecialchars($app['first_name'] . ' ' . $app['last_name']); ?></h6>
                                                <small class="text-muted"><?php echo htmlspecialchars($app['email']); ?></small>
                                            </div>
                                            <div class="col-lg-3 col-md-3">
                                                <span class="badge bg-light text-dark"><?php echo htmlspecialchars($app['job_title']); ?></span>
                                            </div>
                                            <div class="col-lg-3 col-md-2">
                                                <div class="d-flex align-items-center">
                                                    <div class="me-3">
                                                        <small class="text-muted d-block">Test Skoru</small>
                                                        <div class="progress" style="width: 80px; height: 6px;">
                                                            <div class="progress-bar bg-info" role="progressbar" style="width: <?php echo $correct * 10; ?>%" aria-valuenow="<?php echo $correct; ?>" aria-valuemin="0" aria-valuemax="10"></div>
                                                        </div>
                                                    </div>
                                                    <div>
                                                        <small class="text-muted d-block">CV Skoru</small>
                                                        <div class="progress" style="width: 80px; height: 6px;">
                                                            <div class="progress-bar bg-warning" role="progressbar" style="width: <?php echo $cv_score; ?>%" aria-valuenow="<?php echo $cv_score; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-lg-2 col-md-2 text-end">
                                                <a href="?id=<?php echo $app['id']; ?>" class="btn btn-sm btn-primary">
                                                    <i class="fas fa-eye me-1"></i> Detay
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </a>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div class="alert alert-info">Henüz başvuru bulunmamaktadır.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php else: ?>
        <!-- Aday Detay Görünümü -->
        <!-- Profil Başlığı -->
        <div class="profile-header mb-5 text-center">
            <div class="container">
                <div class="avatar mb-4 mx-auto d-flex align-items-center justify-content-center">
                    <span class="text-uppercase">
                        <?php echo substr($application['first_name'], 0, 1) . substr($application['last_name'], 0, 1); ?>
                    </span>
                </div>
                <h1><?php echo htmlspecialchars($application['first_name'] . ' ' . $application['last_name']); ?></h1>
                <h4 class="text-white-50"><?php echo htmlspecialchars($application['job_title']); ?> Adayı</h4>
                <p>
                    <i class="fas fa-envelope me-2"></i> <?php echo htmlspecialchars($application['email']); ?> | 
                    <i class="fas fa-phone me-2"></i> <?php echo htmlspecialchars($application['phone']); ?>
                </p>
                <div class="mt-4">
                    <a href="aday_detay.php" class="btn btn-light">
                        <i class="fas fa-arrow-left me-1"></i> Tüm Adaylara Dön
                    </a>
                </div>
            </div>
        </div>
        
        <div class="container">
            <!-- Temel Bilgiler -->
            <div class="row mb-4">
                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Kişisel Bilgiler</h5>
                            <a href="<?php echo htmlspecialchars($application['cv_path']); ?>" target="_blank" class="btn btn-primary">
                                <i class="fas fa-download me-1"></i> CV İndir
                            </a>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <div class="d-flex align-items-center">
                                        <div class="info-icon gender-icon">
                                            <i class="fas fa-<?php echo $application['gender'] == 'male' ? 'mars' : ($application['gender'] == 'female' ? 'venus' : 'genderless'); ?>"></i>
                                        </div>
                                        <div>
                                            <p class="text-muted mb-0">Cinsiyet</p>
                                            <h6 class="mb-0"><?php echo $application['gender'] == 'male' ? 'Erkek' : ($application['gender'] == 'female' ? 'Kadın' : 'Diğer'); ?></h6>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <div class="d-flex align-items-center">
                                        <div class="info-icon age-icon">
                                            <i class="fas fa-birthday-cake"></i>
                                        </div>
                                        <div>
                                            <p class="text-muted mb-0">Yaş</p>
                                            <h6 class="mb-0"><?php echo htmlspecialchars($application['age']); ?></h6>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <div class="d-flex align-items-center">
                                        <div class="info-icon edu-icon">
                                            <i class="fas fa-graduation-cap"></i>
                                        </div>
                                        <div>
                                            <p class="text-muted mb-0">Eğitim</p>
                                            <h6 class="mb-0"><?php echo htmlspecialchars($application['education']); ?></h6>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <div class="d-flex align-items-center">
                                        <div class="info-icon exp-icon">
                                            <i class="fas fa-briefcase"></i>
                                        </div>
                                        <div>
                                            <p class="text-muted mb-0">Deneyim</p>
                                            <h6 class="mb-0"><?php echo $application['experience']; ?> yıl</h6>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <div class="d-flex align-items-center">
                                        <div class="info-icon loc-icon">
                                            <i class="fas fa-map-marker-alt"></i>
                                        </div>
                                        <div>
                                            <p class="text-muted mb-0">Şehir</p>
                                            <h6 class="mb-0"><?php echo htmlspecialchars($application['city']); ?></h6>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <div class="d-flex align-items-center">
                                        <div class="info-icon salary-icon">
                                            <i class="fas fa-money-bill-wave"></i>
                                        </div>
                                        <div>
                                            <p class="text-muted mb-0">Maaş Beklentisi</p>
                                            <h6 class="mb-0"><?php echo number_format($application['salary_expectation'], 2); ?> TL</h6>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Başvuru Durumu</h5>
                        </div>
                        <div class="card-body text-center">
                            <?php
                            $score_class = '';
                            $score_label = '';
                            
                            if ($overall_score >= 85) {
                                $score_class = 'score-excellent';
                                $score_label = 'Mükemmel';
                            } elseif ($overall_score >= 70) {
                                $score_class = 'score-good';
                                $score_label = 'İyi';
                            } elseif ($overall_score >= 50) {
                                $score_class = 'score-average';
                                $score_label = 'Orta';
                            } else {
                                $score_class = 'score-poor';
                                $score_label = 'Geliştirilmesi Gerek';
                            }
                            ?>
                            <div class="score-circle <?php echo $score_class; ?>">
                                <?php echo round($overall_score); ?>
                            </div>
                            <h4><?php echo $score_label; ?></h4>
                            <p class="text-muted">Genel Başvuru Puanı</p>
                            
                            <div class="d-flex justify-content-between mb-1">
                                <span>Çoktan Seçmeli Sorular</span>
                                <span><?php echo round($mc_score); ?>%</span>
                            </div>
                            <div class="progress mb-3">
                                <div class="progress-bar bg-info" role="progressbar" style="width: <?php echo $mc_score; ?>%" aria-valuenow="<?php echo $mc_score; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                            </div>
                            
                            <div class="d-flex justify-content-between mb-1">
                                <span>Açık Uçlu Sorular</span>
                                <span><?php echo round($open_score); ?>%</span>
                            </div>
                            <div class="progress mb-3">
                                <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo $open_score; ?>%" aria-valuenow="<?php echo $open_score; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                            </div>
                            
                            <div class="d-flex justify-content-between mb-1">
                                <span>CV Puanı</span>
                                <span><?php echo $application['cv_score']; ?>%</span>
                            </div>
                            <div class="progress">
                                <div class="progress-bar bg-warning" role="progressbar" style="width: <?php echo $application['cv_score']; ?>%" aria-valuenow="<?php echo $application['cv_score']; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Puan ve Geribildirim -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">CV Değerlendirmesi</h5>
                        </div>
                        <div class="card-body">
                            <div class="feedback-box">
                                <?php echo nl2br(htmlspecialchars($application['cv_feedback'])); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Sorular ve Cevaplar -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Test Sonuçları</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th scope="col">#</th>
                                    <th scope="col">Soru</th>
                                    <th scope="col">Cevap</th>
                                    <th scope="col">Sonuç</th>
                                    <th scope="col">Puan</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $counter = 1;
                                while ($answer = $answers_result->fetch_assoc()) { 
                                ?>
                                <tr>
                                    <td><?php echo $counter++; ?></td>
                                    <td><?php echo htmlspecialchars($answer['question_text']); ?></td>
                                    <td>
                                        <?php if ($answer['question_type'] == 'multiple_choice'): ?>
                                            <?php echo htmlspecialchars($answer['option_text']); ?>
                                        <?php else: ?>
                                            <?php if (!empty($answer['answer_text'])): ?>
                                                <?php echo nl2br(htmlspecialchars($answer['answer_text'])); ?>
                                            <?php elseif (!empty($answer['answer_file_path'])): ?>
                                                <a href="<?php echo htmlspecialchars($answer['answer_file_path']); ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                                    <i class="fas fa-file-alt me-1"></i> Dosyayı Görüntüle
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted">Cevap verilmemiş</span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($answer['question_type'] == 'multiple_choice'): ?>
                                            <?php if ($answer['is_correct']): ?>
                                                <span class="answer-correct"><i class="fas fa-check-circle me-1"></i> Doğru</span>
                                            <?php else: ?>
                                                <span class="answer-incorrect"><i class="fas fa-times-circle me-1"></i> Yanlış</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <?php if ($answer['answer_score'] >= 80): ?>
                                                <span class="answer-correct">Mükemmel</span>
                                            <?php elseif ($answer['answer_score'] >= 60): ?>
                                                <span class="text-info fw-bold">İyi</span>
                                            <?php elseif ($answer['answer_score'] >= 40): ?>
                                                <span class="text-warning fw-bold">Orta</span>
                                            <?php else: ?>
                                                <span class="answer-incorrect">Zayıf</span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($answer['question_type'] == 'multiple_choice'): ?>
                                            <?php echo $answer['is_correct'] ? '1' : '0'; ?>
                                        <?php else: ?>
                                            <?php echo $answer['answer_score']; ?>%
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php if ($answer['question_type'] == 'open_ended' && !empty($answer['answer_feedback'])): ?>
                                <tr>
                                    <td colspan="5">
                                        <div class="feedback-box mt-2 mb-3">
                                            <h6 class="mb-2">Geribildirim:</h6>
                                            <?php echo nl2br(htmlspecialchars($answer['answer_feedback'])); ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endif; ?>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer">
                    <div class="d-flex justify-content-between align-items-center">
                        <a href="aday_detay.php" class="btn btn-outline-primary">
                            <i class="fas fa-arrow-left me-1"></i> Tüm Adaylara Dön
                        </a>
                        <div>
                            <button class="btn btn-success me-2">
                                <i class="fas fa-check-circle me-1"></i> Adayı Onayla
                            </button>
                            <button class="btn btn-danger">
                                <i class="fas fa-times-circle me-1"></i> Adayı Reddet
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Aday arama fonksiyonu
    document.getElementById('searchApplicants')?.addEventListener('keyup', function() {
        let filter = this.value.toLowerCase();
        let applicantCards = document.getElementsByClassName('applicant-card');
        
        for (let i = 0; i < applicantCards.length; i++) {
            let card = applicantCards[i];
            let text = card.textContent || card.innerText;
            
            if (text.toLowerCase().indexOf(filter) > -1) {
                card.style.display = "";
            } else {
                card.style.display = "none";
            }
        }
    });
    </script>
</body>
</html>
<?php
// Bağlantıyı kapat
$conn->close();
?>