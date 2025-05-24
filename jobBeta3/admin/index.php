<?php
session_start();

// Form gönderildiyse direkt giriş yap
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Kullanıcı adını kaydet (boşsa varsayılan değer)
    $username = !empty($_POST['username']) ? $_POST['username'] : 'admin';
    
    // Admin oturumunu başlat (ID değerini 1 olarak ayarla)
    $_SESSION['admin_id'] = 1;
    $_SESSION['admin_username'] = $username;
    
    // Dashboard'a yönlendir
    header('Location: dashboard.php');
    exit;
}

// Zaten giriş yapmışsa dashboard'a yönlendir
if (isset($_SESSION['admin_id'])) {
    header('Location: dashboard.php');
    exit;
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Girişi - jobBeta2</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            background-color: #121212;
            font-family: 'Poppins', sans-serif;
            color: #e0e0e0;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .login-container {
            max-width: 420px;
            width: 100%;
            padding: 0 20px;
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .login-header .logo {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 15px;
        }
        
        .login-header .logo i {
            font-size: 32px;
            color: #4a6bdf;
            margin-right: 10px;
        }
        
        .login-header h1 {
            color: #ffffff;
            font-weight: 700;
            font-size: 2rem;
            margin-bottom: 5px;
        }
        
        .login-header p {
            color: #9e9e9e;
        }
        
        .card {
            background-color: #1e1e1e;
            border: none;
            border-radius: 10px;
            box-shadow: 0 6px 18px rgba(0,0,0,0.2);
        }
        
        .card-header {
            background-color: #252525;
            color: white;
            font-weight: 600;
            border-bottom: 1px solid #333;
            border-radius: 10px 10px 0 0 !important;
        }
        
        .form-control {
            background-color: #2c2c2c;
            border: 1px solid #333;
            color: #e0e0e0;
            padding: 12px 15px;
        }
        
        .form-control:focus {
            background-color: #2c2c2c;
            border-color: #4a6bdf;
            box-shadow: 0 0 0 0.25rem rgba(74, 107, 223, 0.2);
            color: #e0e0e0;
        }
        
        .form-control::placeholder {
            color: #757575;
        }
        
        .form-label {
            color: #b0b0b0;
            font-weight: 500;
        }
        
        .btn-primary {
            background-color: #4a6bdf;
            border-color: #4a6bdf;
            font-weight: 600;
            padding: 10px 15px;
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover, .btn-primary:focus {
            background-color: #3b5ad0;
            border-color: #3b5ad0;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(74, 107, 223, 0.3);
        }
        
        .back-link {
            color: #b0b0b0;
            transition: color 0.3s ease;
        }
        
        .back-link:hover {
            color: #4a6bdf;
        }
        
        .input-group-text {
            background-color: #2c2c2c;
            border: 1px solid #333;
            border-left: none;
            color: #b0b0b0;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <div class="logo">
                <i class="bi bi-box"></i>
                <h1>jobBeta2</h1>
            </div>
            <p class="text-muted">Admin Paneli</p>
        </div>
        
        <div class="card">
            <div class="card-header text-center py-3">
                <i class="bi bi-shield-lock me-2"></i>Giriş Yap
            </div>
            <div class="card-body p-4">
                <form method="post">
                    <div class="mb-3">
                        <label for="username" class="form-label">Kullanıcı Adı</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-person-fill"></i></span>
                            <input type="text" class="form-control" id="username" name="username" placeholder="Kullanıcı adınızı girin" value="admin">
                        </div>
                    </div>
                    <div class="mb-4">
                        <label for="password" class="form-label">Şifre</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-key-fill"></i></span>
                            <input type="password" class="form-control" id="password" name="password" placeholder="Şifrenizi girin" value="admin123">
                            <span class="input-group-text" id="togglePassword"><i class="bi bi-eye"></i></span>
                        </div>
                    </div>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary py-3">
                            <i class="bi bi-box-arrow-in-right me-2"></i>Giriş Yap
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <div class="text-center mt-4">
            <a href="../index.php" class="back-link text-decoration-none">
                <i class="bi bi-arrow-left me-1"></i>Ana Sayfaya Dön
            </a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Şifre göster/gizle işlevselliği
        document.getElementById('togglePassword').addEventListener('click', function() {
            const passwordInput = document.getElementById('password');
            const icon = this.querySelector('i');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                icon.classList.remove('bi-eye');
                icon.classList.add('bi-eye-slash');
            } else {
                passwordInput.type = 'password';
                icon.classList.remove('bi-eye-slash');
                icon.classList.add('bi-eye');
            }
        });
    </script>
</body>
</html>