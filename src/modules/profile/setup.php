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

    // Profil kontrolü
    $stmt = $conn->prepare("SELECT * FROM user_profiles WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);

    // Sağlık durumlarını çek
    $stmt = $conn->prepare("SELECT condition_name FROM health_conditions WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $healthConditions = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        $age = clean($_POST['age']);
        $gender = clean($_POST['gender']);
        $height = clean($_POST['height']);
        $current_weight = clean($_POST['current_weight']);
        $target_weight = clean($_POST['target_weight']);
        $activity_level = clean($_POST['activity_level']);
        $diet_type = clean($_POST['diet_type']);
        $health_conditions = isset($_POST['health_conditions']) ? $_POST['health_conditions'] : [];

        // Validasyon
        if(empty($age) || empty($gender) || empty($height) || empty($current_weight) || empty($target_weight)) {
            $error = 'Lütfen tüm zorunlu alanları doldurun.';
        } elseif($age < 15 || $age > 100) {
            $error = 'Geçerli bir yaş girin.';
        } elseif($height < 100 || $height > 250) {
            $error = 'Geçerli bir boy girin.';
        } elseif($current_weight < 30 || $current_weight > 300) {
            $error = 'Geçerli bir kilo girin.';
        } elseif($target_weight < 30 || $target_weight > 300) {
            $error = 'Geçerli bir hedef kilo girin.';
        } else {
            // OpenAI'dan kalori hesaplaması
            $prompt = "Lütfen şu bilgilere göre günlük kalori ihtiyacını hesapla ve sadece sayısal değer ver: 
                      Yaş: {$age}, 
                      Cinsiyet: " . ($gender == 'male' ? 'erkek' : 'kadın') . ", 
                      Kilo: {$current_weight} kg, 
                      Boy: {$height} cm, 
                      Aktivite seviyesi: " . ($activity_level == 'sedentary' ? 'hareketsiz' :
                    ($activity_level == 'lightly_active' ? 'az hareketli' :
                        ($activity_level == 'moderately_active' ? 'orta hareketli' : 'çok hareketli'))) . "
                      Hedef: " . ($target_weight < $current_weight ? 'kilo vermek' :
                    ($target_weight > $current_weight ? 'kilo almak' : 'kiloyu korumak')) . "
                      
                      Lütfen sadece günlük kalori ihtiyacını rakam olarak ver. Örnek: 2000";

            try {
                $response = askOpenAI($prompt);

                // Yanıttan sadece sayıyı çıkar
                preg_match('/\d+/', $response['choices'][0]['message']['content'], $matches);
                $daily_calorie = isset($matches[0]) ? intval($matches[0]) : 2000;

                // Mantıklı bir aralıkta olup olmadığını kontrol et
                if ($daily_calorie < 1200 || $daily_calorie > 4000) {
                    $daily_calorie = 2000;
                }

                // Hedef kiloya göre kalori ayarlaması
                if ($target_weight < $current_weight) {
                    // Kilo vermek için günlük 500 kalori azalt
                    $daily_calorie = max(1200, $daily_calorie - 500);
                } elseif ($target_weight > $current_weight) {
                    // Kilo almak için günlük 500 kalori ekle
                    $daily_calorie = min(4000, $daily_calorie + 500);
                }

            } catch (Exception $e) {
                // Hata durumunda Harris-Benedict formülü ile hesapla
                if ($gender == 'male') {
                    $bmr = 88.362 + (13.397 * $current_weight) + (4.799 * $height) - (5.677 * $age);
                } else {
                    $bmr = 447.593 + (9.247 * $current_weight) + (3.098 * $height) - (4.330 * $age);
                }

                // Aktivite faktörü
                $activity_factors = [
                    'sedentary' => 1.2,
                    'lightly_active' => 1.375,
                    'moderately_active' => 1.55,
                    'very_active' => 1.725
                ];

                $daily_calorie = round($bmr * ($activity_factors[$activity_level] ?? 1.2));

                // Hedef kiloya göre ayarlama
                if ($target_weight < $current_weight) {
                    $daily_calorie = max(1200, $daily_calorie - 500);
                } elseif ($target_weight > $current_weight) {
                    $daily_calorie = min(4000, $daily_calorie + 500);
                }
            }

            // Profil oluştur veya güncelle
            if($profile) {
                $stmt = $conn->prepare("
                    UPDATE user_profiles 
                    SET age = ?, gender = ?, height = ?, current_weight = ?, 
                        target_weight = ?, activity_level = ?, diet_type = ?,
                        daily_calorie_limit = ?
                    WHERE user_id = ?
                ");
                $stmt->execute([
                    $age, $gender, $height, $current_weight,
                    $target_weight, $activity_level, $diet_type,
                    $daily_calorie, $_SESSION['user_id']
                ]);
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO user_profiles (
                        user_id, age, gender, height, current_weight, 
                        target_weight, activity_level, diet_type, daily_calorie_limit
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $_SESSION['user_id'], $age, $gender, $height,
                    $current_weight, $target_weight, $activity_level,
                    $diet_type, $daily_calorie
                ]);
            }

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

            $success = 'Profiliniz başarıyla oluşturuldu!';
            header("refresh:2;url=" . SITE_URL . "/index.php");
        }
    }

} catch (PDOException $e) {
    error_log("Setup Error: " . $e->getMessage());
    $error = 'Bir hata oluştu, lütfen tekrar deneyin.';
}

require_once '../../includes/header.php';
?>

    <div class="container py-4">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card shadow-sm">
                    <div class="card-header bg-primary text-white">
                        <h4 class="card-title mb-0">
                            <i class="fas fa-user-cog"></i> Profil Kurulumu
                        </h4>
                    </div>
                    <div class="card-body">
                        <?php if($error): ?>
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-circle me-2"></i><?= $error ?>
                            </div>
                        <?php endif; ?>

                        <?php if($success): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle me-2"></i><?= $success ?>
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="" class="needs-validation" novalidate>
                            <!-- Temel Bilgiler -->
                            <h5 class="mb-3">Temel Bilgiler</h5>
                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label class="form-label">Yaş <span class="text-danger">*</span></label>
                                    <input type="number"
                                           name="age"
                                           class="form-control"
                                           required
                                           min="15"
                                           max="100"
                                           value="<?= $profile['age'] ?? '' ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Cinsiyet <span class="text-danger">*</span></label>
                                    <select name="gender" class="form-select" required>
                                        <option value="">Seçin</option>
                                        <option value="male" <?= ($profile['gender'] ?? '') == 'male' ? 'selected' : '' ?>>Erkek</option>
                                        <option value="female" <?= ($profile['gender'] ?? '') == 'female' ? 'selected' : '' ?>>Kadın</option>
                                    </select>
                                </div>
                            </div>

                            <!-- Fiziksel Bilgiler -->
                            <h5 class="mb-3">Fiziksel Bilgiler</h5>
                            <div class="row g-3 mb-4">
                                <div class="col-md-4">
                                    <label class="form-label">Boy (cm) <span class="text-danger">*</span></label>
                                    <input type="number"
                                           name="height"
                                           class="form-control"
                                           required
                                           min="100"
                                           max="250"
                                           value="<?= $profile['height'] ?? '' ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Mevcut Kilo (kg) <span class="text-danger">*</span></label>
                                    <input type="number"
                                           name="current_weight"
                                           class="form-control"
                                           required
                                           min="30"
                                           max="300"
                                           step="0.1"
                                           value="<?= $profile['current_weight'] ?? '' ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Hedef Kilo (kg) <span class="text-danger">*</span></label>
                                    <input type="number"
                                           name="target_weight"
                                           class="form-control"
                                           required
                                           min="30"
                                           max="300"
                                           step="0.1"
                                           value="<?= $profile['target_weight'] ?? '' ?>">
                                </div>
                            </div>

                            <!-- Aktivite ve Diyet -->
                            <h5 class="mb-3">Aktivite ve Diyet</h5>
                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label class="form-label">Aktivite Seviyesi <span class="text-danger">*</span></label>
                                    <select name="activity_level" class="form-select" required>
                                        <option value="">Seçin</option>
                                        <option value="sedentary" <?= ($profile['activity_level'] ?? '') == 'sedentary' ? 'selected' : '' ?>>
                                            Hareketsiz (Masa başı iş)
                                        </option>
                                        <option value="lightly_active" <?= ($profile['activity_level'] ?? '') == 'lightly_active' ? 'selected' : '' ?>>
                                            Az Hareketli (Haftada 1-3 gün egzersiz)
                                        </option>
                                        <option value="moderately_active" <?= ($profile['activity_level'] ?? '') == 'moderately_active' ? 'selected' : '' ?>>
                                            Orta Hareketli (Haftada 3-5 gün egzersiz)
                                        </option>
                                        <option value="very_active" <?= ($profile['activity_level'] ?? '') == 'very_active' ? 'selected' : '' ?>>
                                            Çok Hareketli (Haftada 6-7 gün egzersiz)
                                        </option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Diyet Tipi</label>
                                    <select name="diet_type" class="form-select">
                                        <option value="normal" <?= ($profile['diet_type'] ?? '') == 'normal' ? 'selected' : '' ?>>Normal</option>
                                        <option value="vegetarian" <?= ($profile['diet_type'] ?? '') == 'vegetarian' ? 'selected' : '' ?>>Vejetaryen</option>
                                        <option value="vegan" <?= ($profile['diet_type'] ?? '') == 'vegan' ? 'selected' : '' ?>>Vegan</option>
                                        <option value="gluten_free" <?= ($profile['diet_type'] ?? '') == 'gluten_free' ? 'selected' : '' ?>>Glutensiz</option>
                                    </select>
                                </div>
                            </div>

                            <!-- Sağlık Durumları -->
                            <h5 class="mb-3">Sağlık Durumları</h5>
                            <div class="mb-4">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <div class="form-check">
                                            <input class="form-check-input"
                                                   type="checkbox"
                                                   name="health_conditions[]"
                                                   value="diabetes">
                                            <label class="form-check-label">Diyabet</label>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-check">
                                            <input class="form-check-input"
                                                   type="checkbox"
                                                   name="health_conditions[]"
                                                   value="hypertension">
                                            <label class="form-check-label">Hipertansiyon</label>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-check">
                                            <input class="form-check-input"
                                                   type="checkbox"
                                                   name="health_conditions[]"
                                                   value="cholesterol">
                                            <label class="form-check-label">Kolesterol</label>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="fas fa-save me-2"></i>Profili Oluştur
                                </button>
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