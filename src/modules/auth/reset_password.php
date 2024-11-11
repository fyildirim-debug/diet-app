<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

$error = '';
$success = '';
$valid_token = false;
$token = $_GET['token'] ?? '';

// Token kontrolü
if(!empty($token)) {
    try {
        $db = new Database();
        $conn = $db->getConnection();

        $stmt = $conn->prepare("
            SELECT user_id 
            FROM password_resets 
            WHERE token = ? 
            AND expires_at > NOW() 
            AND used = 0
        ");
        $stmt->execute([$token]);
        $reset = $stmt->fetch(PDO::FETCH_ASSOC);

        if($reset) {
            $valid_token = true;
        } else {
            $error = 'Geçersiz veya süresi dolmuş link.';
        }
    } catch (PDOException $e) {
        error_log("Token Check Error: " . $e->getMessage());
        $error = 'Bir hata oluştu, lütfen tekrar deneyin.';
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && $valid_token) {
    $password = $_POST['password'];
    $password_confirm = $_POST['password_confirm'];

    if(empty($password) || empty($password_confirm)) {
        $error = 'Lütfen tüm alanları doldurun.';
    } elseif(strlen($password) < 6) {
        $error = 'Şifre en az 6 karakter olmalıdır.';
    } elseif($password !== $password_confirm) {
        $error = 'Şifreler eşleşmiyor.';
    } else {
        try {
            // Şifreyi güncelle
            $stmt = $conn->prepare("
                UPDATE users 
                SET password = ? 
                WHERE id = ?
            ");
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt->execute([$hashed_password, $reset['user_id']]);

            // Token'ı kullanıldı olarak işaretle
            $stmt = $conn->prepare("
                UPDATE password_resets 
                SET used = 1 
                WHERE token = ?
            ");
            $stmt->execute([$token]);

            $success = 'Şifreniz başarıyla güncellendi. Şimdi giriş yapabilirsiniz.';
            header("refresh:2;url=" . SITE_URL . "/modules/auth/login.php");

        } catch (PDOException $e) {
            error_log("Password Reset Error: " . $e->getMessage());
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
                            <i class="fas fa-lock-open fa-3x text-primary"></i>
                            <h4 class="mt-2">Şifre Yenileme</h4>
                            <p class="text-secondary">Yeni şifrenizi belirleyin</p>
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

                        <?php if($valid_token): ?>
                            <form method="POST" action="" class="needs-validation" novalidate>
                                <div class="mb-3">
                                    <label class="form-label">
                                        <i class="fas fa-lock text-secondary me-1"></i>Yeni Şifre
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

                                <div class="d-grid">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-save me-2"></i>Şifreyi Güncelle
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>
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