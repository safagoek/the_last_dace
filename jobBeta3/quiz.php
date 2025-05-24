<?php
// Output buffering başlat - header yönlendirme sorununu çözer
ob_start();

require_once 'config/db.php';
require_once 'includes/header.php';

// Parametreleri kontrol et
$application_id = isset($_GET['application_id']) ? (int)$_GET['application_id'] : 0;
$job_id = isset($_GET['job_id']) ? (int)$_GET['job_id'] : 0;

if ($application_id <= 0 || $job_id <= 0) {
    header('Location: index.php');
    exit;
}

// Başvuru bilgilerini kontrol et
$stmt = $db->prepare("SELECT * FROM applications WHERE id = :application_id AND job_id = :job_id");
$stmt->bindParam(':application_id', $application_id);
$stmt->bindParam(':job_id', $job_id);
$stmt->execute();
$application = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$application) {
    header('Location: index.php');
    exit;
}

// İş ilanını kontrol et
$stmt = $db->prepare("SELECT * FROM jobs WHERE id = :job_id");
$stmt->bindParam(':job_id', $job_id);
$stmt->execute();
$job = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$job) {
    header('Location: index.php');
    exit;
}

// İş ilanına ait soruları çek
$stmt = $db->prepare("SELECT * FROM questions WHERE job_id = :job_id ORDER BY id");
$stmt->bindParam(':job_id', $job_id);
$stmt->execute();
$questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Çoktan seçmeli soruların şıklarını çek
$options = [];
$correct_options = []; // Doğru yanıtları tutacak dizi
foreach ($questions as $question) {
    if ($question['question_type'] == 'multiple_choice') {
        $stmt = $db->prepare("SELECT * FROM options WHERE question_id = :question_id ORDER BY id");
        $stmt->bindParam(':question_id', $question['id']);
        $stmt->execute();
        $question_options = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $options[$question['id']] = $question_options;
        
        // Doğru şıkkı bul ve kaydet
        foreach ($question_options as $option) {
            if ($option['is_correct'] == 1) {
                $correct_options[$question['id']] = $option['id'];
                break;
            }
        }
    }
}

$success = false;
$error = '';

// Form gönderildiyse yanıtları kaydet
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db->beginTransaction();
        
        // Toplam skor ve soru sayısı takibi
        $total_score = 0;
        $total_multiple_choice = 0;
        
        // Soruları ve yanıtları kontrol et
        foreach ($questions as $question) {
            $question_id = $question['id'];
            $answer_text = '';
            $answer_file_path = '';
            
            if ($question['question_type'] == 'multiple_choice') {
                // Çoktan seçmeli soruların sayısını artır
                $total_multiple_choice++;
                
                // Çoktan seçmeli soru
                if (!isset($_POST['answers'][$question_id])) {
                    throw new Exception("Lütfen tüm soruları yanıtlayın.");
                }
                
                $option_id = (int)$_POST['answers'][$question_id];
                
                // Skorlama: Doğru cevap ise 1 puan ekle
                if (isset($correct_options[$question_id]) && $option_id == $correct_options[$question_id]) {
                    $total_score++;
                }
                
                $stmt = $db->prepare("INSERT INTO application_answers (application_id, question_id, option_id) 
                                    VALUES (:application_id, :question_id, :option_id)");
                $stmt->bindParam(':application_id', $application_id);
                $stmt->bindParam(':question_id', $question_id);
                $stmt->bindParam(':option_id', $option_id);
                $stmt->execute();
            } else {
                // Açık uçlu soru - metin veya PDF dosyası
                $answer_text = isset($_POST['answers'][$question_id]) ? trim($_POST['answers'][$question_id]) : '';
                
                // PDF dosyası yükleme kontrolü
                if (isset($_FILES['answer_file']) && isset($_FILES['answer_file']['name'][$question_id]) && $_FILES['answer_file']['name'][$question_id] != '') {
                    // Uploads klasörünü kontrol et ve oluştur
                    $upload_dir = 'uploads/answers/';
                    if (!file_exists($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    
                    $file_tmp = $_FILES['answer_file']['tmp_name'][$question_id];
                    $file_name = $_FILES['answer_file']['name'][$question_id];
                    $file_size = $_FILES['answer_file']['size'][$question_id];
                    $file_error = $_FILES['answer_file']['error'][$question_id];
                    
                    // Dosya hatalarını kontrol et
                    if ($file_error !== 0) {
                        $error_messages = [
                            1 => "Dosya PHP.ini'deki upload_max_filesize değerini aşıyor.",
                            2 => "Dosya HTML formundaki MAX_FILE_SIZE değerini aşıyor.",
                            3 => "Dosya kısmen yüklendi.",
                            4 => "Dosya yüklenmedi.",
                            6 => "Geçici klasör eksik.",
                            7 => "Dosya diske yazılamadı.",
                            8 => "Bir PHP uzantısı dosya yüklemeyi durdurdu."
                        ];
                        throw new Exception("Dosya yüklemede hata: " . ($error_messages[$file_error] ?? "Bilinmeyen hata (kod: $file_error)"));
                    }
                    
                    // Dosya tipini kontrol et
                    $allowed_types = ['application/pdf'];
                    $file_type = mime_content_type($file_tmp);
                    
                    if (!in_array($file_type, $allowed_types)) {
                        throw new Exception("Yalnızca PDF dosyaları yükleyebilirsiniz. Yüklenen dosya tipi: $file_type");
                    }
                    
                    // Dosya boyutunu kontrol et (5MB)
                    if ($file_size > 5 * 1024 * 1024) {
                        throw new Exception("Dosya boyutu 5MB'ı geçemez.");
                    }
                    
                    // Dosyayı güvenli bir şekilde yükle
                    $new_file_name = time() . '_' . $application_id . '_' . $question_id . '_' . basename($file_name);
                    $upload_path = $upload_dir . $new_file_name;
                    
                    if (move_uploaded_file($file_tmp, $upload_path)) {
                        $answer_file_path = $upload_path;
                    } else {
                        throw new Exception("Dosya yüklenirken bir hata oluştu. Lütfen tekrar deneyin.");
                    }
                }
                
                // Hem metin hem dosya boş ise hata ver
                if (empty($answer_text) && empty($answer_file_path)) {
                    throw new Exception("Lütfen tüm sorulara cevap verin veya dosya yükleyin.");
                }
                
                $stmt = $db->prepare("INSERT INTO application_answers (application_id, question_id, answer_text, answer_file_path) 
                                     VALUES (:application_id, :question_id, :answer_text, :answer_file_path)");
                $stmt->bindParam(':application_id', $application_id);
                $stmt->bindParam(':question_id', $question_id);
                $stmt->bindParam(':answer_text', $answer_text);
                $stmt->bindParam(':answer_file_path', $answer_file_path);
                $stmt->execute();
            }
        }
        
        // Başvuru durumunu ve skorunu güncelle
        $stmt = $db->prepare("UPDATE applications SET status = 'completed', score = :score WHERE id = :application_id");
        $stmt->bindParam(':score', $total_score);
        $stmt->bindParam(':application_id', $application_id);
        $stmt->execute();
        
        $db->commit();
        $success = true;
        
        // Debug için skor bilgilerini kaydet
        error_log("Application ID: $application_id - Total Score: $total_score / $total_multiple_choice");
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error = $e->getMessage();
    }
}
?>

<!-- Modern Quiz Uygulaması -->
<div class="quiz-wrapper">
    <div class="container">
        <?php if ($success): ?>
            <!-- Başarılı tamamlama ekranı -->
            <div class="success-container">
                <div class="success-card">
                    <div class="success-icon">
                        <i class="bi bi-check-circle-fill"></i>
                    </div>
                    <h2>Başvurunuz Tamamlandı!</h2>
                    <p>Başvurunuz başarıyla alınmıştır. İncelendikten sonra sizinle iletişime geçeceğiz.</p>
                    <a href="index.php" class="btn btn-primary">
                        <i class="bi bi-house-door me-2"></i>Ana Sayfaya Dön
                    </a>
                </div>
            </div>
        <?php elseif (empty($questions)): ?>
            <!-- Soru yoksa tamamlama ekranı -->
            <div class="success-container">
                <div class="success-card">
                    <div class="success-icon">
                        <i class="bi bi-check-circle-fill"></i>
                    </div>
                    <h2>Başvurunuz Alındı!</h2>
                    <p>Başvurunuz için teşekkür ederiz. Bu pozisyon için ek sorular bulunmamaktadır. İncelendikten sonra sizinle iletişime geçeceğiz.</p>
                    <a href="index.php" class="btn btn-primary">
                        <i class="bi bi-house-door me-2"></i>Ana Sayfaya Dön
                    </a>
                </div>
            </div>
        <?php else: ?>
            <!-- Quiz başlığı -->
            <div class="quiz-header-section">
                <div class="quiz-meta">
                    <h1><?= htmlspecialchars($job['title']) ?></h1>
                    <p><i class="bi bi-geo-alt"></i> <?= htmlspecialchars($job['location']) ?></p>
                </div>
                <div class="quiz-timer">
                    <div class="timer-badge" id="countdown-timer">
                        <i class="bi bi-clock"></i>
                        <span id="timer">20:00</span>
                    </div>
                    <div class="question-count">
                        <?= count($questions) ?> Soru
                    </div>
                </div>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <?= $error ?>
                </div>
            <?php endif; ?>
            
            <!-- Quiz içeriği -->
            <div class="quiz-content">
                <div class="quiz-intro">
                    <h2>Değerlendirme Soruları</h2>
                    <p>Lütfen aşağıdaki soruları yanıtlayarak başvurunuzu tamamlayın. Tüm sorular sınav süresi içinde cevaplanmalıdır.</p>
                </div>
                
                <form method="post" enctype="multipart/form-data" id="quiz-form" class="quiz-form">
                    <div class="questions-container">
                        <?php foreach ($questions as $index => $question): ?>
                            <div class="question-box">
                                <div class="question-header">
                                    <span class="question-number"><?= ($index + 1) ?></span>
                                    <h3 class="question-text"><?= htmlspecialchars($question['question_text']) ?></h3>
                                </div>
                                
                                <div class="question-body">
                                    <?php if ($question['question_type'] == 'multiple_choice'): ?>
                                        <?php if (isset($options[$question['id']])): ?>
                                            <div class="options-grid">
                                                <?php foreach ($options[$question['id']] as $option): ?>
                                                    <label class="option-card" for="option_<?= $option['id'] ?>">
                                                        <input type="radio" name="answers[<?= $question['id'] ?>]" 
                                                            id="option_<?= $option['id'] ?>" 
                                                            value="<?= $option['id'] ?>" required>
                                                        <span class="option-text"><?= htmlspecialchars($option['option_text']) ?></span>
                                                    </label>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <!-- Açık uçlu soru -->
                                        <div class="open-ended-question">
                                            <div class="form-group">
                                                <label>Metin yanıtınız:</label>
                                                <textarea class="form-control" name="answers[<?= $question['id'] ?>]" 
                                                    rows="3" placeholder="Cevabınızı buraya yazın..."></textarea>
                                            </div>
                                            
                                            <div class="divider">
                                                <span>veya</span>
                                            </div>
                                            
                                            <div class="form-group">
                                                <label>PDF dosyası yükleyin:</label>
                                                <div class="file-upload">
                                                    <input type="file" class="form-control" name="answer_file[<?= $question['id'] ?>]" 
                                                        accept=".pdf" id="file_<?= $question['id'] ?>">
                                                    <label for="file_<?= $question['id'] ?>" class="file-label">
                                                        <i class="bi bi-upload"></i>
                                                        <span>Dosya Seç</span>
                                                    </label>
                                                    <div class="file-info">Sadece PDF (Max 5MB)</div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="submit-section">
                        <button type="submit" class="btn btn-primary btn-submit">
                            <span>Başvuruyu Tamamla</span>
                            <i class="bi bi-arrow-right"></i>
                        </button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
:root{--primary:#4361ee;--primary-light:#ebefff;--primary-dark:#3a56d4;--success:#06d6a0;--success-light:#e3fcf7;--danger:#ef476f;--danger-light:#ffedf2;--warning:#ffd166;--warning-light:#fff8eb;--text-dark:#1a1a1a;--text-medium:#4a4a4a;--text-light:#6e6e6e;--border-light:#e7e7e7;--border-medium:#d1d1d1;--bg-light:#f8f9fa;--bg-white:#ffffff;--shadow:0 8px 18px rgba(0,0,0,0.05);--radius:12px;--radius-sm:6px;}*{margin:0;padding:0;box-sizing:border-box;}body{background-color:var(--bg-light);color:var(--text-dark);font-family:'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, 'Open Sans', 'Helvetica Neue', sans-serif;line-height:1.6;}.quiz-wrapper{padding:40px 0;}.container{max-width:960px;margin:0 auto;padding:0 20px;}.quiz-header-section{display:flex;align-items:center;justify-content:space-between;margin-bottom:30px;flex-wrap:wrap;}.quiz-meta h1{font-size:24px;font-weight:700;margin-bottom:4px;color:var(--text-dark);}.quiz-meta p{font-size:14px;color:var(--text-light);display:flex;align-items:center;gap:5px;}.quiz-timer{display:flex;align-items:center;gap:15px;}.timer-badge{display:flex;align-items:center;justify-content:center;background-color:var(--primary-light);color:var(--primary);padding:10px 20px;border-radius:30px;font-weight:600;gap:8px;transition:all 0.3s ease;}.timer-badge.warning{background-color:var(--warning-light);color:var(--warning);}.timer-badge.danger{background-color:var(--danger-light);color:var(--danger);animation:pulse 1s infinite;}.question-count{background-color:var(--bg-white);color:var(--text-medium);padding:8px 15px;border-radius:20px;font-size:13px;font-weight:500;border:1px solid var(--border-light);}.quiz-content{background-color:var(--bg-white);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden;margin-bottom:40px;}.quiz-intro{padding:25px 30px;border-bottom:1px solid var(--border-light);}.quiz-intro h2{font-size:20px;font-weight:600;margin-bottom:5px;}.quiz-intro p{color:var(--text-light);font-size:15px;}.quiz-form{padding:20px 30px 30px;}.questions-container{display:flex;flex-direction:column;gap:25px;margin-bottom:35px;}.question-box{background-color:var(--bg-light);border-radius:var(--radius);overflow:hidden;border:1px solid var(--border-light);transition:all 0.3s ease;}.question-box:hover{box-shadow:0 5px 15px rgba(0,0,0,0.04);}.question-header{padding:20px 25px;border-bottom:1px solid var(--border-light);display:flex;gap:15px;}.question-number{display:flex;align-items:center;justify-content:center;width:28px;height:28px;background-color:var(--primary);color:white;border-radius:50%;font-weight:600;font-size:14px;flex-shrink:0;}.question-text{font-size:16px;font-weight:500;color:var(--text-dark);margin:0;}.question-body{padding:25px;}.options-grid{display:grid;grid-template-columns:repeat(auto-fill, minmax(200px, 1fr));gap:15px;}.option-card{display:flex;padding:15px;background-color:var(--bg-white);border:1px solid var(--border-light);border-radius:var(--radius-sm);cursor:pointer;position:relative;transition:all 0.2s ease;}.option-card:hover{border-color:var(--primary-light);background-color:var(--primary-light);}.option-card input{position:absolute;opacity:0;cursor:pointer;height:0;width:0;}.option-card input:checked + .option-text{color:var(--primary);font-weight:500;}.option-card input:checked ~ .option-card{border-color:var(--primary);background-color:var(--primary-light);}.option-text{font-size:15px;line-height:1.4;transition:all 0.2s ease;}.option-card input:checked + .option-text:before{content:'';position:absolute;top:15px;right:15px;width:16px;height:16px;background-color:var(--primary);border-radius:50%;}.option-card input:checked + .option-text:after{content:'✓';position:absolute;top:15px;right:15px;font-size:10px;color:white;}.open-ended-question{display:flex;flex-direction:column;gap:20px;}.form-group{margin-bottom:0;}.form-group label{display:block;margin-bottom:8px;font-weight:500;font-size:15px;color:var(--text-medium);}.form-control{width:100%;padding:12px 15px;border:1px solid var(--border-medium);border-radius:var(--radius-sm);background-color:var(--bg-white);color:var(--text-dark);font-size:15px;transition:all 0.2s ease;}.form-control:focus{border-color:var(--primary);outline:none;box-shadow:0 0 0 3px var(--primary-light);}.form-control::placeholder{color:var(--text-light);}.divider{display:flex;align-items:center;text-align:center;color:var(--text-light);margin:10px 0;}.divider:before,.divider:after{content:'';flex:1;border-bottom:1px solid var(--border-light);}.divider span{padding:0 10px;font-size:14px;color:var(--text-light);}.file-upload{position:relative;}.file-upload input[type="file"]{width:0.1px;height:0.1px;opacity:0;overflow:hidden;position:absolute;z-index:-1;}.file-label{display:flex;align-items:center;gap:10px;padding:12px 20px;background-color:var(--primary-light);color:var(--primary);border-radius:var(--radius-sm);cursor:pointer;font-weight:500;transition:all 0.2s ease;}.file-label:hover{background-color:rgba(67,97,238,0.15);}.file-info{margin-top:8px;font-size:13px;color:var(--text-light);}.submit-section{display:flex;justify-content:center;}.btn{display:inline-flex;align-items:center;gap:10px;background:none;border:none;cursor:pointer;font-family:inherit;font-size:16px;font-weight:500;padding:14px 28px;border-radius:var(--radius-sm);transition:all 0.2s ease;text-decoration:none;}.btn-primary{background-color:var(--primary);color:white;}.btn-primary:hover{background-color:var(--primary-dark);}.btn-submit{padding:14px 32px;border-radius:30px;font-size:16px;font-weight:600;}.alert{padding:15px 20px;border-radius:var(--radius-sm);margin-bottom:20px;}.alert-danger{background-color:var(--danger-light);color:var(--danger);display:flex;align-items:center;gap:10px;}.success-container{display:flex;justify-content:center;align-items:center;min-height:60vh;}.success-card{background-color:var(--bg-white);border-radius:var(--radius);box-shadow:var(--shadow);padding:50px 40px;text-align:center;max-width:480px;width:100%;}.success-icon{font-size:60px;color:var(--success);margin-bottom:20px;}.success-icon i{background-color:var(--success-light);border-radius:50%;padding:20px;}.success-card h2{font-size:24px;margin-bottom:15px;}.success-card p{color:var(--text-light);margin-bottom:30px;font-size:16px;}@keyframes pulse{0%{opacity:1;}50%{opacity:0.7;}100%{opacity:1;}}@media (max-width:768px){.quiz-header-section{flex-direction:column;align-items:flex-start;gap:20px;}.quiz-timer{width:100%;justify-content:space-between;}.quiz-content{margin-top:20px;}.question-header{flex-direction:column;gap:10px;}.options-grid{grid-template-columns:1fr;}.file-label{width:100%;justify-content:center;}}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Dosya yükleme UI'ı için
    const fileInputs = document.querySelectorAll('input[type="file"]');
    fileInputs.forEach(input => {
        input.addEventListener('change', function(e) {
            const fileName = e.target.files[0]?.name;
            const fileLabel = this.nextElementSibling;
            
            if (fileName) {
                const nameSpan = fileLabel.querySelector('span');
                nameSpan.textContent = fileName.length > 20 ? fileName.substring(0, 17) + '...' : fileName;
            }
        });
    });

    // Geri sayım zamanlayıcısı için değişkenler
    const startingMinutes = 20;
    let timeLeft = startingMinutes * 60; // Saniye cinsinden
    const timerElement = document.getElementById('timer');
    const countdownElement = document.getElementById('countdown-timer');
    const quizForm = document.getElementById('quiz-form');
    
    // Zamanlayıcıyı başlat
    let countdownTimer = setInterval(updateTimer, 1000);
    
    // Zamanlayıcıyı güncelleme fonksiyonu
    function updateTimer() {
        if (timeLeft <= 0) {
            clearInterval(countdownTimer);
            autoSubmitForm();
            return;
        }
        
        const minutes = Math.floor(timeLeft / 60);
        let seconds = timeLeft % 60;
        seconds = seconds < 10 ? '0' + seconds : seconds;
        
        // Zamanlayıcıyı güncelle
        timerElement.innerHTML = `${minutes}:${seconds}`;
        
        // Son 5 dakika için uyarı rengi
        if (timeLeft <= 300 && timeLeft > 60) {
            countdownElement.classList.add('warning');
        }
        
        // Son 1 dakika için tehlike rengi
        if (timeLeft <= 60) {
            countdownElement.classList.remove('warning');
            countdownElement.classList.add('danger');
        }
        
        timeLeft--;
    }
    
    // Süre dolduğunda otomatik gönderme
    function autoSubmitForm() {
        // Çoktan seçmeli sorularda seçim yapılmamış olanları işaretle
        const requiredRadios = document.querySelectorAll('input[type="radio"][required]');
        const radioGroups = {};
        
        // Her radio grubu için işlem yap
        requiredRadios.forEach(function(radio) {
            const name = radio.getAttribute('name');
            if (!radioGroups[name]) {
                radioGroups[name] = {
                    selected: false,
                    options: []
                };
            }
            radioGroups[name].options.push(radio);
            if (radio.checked) {
                radioGroups[name].selected = true;
            }
        });
        
        // Seçilmemiş gruplar için ilk seçeneği otomatik seç (yanlış olarak işaretlenir)
        for (const name in radioGroups) {
            if (!radioGroups[name].selected && radioGroups[name].options.length > 0) {
                radioGroups[name].options[0].checked = true;
            }
        }
        
        // Açık uçlu sorular için boş yanıt ekle
        const textareas = document.querySelectorAll('textarea[name^="answers["]');
        textareas.forEach(function(textarea) {
            if (textarea.value.trim() === '') {
                textarea.value = 'Süre doldu - Yanıtlanmadı';
            }
        });
        
        // Formu otomatik gönder
        alert('Süre doldu! Yanıtlarınız otomatik olarak gönderiliyor.');
        quizForm.submit();
    }
    
    // Form validasyonu
    if (quizForm) {
        quizForm.addEventListener('submit', function(e) {
            const requiredRadios = this.querySelectorAll('input[type="radio"][required]');
            const radioGroups = {};
            
            // Her radio grubu için en az bir seçim yapılmış mı kontrol et
            requiredRadios.forEach(function(radio) {
                const name = radio.getAttribute('name');
                if (!radioGroups[name]) radioGroups[name] = false;
                if (radio.checked) radioGroups[name] = true;
            });
            
            // Seçilmemiş grup varsa uyarı ver ve formu durdur
            const unselectedGroups = Object.keys(radioGroups).filter(name => !radioGroups[name]);
            if (unselectedGroups.length > 0) {
                e.preventDefault();
                alert('Lütfen tüm çoktan seçmeli soruları cevaplayın.');
                
                // İlk seçilmemiş soruya kaydır
                const firstUnselectedName = unselectedGroups[0];
                const firstUnselected = this.querySelector(`[name="${firstUnselectedName}"]`);
                if (firstUnselected) {
                    firstUnselected.closest('.question-box').scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                
                return false;
            }
            
            // Açık uçlu sorular için metin veya dosya kontrolü
            const textareas = this.querySelectorAll('textarea[name^="answers["]');
            let hasEmptyAnswer = false;
            
            textareas.forEach(function(textarea) {
                const questionId = textarea.name.match(/\[(\d+)\]/)[1];
                const fileInput = document.querySelector(`input[name="answer_file[${questionId}]"]`);
                
                // Hem metin hem dosya boşsa uyarı ver
                if (textarea.value.trim() === '' && (!fileInput.files.length || fileInput.files[0].size === 0)) {
                    hasEmptyAnswer = true;
                    textarea.closest('.question-box').scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            });
            
            if (hasEmptyAnswer) {
                e.preventDefault();
                alert('Lütfen tüm açık uçlu sorulara cevap verin veya dosya yükleyin.');
                return false;
            }
            
            // Dosya boyutu kontrolü
            const fileInputs = this.querySelectorAll('input[type="file"]');
            let hasLargeFile = false;
            
            fileInputs.forEach(function(input) {
                if (input.files.length > 0) {
                    const fileSize = input.files[0].size / 1024 / 1024; // MB cinsinden
                    if (fileSize > 5) {
                        hasLargeFile = true;
                        input.value = '';
                        input.closest('.question-box').scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                }
            });
            
            if (hasLargeFile) {
                e.preventDefault();
                alert('Dosya boyutu 5MB\'den büyük olamaz!');
                return false;
            }
        });
    }
    
    // Radio button seçme efekti
    const optionCards = document.querySelectorAll('.option-card');
    optionCards.forEach(card => {
        card.addEventListener('click', function() {
            // Bu sorunun tüm kartlarını seçilmemiş yapıyoruz
            const questionBody = this.closest('.question-body');
            const allCards = questionBody.querySelectorAll('.option-card');
            allCards.forEach(c => c.classList.remove('selected'));
            
            // Tıklanan kartı seçili yapıyoruz
            this.classList.add('selected');
            
            // Radio butonunu seçiyoruz
            const radio = this.querySelector('input[type="radio"]');
            radio.checked = true;
        });
    });
});
</script>

<?php 
require_once 'includes/footer.php';
// Output buffer içeriğini gönder ve buffer'ı temizle
ob_end_flush();
?>