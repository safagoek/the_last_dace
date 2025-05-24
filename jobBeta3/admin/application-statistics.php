<?php
// Veritabanı bağlantısı
$servername = "localhost";
$username = "root"; 
$password = "";
$dbname = "job_application_system_db";

// Tarih ve kullanıcı bilgileri
$currentDateTime = "2025-03-21 23:33:59";
$currentUser = "safagoek";

// Hata raporlamayı açalım
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Bağlantıyı oluştur
try {
    $conn = new mysqli($servername, $username, $password, $dbname);
    
    // Bağlantıyı kontrol et
    if ($conn->connect_error) {
        throw new Exception("Veritabanı bağlantı hatası: " . $conn->connect_error);
    }
    
    // UTF-8 karakter seti ayarı
    $conn->set_charset("utf8mb4");
    
    // GERÇEK VERİTABANI SORGULARI (Güncel tablo isimleriyle)
    
    // 1. Tarih bazlı başvuru sayılarını getir
    $sqlTarihliBasvurular = "SELECT DATE(created_at) as basvuru_tarihi, COUNT(*) as basvuru_sayisi 
                            FROM applications 
                            GROUP BY DATE(created_at) 
                            ORDER BY basvuru_tarihi ASC";
    $resultTarihliBasvurular = $conn->query($sqlTarihliBasvurular);
    $tarihBazlıBasvuruSayilari = [];
    if ($resultTarihliBasvurular && $resultTarihliBasvurular->num_rows > 0) {
        while($row = $resultTarihliBasvurular->fetch_assoc()) {
            $tarihBazlıBasvuruSayilari[] = $row;
        }
    }
    
    // 2. Aylık başvuru sayılarını getir
    $sqlAylikBasvurular = "SELECT 
                            YEAR(created_at) as yil,
                            MONTH(created_at) as ay,
                            CONCAT(
                                CASE 
                                    WHEN MONTH(created_at) = 1 THEN 'Ocak'
                                    WHEN MONTH(created_at) = 2 THEN 'Şubat'
                                    WHEN MONTH(created_at) = 3 THEN 'Mart'
                                    WHEN MONTH(created_at) = 4 THEN 'Nisan'
                                    WHEN MONTH(created_at) = 5 THEN 'Mayıs'
                                    WHEN MONTH(created_at) = 6 THEN 'Haziran'
                                    WHEN MONTH(created_at) = 7 THEN 'Temmuz'
                                    WHEN MONTH(created_at) = 8 THEN 'Ağustos'
                                    WHEN MONTH(created_at) = 9 THEN 'Eylül'
                                    WHEN MONTH(created_at) = 10 THEN 'Ekim'
                                    WHEN MONTH(created_at) = 11 THEN 'Kasım'
                                    WHEN MONTH(created_at) = 12 THEN 'Aralık'
                                END, ' ', YEAR(created_at)
                            ) as ay_adi,
                            COUNT(*) as basvuru_sayisi 
                        FROM applications 
                        GROUP BY YEAR(created_at), MONTH(created_at) 
                        ORDER BY yil ASC, ay ASC";
    $resultAylikBasvurular = $conn->query($sqlAylikBasvurular);
    $aylikBasvuruSayilari = [];
    if ($resultAylikBasvurular && $resultAylikBasvurular->num_rows > 0) {
        while($row = $resultAylikBasvurular->fetch_assoc()) {
            $aylikBasvuruSayilari[] = $row;
        }
    }
    
    // 3. İş ilanları listesini getir
    $sqlIsIlanlari = "SELECT j.id, j.title as baslik, j.description as aciklama, j.location as konum, j.status as durum,
                        j.created_at as olusturulma_tarihi,
                        (SELECT COUNT(*) FROM applications WHERE job_id = j.id) as basvuru_sayisi 
                      FROM jobs j
                      ORDER BY basvuru_sayisi DESC";
    $resultIsIlanlari = $conn->query($sqlIsIlanlari);
    $isIlanlari = [];
    if ($resultIsIlanlari && $resultIsIlanlari->num_rows > 0) {
        while($row = $resultIsIlanlari->fetch_assoc()) {
            // Türkçe durum çevirisi
            $row['durum_tr'] = ($row['durum'] == 'active') ? 'Aktif' : 'Pasif';
            $isIlanlari[] = $row;
        }
    }
    
    // 4. İş başına ortalama maaş beklentisi
    $sqlOrtalamaMaas = "SELECT j.id as is_id, j.title as is_basligi, 
                        AVG(a.salary_expectation) as ort_maas 
                      FROM applications a
                      JOIN jobs j ON a.job_id = j.id
                      GROUP BY j.id
                      ORDER BY ort_maas DESC";
    $resultOrtalamaMaas = $conn->query($sqlOrtalamaMaas);
    $isBasindaOrtalamaMaas = [];
    if ($resultOrtalamaMaas && $resultOrtalamaMaas->num_rows > 0) {
        while($row = $resultOrtalamaMaas->fetch_assoc()) {
            $isBasindaOrtalamaMaas[] = $row;
        }
    }
    
    // 5. İş başına ortalama deneyim
    $sqlOrtalamaDeneyim = "SELECT j.id as is_id, j.title as is_basligi, 
                            AVG(a.experience) as ort_deneyim,
                            COUNT(*) as basvuru_sayisi 
                          FROM applications a
                          JOIN jobs j ON a.job_id = j.id
                          GROUP BY j.id
                          ORDER BY ort_deneyim DESC";
    $resultOrtalamaDeneyim = $conn->query($sqlOrtalamaDeneyim);
    $isBasindaOrtalamaDeneyim = [];
    if ($resultOrtalamaDeneyim && $resultOrtalamaDeneyim->num_rows > 0) {
        while($row = $resultOrtalamaDeneyim->fetch_assoc()) {
            $isBasindaOrtalamaDeneyim[] = $row;
        }
    }
    
    // 6. Cinsiyet dağılımı
    $sqlCinsiyet = "SELECT gender as cinsiyet, COUNT(*) as sayi 
                   FROM applications 
                   GROUP BY cinsiyet";
    $resultCinsiyet = $conn->query($sqlCinsiyet);
    $cinsiyetDagilimi = [];
    if ($resultCinsiyet && $resultCinsiyet->num_rows > 0) {
        while($row = $resultCinsiyet->fetch_assoc()) {
            // Türkçe cinsiyet çevirisi
            if ($row['cinsiyet'] == 'male') {
                $row['cinsiyet_tr'] = 'Erkek';
            } else if ($row['cinsiyet'] == 'female') {
                $row['cinsiyet_tr'] = 'Kadın';
            } else {
                $row['cinsiyet_tr'] = 'Diğer';
            }
            $cinsiyetDagilimi[] = $row;
        }
    }
    
    // 7. En çok başvuru yapılan şehirler
    $sqlSehirler = "SELECT city as sehir, COUNT(*) as basvuru_sayisi 
                    FROM applications 
                    GROUP BY sehir 
                    ORDER BY basvuru_sayisi DESC 
                    LIMIT 10";
    $resultSehirler = $conn->query($sqlSehirler);
    $enCokBasvuruYapilanSehirler = [];
    if ($resultSehirler && $resultSehirler->num_rows > 0) {
        while($row = $resultSehirler->fetch_assoc()) {
            $enCokBasvuruYapilanSehirler[] = $row;
        }
    }
    
    // 8. İlan bazında başvuranların mezun oldukları bölümler
    $sqlMezuniyetBolumleri = "SELECT j.id as is_id, j.title as is_basligi, 
                              a.education as bolum, 
                              COUNT(*) as basvuru_sayisi
                             FROM applications a
                             JOIN jobs j ON a.job_id = j.id
                             GROUP BY j.id, a.education
                             ORDER BY j.id, basvuru_sayisi DESC";
    $resultMezuniyetBolumleri = $conn->query($sqlMezuniyetBolumleri);
    $isIlaniMezuniyetBolumleri = [];
    if ($resultMezuniyetBolumleri && $resultMezuniyetBolumleri->num_rows > 0) {
        while($row = $resultMezuniyetBolumleri->fetch_assoc()) {
            $isIlaniMezuniyetBolumleri[] = $row;
        }
    }
    
    // 9. İş ilanlarına göre ortalama CV puanı
    $sqlCVPuani = "SELECT j.id as is_id, j.title as is_basligi, 
                    AVG(a.cv_score) as ort_cv_puani
                   FROM applications a
                   JOIN jobs j ON a.job_id = j.id
                   GROUP BY j.id
                   ORDER BY ort_cv_puani DESC";
    $resultCVPuani = $conn->query($sqlCVPuani);
    $isIlaniOrtalamaCVPuani = [];
    if ($resultCVPuani && $resultCVPuani->num_rows > 0) {
        while($row = $resultCVPuani->fetch_assoc()) {
            $isIlaniOrtalamaCVPuani[] = $row;
        }
    }
    
    // 10. Cinsiyet ve ilan bazında detaylı analizler
    $sqlCinsiyetIlanAnaliz = "SELECT 
                               j.id as is_id,
                               j.title as is_basligi,
                               a.gender as cinsiyet,
                               AVG(a.experience) as ort_deneyim,
                               AVG(a.age) as ort_yas,
                               AVG(a.salary_expectation) as ort_maas_beklentisi,
                               AVG(a.cv_score) as ort_puan
                             FROM applications a
                             JOIN jobs j ON a.job_id = j.id
                             GROUP BY j.id, a.gender";
    $resultCinsiyetIlanAnaliz = $conn->query($sqlCinsiyetIlanAnaliz);
    $cinsiyetIlanDetaylari = [];
    if ($resultCinsiyetIlanAnaliz && $resultCinsiyetIlanAnaliz->num_rows > 0) {
        while($row = $resultCinsiyetIlanAnaliz->fetch_assoc()) {
            $cinsiyetIlanDetaylari[] = $row;
        }
    }
    
    // 11. En çok yanlış yapılan sorular
    $sqlYanlisSorular = "SELECT 
                         q.id as soru_id,
                         q.question_text as soru_metni,
                         j.title as is_basligi,
                         o.option_text as dogru_cevap,
                         (COUNT(aa.id) - SUM(CASE WHEN aa.option_id = o.id THEN 1 ELSE 0 END)) / COUNT(aa.id) * 100 as yanlis_yapilma_orani
                      FROM questions q
                      JOIN jobs j ON q.job_id = j.id
                      JOIN options o ON o.question_id = q.id AND o.is_correct = 1
                      JOIN application_answers aa ON aa.question_id = q.id
                      WHERE q.question_type = 'multiple_choice'
                      GROUP BY q.id
                      ORDER BY yanlis_yapilma_orani DESC
                      LIMIT 5";
    $resultYanlisSorular = $conn->query($sqlYanlisSorular);
    $enCokYanlisYapilanSorular = [];
    if ($resultYanlisSorular && $resultYanlisSorular->num_rows > 0) {
        while($row = $resultYanlisSorular->fetch_assoc()) {
            $enCokYanlisYapilanSorular[] = $row;
        }
    }
    
    // 12. Haftanın günlerine göre başvuru dağılımı
    $sqlGunDagilimi = "SELECT 
                        WEEKDAY(created_at) as gun_no,
                        CASE WEEKDAY(created_at)
                            WHEN 0 THEN 'Pazartesi'
                            WHEN 1 THEN 'Salı'
                            WHEN 2 THEN 'Çarşamba'
                            WHEN 3 THEN 'Perşembe'
                            WHEN 4 THEN 'Cuma'
                            WHEN 5 THEN 'Cumartesi'
                            WHEN 6 THEN 'Pazar'
                        END as gun_adi,
                        COUNT(*) as basvuru_sayisi
                      FROM applications
                      GROUP BY gun_no, gun_adi
                      ORDER BY gun_no";
    $resultGunDagilimi = $conn->query($sqlGunDagilimi);
    $basvuruGunDagilimi = [];
    if ($resultGunDagilimi && $resultGunDagilimi->num_rows > 0) {
        while($row = $resultGunDagilimi->fetch_assoc()) {
            $basvuruGunDagilimi[] = $row;
        }
    }
    
    // 13. Eğitim dağılımı
    $sqlEgitim = "SELECT education as egitim, COUNT(*) as sayi 
                 FROM applications 
                 GROUP BY egitim";
    $resultEgitim = $conn->query($sqlEgitim);
    $egitimDagilimi = [];
    if ($resultEgitim && $resultEgitim->num_rows > 0) {
        while($row = $resultEgitim->fetch_assoc()) {
            $egitimDagilimi[] = $row;
        }
    }
    
    // 14. Başvuru durumları
    $sqlDurumlar = "SELECT status as durum, COUNT(*) as sayi 
                   FROM applications 
                   GROUP BY durum";
    $resultDurumlar = $conn->query($sqlDurumlar);
    $durumDagilimi = [];
    if ($resultDurumlar && $resultDurumlar->num_rows > 0) {
        while($row = $resultDurumlar->fetch_assoc()) {
            if ($row['durum'] == 'new') {
                $row['durum_tr'] = 'Yeni';
            } else if ($row['durum'] == 'reviewed') {
                $row['durum_tr'] = 'İncelenmiş';
            }
            $durumDagilimi[] = $row;
        }
    }
    
    // 15. Toplam başvuru sayısı
    $sqlToplamBasvuru = "SELECT COUNT(*) as toplam FROM applications";
    $resultToplamBasvuru = $conn->query($sqlToplamBasvuru);
    $toplamBasvuru = 0;
    if ($resultToplamBasvuru && $resultToplamBasvuru->num_rows > 0) {
        $row = $resultToplamBasvuru->fetch_assoc();
        $toplamBasvuru = $row['toplam'];
    }
    
    // Bağlantıyı kapat
    $conn->close();
    
} catch (Exception $e) {
    // Hata durumunda hata mesajını göster
    echo "<div class='alert alert-danger'>Veritabanı hatası: " . $e->getMessage() . "</div>";
    
    // Veri olmaması durumunda örnek veriler oluşturalım
    $tarihBazlıBasvuruSayilari = [
        ['basvuru_tarihi' => '2025-03-01', 'basvuru_sayisi' => 15],
        ['basvuru_tarihi' => '2025-03-02', 'basvuru_sayisi' => 22], 
        ['basvuru_tarihi' => '2025-03-03', 'basvuru_sayisi' => 18]
    ];
    $aylikBasvuruSayilari = [
        ['yil' => 2025, 'ay' => 1, 'ay_adi' => 'Ocak 2025', 'basvuru_sayisi' => 320]
    ];
    $isIlanlari = [
        ['id' => 1, 'baslik' => 'Yazılım Mühendisi', 'durum' => 'active', 'durum_tr' => 'Aktif', 'basvuru_sayisi' => 15]
    ];
    $isBasindaOrtalamaMaas = [
        ['is_id' => 1, 'is_basligi' => 'Yazılım Mühendisi', 'ort_maas' => 25000]
    ];
    $isBasindaOrtalamaDeneyim = [
        ['is_id' => 1, 'is_basligi' => 'Yazılım Mühendisi', 'ort_deneyim' => 3.5, 'basvuru_sayisi' => 15]
    ];
    $cinsiyetDagilimi = [
        ['cinsiyet' => 'male', 'cinsiyet_tr' => 'Erkek', 'sayi' => 25],
        ['cinsiyet' => 'female', 'cinsiyet_tr' => 'Kadın', 'sayi' => 20]
    ];
    $enCokBasvuruYapilanSehirler = [
        ['sehir' => 'İstanbul', 'basvuru_sayisi' => 25],
        ['sehir' => 'Ankara', 'basvuru_sayisi' => 15],
        ['sehir' => 'İzmir', 'basvuru_sayisi' => 10]
    ];
    $isIlaniMezuniyetBolumleri = [
        ['is_id' => 1, 'is_basligi' => 'Yazılım Mühendisi', 'bolum' => 'Bilgisayar Mühendisliği', 'basvuru_sayisi' => 15]
    ];
    $isIlaniOrtalamaCVPuani = [
        ['is_id' => 1, 'is_basligi' => 'Yazılım Mühendisi', 'ort_cv_puani' => 80]
    ];
    $cinsiyetIlanDetaylari = [
        ['is_id' => 1, 'is_basligi' => 'Yazılım Mühendisi', 'cinsiyet' => 'male', 'ort_deneyim' => 3.5]
    ];
    $enCokYanlisYapilanSorular = [
        ['soru_id' => 1, 'soru_metni' => 'Örnek Soru', 'is_basligi' => 'Yazılım Mühendisi', 'dogru_cevap' => 'Doğru Cevap', 'yanlis_yapilma_orani' => 65]
    ];
    $basvuruGunDagilimi = [
        ['gun_no' => 0, 'gun_adi' => 'Pazartesi', 'basvuru_sayisi' => 15]
    ];
    $egitimDagilimi = [
        ['egitim' => 'Lisans', 'sayi' => 30]
    ];
    $durumDagilimi = [
        ['durum' => 'new', 'durum_tr' => 'Yeni', 'sayi' => 20],
        ['durum' => 'reviewed', 'durum_tr' => 'İncelenmiş', 'sayi' => 25]
    ];
    $toplamBasvuru = 45;
}

// PHP verilerini JSON'a çevir
$tarihBazliBasvuruSayilariJSON = json_encode($tarihBazlıBasvuruSayilari);
$aylikBasvuruSayilariJSON = json_encode($aylikBasvuruSayilari);
$isIlanlariJSON = json_encode($isIlanlari);
$isBasindaOrtalamaMaasJSON = json_encode($isBasindaOrtalamaMaas);
$isBasindaOrtalamaDeneyimJSON = json_encode($isBasindaOrtalamaDeneyim);
$cinsiyetDagilimiJSON = json_encode($cinsiyetDagilimi);
$enCokBasvuruYapilanSehirlerJSON = json_encode($enCokBasvuruYapilanSehirler);
$isIlaniMezuniyetBolumleriJSON = json_encode($isIlaniMezuniyetBolumleri);
$isIlaniOrtalamaCVPuaniJSON = json_encode($isIlaniOrtalamaCVPuani);
$cinsiyetIlanDetaylariJSON = json_encode($cinsiyetIlanDetaylari);
$enCokYanlisYapilanSorularJSON = json_encode($enCokYanlisYapilanSorular);
$basvuruGunDagilimiJSON = json_encode($basvuruGunDagilimi);
$egitimDagilimiJSON = json_encode($egitimDagilimi);
$durumDagilimiJSON = json_encode($durumDagilimi);
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>İş Başvuruları Analitik Panosu</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- ApexCharts -->
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
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
        
        .date-filter-badge {
            font-size: 0.8rem;
            padding: 0.35rem 0.75rem;
            border-radius: 50px;
            background-color: var(--light);
            border: 1px solid var(--card-border);
            color: var(--dark);
            font-weight: 600;
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
        
        /* KPI cards */
        .kpi-card {
            background-color: #fff;
            border-radius: 10px;
            box-shadow: var(--card-shadow);
            padding: 1.25rem;
            height: 100%;
        }
        
        .kpi-title {
            font-size: 0.9rem;
            color: var(--secondary);
            margin-bottom: 1rem;
        }
        
        .kpi-value {
            font-size: 2rem;
            font-weight: 700;
        }
        
        .kpi-badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
            border-radius: 50px;
            margin-left: 0.5rem;
        }
        
        .kpi-badge-success {
            background-color: rgba(46, 204, 113, 0.15);
            color: var(--success);
        }
        
        .kpi-badge-danger {
            background-color: rgba(231, 76, 60, 0.15);
            color: var(--danger);
        }
        
        /* Chart containers */
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }
        
        .chart-container-sm {
            height: 200px;
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
        
        /* Filter dropdown */
        .filter-dropdown .dropdown-menu {
            padding: 1rem;
            width: 300px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        .filter-dropdown .dropdown-item {
            padding: 0.5rem 1rem;
            border-radius: 5px;
        }
        
        .filter-dropdown .dropdown-item:hover {
            background-color: #f8f9fa;
        }
        
        .filter-dropdown .dropdown-item.active {
            background-color: var(--primary);
            color: #fff;
        }
        
        /* Custom date range */
        .date-range-container {
            display: none;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid #eee;
        }
        
        /* Mini stats */
        .mini-stat {
            display: flex;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .mini-stat-icon {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            border-radius: 8px;
            margin-right: 1rem;
        }
        
        .mini-stat-content {
            flex: 1;
        }
        
        .mini-stat-label {
            font-size: 0.8rem;
            color: var(--secondary);
            margin-bottom: 0.25rem;
        }
        
        .mini-stat-value {
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 0;
        }
        
        .bg-soft-primary {
            background-color: rgba(67, 97, 238, 0.15);
            color: var(--primary);
        }
        
        .bg-soft-success {
            background-color: rgba(46, 204, 113, 0.15);
            color: var(--success);
        }
        
        .bg-soft-info {
            background-color: rgba(52, 152, 219, 0.15);
            color: var(--info);
        }
        
        .bg-soft-warning {
            background-color: rgba(243, 156, 18, 0.15);
            color: var(--warning);
        }
        
        .bg-soft-danger {
            background-color: rgba(231, 76, 60, 0.15);
            color: var(--danger);
        }
        
        /* Dashboard summary */
        .dashboard-summary {
            background-color: #fff;
            border-radius: 10px;
            box-shadow: var(--card-shadow);
            margin-bottom: 1.5rem;
            padding: 1.5rem;
            border-left: 4px solid var(--primary);
        }
        
        .summary-icon {
            width: 48px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: rgba(67, 97, 238, 0.15);
            color: var(--primary);
            border-radius: 12px;
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
        }
        
        .summary-value {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }
        
        .summary-label {
            color: var(--secondary);
            font-size: 0.85rem;
        }
        
        .dashboard-quick-stats {
            background-color: var(--light);
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .quick-stat {
            display: flex;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .quick-stat:last-child {
            margin-bottom: 0;
        }
        
        .quick-stat-icon {
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background-color: rgba(67, 97, 238, 0.15);
            color: var(--primary);
            margin-right: 1rem;
            font-size: 1rem;
        }
        
        .quick-stat-content {
            flex: 1;
        }
        
        .quick-stat-value {
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            line-height: 1;
        }
        
        .quick-stat-label {
            color: var(--secondary);
            font-size: 0.8rem;
            margin-bottom: 0;
        }
    </style>
</head>

<body>    <!-- Navbar -->
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
                            <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">
                                <i class="bi bi-person-fill"></i>
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
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h1 class="page-title">Başvuru İstatistikleri</h1>
                    <p class="page-subtitle">Detaylı analitik veriler ve grafikler</p>
                </div>
                <div class="col-md-6 text-md-end">
                    <span class="date-filter-badge">
                        <i class="bi bi-calendar3 me-1"></i>
                        Son 30 Gün
                    </span>
                </div>
            </div>
        </div>
    </div>
    
    <!-- İstatistik Kartları -->
    <div class="container">
        <div class="row g-3 mb-4">
            <!-- Toplam Başvuru Kartı -->
            <div class="col-lg-3 col-md-6">
                <div class="stat-card stat-primary">
                    <div class="stat-card-icon">
                        <i class="fas fa-clipboard-list"></i>
                    </div>
                    <div class="stat-card-content">
                        <div class="stat-card-title">Toplam Başvuru</div>
                        <div class="stat-card-value"><?php echo number_format($toplamBasvuru, 0, ',', '.'); ?></div>
                        <div class="text-success small fw-semibold">
                            <i class="fas fa-arrow-up me-1"></i>12.5% geçen aya göre
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Ortalama Maaş Kartı -->
            <div class="col-lg-3 col-md-6">
                <div class="stat-card stat-success">
                    <div class="stat-card-icon">
                        <i class="fas fa-money-bill-wave"></i>
                    </div>
                    <div class="stat-card-content">
                        <div class="stat-card-title">Ortalama Maaş Beklentisi</div>
                        <?php
                        $avgSalary = 0;
                        $count = 0;
                        foreach($isBasindaOrtalamaMaas as $maas) {
                            $avgSalary += $maas['ort_maas'];
                            $count++;
                        }
                        $avgSalary = $count > 0 ? $avgSalary / $count : 0;
                        ?>
                        <div class="stat-card-value">₺<?php echo number_format($avgSalary, 0, ',', '.'); ?></div>
                        <div class="text-success small fw-semibold">
                            <i class="fas fa-arrow-up me-1"></i>8.3% geçen aya göre
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Ortalama Deneyim Kartı -->
            <div class="col-lg-3 col-md-6">
                <div class="stat-card stat-warning">
                    <div class="stat-card-icon">
                        <i class="fas fa-briefcase"></i>
                    </div>
                    <div class="stat-card-content">
                        <div class="stat-card-title">Ortalama Deneyim</div>
                        <?php
                        $avgExp = 0;
                        $count = 0;
                        foreach($isBasindaOrtalamaDeneyim as $deneyim) {
                            $avgExp += $deneyim['ort_deneyim'];
                            $count++;
                        }
                        $avgExp = $count > 0 ? $avgExp / $count : 0;
                        ?>
                        <div class="stat-card-value"><?php echo number_format($avgExp, 1); ?> yıl</div>
                        <div class="text-danger small fw-semibold">
                            <i class="fas fa-arrow-down me-1"></i>2.1% geçen aya göre
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- İncelenen Başvurular Kartı -->
            <div class="col-lg-3 col-md-6">
                <div class="stat-card stat-info">
                    <div class="stat-card-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-card-content">
                        <div class="stat-card-title">İncelenen Başvurular</div>
                        <?php
                        $reviewedCount = 0;
                        foreach($durumDagilimi as $durum) {
                            if($durum['durum'] == 'reviewed') {
                                $reviewedCount = $durum['sayi'];
                                break;
                            }
                        }
                        $reviewPercentage = $toplamBasvuru > 0 ? ($reviewedCount / $toplamBasvuru) * 100 : 0;
                        ?>
                        <div class="d-flex align-items-center">
                            <div class="stat-card-value"><?php echo number_format($reviewedCount, 0, ',', '.'); ?></div>
                            <div class="text-secondary ms-2">/<?php echo number_format($toplamBasvuru, 0, ',', '.'); ?></div>
                        </div>
                        <div class="progress mt-2" style="height: 8px;">
                            <div class="progress-bar bg-info" role="progressbar" style="width: <?php echo $reviewPercentage; ?>%" aria-valuenow="<?php echo $reviewPercentage; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- En Çok Başvurulan 3 İl -->
        <div class="card mb-4">
            <div class="card-header">
                <i class="fas fa-map-marker-alt me-2 text-primary"></i>En Çok Başvuru Yapılan İller
            </div>
            <div class="card-body">
                <div class="row">
                    <?php 
                    // İlk 3 şehir için mini kartlar
                    $topCities = array_slice($enCokBasvuruYapilanSehirler, 0, 3);
                    $cityColors = ['primary', 'success', 'warning'];
                    $cityIcons = ['building', 'city', 'landmark'];
                    
                    foreach($topCities as $index => $city) {
                        $color = $cityColors[$index];
                        $icon = $cityIcons[$index];
                        ?>
                        <div class="col-md-4 mb-3 mb-md-0">
                            <div class="mini-stat-card">
                                <div class="mini-stat-icon" style="background: linear-gradient(45deg, var(--<?php echo $color; ?>), var(--accent-<?php echo ($index == 0 ? 'purple' : ($index == 1 ? 'teal' : 'orange')); ?>));">
                                    <i class="fas fa-<?php echo $icon; ?>"></i>
                                </div>
                                <div class="mini-stat-content">
                                    <div class="mini-stat-value"><?php echo number_format($city['basvuru_sayisi'], 0, ',', '.'); ?> başvuru</div>
                                    <div class="mini-stat-label"><?php echo $city['sehir']; ?></div>
                                </div>
                            </div>
                        </div>
                    <?php } ?>
                </div>
            </div>
        </div>
        
        <!-- Başvuru Trendi ve Cinsiyet Grafikleri -->
        <div class="row">
            <!-- Başvuru Trendi -->
            <div class="col-lg-8 mb-4">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div><i class="fas fa-chart-line me-2 text-primary"></i>Başvuru Trendi</div>
                        <div class="btn-group" role="group">
                            <button type="button" class="btn btn-sm btn-outline-primary active" data-period="daily">Günlük</button>
                            <button type="button" class="btn btn-sm btn-outline-primary" data-period="monthly">Aylık</button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <div id="applicationTrendChart"></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Cinsiyet Dağılımı -->
            <div class="col-lg-4 mb-4">
                <div class="card h-100">
                    <div class="card-header">
                        <i class="fas fa-venus-mars me-2 text-primary"></i>Cinsiyet Dağılımı
                    </div>
                    <div class="card-body">
                        <div class="chart-container" style="height: 250px;">
                            <div id="genderDistributionChart"></div>
                        </div>
                        
                        <div class="row text-center mt-3">
                            <?php
                            foreach($cinsiyetDagilimi as $cinsiyet) {
                                $label = $cinsiyet['cinsiyet_tr'];
                                $value = $cinsiyet['sayi'];
                                $percentage = $toplamBasvuru > 0 ? round(($value / $toplamBasvuru) * 100, 1) : 0;
                                $colorClass = $cinsiyet['cinsiyet'] == 'male' ? 'primary' : 
                                    ($cinsiyet['cinsiyet'] == 'female' ? 'danger' : 'info');
                                ?>
                                <div class="col-4">
                                    <div class="fw-bold text-<?php echo $colorClass; ?>"><?php echo $percentage; ?>%</div>
                                    <div class="small text-muted"><?php echo $label; ?></div>
                                </div>
                            <?php } ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Quick Stats Dashboard -->
        <div class="dashboard-quick-stats mb-4">
            <div class="row">
                <div class="col-md-3 mb-3 mb-md-0">
                    <div class="quick-stat">
                        <div class="quick-stat-icon" style="background-color: rgba(67, 97, 238, 0.15);">
                            <i class="fas fa-user-graduate" style="color: var(--primary);"></i>
                        </div>
                        <div class="quick-stat-content">
                            <div class="quick-stat-value">
                                <?php
                                    $lisansCount = 0;
                                    foreach($egitimDagilimi as $egitim) {
                                        if (stripos($egitim['egitim'], 'lisans') !== false) {
                                            $lisansCount += $egitim['sayi'];
                                        }
                                    }
                                    echo number_format($lisansCount, 0, ',', '.');
                                ?>
                            </div>
                            <div class="quick-stat-label">Lisans Mezunu</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 mb-3 mb-md-0">
                    <div class="quick-stat">
                        <div class="quick-stat-icon" style="background-color: rgba(46, 204, 113, 0.15);">
                            <i class="fas fa-code" style="color: var(--success);"></i>
                        </div>
                        <div class="quick-stat-content">
                            <div class="quick-stat-value">
                                <?php
                                    $yazilimCount = 0;
                                    foreach($isIlaniMezuniyetBolumleri as $bolum) {
                                        if (stripos($bolum['bolum'], 'bilgisayar') !== false || stripos($bolum['bolum'], 'yazılım') !== false) {
                                            $yazilimCount += $bolum['basvuru_sayisi'];
                                        }
                                    }
                                    echo number_format($yazilimCount, 0, ',', '.');
                                ?>
                            </div>
                            <div class="quick-stat-label">BT/Yazılım Alanı</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3 mb-3 mb-md-0">
                    <div class="quick-stat">
                        <div class="quick-stat-icon" style="background-color: rgba(243, 156, 18, 0.15);">
                            <i class="fas fa-calendar-alt" style="color: var(--warning);"></i>
                        </div>
                        <div class="quick-stat-content">
                            <div class="quick-stat-value">
                                <?php
                                    // Son 7 günün başvuru sayısı
                                    $lastWeekCount = 0;
                                    $sevenDaysAgo = date('Y-m-d', strtotime('-7 days'));
                                    
                                    foreach($tarihBazlıBasvuruSayilari as $tarih) {
                                        if ($tarih['basvuru_tarihi'] >= $sevenDaysAgo) {
                                            $lastWeekCount += $tarih['basvuru_sayisi'];
                                        }
                                    }
                                    echo number_format($lastWeekCount, 0, ',', '.');
                                ?>
                            </div>
                            <div class="quick-stat-label">Son 7 Gün</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="quick-stat">
                        <div class="quick-stat-icon" style="background-color: rgba(231, 76, 60, 0.15);">
                            <i class="fas fa-star" style="color: var(--danger);"></i>
                        </div>
                        <div class="quick-stat-content">
                            <div class="quick-stat-value">
                                <?php
                                    $avgCVScore = 0;
                                    $count = 0;
                                    foreach($isIlaniOrtalamaCVPuani as $cvPuan) {
                                        $avgCVScore += $cvPuan['ort_cv_puani'];
                                        $count++;
                                    }
                                    $avgCVScore = $count > 0 ? round($avgCVScore / $count) : 0;
                                    echo $avgCVScore;
                                ?>
                            </div>
                            <div class="quick-stat-label">Ortalama CV Puanı</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- İş İlanlarına Göre Analiz Başlığı -->
        <h5 class="mb-3 fw-bold"><i class="fas fa-chart-bar text-primary me-2"></i>İş İlanlarına Göre Analizler</h5>
        
        <!-- İş İlanlarına Göre Maaş ve Deneyim Analizleri -->
        <div class="row mb-4">
            <!-- İş İlanlarına Göre Ortalama Maaş -->
            <div class="col-lg-6 mb-4">
                <div class="card h-100">
                    <div class="card-header">
                        <i class="fas fa-money-bill-alt me-2 text-primary"></i>İş İlanlarına Göre Ortalama Maaş
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <div id="jobSalaryChart"></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- İş İlanlarına Göre Ortalama Deneyim -->
            <div class="col-lg-6 mb-4">
                <div class="card h-100">
                    <div class="card-header">
                        <i class="fas fa-briefcase me-2 text-primary"></i>İş İlanlarına Göre Ortalama Deneyim
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <div id="jobExperienceChart"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- İş İlanlarına Göre CV Puanları -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-file-alt me-2 text-primary"></i>İş İlanlarına Göre Ortalama CV Puanı
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <div id="jobCVScoreChart"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Haftanın Günlerine Göre Başvuru Dağılımı -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-header">
                        <i class="fas fa-calendar-week me-2 text-primary"></i>Haftanın Günlerine Göre Başvurular
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <div id="weekdayDistributionChart"></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-header">
                        <i class="fas fa-graduation-cap me-2 text-primary"></i>Eğitim Durumu Dağılımı
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <div id="educationDistributionChart"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- İş İlanlarına Göre Mezun Olunan Bölüm Analizi -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div><i class="fas fa-graduation-cap me-2 text-primary"></i>İş İlanlarına Göre En Çok Başvuru Yapılan Bölümler</div>
                        <select class="form-select form-select-sm w-auto" id="jobDegreeFilter">
                            <option value="all">Tüm İlanlar</option>
                            <?php
                            // Benzersiz iş ilanlarını listelemek için
                            $uniqueJobs = [];
                            foreach ($isIlaniMezuniyetBolumleri as $item) {
                                $jobId = $item['is_id'];
                                $jobTitle = $item['is_basligi'];
                                
                                if (!in_array(['id' => $jobId, 'title' => $jobTitle], $uniqueJobs)) {
                                    $uniqueJobs[] = ['id' => $jobId, 'title' => $jobTitle];
                                    echo '<option value="'.$jobId.'">'.$jobTitle.'</option>';
                                }
                            }
                            ?>
                        </select>
                    </div>
                    <div class="card-body">
                        <div class="row" id="jobDegreeChartsContainer">
                            <!-- JavaScript tarafından doldurulacak -->
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- En Çok Yanlış Yapılan Sorular -->
        <h5 class="mb-3 fw-bold"><i class="fas fa-question-circle text-primary me-2"></i>Soru Analizi</h5>
        
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-times-circle me-2 text-danger"></i>En Çok Yanlış Yapılan Sorular
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th style="width: 5%">#</th>
                                        <th style="width: 45%">Soru</th>
                                        <th style="width: 20%">İlan</th>
                                        <th style="width: 15%">Doğru Cevap</th>
                                        <th style="width: 15%">Yanlış Oranı</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    // En çok yanlış yapılan soruları listele
                                    foreach ($enCokYanlisYapilanSorular as $index => $soru) {
                                        echo '<tr>';
                                        echo '<td>' . ($index + 1) . '</td>';
                                        echo '<td>' . $soru['soru_metni'] . '</td>';
                                        echo '<td>' . $soru['is_basligi'] . '</td>';
                                        echo '<td><span class="badge bg-success">' . $soru['dogru_cevap'] . '</span></td>';
                                        echo '<td>';
                                        
                                        // Yanlış yapma oranına göre renk belirleme
                                        $badgeClass = $soru['yanlis_yapilma_orani'] > 70 ? 'danger' : 
                                            ($soru['yanlis_yapilma_orani'] > 50 ? 'warning' : 'primary');
                                            
                                        echo '<div class="progress" style="height:6px">';
                                        echo '<div class="progress-bar bg-' . $badgeClass . '" style="width: ' . $soru['yanlis_yapilma_orani'] . '%"></div>';
                                        echo '</div>';
                                        echo '<div class="small mt-1 text-end">' . number_format($soru['yanlis_yapilma_orani'], 1) . '%</div>';
                                        echo '</td>';
                                        echo '</tr>';
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Aktif İş İlanları -->
        <h5 class="mb-3 fw-bold"><i class="fas fa-clipboard-list text-primary me-2"></i>Aktif İş İlanları</h5>
        
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <i class="fas fa-briefcase me-2 text-primary"></i>İlan Başına Başvuru Sayıları
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>İlan Adı</th>
                                        <th>Konum</th>
                                        <th>Durum</th>
                                        <th>Başvuru Sayısı</th>
                                        <th class="text-end">Detay</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($isIlanlari as $ilan): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($ilan['baslik']); ?></td>
                                        <td><?php echo htmlspecialchars($ilan['konum'] ?? 'Belirtilmemiş'); ?></td>
                                        <td>
                                            <?php if($ilan['durum'] == 'active'): ?>
                                            <span class="status-badge badge-reviewed">Aktif</span>
                                            <?php else: ?>
                                            <span class="status-badge bg-secondary bg-opacity-10 text-secondary">Pasif</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo $ilan['basvuru_sayisi']; ?></td>
                                        <td class="text-end">
                                            <button class="btn btn-sm btn-outline-primary">
                                                <i class="fas fa-eye me-1"></i>Görüntüle
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Sonuç -->
        <div class="text-center py-4 mb-4 text-muted small">
            <p>
                Son güncelleme: <?php echo $currentDateTime; ?> |
                Kullanıcı: <?php echo $currentUser; ?> |
                <span id="totalAnalyzedData"><?php echo number_format($toplamBasvuru, 0, ',', '.'); ?></span> veri analiz edildi
            </p>
        </div>
    </div>
    
    <script>
        // PHP verilerini JavaScript'e aktar
        const tarihBazliBasvuruSayilari = <?php echo $tarihBazliBasvuruSayilariJSON ?: '[]'; ?>;
        const aylikBasvuruSayilari = <?php echo $aylikBasvuruSayilariJSON ?: '[]'; ?>;
        const isIlanlari = <?php echo $isIlanlariJSON ?: '[]'; ?>;
        const isBasindaOrtalamaMaas = <?php echo $isBasindaOrtalamaMaasJSON ?: '[]'; ?>;
        const isBasindaOrtalamaDeneyim = <?php echo $isBasindaOrtalamaDeneyimJSON ?: '[]'; ?>;
        const cinsiyetDagilimi = <?php echo $cinsiyetDagilimiJSON ?: '[]'; ?>;
        const enCokBasvuruYapilanSehirler = <?php echo $enCokBasvuruYapilanSehirlerJSON ?: '[]'; ?>;
        const isIlaniMezuniyetBolumleri = <?php echo $isIlaniMezuniyetBolumleriJSON ?: '[]'; ?>;
        const isIlaniOrtalamaCVPuani = <?php echo $isIlaniOrtalamaCVPuaniJSON ?: '[]'; ?>;
        const cinsiyetIlanDetaylari = <?php echo $cinsiyetIlanDetaylariJSON ?: '[]'; ?>;
        const enCokYanlisYapilanSorular = <?php echo $enCokYanlisYapilanSorularJSON ?: '[]'; ?>;
        const basvuruGunDagilimi = <?php echo $basvuruGunDagilimiJSON ?: '[]'; ?>;
        const egitimDagilimi = <?php echo $egitimDagilimiJSON ?: '[]'; ?>;
        const durumDagilimi = <?php echo $durumDagilimiJSON ?: '[]'; ?>;
        
        // Sayfa yüklendiğinde çalışacak kod
        document.addEventListener("DOMContentLoaded", function() {
            // Başvuru trendi grafiği
            if (window.ApexCharts && tarihBazliBasvuruSayilari.length > 0) {
                const trendData = tarihBazliBasvuruSayilari.map(item => parseInt(item.basvuru_sayisi || 0));
                const trendLabels = tarihBazliBasvuruSayilari.map(item => item.basvuru_tarihi);
                
                const trendOptions = {
                    series: [{
                        name: 'Başvuru Sayısı',
                        data: trendData
                    }],
                    chart: {
                        type: 'area',
                        height: 350,
                        fontFamily: 'Inter, sans-serif',
                        toolbar: {
                            show: true
                        }
                    },
                    dataLabels: {
                        enabled: false
                    },
                    stroke: {
                        curve: 'smooth',
                        width: 3
                    },
                    colors: ['var(--primary)'],
                    fill: {
                        type: 'gradient',
                        gradient: {
                            shadeIntensity: 1,
                            opacityFrom: 0.7,
                            opacityTo: 0.2,
                            stops: [0, 90, 100]
                        }
                    },
                    xaxis: {
                        categories: trendLabels,
                        labels: {
                            style: {
                                fontFamily: 'Inter, sans-serif'
                            },
                            rotate: -45,
                            rotateAlways: false
                        }
                    },
                    yaxis: {
                        title: {
                            text: 'Başvuru Sayısı'
                        }
                    },
                    tooltip: {
                        y: {
                            formatter: function(val) {
                                return val + " başvuru";
                            }
                        }
                    },
                    grid: {
                        borderColor: '#f1f1f1',
                        strokeDashArray: 4
                    },
                    markers: {
                        size: 4,
                        colors: ['var(--primary)'],
                        strokeColors: '#fff',
                        strokeWidth: 2,
                        hover: {
                            size: 6
                        }
                    }
                };
                
                try {
                    const trendChart = new ApexCharts(document.querySelector("#applicationTrendChart"), trendOptions);
                    trendChart.render();
                    
                    // Tarih periyodu değişikliği dinleyicileri
                    document.querySelectorAll('[data-period]').forEach(button => {
                        button.addEventListener('click', function() {
                            // Aktif düğmeyi güncelle
                            document.querySelectorAll('[data-period]').forEach(btn => {
                                btn.classList.remove('active');
                            });
                            this.classList.add('active');
                            
                            const period = this.getAttribute('data-period');
                            let data = [];
                            let categories = [];
                            
                            switch(period) {
                                case 'daily':
                                    data = tarihBazliBasvuruSayilari.map(item => parseInt(item.basvuru_sayisi || 0));
                                    categories = tarihBazliBasvuruSayilari.map(item => item.basvuru_tarihi);
                                    break;
                                case 'monthly':
                                    if (aylikBasvuruSayilari.length > 0) {
                                        data = aylikBasvuruSayilari.map(item => parseInt(item.basvuru_sayisi || 0));
                                        categories = aylikBasvuruSayilari.map(item => item.ay_adi);
                                    }
                                    break;
                            }
                            
                            // Veri varsa grafiği güncelle
                            if (data.length > 0) {
                                trendChart.updateOptions({
                                    xaxis: {
                                        categories: categories
                                    }
                                });
                                trendChart.updateSeries([{
                                    name: 'Başvuru Sayısı',
                                    data: data
                                }]);
                            }
                        });
                    });
                } catch (error) {
                    console.error("Trend grafiği oluşturulurken hata oluştu:", error);
                }
            }
            
            // Cinsiyet dağılımı grafiği
            if (window.ApexCharts && cinsiyetDagilimi.length > 0) {
                const genderData = cinsiyetDagilimi.map(item => parseInt(item.sayi || 0));
                const genderLabels = cinsiyetDagilimi.map(item => item.cinsiyet_tr);
                
                const genderOptions = {
                    series: genderData,
                    chart: {
                        type: 'donut',
                        height: 250,
                        fontFamily: 'Inter, sans-serif'
                    },
                    labels: genderLabels,
                    colors: ['#4361ee', '#e74c3c', '#3498db'],
                    legend: {
                        position: 'bottom',
                        fontFamily: 'Inter, sans-serif'
                    },
                    dataLabels: {
                        enabled: true,
                        formatter: function(val) {
                            return Math.round(val) + "%";
                        }
                    },
                    plotOptions: {
                        pie: {
                            donut: {
                                size: '60%',
                                labels: {
                                    show: true,
                                    total: {
                                        show: true,
                                        label: 'Toplam',
                                        formatter: function(w) {
                                            return w.globals.seriesTotals.reduce((a, b) => a + b, 0);
                                        }
                                    }
                                }
                            }
                        }
                    }
                };
                
                try {
                    const genderChart = new ApexCharts(document.querySelector("#genderDistributionChart"), genderOptions);
                    genderChart.render();
                } catch (error) {
                    console.error("Cinsiyet grafiği oluşturulurken hata oluştu:", error);
                }
            }
            
            // İş İlanlarına Göre Ortalama Maaş Grafiği
            if (window.ApexCharts && isBasindaOrtalamaMaas.length > 0) {
                const jobSalaryOptions = {
                    series: [{
                        name: 'Ortalama Maaş Beklentisi',
                        data: isBasindaOrtalamaMaas.map(item => parseFloat(item.ort_maas || 0))
                    }],
                    chart: {
                        type: 'bar',
                        height: 350,
                        fontFamily: 'Inter, sans-serif',
                        toolbar: {
                            show: false
                        }
                    },
                    plotOptions: {
                        bar: {
                            horizontal: true,
                            barHeight: '70%',
                            borderRadius: 5
                        }
                    },
                    colors: ['#4361ee'],
                    dataLabels: {
                        enabled: true,
                        formatter: function(val) {
                            return '₺' + val.toLocaleString('tr-TR');
                        },
                        style: {
                            fontFamily: 'Inter, sans-serif'
                        }
                    },
                    xaxis: {
                        categories: isBasindaOrtalamaMaas.map(item => item.is_basligi),
                        labels: {
                            style: {
                                fontFamily: 'Inter, sans-serif'
                            }
                        },
                        title: {
                            text: 'Ortalama Maaş Beklentisi (₺)'
                        }
                    },
                    tooltip: {
                        y: {
                            formatter: function(val) {
                                return '₺' + val.toLocaleString('tr-TR');
                            }
                        }
                    }
                };
                
                try {
                    const jobSalaryChart = new ApexCharts(document.querySelector("#jobSalaryChart"), jobSalaryOptions);
                    jobSalaryChart.render();
                } catch (error) {
                    console.error("Maaş grafiği oluşturulurken hata oluştu:", error);
                }
            }
            
            // İş İlanlarına Göre Ortalama Deneyim Grafiği
            if (window.ApexCharts && isBasindaOrtalamaDeneyim.length > 0) {
                const jobExperienceOptions = {
                    series: [{
                        name: 'Ortalama Deneyim (Yıl)',
                        data: isBasindaOrtalamaDeneyim.map(item => parseFloat(item.ort_deneyim || 0))
                    }],
                    chart: {
                        type: 'bar',
                        height: 350,
                        fontFamily: 'Inter, sans-serif',
                        toolbar: {
                            show: false
                        }
                    },
                    plotOptions: {
                        bar: {
                            horizontal: true,
                            barHeight: '70%',
                            borderRadius: 5,
                        }
                    },
                    colors: ['#2ecc71'],
                    dataLabels: {
                        enabled: true,
                        formatter: function(val) {
                            return val.toFixed(1) + ' yıl';
                        },
                        style: {
                            fontFamily: 'Inter, sans-serif'
                        }
                    },
                    xaxis: {
                        categories: isBasindaOrtalamaDeneyim.map(item => item.is_basligi),
                        labels: {
                            style: {
                                fontFamily: 'Inter, sans-serif'
                            }
                        },
                        title: {
                            text: 'Ortalama Deneyim (Yıl)'
                        }
                    },
                    tooltip: {
                        y: {
                            formatter: function(val) {
                                return val.toFixed(1) + ' yıl';
                            }
                        }
                    }
                };
                
                try {
                    const jobExperienceChart = new ApexCharts(document.querySelector("#jobExperienceChart"), jobExperienceOptions);
                    jobExperienceChart.render();
                } catch (error) {
                    console.error("Deneyim grafiği oluşturulurken hata oluştu:", error);
                }
            }
            
            // İş İlanlarına Göre Ortalama CV Puanı Grafiği
            if (window.ApexCharts && isIlaniOrtalamaCVPuani.length > 0) {
                const jobCVScoreOptions = {
                    series: [{
                        name: 'Ortalama CV Puanı',
                        data: isIlaniOrtalamaCVPuani.map(item => parseFloat(item.ort_cv_puani || 0))
                    }],
                    chart: {
                        type: 'bar',
                        height: 350,
                        fontFamily: 'Inter, sans-serif',
                        toolbar: {
                            show: false
                        }
                    },
                    plotOptions: {
                        bar: {
                            horizontal: false,
                            columnWidth: '60%',
                            borderRadius: 5,
                            dataLabels: {
                                position: 'top'
                            }
                        }
                    },
                    colors: ['#3498db'],
                    dataLabels: {
                        enabled: true,
                        formatter: function(val) {
                            return val.toFixed(1);
                        },
                        offsetY: -20,
                        style: {
                            fontFamily: 'Inter, sans-serif',
                            fontSize: '12px',
                            fontWeight: 'bold'
                        }
                    },
                    xaxis: {
                        categories: isIlaniOrtalamaCVPuani.map(item => item.is_basligi),
                        labels: {
                            style: {
                                fontFamily: 'Inter, sans-serif',
                                fontSize: '12px'
                            },
                            rotate: -45,
                            rotateAlways: true
                        }
                    },
                    yaxis: {
                        min: 0,
                        max: 100,
                        title: {
                            text: 'CV Puanı (0-100)',
                            style: {
                                fontFamily: 'Inter, sans-serif'
                            }
                        }
                    },
                    tooltip: {
                        y: {
                            formatter: function(val) {
                                return val.toFixed(1) + ' / 100';
                            }
                        }
                    }
                };
                
                try {
                    const jobCVScoreChart = new ApexCharts(document.querySelector("#jobCVScoreChart"), jobCVScoreOptions);
                    jobCVScoreChart.render();
                } catch (error) {
                    console.error("CV Puanı grafiği oluşturulurken hata oluştu:", error);
                }
            }
            
            // Haftanın günlerine göre başvuru dağılımı grafiği
            if (window.ApexCharts && basvuruGunDagilimi.length > 0) {
                const weekdayData = basvuruGunDagilimi.map(item => parseInt(item.basvuru_sayisi || 0));
                const weekdayLabels = basvuruGunDagilimi.map(item => item.gun_adi);
                
                const weekdayOptions = {
                    series: [{
                        name: 'Başvuru Sayısı',
                        data: weekdayData
                    }],
                    chart: {
                        type: 'radar',
                        height: 320,
                        fontFamily: 'Inter, sans-serif',
                        dropShadow: {
                            enabled: true,
                            blur: 1,
                            left: 1,
                            top: 1
                        }
                    },
                    stroke: {
                        width: 2
                    },
                    fill: {
                        opacity: 0.2
                    },
                    markers: {
                        size: 4,
                        hover: {
                            size: 6
                        }
                    },
                    colors: ['#9333ea'],
                    xaxis: {
                        categories: weekdayLabels
                    },
                    yaxis: {
                        show: false
                    },
                    dataLabels: {
                        enabled: true,
                        background: {
                            enabled: true,
                            borderRadius: 2
                        }
                    }
                };
                
                try {
                    const weekdayChart = new ApexCharts(document.querySelector("#weekdayDistributionChart"), weekdayOptions);
                    weekdayChart.render();
                } catch (error) {
                    console.error("Haftalık dağılım grafiği oluşturulurken hata oluştu:", error);
                }
            }
            
            // Eğitim dağılımı grafiği
            if (window.ApexCharts && egitimDagilimi.length > 0) {
                const educationData = egitimDagilimi.map(item => parseInt(item.sayi || 0));
                const educationLabels = egitimDagilimi.map(item => item.egitim);
                
                const educationOptions = {
                    series: educationData,
                    chart: {
                        type: 'pie',
                        height: 320,
                        fontFamily: 'Inter, sans-serif'
                    },
                    labels: educationLabels,
                    colors: ['#f72585', '#4361ee', '#4cc9f0', '#06d6a0', '#ffd166'],
                    legend: {
                        position: 'bottom',
                        fontFamily: 'Inter, sans-serif'
                    },
                    dataLabels: {
                        enabled: true
                    },
                    plotOptions: {
                        pie: {
                            donut: {
                                size: '50%'
                            }
                        }
                    }
                };
                
                try {
                    const educationChart = new ApexCharts(document.querySelector("#educationDistributionChart"), educationOptions);
                    educationChart.render();
                } catch (error) {
                    console.error("Eğitim dağılımı grafiği oluşturulurken hata oluştu:", error);
                }
            }
            
            // İş başvurularına göre mezuniyet bölümleri analizi
            if (isIlaniMezuniyetBolumleri.length > 0) {
                // Verileri işle ve grafiklendir
                renderJobDegreeCharts('all');
                
                // Filtre değiştiğinde çalışacak fonksiyon
                const jobDegreeFilter = document.getElementById('jobDegreeFilter');
                if (jobDegreeFilter) {
                    jobDegreeFilter.addEventListener('change', function() {
                        renderJobDegreeCharts(this.value);
                    });
                }
                
                function renderJobDegreeCharts(jobFilter) {
                    const container = document.getElementById('jobDegreeChartsContainer');
                    if (!container) return;
                    
                    container.innerHTML = ''; // Önceki grafikleri temizle
                    
                    // İş ilanlarına göre mezuniyet bölümlerini grupla
                    const groupedData = {};
                    isIlaniMezuniyetBolumleri.forEach(item => {
                        if (jobFilter !== 'all' && item.is_id.toString() !== jobFilter) return;
                        
                        if (!groupedData[item.is_id]) {
                            groupedData[item.is_id] = {
                                id: item.is_id,
                                title: item.is_basligi,
                                data: []
                            };
                        }
                        
                        groupedData[item.is_id].data.push({
                            bolum: item.bolum,
                            basvuru_sayisi: parseInt(item.basvuru_sayisi || 0)
                        });
                    });
                    
                    // Her ilan için pasta grafik oluştur
                    Object.values(groupedData).forEach(job => {
                        // Grafik konteyner kolonu oluştur
                        const colDiv = document.createElement('div');
                        colDiv.className = 'col-md-6 mb-4';
                        
                        // Grafik konteyneri oluştur
                        const chartDiv = document.createElement('div');
                        chartDiv.id = `jobDegreeChart-${job.id}`;
                        chartDiv.style.height = '300px';
                        
                        // Kolonu sayfaya ekle
                        colDiv.appendChild(chartDiv);
                        container.appendChild(colDiv);
                        
                        // Grafik ayarları
                        const chartOptions = {
                            series: job.data.map(item => item.basvuru_sayisi),
                            chart: {
                                type: 'pie',
                                height: 300,
                                fontFamily: 'Inter, sans-serif'
                            },
                            labels: job.data.map(item => item.bolum),
                            title: {
                                text: job.title,
                                align: 'center',
                                style: {
                                    fontSize: '16px',
                                    fontFamily: 'Inter, sans-serif',
                                    fontWeight: 600
                                }
                            },
                            colors: ['#4361ee', '#3a0ca3', '#7209b7', '#f72585', '#4cc9f0', '#4f46e5', '#059669'],
                            legend: {
                                position: 'bottom',
                                fontFamily: 'Inter, sans-serif',
                                fontSize: '13px'
                            },
                            dataLabels: {
                                enabled: true,
                                formatter: function(val, opts) {
                                    return opts.w.config.series[opts.seriesIndex] + ' kişi';
                                }
                            },
                            responsive: [{
                                breakpoint: 480,
                                options: {
                                    legend: {
                                        position: 'bottom'
                                    }
                                }
                            }]
                        };
                        
                        // Grafiği oluştur ve göster
                        try {
                            new ApexCharts(document.getElementById(`jobDegreeChart-${job.id}`), chartOptions).render();
                        } catch (error) {
                            console.error("Mezuniyet grafiği oluşturulurken hata oluştu:", error);
                        }
                    });
                }
            }
            
            // Yenileme butonu
            document.getElementById('refreshBtn')?.addEventListener('click', function() {
                alert('Veriler yenileniyor...');
                window.location.reload();
            });
            
            // Yazdırma butonu
            document.getElementById('printBtn')?.addEventListener('click', function() {
                window.print();
            });
        });
    </script>
</body>
</html>