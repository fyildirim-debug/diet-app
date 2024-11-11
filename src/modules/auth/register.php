<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Kullanıcı zaten giriş yapmışsa ana sayfaya yönlendir
if(isLoggedIn()) {
    redirect('/');
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = clean($_POST['name']);
    $email = clean($_POST['email']);
    $password = $_POST['password'];
    $password_confirm = $_POST['password_confirm'];

    // Validasyon kontrolleri
    if(empty($name) || empty($email) || empty($password) || empty($password_confirm)) {
        $error = 'Lütfen tüm alanları doldurun.';
    } elseif(!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Geçerli bir email adresi girin.';
    } elseif(strlen($password) < 6) {
        $error = 'Şifre en az 6 karakter olmalıdır.';
    } elseif($password !== $password_confirm) {
        $error = 'Şifreler eşleşmiyor.';
    } else {
        try {
            $db = new Database();
            $conn = $db->getConnection();

            // Email kontrolü
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if($stmt->fetch()) {
                $error = 'Bu email adresi zaten kayıtlı.';
            } else {
                // Yeni kullanıcı kaydı
                $stmt = $conn->prepare("
                    INSERT INTO users (name, email, password, created_at, status) 
                    VALUES (?, ?, ?, NOW(), 1)
                ");

                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt->execute([$name, $email, $hashed_password]);

                $user_id = $conn->lastInsertId();

                // Varsayılan profil oluştur
                $stmt = $conn->prepare("
                    INSERT INTO user_profiles (user_id, daily_calorie_limit) 
                    VALUES (?, 2000)
                ");
                $stmt->execute([$user_id]);

                $success = 'Hesabınız başarıyla oluşturuldu. Şimdi giriş yapabilirsiniz.';

                // Başarılı kayıttan sonra giriş sayfasına yönlendir
                header("refresh:2;url=" . SITE_URL . "/modules/auth/login.php");
            }
        } catch (PDOException $e) {
            error_log("Registration Error: " . $e->getMessage());
            $error = 'Bir hata oluştu, lütfen tekrar deneyin.';
        }
    }
}

require_once '../../includes/header.php';
?>

    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-5">
                <div class="card shadow-sm">
                    <div class="card-body p-4">
                        <div class="text-center mb-4">
                            <i class="fas fa-user-plus fa-3x text-primary"></i>
                            <h4 class="mt-2">Hesap Oluştur</h4>
                            <p class="text-secondary">Sağlıklı yaşam yolculuğuna başlayın</p>
                        </div>

                        <?php if($error): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="fas fa-exclamation-circle me-2"></i><?= $error ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <?php if($success): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <i class="fas fa-check-circle me-2"></i><?= $success ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="" class="needs-validation" novalidate>
                            <div class="mb-3">
                                <label class="form-label">
                                    <i class="fas fa-user text-secondary me-1"></i>Ad Soyad
                                </label>
                                <input type="text"
                                       name="name"
                                       class="form-control"
                                       required
                                       value="<?= isset($_POST['name']) ? clean($_POST['name']) : '' ?>"
                                       pattern="[A-Za-zÇçĞğİıÖöŞşÜü\s]+"
                                       minlength="3">
                                <div class="invalid-feedback">Geçerli bir ad soyad girin (en az 3 karakter).</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">
                                    <i class="fas fa-envelope text-secondary me-1"></i>Email
                                </label>
                                <input type="email"
                                       name="email"
                                       class="form-control"
                                       required
                                       value="<?= isset($_POST['email']) ? clean($_POST['email']) : '' ?>">
                                <div class="invalid-feedback">Geçerli bir email adresi girin.</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">
                                    <i class="fas fa-lock text-secondary me-1"></i>Şifre
                                </label>
                                <div class="input-group">
                                    <input type="password"
                                           name="password"
                                           class="form-control"
                                           required
                                           id="password"
                                           minlength="6">
                                    <button class="btn btn-outline-secondary"
                                            type="button"
                                            onclick="togglePassword('password', 'toggleIcon1')">
                                        <i class="fas fa-eye" id="toggleIcon1"></i>
                                    </button>
                                </div>
                                <div class="form-text">En az 6 karakter olmalıdır.</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">
                                    <i class="fas fa-lock text-secondary me-1"></i>Şifre Tekrar
                                </label>
                                <div class="input-group">
                                    <input type="password"
                                           name="password_confirm"
                                           class="form-control"
                                           required
                                           id="password_confirm"
                                           minlength="6">
                                    <button class="btn btn-outline-secondary"
                                            type="button"
                                            onclick="togglePassword('password_confirm', 'toggleIcon2')">
                                        <i class="fas fa-eye" id="toggleIcon2"></i>
                                    </button>
                                </div>
                                <div class="invalid-feedback">Şifreler eşleşmiyor.</div>
                            </div>

                            <div class="mb-3 form-check">
                                <input type="checkbox" class="form-check-input" id="terms" required>
                                <label class="form-check-label" for="terms">
                                    <small>
                                        <a href="#" class="text-decoration-none">Kullanım şartlarını</a> ve
                                        <a href="#" class="text-decoration-none">Gizlilik politikasını</a> kabul ediyorum
                                    </small>
                                </label>
                                <div class="invalid-feedback">
                                    Devam etmek için şartları kabul etmelisiniz.
                                </div>
                            </div>

                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-user-plus me-2"></i>Kayıt Ol
                                </button>
                                <a href="<?= SITE_URL ?>/modules/auth/login.php" class="btn btn-outline-primary">
                                    <i class="fas fa-sign-in-alt me-2"></i>Giriş Yap
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function togglePassword(inputId, iconId) {
            const passwordInput = document.getElementById(inputId);
            const toggleIcon = document.getElementById(iconId);

            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            }
        }

        // Şifre eşleşme kontrolü
        document.getElementById('password_confirm').addEventListener('input', function() {
            if(this.value !== document.getElementById('password').value) {
                this.setCustomValidity('Şifreler eşleşmiyor.');
            } else {
                this.setCustomValidity('');
            }
        });

        // Form doğrulama
        (function () {
            'use strict'
            var forms = document.querySelectorAll('.needs-validation')
            Array.prototype.slice.call(forms).forEach(function (form) {
                form.addEventListener('submit', function (event) {
                    if (!form.checkValidity()) {
                        event.preventDefault()
                        event.stopPropagation()
                    }
                    form.classList.add('was-validated')
                }, false)
            })
        })()
    </script>

<?php require_once '../../includes/footer.php'; ?>