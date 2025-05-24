<?php
// Output buffering başlat - header yönlendirme sorununu çözer
ob_start();

require_once 'config/db.php';
require_once 'includes/header.php';

$selected_job_id = isset($_GET['job_id']) ? (int)$_GET['job_id'] : 0;

// İş ilanlarını çek
$stmt = $db->prepare("SELECT * FROM jobs WHERE status = 'active' ORDER BY title");
$stmt->execute();
$jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Seçili iş bilgisini al
$selected_job = null;
if ($selected_job_id > 0) {
    foreach ($jobs as $job) {
        if ($job['id'] == $selected_job_id) {
            $selected_job = $job;
            break;
        }
    }
}

// Form gönderildiyse işle
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Form verilerini al
    $job_id = (int)$_POST['job_id'];
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $phone = trim($_POST['phone']);
    $email = trim($_POST['email']);
    $gender = $_POST['gender'];
    $age = (int)$_POST['age'];
    $city = trim($_POST['city']);
    $salary = (float)$_POST['salary'];
    $education = trim($_POST['education']);
    $experience = (int)$_POST['experience']; // Yeni deneyim alanı
    
    // CV dosyasını yükle
    $cv_path = '';
    if (isset($_FILES['cv']) && $_FILES['cv']['error'] === 0) {
        $upload_dir = 'uploads/cv/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $file_name = time() . '_' . basename($_FILES['cv']['name']);
        $target_path = $upload_dir . $file_name;
        
        if (move_uploaded_file($_FILES['cv']['tmp_name'], $target_path)) {
            $cv_path = $target_path;
        }
    }
    
    if (empty($cv_path)) {
        // CV yükleme hatası
        $error = "CV yüklenirken bir hata oluştu.";
    } else {
        // Başvuruyu veritabanına kaydet
        $stmt = $db->prepare("INSERT INTO applications (job_id, first_name, last_name, phone, email, gender, age, city, salary_expectation, education, cv_path, experience) 
                             VALUES (:job_id, :first_name, :last_name, :phone, :email, :gender, :age, :city, :salary, :education, :cv_path, :experience)");
        
        $stmt->bindParam(':job_id', $job_id);
        $stmt->bindParam(':first_name', $first_name);
        $stmt->bindParam(':last_name', $last_name);
        $stmt->bindParam(':phone', $phone);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':gender', $gender);
        $stmt->bindParam(':age', $age);
        $stmt->bindParam(':city', $city);
        $stmt->bindParam(':salary', $salary);
        $stmt->bindParam(':education', $education);
        $stmt->bindParam(':cv_path', $cv_path);
        $stmt->bindParam(':experience', $experience); // Deneyim alanını ekle
        
        if ($stmt->execute()) {
            $application_id = $db->lastInsertId();
            // Quiz sayfasına yönlendir
            header("Location: quiz.php?application_id=$application_id&job_id=$job_id");
            exit;
        } else {
            $error = "Başvuru kaydedilirken bir hata oluştu.";
        }
    }
}

// Türkiye'nin büyük şehirlerini tanımla
$cities = [
    'İstanbul', 'Ankara', 'İzmir', 'Bursa', 'Antalya', 'Adana', 'Konya', 
    'Gaziantep', 'Şanlıurfa', 'Kocaeli', 'Mersin', 'Diyarbakır', 'Hatay', 
    'Manisa', 'Kayseri', 'Samsun', 'Balıkesir', 'Kahramanmaraş', 'Van', 'Aydın'
];

// Popüler bölümler
$departments = [
    'Bilgisayar Mühendisliği',
    'Elektrik-Elektronik Mühendisliği',
    'Makine Mühendisliği',
    'Endüstri Mühendisliği',
    'İşletme',
    'Ekonomi',
    'Psikoloji',
    'Hukuk',
    'Tıp',
    'Mimarlık',
    'İnşaat Mühendisliği',
    'Yazılım Mühendisliği',
    'Moleküler Biyoloji ve Genetik',
    'Uluslararası İlişkiler',
    'Grafik Tasarım',
    'İletişim',
    'Sosyoloji',
    'Kimya Mühendisliği',
    'Gıda Mühendisliği',
    'Yönetim Bilişim Sistemleri'
];
?>

<!-- Modern Başvuru Sayfası -->
<div class="application-wrapper">
    <div class="container">
        <!-- Başvuru Başlık -->
        <div class="application-header">
            <div class="job-info">
                <h1><?= $selected_job ? htmlspecialchars($selected_job['title']) : 'İş Başvurusu' ?></h1>
                <?php if ($selected_job): ?>
                    <div class="job-meta">
                        <span class="location"><i class="bi bi-geo-alt"></i> <?= htmlspecialchars($selected_job['location']) ?></span>
                    </div>
                <?php endif; ?>
            </div>
            <div class="steps-indicator">
                <div class="step active">
                    <div class="step-number">1</div>
                    <div class="step-label">Başvuru Formu</div>
                </div>
                <div class="step">
                    <div class="step-number">2</div>
                    <div class="step-label">Değerlendirme</div>
                </div>
                <div class="step">
                    <div class="step-number">3</div>
                    <div class="step-label">Tamamlandı</div>
                </div>
            </div>
        </div>

        <?php if (isset($error)): ?>
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-circle me-2"></i>
                <?= $error ?>
            </div>
        <?php endif; ?>

        <!-- Başvuru Formu -->
        <div class="application-card">
            <div class="application-card-header">
                <h2><i class="bi bi-person-vcard me-2"></i>Kişisel Bilgiler</h2>
                <p>Başvurunuzu tamamlamak için aşağıdaki bilgileri doldurun.</p>
            </div>
            <div class="application-card-body">
                <form method="post" enctype="multipart/form-data" id="application-form">
                    <div class="form-section">
                        <h3>Temel Bilgiler</h3>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="first_name">Adınız <span class="required">*</span></label>
                                    <input type="text" class="form-control" id="first_name" name="first_name" placeholder="Adınızı girin" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="last_name">Soyadınız <span class="required">*</span></label>
                                    <input type="text" class="form-control" id="last_name" name="last_name" placeholder="Soyadınızı girin" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="phone">Telefon Numarası <span class="required">*</span></label>
                                    <input type="tel" class="form-control" id="phone" name="phone" placeholder="05xx xxx xx xx" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="email">E-posta Adresi <span class="required">*</span></label>
                                    <input type="email" class="form-control" id="email" name="email" placeholder="ornek@email.com" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="gender">Cinsiyet <span class="required">*</span></label>
                                    <select class="form-select" id="gender" name="gender" required>
                                        <option value="">Seçiniz</option>
                                        <option value="male">Erkek</option>
                                        <option value="female">Kadın</option>
                                        <option value="other">Diğer</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="age">Yaş <span class="required">*</span></label>
                                    <input type="number" class="form-control" id="age" name="age" min="18" max="100" placeholder="Yaşınızı girin" required>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <h3>Lokasyon ve Eğitim</h3>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="city">Şehir <span class="required">*</span></label>
                                    <select class="form-select" id="city" name="city" required>
                                        <option value="">Şehir Seçiniz</option>
                                        <?php foreach ($cities as $city): ?>
                                            <option value="<?= htmlspecialchars($city) ?>"><?= htmlspecialchars($city) ?></option>
                                        <?php endforeach; ?>
                                        <option value="other">Diğer</option>
                                    </select>
                                    <div id="otherCity" class="mt-2 d-none">
                                        <input type="text" class="form-control" id="other_city_input" placeholder="Şehrinizi yazın">
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="education">Eğitim/Mezun Olduğunuz Bölüm <span class="required">*</span></label>
                                    <select class="form-select" id="education" name="education" required>
                                        <option value="">Bölüm Seçiniz</option>
                                        <?php foreach ($departments as $department): ?>
                                            <option value="<?= htmlspecialchars($department) ?>"><?= htmlspecialchars($department) ?></option>
                                        <?php endforeach; ?>
                                        <option value="other">Diğer</option>
                                    </select>
                                    <div id="otherEducation" class="mt-2 d-none">
                                        <input type="text" class="form-control" id="other_education_input" placeholder="Bölümünüzü yazın">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <h3>İş Deneyimi ve Beklentiler</h3>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="experience">İş Deneyimi (Yıl) <span class="required">*</span></label>
                                    <select class="form-select" id="experience" name="experience" required>
                                        <option value="">Deneyim Seçiniz</option>
                                        <option value="0">Deneyim yok (0 yıl)</option>
                                        <option value="1">1 yıl</option>
                                        <option value="2">2 yıl</option>
                                        <option value="3">3 yıl</option>
                                        <option value="4">4 yıl</option>
                                        <option value="5">5 yıl</option>
                                        <option value="6">6 yıl</option>
                                        <option value="7">7 yıl</option>
                                        <option value="8">8 yıl</option>
                                        <option value="9">9 yıl</option>
                                        <option value="10">10 yıl</option>
                                        <option value="11">11-15 yıl</option>
                                        <option value="16">16-20 yıl</option>
                                        <option value="21">20+ yıl</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="salary">Maaş Beklentisi (TL) <span class="required">*</span></label>
                                    <input type="number" class="form-control" id="salary" name="salary" min="0" step="1000" placeholder="Aylık beklentinizi girin" required>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <h3>Dökümanlar ve Pozisyon</h3>
                        
                        <div class="form-group">
                            <label for="cv">CV (PDF) <span class="required">*</span></label>
                            <div class="custom-file-upload">
                                <input type="file" class="form-control" id="cv" name="cv" accept=".pdf" required>
                                <label for="cv" class="file-label">
                                    <i class="bi bi-upload"></i>
                                    <span>PDF Dosyası Seçin</span>
                                </label>
                                <div class="file-info">PDF formatında, maksimum 5MB</div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="job_id">Başvurulan İş İlanı <span class="required">*</span></label>
                            <select class="form-select" id="job_id" name="job_id" required>
                                <option value="">Seçiniz</option>
                                <?php foreach ($jobs as $job): ?>
                                    <option value="<?= $job['id'] ?>" <?= ($job['id'] == $selected_job_id) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($job['title'] . ' - ' . $job['location']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-check privacy-check">
                            <input type="checkbox" class="form-check-input" id="terms" name="terms" required>
                            <label class="form-check-label" for="terms">
                                Kişisel verilerimin, başvuru değerlendirme süreçlerinde kullanılmasını kabul ediyorum. <span class="required">*</span>
                            </label>
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary btn-submit">
                            <span>Başvuruyu Tamamla ve Sorulara Geç</span>
                            <i class="bi bi-arrow-right-circle ms-2"></i>
                        </button>
                        <a href="index.php" class="btn btn-light btn-cancel">
                            İptal
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
:root{--primary:#4361ee;--primary-light:#ebefff;--primary-dark:#3a56d4;--secondary:#6c757d;--success:#10b981;--light:#f8f9fa;--dark:#212529;--border-color:#e9ecef;--gray-100:#f8f9fa;--gray-200:#e9ecef;--gray-300:#dee2e6;--gray-400:#ced4da;--gray-500:#adb5bd;--gray-600:#6c757d;--gray-700:#495057;--gray-800:#343a40;--gray-900:#212529;--danger:#dc3545;--warning:#ffc107;--border-radius:12px;--box-shadow:0 5px 20px rgba(0,0,0,0.05);--transition:all 0.25s ease;}*{margin:0;padding:0;box-sizing:border-box;}body{background-color:var(--gray-100);color:var(--gray-900);font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Oxygen,Ubuntu,Cantarell,'Helvetica Neue',sans-serif;}.application-wrapper{padding:40px 0;}.application-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:30px;flex-wrap:wrap;gap:20px;}.job-info h1{font-size:28px;font-weight:700;margin:0 0 10px;color:var(--gray-900);}.job-meta{color:var(--gray-600);font-size:16px;display:flex;align-items:center;gap:15px;}.job-meta .location{display:flex;align-items:center;gap:5px;}.steps-indicator{display:flex;gap:10px;}.step{display:flex;align-items:center;gap:10px;color:var(--gray-500);font-weight:500;}.step.active{color:var(--primary);}.step-number{width:30px;height:30px;display:flex;align-items:center;justify-content:center;background-color:var(--gray-200);border-radius:50%;font-weight:600;}.step.active .step-number{background-color:var(--primary);color:white;}.application-card{background-color:white;border-radius:var(--border-radius);box-shadow:var(--box-shadow);overflow:hidden;margin-bottom:30px;}.application-card-header{padding:25px 30px;background-color:var(--primary-light);border-bottom:1px solid var(--gray-200);}.application-card-header h2{font-size:20px;font-weight:600;margin:0 0 8px;color:var(--gray-900);}.application-card-header p{color:var(--gray-600);margin:0;}.application-card-body{padding:30px;}.form-section{margin-bottom:35px;border-bottom:1px solid var(--gray-200);padding-bottom:25px;}.form-section:last-child{border-bottom:none;margin-bottom:15px;}.form-section h3{font-size:18px;font-weight:600;margin-bottom:20px;color:var(--gray-800);padding-bottom:10px;border-bottom:1px dashed var(--gray-300);}.form-group{margin-bottom:20px;}.form-group label{display:block;margin-bottom:8px;font-weight:500;color:var(--gray-700);}.required{color:var(--danger);margin-left:2px;}.form-control,.form-select{display:block;width:100%;padding:12px 16px;font-size:15px;font-weight:400;line-height:1.5;color:var(--gray-700);background-color:white;background-clip:padding-box;border:1px solid var(--gray-300);border-radius:var(--border-radius);transition:border-color .15s ease-in-out,box-shadow .15s ease-in-out;}.form-control:focus,.form-select:focus{color:var(--gray-900);background-color:white;border-color:var(--primary);outline:0;box-shadow:0 0 0 3px var(--primary-light);}.form-control::placeholder{color:var(--gray-500);opacity:1;}.form-check{padding-left:30px;position:relative;margin-top:15px;}.form-check-input{position:absolute;left:0;top:5px;width:20px;height:20px;margin-top:0;vertical-align:top;border:1px solid var(--gray-400);appearance:none;-webkit-appearance:none;background-color:white;border-radius:4px;}.form-check-input:checked{background-color:var(--primary);border-color:var(--primary);}.form-check-input:checked::after{content:"✓";position:absolute;top:-1px;left:5px;font-size:15px;color:white;}.form-check-label{margin-bottom:0;font-size:15px;color:var(--gray-700);}.privacy-check{padding:15px;background-color:var(--gray-100);border-radius:var(--border-radius);margin-top:20px;}.custom-file-upload{position:relative;}.custom-file-upload input[type="file"]{position:absolute;left:0;top:0;opacity:0;width:0.1px;height:0.1px;}.file-label{display:flex;align-items:center;justify-content:center;padding:14px 20px;background-color:var(--gray-100);border:1px dashed var(--gray-400);border-radius:var(--border-radius);cursor:pointer;font-weight:500;color:var(--gray-700);transition:all 0.2s ease;gap:10px;}.file-label:hover{background-color:var(--primary-light);border-color:var(--primary);color:var(--primary);}.file-info{margin-top:8px;font-size:13px;color:var(--gray-600);text-align:center;}.form-actions{display:flex;justify-content:center;gap:15px;margin-top:30px;}.btn{display:inline-flex;align-items:center;justify-content:center;font-weight:500;line-height:1.5;text-align:center;vertical-align:middle;cursor:pointer;user-select:none;border:1px solid transparent;padding:12px 24px;font-size:16px;border-radius:30px;transition:all .2s ease-in-out;gap:8px;}.btn-primary{color:white;background-color:var(--primary);border-color:var(--primary);}.btn-primary:hover{background-color:var(--primary-dark);border-color:var(--primary-dark);}.btn-light{color:var(--gray-700);background-color:var(--gray-200);border-color:transparent;}.btn-light:hover{background-color:var(--gray-300);color:var(--gray-800);}.btn-submit{padding:14px 28px;font-weight:600;}.alert{position:relative;padding:15px 20px;margin-bottom:20px;border:1px solid transparent;border-radius:var(--border-radius);}.alert-danger{color:var(--danger);background-color:#f8d7da;border-color:#f5c2c7;}@media (max-width:768px){.application-header{flex-direction:column;}.steps-indicator{width:100%;justify-content:space-between;}.form-actions{flex-direction:column;}.btn-cancel{order:2;width:auto;}}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Dosya seçiminde dosya adını göster
    const cvInput = document.getElementById('cv');
    const fileLabel = document.querySelector('.file-label span');
    const originalText = fileLabel.textContent;
    
    cvInput.addEventListener('change', function() {
        if (this.files.length > 0) {
            const fileName = this.files[0].name;
            fileLabel.textContent = fileName.length > 25 ? fileName.substring(0, 22) + '...' : fileName;
            
            // Dosya boyutu kontrolü
            const fileSize = this.files[0].size / 1024 / 1024; // MB cinsinden
            if (fileSize > 5) {
                alert('Dosya boyutu 5MB\'den büyük olamaz!');
                this.value = '';
                fileLabel.textContent = originalText;
            }
        } else {
            fileLabel.textContent = originalText;
        }
    });

    // "Diğer" şehir seçildiğinde input göster
    const citySelect = document.getElementById('city');
    const otherCityDiv = document.getElementById('otherCity');
    const otherCityInput = document.getElementById('other_city_input');
    
    citySelect.addEventListener('change', function() {
        if (this.value === 'other') {
            otherCityDiv.classList.remove('d-none');
            otherCityInput.setAttribute('name', 'city');
            otherCityInput.setAttribute('required', 'required');
            this.removeAttribute('name');
        } else {
            otherCityDiv.classList.add('d-none');
            otherCityInput.removeAttribute('name');
            otherCityInput.removeAttribute('required');
            this.setAttribute('name', 'city');
        }
    });
    
    // "Diğer" eğitim seçildiğinde input göster
    const educationSelect = document.getElementById('education');
    const otherEducationDiv = document.getElementById('otherEducation');
    const otherEducationInput = document.getElementById('other_education_input');
    
    educationSelect.addEventListener('change', function() {
        if (this.value === 'other') {
            otherEducationDiv.classList.remove('d-none');
            otherEducationInput.setAttribute('name', 'education');
            otherEducationInput.setAttribute('required', 'required');
            this.removeAttribute('name');
        } else {
            otherEducationDiv.classList.add('d-none');
            otherEducationInput.removeAttribute('name');
            otherEducationInput.removeAttribute('required');
            this.setAttribute('name', 'education');
        }
    });
    
    // Form doğrulama
    const applicationForm = document.getElementById('application-form');
    applicationForm.addEventListener('submit', function(e) {
        const requiredFields = this.querySelectorAll('[required]');
        let allValid = true;
        
        requiredFields.forEach(field => {
            if (!field.value.trim()) {
                allValid = false;
                field.classList.add('is-invalid');
            } else {
                field.classList.remove('is-invalid');
            }
        });
        
        // Kontrol kutusunu kontrol et
        const termsCheckbox = document.getElementById('terms');
        if (!termsCheckbox.checked) {
            allValid = false;
            termsCheckbox.classList.add('is-invalid');
        } else {
            termsCheckbox.classList.remove('is-invalid');
        }
        
        if (!allValid) {
            e.preventDefault();
            alert('Lütfen tüm zorunlu alanları doldurun.');
        }
    });
});
</script>

<?php 
require_once 'includes/footer.php';
// Output buffer içeriğini gönder ve buffer'ı temizle
ob_end_flush();
?>