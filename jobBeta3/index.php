<?php
require_once 'config/db.php';
require_once 'includes/header.php';

// İş ilanlarını veritabanından çek
$stmt = $db->prepare("SELECT * FROM jobs WHERE status = 'active' ORDER BY created_at DESC");
$stmt->execute();
$jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Lokasyonları benzersiz olarak çek (filtre için)
$stmt = $db->query("SELECT DISTINCT location FROM jobs WHERE status = 'active' ORDER BY location");
$locations = $stmt->fetchAll(PDO::FETCH_COLUMN);
?>

<!-- Modern Hero Section -->
<section class="hero">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-lg-6 pe-lg-5">
                <h1 class="hero-title">jobBeta<span class="text-primary">2</span></h1>
                <p class="hero-subtitle">Yapay Zeka Destekli İşe Alım Uygulaması</p>
                <p class="hero-description">Kariyerinize uygun pozisyonları keşfedin ve tek tıkla başvurun.</p>
                <a href="#jobs-section" class="btn btn-primary btn-lg hero-btn">
                    <span>İş İlanlarını Görüntüle</span>
                    <i class="bi bi-arrow-down-circle ms-2"></i>
                </a>
            </div>
            <div class="col-lg-6 d-none d-lg-block">
                <div class="hero-image">
                    <img src="assets/images/hero-illustration.svg" alt="İş Arama" class="img-fluid" onerror="this.style.display='none'">
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Search Filters Section -->
<section class="search-section" id="jobs-section">
    <div class="container">
        <div class="search-card">
            <div class="search-header">
                <h2><i class="bi bi-search me-2"></i>İş Ara</h2>
                <p>Kriterlerinize uygun pozisyonları filtreleyerek bulun</p>
            </div>
            <div class="search-body">
                <form id="search-form">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label for="search-keyword">Anahtar Kelime</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                                    <input type="text" class="form-control" id="search-keyword" placeholder="İş unvanı, anahtar kelime...">
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="search-location">Lokasyon</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-geo-alt"></i></span>
                                    <select class="form-select" id="search-location">
                                        <option value="">Tüm Lokasyonlar</option>
                                        <?php foreach ($locations as $location): ?>
                                            <option value="<?= htmlspecialchars($location) ?>"><?= htmlspecialchars($location) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="search-sort">Sıralama</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-sort-down"></i></span>
                                    <select class="form-select" id="search-sort">
                                        <option value="newest">En Yeni</option>
                                        <option value="oldest">En Eski</option>
                                        <option value="az">A-Z</option>
                                        <option value="za">Z-A</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="form-group h-100">
                                <label class="d-none d-md-block">&nbsp;</label>
                                <button type="button" class="btn btn-primary w-100 h-md-75" id="btn-filter">
                                    <i class="bi bi-funnel me-md-2"></i> <span>Filtrele</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</section>

<!-- Jobs Listing Section -->
<section class="jobs-section">
    <div class="container">
        <div class="jobs-header">
            <div class="d-flex justify-content-between align-items-center">
                <h2><i class="bi bi-briefcase me-2"></i>Açık Pozisyonlar</h2>
                <div class="job-count">
                    <span class="count-badge"><?= count($jobs) ?> ilan</span>
                </div>
            </div>
        </div>

        <div class="row" id="jobs-container">
            <?php if (count($jobs) > 0): ?>
                <?php foreach ($jobs as $job): ?>
                    <div class="col-lg-6 mb-4 job-item">
                        <div class="job-card">
                            <div class="job-card-header">
                                <div class="job-card-title">
                                    <h3><?= htmlspecialchars($job['title']) ?></h3>
                                    <div class="job-date">
                                        <i class="bi bi-calendar3"></i>
                                        <?= date('d M Y', strtotime($job['created_at'])) ?>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="job-card-body">
                                <div class="job-tags">
                                    <span class="job-tag">
                                        <i class="bi bi-geo-alt"></i> 
                                        <?= htmlspecialchars($job['location']) ?>
                                    </span>
                                    <span class="job-tag">
                                        <i class="bi bi-briefcase"></i> 
                                        Tam Zamanlı
                                    </span>
                                </div>
                                
                                <div class="job-description">
                                    <?= mb_strlen($job['description']) > 150 ? mb_substr(htmlspecialchars($job['description']), 0, 150) . '...' : htmlspecialchars($job['description']) ?>
                                </div>
                            </div>
                            
                            <div class="job-card-footer">
                                <a href="apply.php?job_id=<?= $job['id'] ?>" class="btn btn-primary">
                                    <span>Başvur</span>
                                    <i class="bi bi-arrow-right ms-2"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="col-12">
                    <div class="empty-state">
                        <div class="empty-state-icon">
                            <i class="bi bi-briefcase-fill"></i>
                        </div>
                        <h3>Henüz İlan Bulunmuyor</h3>
                        <p>Şu anda aktif iş ilanı bulunmamaktadır. Daha sonra tekrar kontrol edin.</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- Add modern, white theme CSS -->
<style>
:root{--primary:#4361ee;--primary-light:#ebefff;--primary-dark:#3a56d4;--secondary:#6c757d;--success:#10b981;--light:#f8f9fa;--dark:#212529;--border-color:#e9ecef;--border-radius:10px;--shadow:0 5px 20px rgba(0,0,0,0.05);--transition:all 0.25s ease;}*{margin:0;padding:0;box-sizing:border-box;}body{background-color:#f8f9fa;color:#212529;font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Oxygen,Ubuntu,Cantarell,'Helvetica Neue',sans-serif;}.btn{border-radius:30px;padding:10px 25px;font-weight:500;transition:var(--transition);}.btn-primary{background-color:var(--primary);border-color:var(--primary);}.btn-primary:hover{background-color:var(--primary-dark);border-color:var(--primary-dark);}.form-control,.form-select{padding:12px 15px;border-radius:var(--border-radius);border:1px solid var(--border-color);background-color:#fff;transition:var(--transition);}.form-control:focus,.form-select:focus{border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-light);}.input-group-text{background-color:var(--light);border-color:var(--border-color);}.form-group{margin-bottom:1rem;}.form-group label{font-weight:500;margin-bottom:0.5rem;display:inline-block;font-size:14px;color:#495057;}.hero{background-color:#fff;padding:80px 0;position:relative;overflow:hidden;box-shadow:0 3px 10px rgba(0,0,0,0.03);margin-bottom:3rem;}.hero-title{font-size:3.5rem;font-weight:800;margin-bottom:1rem;color:#212529;}.hero-subtitle{font-size:1.25rem;color:var(--primary);font-weight:600;margin-bottom:1rem;}.hero-description{font-size:1.1rem;color:#6c757d;margin-bottom:2rem;}.hero-btn{padding:15px 30px;font-weight:600;font-size:1.1rem;}.hero-image{display:flex;justify-content:flex-end;margin-top:20px;}.search-section{margin-bottom:3rem;}.search-card{background-color:#fff;border-radius:var(--border-radius);box-shadow:var(--shadow);overflow:hidden;}.search-header{padding:1.5rem;border-bottom:1px solid var(--border-color);background-color:var(--primary-light);}.search-header h2{font-weight:700;font-size:1.5rem;margin-bottom:0.5rem;color:#212529;}.search-header p{color:#6c757d;margin-bottom:0;}.search-body{padding:1.5rem;}.jobs-section{padding-bottom:3rem;}.jobs-header{margin-bottom:1.5rem;}.jobs-header h2{font-weight:700;font-size:1.5rem;color:#212529;}.count-badge{background-color:var(--primary-light);color:var(--primary);font-weight:600;padding:8px 16px;border-radius:30px;font-size:14px;}.job-card{background-color:#fff;border-radius:var(--border-radius);box-shadow:var(--shadow);overflow:hidden;height:100%;transition:transform 0.3s ease,box-shadow 0.3s ease;border:1px solid var(--border-color);display:flex;flex-direction:column;}.job-card:hover{transform:translateY(-5px);box-shadow:0 10px 25px rgba(0,0,0,0.1);}.job-card-header{padding:1.5rem 1.5rem 0.75rem;}.job-card-title{display:flex;justify-content:space-between;align-items:flex-start;}.job-card-title h3{font-size:1.25rem;font-weight:600;margin:0 0 5px;color:#212529;}.job-date{color:#6c757d;font-size:14px;}.job-card-body{padding:0rem 1.5rem 1.25rem;flex:1;}.job-tags{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:1rem;}.job-tag{display:inline-flex;align-items:center;background-color:var(--primary-light);color:var(--primary);padding:6px 12px;border-radius:20px;font-size:13px;font-weight:500;}.job-tag i{margin-right:5px;}.job-description{color:#6c757d;font-size:14px;line-height:1.6;}.job-card-footer{padding:1.25rem 1.5rem;border-top:1px solid var(--border-color);background-color:#f8f9fa;}.empty-state{background-color:#fff;border-radius:var(--border-radius);box-shadow:var(--shadow);padding:3rem;text-align:center;margin:2rem 0;}.empty-state-icon{font-size:3rem;color:var(--primary);background-color:var(--primary-light);width:100px;height:100px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1.5rem;}.empty-state h3{font-weight:600;margin-bottom:1rem;color:#212529;}.empty-state p{color:#6c757d;margin-bottom:0;}@media (max-width:992px){.hero{padding:50px 0;}.hero-title{font-size:2.5rem;}.hero-image{justify-content:center;}}@media (max-width:768px){.hero{padding:40px 0;text-align:center;}.hero-title{font-size:2rem;}.hero-btn{width:100%;}.search-card{border-radius:var(--border-radius);}.job-card-title{flex-direction:column;}.job-date{margin-top:5px;}}
</style>

<!-- Add page-specific JavaScript -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Smooth scroll for anchor links
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function(e) {
            e.preventDefault();
            const targetId = this.getAttribute('href');
            const targetElement = document.querySelector(targetId);
            
            if (targetElement) {
                window.scrollTo({
                    top: targetElement.offsetTop - 80,
                    behavior: 'smooth'
                });
            }
        });
    });
    
    // Filter functionality
    document.getElementById('btn-filter').addEventListener('click', function() {
        const keyword = document.getElementById('search-keyword').value.toLowerCase();
        const location = document.getElementById('search-location').value.toLowerCase();
        const sort = document.getElementById('search-sort').value;
        
        const jobItems = document.querySelectorAll('.job-item');
        let visibleJobs = 0;
        
        // Convert NodeList to Array for sorting
        const jobsArray = Array.from(jobItems);
        
        // Apply filters
        jobsArray.forEach(item => {
            const title = item.querySelector('.job-card-title h3').textContent.toLowerCase();
            const jobLocation = item.querySelector('.job-tags').textContent.toLowerCase();
            
            const matchesKeyword = keyword === '' || title.includes(keyword);
            const matchesLocation = location === '' || jobLocation.includes(location);
            
            if (matchesKeyword && matchesLocation) {
                item.style.display = 'block';
                visibleJobs++;
            } else {
                item.style.display = 'none';
            }
        });
        
        // Sort the visible jobs
        const sortedJobs = jobsArray.filter(item => item.style.display !== 'none');
        if (sort === 'newest') {
            // Default order - already sorted by date desc
        } else if (sort === 'oldest') {
            sortedJobs.reverse();
        } else if (sort === 'az') {
            sortedJobs.sort((a, b) => {
                const titleA = a.querySelector('.job-card-title h3').textContent;
                const titleB = b.querySelector('.job-card-title h3').textContent;
                return titleA.localeCompare(titleB);
            });
        } else if (sort === 'za') {
            sortedJobs.sort((a, b) => {
                const titleA = a.querySelector('.job-card-title h3').textContent;
                const titleB = b.querySelector('.job-card-title h3').textContent;
                return titleB.localeCompare(titleA);
            });
        }
        
        // Update the badge count
        document.querySelector('.count-badge').textContent = visibleJobs + ' ilan';
        
        // Reappend sorted items to container
        const container = document.getElementById('jobs-container');
        sortedJobs.forEach(item => {
            container.appendChild(item);
        });
        
        // Add filter animation
        jobsArray.forEach(item => {
            if (item.style.display !== 'none') {
                item.classList.add('filter-animation');
                setTimeout(() => {
                    item.classList.remove('filter-animation');
                }, 500);
            }
        });
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>