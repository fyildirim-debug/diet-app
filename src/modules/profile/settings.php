<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Giriş kontrolü
requireLogin();

$error = '';
$success = '';

try {
    $db = new Database();
    $conn = $db->getConnection();

    // Kullanıcı bilgilerini çek
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Bildirim ayarlarını çek
    $stmt = $conn->prepare("SELECT * FROM user_settings WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        if (isset($_POST['update_password'])) {
            $current_password = $_POST['current_password'];
            $new_password = $_POST['new_password'];
            $confirm_password = $_POST['confirm_password'];

            if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
                $error = 'Tüm şifre alanlarını doldurun.';
            } elseif (!password_verify($current_password, $user['password'])) {
                $error = 'Mevcut şifre yanlış.';
            } elseif ($new_password !== $confirm_password) {
                $error = 'Yeni şifreler eşleşmiyor.';
            } elseif (strlen($new_password) < 6) {
                $error = 'Şifre en az 6 karakter olmalıdır.';
            } else {
                $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->execute([password_hash($new_password, PASSWORD_DEFAULT), $_SESSION['user_id']]);
                $success = 'Şifreniz başarıyla güncellendi.';
            }
        }
        elseif (isset($_POST['update_email'])) {
            $new_email = clean($_POST['new_email']);

            if (empty($new_email)) {
                $error = 'Email adresi boş olamaz.';
            } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Geçerli bir email adresi girin.';
            } else {
                // Email kullanımda mı kontrol et
                $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                $stmt->execute([$new_email, $_SESSION['user_id']]);
                if ($stmt->fetch()) {
                    $error = 'Bu email adresi başka bir kullanıcı tarafından kullanılıyor.';
                } else {
                    $stmt = $conn->prepare("UPDATE users SET email = ? WHERE id = ?");
                    $stmt->execute([$new_email, $_SESSION['user_id']]);
                    $success = 'Email adresiniz başarıyla güncellendi.';
                }
            }
        }
        elseif (isset($_POST['update_notifications'])) {
            $email_notifications = isset($_POST['email_notifications']) ? 1 : 0;
            $meal_reminders = isset($_POST['meal_reminders']) ? 1 : 0;
            $water_reminders = isset($_POST['water_reminders']) ? 1 : 0;
            $weight_reminders = isset($_POST['weight_reminders']) ? 1 : 0;

            if ($settings) {
                $stmt = $conn->prepare("
                    UPDATE user_settings 
                    SET email_notifications = ?, 
                        meal_reminders = ?,
                        water_reminders = ?,
                        weight_reminders = ?
                    WHERE user_id = ?
                ");
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO user_settings 
                    (user_id, email_notifications, meal_reminders, water_reminders, weight_reminders)
                    VALUES (?, ?, ?, ?, ?)
                ");
            }

            $stmt->execute([
                $email_notifications,
                $meal_reminders,
                $water_reminders,
                $weight_reminders,
                $_SESSION['user_id']
            ]);

            $success = 'Bildirim ayarlarınız güncellendi.';
        }
        elseif (isset($_POST['delete_account'])) {
            $confirmation = clean($_POST['delete_confirmation']);

            if ($confirmation !== 'DELETE') {
                $error = 'Hesap silme işlemi için DELETE yazın.';
            } else {
                // İlişkili tüm verileri sil
                $conn->beginTransaction();

                try {
                    $tables = [
                        'meal_logs',
                        'water_tracking',
                        'weight_tracking',
                        'sleep_tracking',
                        'health_conditions',
                        'user_settings',
                        'diet_programs',
                        'user_profiles'
                    ];

                    foreach ($tables as $table) {
                        $stmt = $conn->prepare("DELETE FROM $table WHERE user_id = ?");
                        $stmt->execute([$_SESSION['user_id']]);
                    }

                    // En son kullanıcıyı sil
                    $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
                    $stmt->execute([$_SESSION['user_id']]);

                    $conn->commit();

                    // Oturumu sonlandır
                    session_destroy();
                    redirect('/modules/auth/login.php');

                } catch (Exception $e) {
                    $conn->rollBack();
                    $error = 'Hesap silme işlemi sırasında bir hata oluştu.';
                }
            }
        }
    }

} catch (PDOException $e) {
    error_log("Settings Error: " . $e->getMessage());
    $error = 'Bir hata oluştu, lütfen tekrar deneyin.';
}

require_once '../../includes/header.php';
?>

    <div class="container py-4">
        <div class="row">
            <div class="col-md-3 mb-4">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="nav flex-column nav-pills">
                            <button class="nav-link active mb-2" data-bs-toggle="pill" data-bs-target="#password">
                                <i class="fas fa-key me-2"></i>Şifre Değiştir
                            </button>
                            <button class="nav-link mb-2" data-bs-toggle="pill" data-bs-target="#email">
                                <i class="fas fa-envelope me-2"></i>Email Değiştir
                            </button>
                            <button class="nav-link mb-2" data-bs-toggle="pill" data-bs-target="#notifications">
                                <i class="fas fa-bell me-2"></i>Bildirimler
                            </button>
                            <button class="nav-link text-danger" data-bs-toggle="pill" data-bs-target="#delete">
                                <i class="fas fa-trash-alt me-2"></i>Hesabı Sil
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-9">
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

                <div class="tab-content">
                    <!-- Şifre Değiştir -->
                    <div class="tab-pane fade show active" id="password">
                        <div class="card shadow-sm">
                            <div class="card-header bg-light">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-key text-primary"></i> Şifre Değiştir
                                </h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" action="" class="needs-validation" novalidate>
                                    <div class="mb-3">
                                        <label class="form-label">Mevcut Şifre</label>
                                        <input type="password" name="current_password" class="form-control" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Yeni Şifre</label>
                                        <input type="password" name="new_password" class="form-control" required minlength="6">
                                        <div class="form-text">En az 6 karakter</div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Yeni Şifre (Tekrar)</label>
                                        <input type="password" name="confirm_password" class="form-control" required minlength="6">
                                    </div>
                                    <button type="submit" name="update_password" class="btn btn-primary">
                                        <i class="fas fa-save me-2"></i>Şifreyi Güncelle
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Email Değiştir -->
                    <div class="tab-pane fade" id="email">
                        <div class="card shadow-sm">
                            <div class="card-header bg-light">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-envelope text-primary"></i> Email Değiştir
                                </h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" action="" class="needs-validation" novalidate>
                                    <div class="mb-3">
                                        <label class="form-label">Mevcut Email</label>
                                        <input type="email" class="form-control" value="<?= htmlspecialchars($user['email']) ?>" disabled>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Yeni Email</label>
                                        <input type="email" name="new_email" class="form-control" required>
                                    </div>
                                    <button type="submit" name="update_email" class="btn btn-primary">
                                        <i class="fas fa-save me-2"></i>Email'i Güncelle
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Bildirimler -->
                    <div class="tab-pane fade" id="notifications">
                        <div class="card shadow-sm">
                            <div class="card-header bg-light">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-bell text-primary"></i> Bildirim Ayarları
                                </h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" action="">
                                    <div class="mb-3">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" name="email_notifications"
                                                <?= ($settings['email_notifications'] ?? 0) ? 'checked' : '' ?>>
                                            <label class="form-check-label">Email Bildirimleri</label>
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" name="meal_reminders"
                                                <?= ($settings['meal_reminders'] ?? 0) ? 'checked' : '' ?>>
                                            <label class="form-check-label">Öğün Hatırlatıcıları</label>
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" name="water_reminders"
                                                <?= ($settings['water_reminders'] ?? 0) ? 'checked' : '' ?>>
                                            <label class="form-check-label">Su İçme Hatırlatıcıları</label>
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <div class="form-check form-switch">
                                            <input class="form-check-input" type="checkbox" name="weight_reminders"
                                                <?= ($settings['weight_reminders'] ?? 0) ? 'checked' : '' ?>>
                                            <label class="form-check-label">Kilo Takip Hatırlatıcıları</label>
                                        </div>
                                    </div>
                                    <button type="submit" name="update_notifications" class="btn btn-primary">
                                        <i class="fas fa-save me-2"></i>Ayarları Kaydet
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Hesap Silme -->
                    <div class="tab-pane fade" id="delete">
                        <div class="card shadow-sm border-danger">
                            <div class="card-header bg-danger text-white">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-exclamation-triangle"></i> Hesabı Sil
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="alert alert-warning">
                                    <i class="fas fa-exclamation-circle me-2"></i>
                                    <strong>Dikkat!</strong> Bu işlem geri alınamaz ve tüm verileriniz silinir.
                                </div>
                                <form method="POST" action="" class="needs-validation" novalidate>
                                    <div class="mb-3">
                                        <label class="form-label">Onay</label>
                                        <input type="text"
                                               name="delete_confirmation"
                                               class="form-control"
                                               required
                                               placeholder="Onaylamak için 'DELETE' yazın">
                                    </div>
                                    <button type="submit"
                                            name="delete_account"
                                            class="btn btn-danger"
                                            onclick="return confirm('Hesabınızı silmek istediğinizden emin misiniz?')">
                                        <i class="fas fa-trash-alt me-2"></i>Hesabı Sil
                                    </button>
                                </form>
                            </div>
                        </div>
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