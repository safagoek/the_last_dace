<?php
require_once 'config/db.php';
require_once 'includes/header.php';
?>

<div class="container">
    <div class="thank-you-container text-center my-5">
        <div class="success-card">
            <div class="success-icon">
                <i class="bi bi-check-circle-fill"></i>
            </div>
            <h2>Başvurunuz başarıyla tamamlandı!</h2>
            <p class="lead mt-4">Teşekkür ederiz. Başvurunuz alındı ve değerlendirme sürecine alındı.</p>
            
            <div class="mt-5">
                <p class="text-secondary">Sizinle en kısa sürede iletişime geçeceğiz.</p>
            </div>
            
            <div class="mt-4">
                <a href="index.php" class="btn btn-primary">
                    <i class="bi bi-house-door me-2"></i>Ana Sayfaya Dön
                </a>
            </div>
        </div>
    </div>
</div>

<style>
    :root {
        --dark-bg: #121212;
        --card-bg: #1e1e1e;
        --text-primary: #e0e0e0;
        --text-secondary: #a0a0a0;
        --accent: #4b70e2;
        --accent-hover: #3a5bc7;
        --success: #10b981;
        --success-light: rgba(16, 185, 129, 0.15);
    }
    
    body {
        background-color: var(--dark-bg);
        color: var(--text-primary);
    }
    
    .thank-you-container {
        padding: 3rem 1rem;
    }
    
    .success-card {
        background-color: var(--card-bg);
        padding: 3rem 2rem;
        border-radius: 15px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.25);
        max-width: 650px;
        margin: 0 auto;
        position: relative;
        border-top: 4px solid var(--success);
    }
    
    .success-icon {
        font-size: 4rem;
        color: var(--success);
        margin-bottom: 1.5rem;
    }
    
    .success-icon i {
        background-color: var(--success-light);
        border-radius: 50%;
        padding: 1rem;
    }
    
    .success-card h2 {
        color: var(--text-primary);
        font-weight: 600;
    }
    
    .success-card .lead {
        color: var(--text-primary);
    }
    
    .text-secondary {
        color: var(--text-secondary) !important;
    }
    
    .btn-primary {
        background-color: var(--accent);
        border-color: var(--accent);
        padding: 0.6rem 1.5rem;
        font-weight: 500;
        border-radius: 5px;
    }
    
    .btn-primary:hover, .btn-primary:focus {
        background-color: var(--accent-hover);
        border-color: var(--accent-hover);
    }
    
    @media (max-width: 576px) {
        .success-card {
            padding: 2rem 1rem;
        }
        
        .success-icon {
            font-size: 3rem;
        }
    }
</style>

<?php require_once 'includes/footer.php'; ?>