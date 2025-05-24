<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>jobBeta2 - İş Başvuru Sistemi</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        :root {
            --primary: #4361ee;
            --primary-dark: #3a56d4;
            --gray-900: #212529;
            --gray-800: #343a40;
            --gray-700: #495057;
            --gray-600: #6c757d;
            --gray-200: #e9ecef;
            --gray-100: #f8f9fa;
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            color: var(--gray-800);
            background-color: var(--gray-100);
        }
        
        /* Progress bar */
        .progress-container {
            position: fixed;
            top: 0;
            z-index: 1100;
            width: 100%;
            height: 4px;
            background: transparent;
        }
        
        .progress-bar {
            height: 4px;
            background: var(--primary);
            width: 0%;
            transition: width 0.2s ease;
        }
        
        /* Navbar */
        .navbar {
            background-color: white !important;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            padding: 15px 0;
        }
        
        .navbar-brand {
            font-weight: 700;
            font-size: 1.6rem;
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--gray-900) !important;
        }
        
        .navbar-brand span {
            color: var(--primary);
        }
        
        .brand-icon {
            color: var(--primary);
            font-size: 1.8rem;
        }
        
        .nav-link {
            font-weight: 500;
            padding: 0.5rem 1rem !important;
            color: var(--gray-700) !important;
            transition: color 0.3s;
        }
        
        .nav-link:hover {
            color: var(--primary) !important;
        }
        
        .active > .nav-link,
        .nav-link.active {
            color: var(--primary) !important;
            font-weight: 600;
        }
        
        .navbar-toggler {
            border-color: var(--gray-200);
        }
        
        .navbar-toggler-icon {
            filter: invert(0);
        }
        
        /* Footer */
        .simple-footer {
            background-color: white;
            padding: 25px 0;
            border-top: 1px solid var(--gray-200);
            margin-top: 80px;
        }
        
        .footer-content {
            display: flex;
            justify-content: center;
            align-items: center;
            flex-direction: column;
        }
        
        .social-links {
            margin-bottom: 15px;
        }
        
        .social-links a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            margin: 0 5px;
            border-radius: 50%;
            color: var(--gray-600);
            transition: all 0.3s;
            background-color: var(--gray-100);
        }
        
        .social-links a:hover {
            color: white;
            background-color: var(--primary);
        }
        
        .copyright {
            color: var(--gray-600);
            margin: 0;
            font-size: 14px;
        }
        
        /* Button styles */
        .btn-primary {
            background-color: var(--primary);
            border-color: var(--primary);
        }
        
        .btn-primary:hover,
        .btn-primary:focus {
            background-color: var(--primary-dark);
            border-color: var(--primary-dark);
        }
    </style>
</head>
<body>
    <!-- Scroll Progress Bar -->
    <div class="progress-container">
        <div class="progress-bar" id="scrollProgress"></div>
    </div>

    <!-- Header -->
    <nav class="navbar navbar-expand-lg navbar-light sticky-top">
        <div class="container">
            <a class="navbar-brand" href="index.php">
                <i class="bi bi-box brand-icon"></i>
                job<span>Beta2</span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : '' ?>" href="index.php">Ana Sayfa</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= basename($_SERVER['PHP_SELF']) == 'apply.php' ? 'active' : '' ?>" href="apply.php">Başvuru Yap</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="admin/index.php">Admin Girişi</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>