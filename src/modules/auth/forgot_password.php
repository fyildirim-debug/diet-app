<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = clean($_POST['email']);

    if(empty($email)) {
        $error = 'Lütfen email adresinizi girin.';
    } elseif(!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Geçerli bir email adresi girin.';
    } else {
        try {
            $db = new Database();
            $conn = $db->getConnection();

            // Email kontrolü
            $stmt = $conn->prepare("SELECT id, name FROM users WHERE email = ? AND status = 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if($user) {
                // Benzersiz token oluştur
                $token = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

                // Token'ı veritabanına kaydet
                $stmt = $conn->prepare("
                    INSERT INTO password_resets (user_id, token, expires_at) 
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$user['id'], $token, $expires]);

                // Reset linkini oluştur
                $reset_link = SITE_URL . "/modules/auth/reset_password.php?token=" . $token;

                // Email gönderme işlemi burada yapılacak
                // Şimdilik sadece başarılı mesajı gösterelim
                $success = 'Şifre sıfırlama talimatları email adresinize gönderildi.';

                // Test amaçlı linki göster (Gerçek uygulamada kaldırılacak)
                $success .= "<br>Test Link: <a href='$reset_link'>Şifreyi Sıfırla</a>";
            } else {
                $error = 'Bu email adresi ile kayıtlı kullanıcı bulunamadı.';
            }
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
                            <i class="fas fa-key fa-3x text-primary"></i>
                            <h4 class="mt-2">Şifremi Unuttum</h4>
                            <p class="text-secondary">Email adresinizi girin</p>
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

                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-paper-plane me-2"></i>Gönder
                                </button>
                                <a href="<?= SITE_URL ?>/modules/auth/login.php" class="btn btn-outline-primary">
                                    <i class="fas fa-arrow-left me-2"></i>Giriş Sayfasına Dön
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
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