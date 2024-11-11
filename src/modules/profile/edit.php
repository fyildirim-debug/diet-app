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

    // Mevcut kullanıcı bilgilerini çek
    $stmt = $conn->prepare("
        SELECT u.*, up.*
        FROM users u
        LEFT JOIN user_profiles up ON u.id = up.user_id
        WHERE u.id = ?
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        // Form verilerini al
        $name = clean($_POST['name']);
        $email = clean($_POST['email']);
        $age = clean($_POST['age']);
        $gender = clean($_POST['gender']);
        $height = clean($_POST['height']);
        $current_weight = clean($_POST['current_weight']);
        $target_weight = clean($_POST['target_weight']);
        $activity_level = clean($_POST['activity_level']);
        $diet_type = clean($_POST['diet_type']);
        $health_conditions = isset($_POST['health_conditions']) ? $_POST['health_conditions'] : [];

        // Validasyon
        if(empty($name) || empty($email)) {
            $error = 'Ad ve email alanları zorunludur.';
        } elseif(!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Geçerli bir email adresi girin.';
        } else {
            // Email değişikliği varsa, başka kullanıcıda kullanılıyor mu kontrol et
            if($email !== $user['email']) {
                $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                $stmt->execute([$email, $_SESSION['user_id']]);
                if($stmt->fetch()) {
                    $error = 'Bu email adresi başka bir kullanıcı tarafından kullanılıyor.';
                }
            }

            if(empty($error)) {
                // Kullanıcı tablosunu güncelle
                $stmt = $conn->prepare("
                    UPDATE users 
                    SET name = ?, email = ?
                    WHERE id = ?
                ");
                $stmt->execute([$name, $email, $_SESSION['user_id']]);

                // Profil tablosunu güncelle
                $stmt = $conn->prepare("
                    INSERT INTO user_profiles (
                        user_id, age, gender, height, current_weight, 
                        target_weight, activity_level, diet_type
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        age = VALUES(age),
                        gender = VALUES(gender),
                        height = VALUES(height),
                        current_weight = VALUES(current_weight),
                        target_weight = VALUES(target_weight),
                        activity_level = VALUES(activity_level),
                        diet_type = VALUES(diet_type)
                ");
                $stmt->execute([
                    $_SESSION['user_id'], $age, $gender, $height,
                    $current_weight, $target_weight, $activity_level, $diet_type
                ]);

                // Sağlık durumlarını güncelle
                $stmt = $conn->prepare("DELETE FROM health_conditions WHERE user_id = ?");
                $stmt->execute([$_SESSION['user_id']]);

                if(!empty($health_conditions)) {
                    $stmt = $conn->prepare("
                        INSERT INTO health_conditions (user_id, condition_name) 
                        VALUES (?, ?)
                    ");
                    foreach($health_conditions as $condition) {
                        $stmt->execute([$_SESSION['user_id'], $condition]);
                    }
                }

                // Kalori limitini OpenAI ile hesapla
                $prompt = "Lütfen şu bilgilere göre günlük kalori ihtiyacını hesapla: 
                          Yaş: $age, 
                          Cinsiyet: $gender, 
                          Kilo: $current_weight kg, 
                          Boy: $height cm, 
                          Aktivite seviyesi: $activity_level. 
                          Sadece sayısal değeri ver.";

                $response = askOpenAI($prompt);
                $daily_calorie = intval($response['choices'][0]['message']['content']);

                // Kalori limitini güncelle
                $stmt = $conn->prepare("
                    UPDATE user_profiles 
                    SET daily_calorie_limit = ? 
                    WHERE user_id = ?
                ");
                $stmt->execute([$daily_calorie, $_SESSION['user_id']]);

                $success = 'Profil bilgileriniz başarıyla güncellendi.';

                // Session'daki kullanıcı adını güncelle
                $_SESSION['user_name'] = $name;
            }
        }
    }

    // Mevcut sağlık durumlarını çek
    $stmt = $conn->prepare("SELECT condition_name FROM health_conditions WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $health_conditions = $stmt->fetchAll(PDO::FETCH_COLUMN);

} catch (PDOException $e) {
    error_log("Profile Edit Error: " . $e->getMessage());
    $error = 'Bir hata oluştu, lütfen tekrar deneyin.';
}

require_once '../../includes/header.php';
?>

    <div class="container py-4">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card shadow-sm">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-user-edit text-primary"></i> Profil Düzenle
                        </h5>
                    </div>
                    <div class="card-body">
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
                            <!-- Temel Bilgiler -->
                            <h6 class="mb-3">Temel Bilgiler</h6>
                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label class="form-label">Ad Soyad</label>
                                    <input type="text"
                                           name="name"
                                           class="form-control"
                                           required
                                           value="<?= htmlspecialchars($user['name']) ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Email</label>
                                    <input type="email"
                                           name="email"
                                           class="form-control"
                                           required
                                           value="<?= htmlspecialchars($user['email']) ?>">
                                </div>
                            </div>

                            <!-- Fiziksel Bilgiler -->
                            <h6 class="mb-3">Fiziksel Bilgiler</h6>
                            <div class="row g-3 mb-4">
                                <div class="col-md-3">
                                    <label class="form-label">Yaş</label>
                                    <input type="number"
                                           name="age"
                                           class="form-control"
                                           required
                                           value="<?= $user['age'] ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Cinsiyet</label>
                                    <select name="gender" class="form-select" required>
                                        <option value="male" <?= $user['gender'] == 'male' ? 'selected' : '' ?>>Erkek</option>
                                        <option value="female" <?= $user['gender'] == 'female' ? 'selected' : '' ?>>Kadın</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Boy (cm)</label>
                                    <input type="number"
                                           name="height"
                                           class="form-control"
                                           required
                                           value="<?= $user['height'] ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Mevcut Kilo (kg)</label>
                                    <input type="number"
                                           name="current_weight"
                                           class="form-control"
                                           required
                                           step="0.1"
                                           value="<?= $user['current_weight'] ?>">
                                </div>
                            </div>

                            <!-- Hedef ve Aktivite -->
                            <h6 class="mb-3">Hedef ve Aktivite</h6>
                            <div class="row g-3 mb-4">
                                <div class="col-md-4">
                                    <label class="form-label">Hedef Kilo (kg)</label>
                                    <input type="number"
                                           name="target_weight"
                                           class="form-control"
                                           required
                                           step="0.1"
                                           value="<?= $user['target_weight'] ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Aktivite Seviyesi</label>
                                    <select name="activity_level" class="form-select" required>
                                        <option value="sedentary" <?= $user['activity_level'] == 'sedentary' ? 'selected' : '' ?>>
                                            Hareketsiz
                                        </option>
                                        <option value="lightly_active" <?= $user['activity_level'] == 'lightly_active' ? 'selected' : '' ?>>
                                            Az Hareketli
                                        </option>
                                        <option value="moderately_active" <?= $user['activity_level'] == 'moderately_active' ? 'selected' : '' ?>>
                                            Orta Hareketli
                                        </option>
                                        <option value="very_active" <?= $user['activity_level'] == 'very_active' ? 'selected' : '' ?>>
                                            Çok Hareketli
                                        </option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Diyet Tipi</label>
                                    <select name="diet_type" class="form-select" required>
                                        <option value="normal" <?= $user['diet_type'] == 'normal' ? 'selected' : '' ?>>
                                            Normal
                                        </option>
                                        <option value="vegetarian" <?= $user['diet_type'] == 'vegetarian' ? 'selected' : '' ?>>
                                            Vejetaryen
                                        </option>
                                        <option value="vegan" <?= $user['diet_type'] == 'vegan' ? 'selected' : '' ?>>
                                            Vegan
                                        </option>
                                        <option value="gluten_free" <?= $user['diet_type'] == 'gluten_free' ? 'selected' : '' ?>>
                                            Glutensiz
                                        </option>
                                    </select>
                                </div>
                            </div>

                            <!-- Sağlık Durumları -->
                            <h6 class="mb-3">Sağlık Durumları</h6>
                            <div class="mb-4">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <div class="form-check">
                                            <input class="form-check-input"
                                                   type="checkbox"
                                                   name="health_conditions[]"
                                                   value="diabetes"
                                                <?= in_array('diabetes', $health_conditions) ? 'checked' : '' ?>>
                                            <label class="form-check-label">Diyabet</label>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-check">
                                            <input class="form-check-input"
                                                   type="checkbox"
                                                   name="health_conditions[]"
                                                   value="hypertension"
                                                <?= in_array('hypertension', $health_conditions) ? 'checked' : '' ?>>
                                            <label class="form-check-label">Hipertansiyon</label>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-check">
                                            <input class="form-check-input"
                                                   type="checkbox"
                                                   name="health_conditions[]"
                                                   value="cholesterol"
                                                <?= in_array('cholesterol', $health_conditions) ? 'checked' : '' ?>>
                                            <label class="form-check-label">Kolesterol</label>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save me-2"></i>Değişiklikleri Kaydet
                                </button>
                                <a href="view.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-arrow-left me-2"></i>Profile Dön
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