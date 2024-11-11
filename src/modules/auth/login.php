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
    $email = clean($_POST['email']);
    $password = $_POST['password'];

    if(empty($email) || empty($password)) {
        $error = 'Lütfen tüm alanları doldurun.';
    } else {
        try {
            $db = new Database();
            $conn = $db->getConnection();

            $stmt = $conn->prepare("SELECT id, name, password FROM users WHERE email = ? AND status = 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['name'];

                // Son giriş tarihini güncelle
                $stmt = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                $stmt->execute([$user['id']]);

                redirect('/');
            } else {
                $error = 'Geçersiz email veya şifre!';
            }
        } catch (PDOException $e) {
            error_log("Login Error: " . $e->getMessage());
            $error = 'Bir hata oluştu, lütfen tekrar deneyin.';
        }
    }
}

require_once '../../includes/header.php';
?>

    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-4">
                <div class="card shadow-sm">
                    <div class="card-body p-4">
                        <div class="text-center mb-4">
                            <i class="fas fa-user-circle fa-3x text-primary"></i>
                            <h4 class="mt-2">Giriş Yap</h4>
                            <p class="text-secondary">Hesabınıza giriş yapın</p>
                        </div>

                        <?php if($error): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="fas fa-exclamation-circle me-2"></i><?= $error ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="" class="needs-validation" novalidate>
                            <div class="mb-3">
                                <label class="form-label">
                                    <i class="fas fa-envelope text-secondary me-1"></i>Email
                                </label>
                                <input type="email"
                                       name="email"
                                       class="form-control"
                                       required
                                       value="<?= isset($_POST['email']) ? clean($_POST['email']) : '' ?>"
                                       autocomplete="email">
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
                                           autocomplete="current-password">
                                    <button class="btn btn-outline-secondary"
                                            type="button"
                                            onclick="togglePassword()">
                                        <i class="fas fa-eye" id="toggleIcon"></i>
                                    </button>
                                </div>
                                <div class="invalid-feedback">Şifrenizi girin.</div>
                            </div>

                            <div class="mb-3 form-check">
                                <input type="checkbox" class="form-check-input" id="remember" name="remember">
                                <label class="form-check-label" for="remember">Beni hatırla</label>
                            </div>

                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-sign-in-alt me-2"></i>Giriş Yap
                                </button>
                                <a href="<?= SITE_URL ?>/modules/auth/register.php" class="btn btn-outline-primary">
                                    <i class="fas fa-user-plus me-2"></i>Hesap Oluştur
                                </a>
                            </div>

                            <div class="text-center mt-3">
                                <a href="<?= SITE_URL ?>/modules/auth/forgot_password.php" class="text-decoration-none">
                                    <i class="fas fa-key me-1"></i>Şifremi Unuttum
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const toggleIcon = document.getElementById('toggleIcon');

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