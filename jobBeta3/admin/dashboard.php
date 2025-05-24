<?php
// Output buffering başlat
ob_start();

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit;
}

require_once '../config/db.php';

// Zaman aralığı filtreleme
$time_filter = isset($_GET['time_filter']) ? $_GET['time_filter'] : 'last7days';
$custom_start = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$custom_end = isset($_GET['end_date']) ? $_GET['end_date'] : '';

// Zaman aralığı için WHERE cümlesi
$date_where = "";
switch ($time_filter) {
    case 'today':
        $date_where = "WHERE created_at >= CURRENT_DATE()";
        $date_label = "Bugün";
        break;
    case 'yesterday':
        $date_where = "WHERE created_at >= DATE_SUB(CURRENT_DATE(), INTERVAL 1 DAY) AND created_at < CURRENT_DATE()";
        $date_label = "Dün";
        break;
    case 'last7days':
        $date_where = "WHERE created_at >= DATE_SUB(CURRENT_DATE(), INTERVAL 7 DAY)";
        $date_label = "Son 7 Gün";
        break;
    case 'last30days':
        $date_where = "WHERE created_at >= DATE_SUB(CURRENT_DATE(), INTERVAL 30 DAY)";
        $date_label = "Son 30 Gün";
        break;
    case 'thismonth':
        $date_where = "WHERE MONTH(created_at) = MONTH(CURRENT_DATE()) AND YEAR(created_at) = YEAR(CURRENT_DATE())";
        $date_label = "Bu Ay";
        break;
    case 'lastmonth':
        $date_where = "WHERE MONTH(created_at) = MONTH(DATE_SUB(CURRENT_DATE(), INTERVAL 1 MONTH)) AND YEAR(created_at) = YEAR(DATE_SUB(CURRENT_DATE(), INTERVAL 1 MONTH))";
        $date_label = "Geçen Ay";
        break;
    case 'custom':
        if (!empty($custom_start) && !empty($custom_end)) {
            $date_where = "WHERE DATE(created_at) BETWEEN '$custom_start' AND '$custom_end'";
            $date_label = date('d.m.Y', strtotime($custom_start)) . " - " . date('d.m.Y', strtotime($custom_end));
        } else {
            $date_where = "WHERE created_at >= DATE_SUB(CURRENT_DATE(), INTERVAL 7 DAY)";
            $date_label = "Son 7 Gün";
        }
        break;
    default:
        $date_where = "WHERE created_at >= DATE_SUB(CURRENT_DATE(), INTERVAL 7 DAY)";
        $date_label = "Son 7 Gün";
}

// Başvuru trendi verisi (gün bazında)
$query = "
    SELECT 
        DATE(created_at) as date,
        COUNT(*) as count
    FROM 
        applications
    $date_where
    GROUP BY 
        DATE(created_at)
    ORDER BY 
        date ASC
";

$stmt = $db->prepare($query);
$stmt->execute();
$application_trend = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Chart için verileri hazırla
$trend_dates = [];
$trend_counts = [];

foreach ($application_trend as $item) {
    $trend_dates[] = date('d M', strtotime($item['date']));
    $trend_counts[] = (int)$item['count'];
}

// Toplam istatistikler
// 1. Toplam başvuru sayısı
$stmt = $db->prepare("SELECT COUNT(*) as count FROM applications");
$stmt->execute();
$total_applications = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;

// 2. Aktif iş ilanı sayısı
$stmt = $db->prepare("SELECT COUNT(*) as count FROM jobs WHERE status = 'active'");
$stmt->execute();
$active_jobs = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;

// 3. Seçili dönemdeki yeni başvurular
$stmt = $db->prepare("SELECT COUNT(*) as count FROM applications $date_where");
$stmt->execute();
$period_applications = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;

// 4. Bekleyen başvurular
$stmt = $db->prepare("SELECT COUNT(*) as count FROM applications WHERE status = 'new'");
$stmt->execute();
$pending_applications = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;

// 5. İncelenen başvurular
$stmt = $db->prepare("SELECT COUNT(*) as count FROM applications WHERE status = 'reviewed'");
$stmt->execute();
$reviewed_applications = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;

// 6. Ortalama başvuru puanı
$stmt = $db->prepare("SELECT AVG(score) as avg_score FROM applications WHERE score > 0");
$stmt->execute();
$avg_score = round($stmt->fetch(PDO::FETCH_ASSOC)['avg_score'] ?? 0, 1);

// 7. Ortalama CV puanı
$stmt = $db->prepare("SELECT AVG(cv_score) as avg_cv_score FROM applications WHERE cv_score > 0");
$stmt->execute();
$avg_cv_score = round($stmt->fetch(PDO::FETCH_ASSOC)['avg_cv_score'] ?? 0, 1);

// 8. Başvuru cinsiyeti dağılımı
$stmt = $db->prepare("
    SELECT 
        gender,
        COUNT(*) as count,
        ROUND((COUNT(*) / (SELECT COUNT(*) FROM applications)) * 100, 1) as percentage
    FROM 
        applications
    GROUP BY 
        gender
");
$stmt->execute();
$gender_distribution = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 9. Yaş gruplarına göre başvuru dağılımı
$stmt = $db->prepare("
    SELECT 
        CASE 
            WHEN age < 20 THEN '<20'
            WHEN age BETWEEN 20 AND 25 THEN '20-25'
            WHEN age BETWEEN 26 AND 30 THEN '26-30'
            WHEN age BETWEEN 31 AND 35 THEN '31-35'
            WHEN age BETWEEN 36 AND 40 THEN '36-40'
            WHEN age > 40 THEN '40+'
        END AS age_group,
        COUNT(*) as count,
        ROUND((COUNT(*) / (SELECT COUNT(*) FROM applications)) * 100, 1) as percentage
    FROM 
        applications
    GROUP BY 
        age_group
    ORDER BY 
        CASE 
            WHEN age_group = '<20' THEN 1
            WHEN age_group = '20-25' THEN 2
            WHEN age_group = '26-30' THEN 3
            WHEN age_group = '31-35' THEN 4
            WHEN age_group = '36-40' THEN 5
            WHEN age_group = '40+' THEN 6
        END
");
$stmt->execute();
$age_distribution = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 10. Lokasyona göre başvurular (Top 5)
$stmt = $db->prepare("
    SELECT 
        j.location,
        COUNT(a.id) as application_count,
        ROUND((COUNT(a.id) / (SELECT COUNT(*) FROM applications)) * 100, 1) as percentage
    FROM 
        applications a
    JOIN 
        jobs j ON a.job_id = j.id
    GROUP BY 
        j.location
    ORDER BY 
        application_count DESC
    LIMIT 5
");
$stmt->execute();
$locations_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 11. Pozisyona göre başvurular (Top 5)
$stmt = $db->prepare("
    SELECT 
        j.title,
        COUNT(a.id) as application_count,
        ROUND((COUNT(a.id) / (SELECT COUNT(*) FROM applications)) * 100, 1) as percentage
    FROM 
        applications a
    JOIN 
        jobs j ON a.job_id = j.id
    GROUP BY 
        j.title
    ORDER BY 
        application_count DESC
    LIMIT 5
");
$stmt->execute();
$positions_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 12. Eğitim düzeylerine göre başvuru dağılımı (Top 5)
$stmt = $db->prepare("
    SELECT 
        education,
        COUNT(*) as count,
        ROUND((COUNT(*) / (SELECT COUNT(*) FROM applications)) * 100, 1) as percentage
    FROM 
        applications
    GROUP BY 
        education
    ORDER BY 
        count DESC
    LIMIT 5
");
$stmt->execute();
$education_distribution = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 13. Maaş beklenti aralıkları
$stmt = $db->prepare("
    SELECT 
        CASE 
            WHEN salary_expectation < 10000 THEN '<10.000 TL'
            WHEN salary_expectation BETWEEN 10000 AND 15000 THEN '10.000 - 15.000 TL'
            WHEN salary_expectation BETWEEN 15001 AND 20000 THEN '15.001 - 20.000 TL'
            WHEN salary_expectation BETWEEN 20001 AND 30000 THEN '20.001 - 30.000 TL'
            WHEN salary_expectation BETWEEN 30001 AND 50000 THEN '30.001 - 50.000 TL'
            WHEN salary_expectation > 50000 THEN '50.000+ TL'
        END as salary_range,
        COUNT(*) as count,
        ROUND(AVG(salary_expectation), 0) as avg_salary,
        ROUND((COUNT(*) / (SELECT COUNT(*) FROM applications)) * 100, 1) as percentage
    FROM 
        applications
    GROUP BY 
        salary_range
    ORDER BY 
        CASE 
            WHEN salary_range = '<10.000 TL' THEN 1
            WHEN salary_range = '10.000 - 15.000 TL' THEN 2
            WHEN salary_range = '15.001 - 20.000 TL' THEN 3
            WHEN salary_range = '20.001 - 30.000 TL' THEN 4
            WHEN salary_range = '30.001 - 50.000 TL' THEN 5
            WHEN salary_range = '50.000+ TL' THEN 6
        END
");
$stmt->execute();
$salary_distribution = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 14. Başvuru değerlendirme puanları dağılımı
$stmt = $db->prepare("
    SELECT 
        CASE 
            WHEN score BETWEEN 0 AND 20 THEN '0-20'
            WHEN score BETWEEN 21 AND 40 THEN '21-40'
            WHEN score BETWEEN 41 AND 60 THEN '41-60'
            WHEN score BETWEEN 61 AND 80 THEN '61-80'
            WHEN score BETWEEN 81 AND 100 THEN '81-100'
            ELSE 'Değerlendirilmemiş'
        END AS score_range,
        COUNT(*) as count,
        ROUND((COUNT(*) / (SELECT COUNT(*) FROM applications)) * 100, 1) as percentage
    FROM 
        applications
    WHERE 
        score IS NOT NULL
    GROUP BY 
        score_range
    ORDER BY 
        CASE 
            WHEN score_range = 'Değerlendirilmemiş' THEN 0
            WHEN score_range = '0-20' THEN 1
            WHEN score_range = '21-40' THEN 2
            WHEN score_range = '41-60' THEN 3
            WHEN score_range = '61-80' THEN 4
            WHEN score_range = '81-100' THEN 5
        END
");
$stmt->execute();
$score_distribution = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 15. En yüksek performans gösteren ilanlar
$stmt = $db->prepare("
    SELECT 
        j.id,
        j.title,
        COUNT(a.id) as applicant_count,
        ROUND(AVG(a.score), 1) as avg_score,
        ROUND(AVG(a.cv_score), 1) as avg_cv_score
    FROM 
        jobs j
    LEFT JOIN 
        applications a ON j.id = a.job_id
    WHERE 
        j.status = 'active'
    GROUP BY 
        j.id, j.title
    ORDER BY 
        applicant_count DESC
    LIMIT 5
");
$stmt->execute();
$top_performing_jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 16. Zaman içinde başvuru kalitesi
$stmt = $db->prepare("
    SELECT 
        DATE_FORMAT(created_at, '%Y-%m') as month,
        DATE_FORMAT(created_at, '%b %Y') as month_label,
        ROUND(AVG(score), 1) as avg_score,
        COUNT(*) as applicant_count
    FROM 
        applications
    WHERE 
        score > 0
    GROUP BY 
        month, month_label
    ORDER BY 
        month ASC
    LIMIT 6
");
$stmt->execute();
$quality_trend = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 17. Son yapılan başvurular
$stmt = $db->prepare("
    SELECT 
        a.id, 
        a.first_name, 
        a.last_name, 
        a.email,
        a.phone,
        a.created_at,
        a.status,
        a.score,
        a.cv_score,
        j.title AS job_title,
        j.location AS job_location
    FROM 
        applications a
    JOIN 
        jobs j ON a.job_id = j.id
    ORDER BY 
        a.created_at DESC
    LIMIT 10
");
$stmt->execute();
$recent_applications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 18. Performans göstergeleri (KPI)
// Başvuru kabul oranı - Reviewed uygulamaların oranı
$acceptance_rate = $total_applications > 0 ? round(($reviewed_applications / $total_applications) * 100, 1) : 0;

// Yeni başvuru artış oranı
$stmt = $db->prepare("
    SELECT 
        COUNT(*) as current_count
    FROM 
        applications
    WHERE 
        created_at >= DATE_SUB(CURRENT_DATE(), INTERVAL 7 DAY)
");
$stmt->execute();
$current_week_count = $stmt->fetch(PDO::FETCH_ASSOC)['current_count'] ?? 0;

// Line 380 civarında, büyüme oranı hesaplama kısmı
// Önce hatanın olduğu bölümü bulun ve şununla değiştirin:

$stmt = $db->prepare("
    SELECT 
        COUNT(*) as previous_count
    FROM 
        applications
    WHERE 
        created_at BETWEEN DATE_SUB(CURRENT_DATE(), INTERVAL 14 DAY) AND DATE_SUB(CURRENT_DATE(), INTERVAL 7 DAY)
");
$stmt->execute();
$previous_count_result = $stmt->fetch(PDO::FETCH_ASSOC);
$previous_week_count = $previous_count_result['previous_count'] ?? 0;

// Sıfıra bölme hatasını önlemek için kontrol ekliyoruz
if ($previous_week_count == 0) {
    // Önceki hafta başvuru yoksa, mevcut hafta varsa %100 artış, yoksa %0 artış olarak göster
    $growth_rate = $current_week_count > 0 ? 100 : 0;
} else {
    $growth_rate = round((($current_week_count - $previous_week_count) / $previous_week_count) * 100, 1);
}

$growth_indicator = $growth_rate >= 0 ? 'up' : 'down';
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard |</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

<style>
    .admin-navbar {
        background-color: white;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        padding: 12px 0;
    }
    
    .admin-navbar .navbar-brand {
        font-weight: 600;
        color: #333;
        display: flex;
        align-items: center;
    }
    
    .admin-navbar .brand-icon {
        color: #4361ee;
        margin-right: 8px;
    }
    
    .admin-navbar .nav-link {
        color: #495057;
        padding: 0.5rem 0.8rem;
        border-radius: 6px;
        margin-right: 5px;
        transition: all 0.2s;
        display: flex;
        align-items: center;
        font-weight: 500;
    }
    
    .admin-navbar .nav-link i {
        margin-right: 6px;
        font-size: 1.1em;
    }
    
    .admin-navbar .nav-link:hover {
        color: #4361ee;
        background-color: #f8f9fa;
    }
    
    .admin-navbar .nav-link.active {
        color: #4361ee;
        background-color: #ebefff;
        font-weight: 600;
    }
    
    .admin-navbar .logout-link {
        color: #6c757d;
    }
    
    .admin-navbar .logout-link:hover {
        color: #dc3545;
        background-color: rgba(220, 53, 69, 0.1);
    }
    
    @media (max-width: 992px) {
        .admin-navbar .navbar-nav {
            padding-top: 15px;
        }
        
        .admin-navbar .nav-link {
            padding: 10px;
            margin-bottom: 5px;
        }
    }
</style>

    <div class="page-header">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h1 class="page-title">Dashboard</h1>
                    <p class="page-subtitle">Sistem genel bakışı ve analitik verileri</p>
                </div>
                <div class="col-md-6 text-md-end">
                    <div class="date-filter-badge mb-2 mb-md-0" data-bs-toggle="dropdown">
                        <i class="bi bi-calendar3 me-1"></i> <?= $date_label ?> <i class="bi bi-chevron-down ms-1"></i>
                    </div>
                    <div class="dropdown filter-dropdown d-inline-block">
                        <div class="dropdown-menu dropdown-menu-end">
                            <h6 class="dropdown-header">Zaman Aralığı</h6>
                            <a class="dropdown-item <?= $time_filter == 'today' ? 'active' : '' ?>" href="?time_filter=today">Bugün</a>
                            <a class="dropdown-item <?= $time_filter == 'yesterday' ? 'active' : '' ?>" href="?time_filter=yesterday">Dün</a>
                            <a class="dropdown-item <?= $time_filter == 'last7days' ? 'active' : '' ?>" href="?time_filter=last7days">Son 7 Gün</a>
                            <a class="dropdown-item <?= $time_filter == 'last30days' ? 'active' : '' ?>" href="?time_filter=last30days">Son 30 Gün</a>
                            <a class="dropdown-item <?= $time_filter == 'thismonth' ? 'active' : '' ?>" href="?time_filter=thismonth">Bu Ay</a>
                            <a class="dropdown-item <?= $time_filter == 'lastmonth' ? 'active' : '' ?>" href="?time_filter=lastmonth">Geçen Ay</a>
                            <a class="dropdown-item <?= $time_filter == 'custom' ? 'active' : '' ?>" href="#" id="customDateRange">Özel Aralık</a>
                            
                            <div class="date-range-container" id="dateRangeContainer">
                                <form action="" method="get">
                                    <input type="hidden" name="time_filter" value="custom">
                                    <div class="mb-2">
                                        <label class="form-label">Başlangıç Tarihi</label>
                                        <input type="date" name="start_date" class="form-control" value="<?= $custom_start ?>">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Bitiş Tarihi</label>
                                        <input type="date" name="end_date" class="form-control" value="<?= $custom_end ?>">
                                    </div>
                                    <button type="submit" class="btn btn-primary btn-sm w-100">Uygula</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="container mb-5">
        <!-- Dashboard Summary -->
        <div class="dashboard-summary">
            <div class="row">
                <div class="col-md-3 col-6 text-center mb-3 mb-md-0">
                    <div class="summary-icon mx-auto">
                        <i class="bi bi-file-earmark-person"></i>
                    </div>
                    <div class="summary-value"><?= $total_applications ?></div>
                    <div class="summary-label">Toplam Başvuru</div>
                </div>
                <div class="col-md-3 col-6 text-center mb-3 mb-md-0">
                    <div class="summary-icon mx-auto" style="background-color: rgba(46, 204, 113, 0.15); color: var(--success);">
                        <i class="bi bi-briefcase"></i>
                    </div>
                    <div class="summary-value"><?= $active_jobs ?></div>
                    <div class="summary-label">Aktif İlan</div>
                </div>
                <div class="col-md-3 col-6 text-center">
                    <div class="summary-icon mx-auto" style="background-color: rgba(52, 152, 219, 0.15); color: var(--info);">
                        <i class="bi bi-hourglass-split"></i>
                    </div>
                    <div class="summary-value"><?= $pending_applications ?></div>
                    <div class="summary-label">İncelenmeyi Bekleyen</div>
                </div>
                <div class="col-md-3 col-6 text-center">
                    <div class="summary-icon mx-auto" style="background-color: rgba(243, 156, 18, 0.15); color: var(--warning);">
                        <i class="bi bi-graph-up"></i>
                    </div>
                    <div class="summary-value"><?= $period_applications ?></div>
                    <div class="summary-label"><?= $date_label ?> Başvuru</div>
                </div>
            </div>
        </div>
        
        <!-- KPI İstatistikleri -->
        <div class="row">
            <div class="col-lg-4 mb-4">
                <div class="kpi-card">
                    <div class="d-flex align-items-center mb-4">
                        <div class="mini-stat-icon bg-soft-primary">
                            <i class="bi bi-speedometer2"></i>
                        </div>
                        <div class="ms-3">
                            <h5 class="mb-0">Performans Göstergeleri</h5>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-6 mb-3">
                            <div class="kpi-title">Başvuru Artışı</div>
                            <div class="d-flex align-items-center">
                                <div class="kpi-value"><?= $growth_rate ?>%</div>
                                <span class="kpi-badge <?= $growth_indicator === 'up' ? 'kpi-badge-success' : 'kpi-badge-danger' ?>">
                                    <i class="bi bi-arrow-<?= $growth_indicator ?>"></i>
                                </span>
                            </div>
                            <div class="text-muted small">Önceki haftaya göre</div>
                        </div>
                        
                        <div class="col-6 mb-3">
                            <div class="kpi-title">İnceleme Oranı</div>
                            <div class="d-flex align-items-center">
                                <div class="kpi-value"><?= $acceptance_rate ?>%</div>
                                <span class="kpi-badge kpi-badge-success">
                                    <i class="bi bi-check-circle"></i>
                                </span>
                            </div>
                            <div class="text-muted small">İncelenen başvurular</div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-6">
                            <div class="kpi-title">Ortalama Puan</div>
                            <div class="kpi-value"><?= $avg_score > 0 ? $avg_score : 'N/A' ?></div>
                            <div class="text-muted small">Başvuru puanı</div>
                        </div>
                        
                        <div class="col-6">
                            <div class="kpi-title">CV Puanı</div>
                            <div class="kpi-value"><?= $avg_cv_score > 0 ? $avg_cv_score : 'N/A' ?></div>
                            <div class="text-muted small">Ortalama CV puanı</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-8 mb-4">
                <div class="card h-100">
                    <div class="card-header">
                        <div class="card-title">
                            <i class="bi bi-graph-up me-2"></i>Başvuru Trendi - <?= $date_label ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="applicationTrendChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- İstatistik Kartları -->
            <div class="col-lg-4 col-md-6 mb-4">
                <div class="stat-card stat-primary">
                    <div class="stat-icon"><i class="bi bi-file-earmark-person"></i></div>
                    <div class="stat-title">Toplam Başvuru</div>
                    <div class="stat-value"><?= number_format($total_applications) ?></div>
                    <div class="stat-desc">
                        <?php if ($growth_rate >= 0): ?>
                            <i class="bi bi-arrow-up-right text-success"></i> 
                            <span class="text-success"><?= $growth_rate ?>%</span> artış son haftada
                        <?php else: ?>
                            <i class="bi bi-arrow-down-right text-danger"></i> 
                            <span class="text-danger"><?= abs($growth_rate) ?>%</span> azalış son haftada
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-lg-4 col-md-6 mb-4">
                <div class="stat-card stat-success">
                    <div class="stat-icon"><i class="bi bi-briefcase"></i></div>
                    <div class="stat-title">Aktif İş İlanları</div>
                    <div class="stat-value"><?= $active_jobs ?></div>
                    <div class="stat-desc">
                        <i class="bi bi-check-circle text-success"></i> 
                        İlan başına <?= $total_applications > 0 && $active_jobs > 0 ? round($total_applications / $active_jobs, 1) : 0 ?> başvuru ortalaması
                    </div>
                </div>
            </div>

            <div class="col-lg-4 col-md-6 mb-4">
                <div class="stat-card stat-warning">
                    <div class="stat-icon"><i class="bi bi-hourglass-split"></i></div>
                    <div class="stat-title">Bekleyen Başvurular</div>
                    <div class="stat-value"><?= $pending_applications ?></div>
                    <div class="stat-desc">
                        <i class="bi bi-clock text-warning"></i> 
                        Toplam başvuruların <?= $total_applications > 0 ? round(($pending_applications / $total_applications) * 100, 1) : 0 ?>%'i
                    </div>
                </div>
            </div>

            <div class="col-lg-4 col-md-6 mb-4">
                <div class="stat-card stat-info">
                    <div class="stat-icon"><i class="bi bi-check-square"></i></div>
                    <div class="stat-title">İncelenen Başvurular</div>
                    <div class="stat-value"><?= $reviewed_applications ?></div>
                    <div class="stat-desc">
                        <i class="bi bi-check-all text-info"></i> 
                        Toplam başvuruların <?= $total_applications > 0 ? round(($reviewed_applications / $total_applications) * 100, 1) : 0 ?>%'i
                    </div>
                </div>
            </div>

            <div class="col-lg-4 col-md-6 mb-4">
                <div class="stat-card stat-danger">
                    <div class="stat-icon"><i class="bi bi-calendar-check"></i></div>
                    <div class="stat-title"><?= $date_label ?></div>
                    <div class="stat-value"><?= $period_applications ?></div>
                    <div class="stat-desc">
                        <i class="bi bi-calendar3 text-danger"></i> 
                        Seçili dönemdeki başvuru sayısı
                    </div>
                </div>
            </div>

            <div class="col-lg-4 col-md-6 mb-4">
                <div class="stat-card stat-primary">
                    <div class="stat-icon"><i class="bi bi-trophy"></i></div>
                    <div class="stat-title">Değerlendirme Puanı</div>
                    <div class="stat-value"><?= $avg_score ?></div>
                    <div class="stat-desc">
                        <i class="bi bi-bar-chart text-primary"></i> 
                        Ortalama başvuru değerlendirme puanı
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Cinsiyet Dağılımı ve Yaş Dağılımı -->
            <div class="col-lg-6 mb-4">
                <div class="card h-100">
                    <div class="card-header">
                        <div class="card-title">
                            <i class="bi bi-people me-2"></i>Demografik Veriler
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <!-- Cinsiyet Dağılımı -->
                            <div class="col-md-6">
                                <h6 class="text-muted mb-3">Cinsiyet Dağılımı</h6>
                                <div class="chart-container-sm mb-4">
                                    <canvas id="genderChart"></canvas>
                                </div>
                                
                                <?php foreach ($gender_distribution as $gender): ?>
                                <div class="progress-bar-container">
                                    <div class="progress-bar-label">
                                        <span class="label">
                                            <?php
                                                switch ($gender['gender']) {
                                                    case 'male':
                                                        echo 'Erkek';
                                                        $color = '#3498db';
                                                        break;
                                                    case 'female':
                                                        echo 'Kadın';
                                                        $color = '#e74c3c';
                                                        break;
                                                    default:
                                                        echo 'Diğer';
                                                        $color = '#95a5a6';
                                                }
                                            ?>
                                        </span>
                                        <span class="value"><?= $gender['count'] ?> (<?= $gender['percentage'] ?>%)</span>
                                    </div>
                                    <div class="progress">
                                        <div class="progress-bar" role="progressbar" 
                                             style="width: <?= $gender['percentage'] ?>%; background-color: <?= $color ?>;" 
                                             aria-valuenow="<?= $gender['percentage'] ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <!-- Yaş Dağılımı -->
                            <div class="col-md-6">
                                <h6 class="text-muted mb-3">Yaş Dağılımı</h6>
                                <div class="chart-container-sm mb-4">
                                    <canvas id="ageChart"></canvas>
                                </div>
                                
                                <?php foreach ($age_distribution as $age): ?>
                                <div class="progress-bar-container">
                                    <div class="progress-bar-label">
                                        <span class="label"><?= $age['age_group'] ?></span>
                                        <span class="value"><?= $age['count'] ?> (<?= $age['percentage'] ?>%)</span>
                                    </div>
                                    <div class="progress">
                                        <div class="progress-bar bg-success" role="progressbar" 
                                             style="width: <?= $age['percentage'] ?>%;" 
                                             aria-valuenow="<?= $age['percentage'] ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Lokasyon ve Eğitim Dağılımı -->
            <div class="col-lg-6 mb-4">
                <div class="card h-100">
                    <div class="card-header">
                        <div class="card-title">
                            <i class="bi bi-geo-alt me-2"></i>Lokasyon ve Eğitim
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <!-- Lokasyona Göre Başvurular -->
                            <div class="col-md-6">
                                <h6 class="text-muted mb-3">Başvuru Lokasyonları (Top 5)</h6>
                                <?php foreach ($locations_data as $location): ?>
                                <div class="progress-bar-container">
                                    <div class="progress-bar-label">
                                        <span class="label"><?= htmlspecialchars($location['location']) ?></span>
                                        <span class="value"><?= $location['application_count'] ?> (<?= $location['percentage'] ?>%)</span>
                                    </div>
                                    <div class="progress">
                                        <div class="progress-bar bg-info" role="progressbar" 
                                             style="width: <?= $location['percentage'] ?>%;" 
                                             aria-valuenow="<?= $location['percentage'] ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <!-- Eğitim Dağılımı -->
                            <div class="col-md-6">
                                <h6 class="text-muted mb-3">Eğitim Dağılımı (Top 5)</h6>
                                <?php foreach ($education_distribution as $index => $edu): ?>
                                <?php 
                                    $colors = ['#4361ee', '#2ecc71', '#f39c12', '#e74c3c', '#9b59b6'];
                                    $color = $colors[$index % count($colors)];
                                ?>
                                <div class="progress-bar-container">
                                    <div class="progress-bar-label">
                                        <span class="label"><?= mb_strimwidth(htmlspecialchars($edu['education']), 0, 20, '...') ?></span>
                                        <span class="value"><?= $edu['count'] ?> (<?= $edu['percentage'] ?>%)</span>
                                    </div>
                                    <div class="progress">
                                        <div class="progress-bar" role="progressbar" 
                                             style="width: <?= $edu['percentage'] ?>%; background-color: <?= $color ?>;" 
                                             aria-valuenow="<?= $edu['percentage'] ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Pozisyona Göre Başvurular -->
            <div class="col-lg-6 mb-4">
                <div class="card h-100">
                    <div class="card-header">
                        <div class="card-title">
                            <i class="bi bi-briefcase me-2"></i>Pozisyona Göre Başvurular
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="positionsChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Başvuru Puanları Dağılımı -->
            <div class="col-lg-6 mb-4">
                <div class="card h-100">
                    <div class="card-header">
                        <div class="card-title">
                            <i class="bi bi-bar-chart me-2"></i>Başvuru Puanları Dağılımı
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="scoreChart"></canvas>
                        </div>
                        
                        <div class="row mt-4">
                            <?php foreach ($score_distribution as $score): ?>
                            <div class="col-md-6 col-lg-4 mb-3">
                                <div class="d-flex align-items-center">
                                    <?php
                                        $badgeClass = '';
                                        if ($score['score_range'] === '81-100') $badgeClass = 'bg-success';
                                        elseif ($score['score_range'] === '61-80') $badgeClass = 'bg-info';
                                        elseif ($score['score_range'] === '41-60') $badgeClass = 'bg-warning';
                                        elseif ($score['score_range'] === '21-40') $badgeClass = 'bg-danger';
                                        elseif ($score['score_range'] === '0-20') $badgeClass = 'bg-secondary';
                                        else $badgeClass = 'bg-light';
                                    ?>
                                    <div class="rounded-circle <?= $badgeClass ?>" style="width: 10px; height: 10px;"></div>
                                    <div class="ms-2">
                                        <span class="small fw-medium"><?= $score['score_range'] ?></span>
                                        <span class="text-muted ms-2"><?= $score['count'] ?> başvuru</span>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Maaş Beklentileri Dağılımı -->
            <div class="col-lg-6 mb-4">
                <div class="card h-100">
                    <div class="card-header">
                        <div class="card-title">
                            <i class="bi bi-currency-dollar me-2"></i>Maaş Beklentileri
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="salaryChart"></canvas>
                        </div>
                        
                        <div class="table-responsive mt-4">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Maaş Aralığı</th>
                                        <th>Başvuru</th>
                                        <th>Ortalama</th>
                                        <th>%</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($salary_distribution as $salary): ?>
                                    <tr>
                                        <td><?= $salary['salary_range'] ?></td>
                                        <td><?= $salary['count'] ?></td>
                                        <td><?= number_format($salary['avg_salary'], 0, ',', '.') ?> TL</td>
                                        <td><?= $salary['percentage'] ?>%</td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Yüksek Performanslı İlanlar -->
            <div class="col-lg-6 mb-4">
                <div class="card h-100">
                    <div class="card-header">
                        <div class="card-title">
                            <i class="bi bi-trophy me-2"></i>En Yüksek Performanslı İlanlar
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>İlan Başlığı</th>
                                        <th>Başvuru</th>
                                        <th>Ort. Puan</th>
                                        <th>CV Puanı</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($top_performing_jobs as $job): ?>
                                    <tr>
                                        <td>
                                            <a href="view-job.php?id=<?= $job['id'] ?>" class="text-decoration-none">
                                                <?= htmlspecialchars($job['title']) ?>
                                            </a>
                                        </td>
                                        <td><?= $job['applicant_count'] ?></td>
                                        <td>
                                            <?php if ($job['avg_score'] > 0): ?>
                                                <span class="fw-medium"><?= $job['avg_score'] ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($job['avg_cv_score'] > 0): ?>
                                                <span class="fw-medium"><?= $job['avg_cv_score'] ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (count($top_performing_jobs) === 0): ?>
                                    <tr>
                                        <td colspan="4" class="text-center py-4">Henüz yeterli veri bulunmamaktadır.</td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Zaman İçinde Başvuru Kalitesi Trendi -->
        <div class="row">
            <div class="col-12 mb-4">
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">
                            <i class="bi bi-clock-history me-2"></i>Zaman İçinde Başvuru Kalitesi
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="qualityTrendChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Mevcut dashboard içeriğinin uygun bir bölümüne aşağıdaki kodu ekleyin -->

<div class="row mb-4">
    <!-- En Çok Yanlış Yapılan Sorular Kartı -->
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title">
                    <i class="bi bi-exclamation-circle-fill me-2 text-danger"></i>En Çok Yanlış Yapılan Sorular
                </h5>
                <span class="badge bg-danger">Top 7</span>
            </div>
            <div class="card-body">
                <?php
                // En çok yanlış cevaplanan soruları çek
                $wrong_answers_query = "
                    SELECT 
                        q.id,
                        q.question_text,
                        j.title as job_title,
                        COUNT(aa.id) as wrong_answers,
                        (
                            SELECT COUNT(aa2.id) 
                            FROM application_answers aa2 
                            JOIN options o2 ON aa2.option_id = o2.id 
                            WHERE aa2.question_id = q.id
                        ) as total_answers,
                        ROUND(
                            COUNT(aa.id) * 100.0 / (
                                SELECT COUNT(aa2.id) 
                                FROM application_answers aa2 
                                WHERE aa2.question_id = q.id
                            )
                        ) as error_rate
                    FROM 
                        questions q
                    JOIN 
                        application_answers aa ON q.id = aa.question_id
                    JOIN 
                        options o ON aa.option_id = o.id
                    JOIN 
                        jobs j ON q.job_id = j.id
                    WHERE 
                        q.question_type = 'multiple_choice' 
                        AND o.is_correct = 0
                    GROUP BY 
                        q.id, q.question_text, j.title
                    HAVING 
                        COUNT(aa.id) > 0
                    ORDER BY 
                        wrong_answers DESC
                    LIMIT 7
                ";
                
                try {
                    $stmt = $db->query($wrong_answers_query);
                    $wrong_questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    if (count($wrong_questions) > 0):
                ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Soru</th>
                                    <th>İlan</th>
                                    <th>Yanlış Cevap</th>
                                    <th>Hata Oranı</th>
                                    <th>İşlem</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($wrong_questions as $index => $question): ?>
                                    <tr>
                                        <td><?= $index + 1 ?></td>
                                        <td>
                                            <?php
                                                // Soru metni uzunsa kısalt
                                                $question_text = $question['question_text'];
                                                if(mb_strlen($question_text) > 70) {
                                                    $question_text = mb_substr($question_text, 0, 70) . '...';
                                                }
                                                echo htmlspecialchars($question_text);
                                            ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary"><?= htmlspecialchars($question['job_title']) ?></span>
                                        </td>
                                        <td>
                                            <span class="badge bg-danger"><?= $question['wrong_answers'] ?></span>
                                            <small class="text-muted">/ <?= $question['total_answers'] ?></small>
                                        </td>
                                        <td>
                                            <?php 
                                                $error_rate = $question['error_rate'];
                                                $rate_class = 'bg-success';
                                                if($error_rate > 50) $rate_class = 'bg-danger';
                                                else if($error_rate > 30) $rate_class = 'bg-warning';
                                            ?>
                                            <div class="progress" style="height: 8px; width: 100px;">
                                                <div class="progress-bar <?= $rate_class ?>" role="progressbar" style="width: <?= $error_rate ?>%" aria-valuenow="<?= $error_rate ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                            </div>
                                            <small><?= $error_rate ?>%</small>
                                        </td>
                                        <td>
                                            <a href="edit-question.php?id=<?= $question['id'] ?>" class="btn btn-sm btn-outline-primary">
                                                <i class="bi bi-pencil-square"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center py-4">
                        <i class="bi bi-check-circle text-success fs-1"></i>
                        <p class="mt-3">Henüz yanlış cevaplanan soru bulunmamaktadır.</p>
                    </div>
                <?php 
                    endif;
                } catch (PDOException $e) {
                    echo '<div class="alert alert-danger">Sorular alınırken bir hata oluştu.</div>';
                    error_log($e->getMessage());
                }
                ?>
            </div>
        </div>
    </div>
</div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Özel tarih aralığı gösterme/gizleme
        document.addEventListener('DOMContentLoaded', function() {
            const customDateRange = document.getElementById('customDateRange');
            const dateRangeContainer = document.getElementById('dateRangeContainer');
            
            // Özel tarih aralığı seçildiyse göster
            if (<?= $time_filter === 'custom' ? 'true' : 'false' ?>) {
                dateRangeContainer.style.display = 'block';
            }
            
            // Özel tarih aralığı tıklandığında göster
            customDateRange.addEventListener('click', function(e) {
                e.preventDefault();
                dateRangeContainer.style.display = dateRangeContainer.style.display === 'block' ? 'none' : 'block';
            });
        });
        
        // Chart.js ile grafikleri oluşturma
        // --------------------------------------------
        
        // Başvuru trendi grafiği
        const trendCtx = document.getElementById('applicationTrendChart').getContext('2d');
        new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode($trend_dates) ?>,
                datasets: [{
                    label: 'Başvuru Sayısı',
                    data: <?= json_encode($trend_counts) ?>,
                    backgroundColor: 'rgba(67, 97, 238, 0.2)',
                    borderColor: 'rgba(67, 97, 238, 1)',
                    borderWidth: 2,
                    pointBackgroundColor: '#fff',
                    pointBorderColor: 'rgba(67, 97, 238, 1)',
                    pointRadius: 4,
                    tension: 0.3
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            usePointStyle: true,
                            boxWidth: 6
                        }
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: false
                        }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        }
                    }
                }
            }
        });
        
        // Cinsiyet dağılımı grafiği
        const genderData = <?= json_encode(array_column($gender_distribution, 'count')) ?>;
        const genderLabels = <?= json_encode(array_map(function($item) {
            switch ($item['gender']) {
                case 'male': return 'Erkek';
                case 'female': return 'Kadın';
                default: return 'Diğer';
            }
        }, $gender_distribution)) ?>;
        
        const genderColors = ['rgba(52, 152, 219, 0.7)', 'rgba(231, 76, 60, 0.7)', 'rgba(149, 165, 166, 0.7)'];
        
        const genderCtx = document.getElementById('genderChart').getContext('2d');
        new Chart(genderCtx, {
            type: 'doughnut',
            data: {
                labels: genderLabels,
                datasets: [{
                    data: genderData,
                    backgroundColor: genderColors,
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            usePointStyle: true,
                            boxWidth: 6
                        }
                    }
                },
                cutout: '70%'
            }
        });
        
        // Yaş dağılımı grafiği
        const ageData = <?= json_encode(array_column($age_distribution, 'count')) ?>;
        const ageLabels = <?= json_encode(array_column($age_distribution, 'age_group')) ?>;
        
        const ageCtx = document.getElementById('ageChart').getContext('2d');
        new Chart(ageCtx, {
            type: 'bar',
            data: {
                labels: ageLabels,
                datasets: [{
                    label: 'Başvuru Sayısı',
                    data: ageData,
                    backgroundColor: 'rgba(46, 204, 113, 0.7)',
                    borderWidth: 0,
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: false
                        }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        }
                    }
                }
            }
        });
        
        // Pozisyonlara göre başvuru grafiği
        const positionsData = <?= json_encode(array_column($positions_data, 'application_count')) ?>;
        const positionsLabels = <?= json_encode(array_column($positions_data, 'title')) ?>;
        
        const positionsCtx = document.getElementById('positionsChart').getContext('2d');
        new Chart(positionsCtx, {
            type: 'horizontalBar',
            type: 'bar', // Using regular bar as horizontalBar is deprecated
            data: {
                labels: positionsLabels,
                datasets: [{
                    label: 'Başvuru Sayısı',
                    data: positionsData,
                    backgroundColor: [
                        'rgba(67, 97, 238, 0.7)',
                        'rgba(46, 204, 113, 0.7)',
                        'rgba(243, 156, 18, 0.7)',
                        'rgba(231, 76, 60, 0.7)',
                        'rgba(155, 89, 182, 0.7)'
                    ],
                    borderWidth: 0,
                    borderRadius: 4
                }]
            },
            options: {
                indexAxis: 'y', // For horizontal bar chart
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        }
                    },
                    y: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
        
        // Puan dağılımı grafiği
        const scoreData = <?= json_encode(array_column($score_distribution, 'count')) ?>;
        const scoreLabels = <?= json_encode(array_column($score_distribution, 'score_range')) ?>;
        
        const scoreCtx = document.getElementById('scoreChart').getContext('2d');
        new Chart(scoreCtx, {
            type: 'pie',
            data: {
                labels: scoreLabels,
                datasets: [{
                    data: scoreData,
                    backgroundColor: [
                        'rgba(46, 204, 113, 0.7)', // 81-100
                        'rgba(52, 152, 219, 0.7)', // 61-80
                        'rgba(243, 156, 18, 0.7)', // 41-60
                        'rgba(231, 76, 60, 0.7)',  // 21-40
                        'rgba(149, 165, 166, 0.7)', // 0-20
                        'rgba(200, 200, 200, 0.7)'  // Değerlendirilmemiş
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            usePointStyle: true,
                            boxWidth: 6
                        }
                    }
                }
            }
        });
        
        // Maaş dağılımı grafiği
        const salaryData = <?= json_encode(array_column($salary_distribution, 'count')) ?>;
        const salaryLabels = <?= json_encode(array_column($salary_distribution, 'salary_range')) ?>;
        
        const salaryCtx = document.getElementById('salaryChart').getContext('2d');
        new Chart(salaryCtx, {
            type: 'bar',
            data: {
                labels: salaryLabels,
                datasets: [{
                    label: 'Başvuru Sayısı',
                    data: salaryData,
                    backgroundColor: 'rgba(243, 156, 18, 0.7)',
                    borderWidth: 0,
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: false
                        }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        }
                    }
                }
            }
        });
        
        // Başvuru kalitesi trendi grafiği
        const qualityLabels = <?= json_encode(array_column($quality_trend, 'month_label')) ?>;
        const qualityScores = <?= json_encode(array_column($quality_trend, 'avg_score')) ?>;
        const qualityCounts = <?= json_encode(array_column($quality_trend, 'applicant_count')) ?>;
        
        const qualityCtx = document.getElementById('qualityTrendChart').getContext('2d');
        new Chart(qualityCtx, {
            type: 'line',
            data: {
                labels: qualityLabels,
                datasets: [
                    {
                        label: 'Ortalama Puan',
                        data: qualityScores,
                        backgroundColor: 'rgba(52, 152, 219, 0.2)',
                        borderColor: 'rgba(52, 152, 219, 1)',
                        borderWidth: 2,
                        pointBackgroundColor: '#fff',
                        pointBorderColor: 'rgba(52, 152, 219, 1)',
                        pointRadius: 4,
                        yAxisID: 'y',
                        tension: 0.3
                    },
                    {
                        label: 'Başvuru Sayısı',
                        data: qualityCounts,
                        backgroundColor: 'rgba(231, 76, 60, 0.2)',
                        borderColor: 'rgba(231, 76, 60, 1)',
                        borderWidth: 2,
                        pointBackgroundColor: '#fff',
                        pointBorderColor: 'rgba(231, 76, 60, 1)',
                        pointRadius: 4,
                        yAxisID: 'y1',
                        tension: 0.3
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            usePointStyle: true,
                            boxWidth: 6
                        }
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: false
                        }
                    },
                    y: {
                        beginAtZero: true,
                        position: 'left',
                        title: {
                            display: true,
                            text: 'Ortalama Puan'
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        }
                    },
                    y1: {
                        beginAtZero: true,
                        position: 'right',
                        title: {
                            display: true,
                            text: 'Başvuru Sayısı'
                        },
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>

<?php
// Output buffer içeriğini gönder ve buffer'ı temizle
ob_end_flush();
?>