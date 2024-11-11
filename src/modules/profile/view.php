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

    // Kullanıcı ve profil bilgilerini çek
    $stmt = $conn->prepare("
        SELECT u.*, up.*
        FROM users u
        LEFT JOIN user_profiles up ON u.id = up.user_id
        WHERE u.id = ?
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Varsayılan değerler
    if (!$user) {
        $user = [
            'name' => '',
            'email' => '',
            'current_weight' => 0,
            'target_weight' => 0,
            'daily_calorie_limit' => 0,
            'height' => 0,
            'age' => 0,
            'gender' => '',
            'activity_level' => '',
            'diet_type' => 'normal'
        ];
    }

    // Son kilo kaydını çek
    $stmt = $conn->prepare("
        SELECT weight, date 
        FROM weight_tracking 
        WHERE user_id = ? 
        ORDER BY date DESC 
        LIMIT 1
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $lastWeight = $stmt->fetch(PDO::FETCH_ASSOC);

    // Son kilo kaydı yoksa varsayılan değerler
    if (!$lastWeight) {
        $lastWeight = [
            'weight' => $user['current_weight'],
            'date' => date('Y-m-d')
        ];
    }

    // Son 7 günlük kilo takibi
    $stmt = $conn->prepare("
        SELECT weight, date 
        FROM weight_tracking 
        WHERE user_id = ? 
        ORDER BY date DESC 
        LIMIT 7
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $weightHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Bugünkü kalori alımı
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(f.calories * ml.serving_amount), 0) as total_calories
        FROM meal_logs ml
        JOIN foods f ON f.id = ml.food_id
        WHERE ml.user_id = ? AND DATE(ml.date) = CURRENT_DATE()
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $todayCalories = $stmt->fetch(PDO::FETCH_ASSOC)['total_calories'];

    // Bugünkü su tüketimi
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(amount), 0) as total_water
        FROM water_tracking
        WHERE user_id = ? AND DATE(date) = CURRENT_DATE()
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $todayWater = $stmt->fetch(PDO::FETCH_ASSOC)['total_water'];

    // Sağlık durumlarını çek
    $stmt = $conn->prepare("SELECT condition_name FROM health_conditions WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $healthConditions = $stmt->fetchAll(PDO::FETCH_COLUMN);

} catch (PDOException $e) {
    error_log("Profile View Error: " . $e->getMessage());
    $error = 'Bir hata oluştu, lütfen tekrar deneyin.';
}

require_once '../../includes/header.php';
?>

    <div class="container py-4">
        <div class="row">
            <!-- Sol Kolon - Profil Bilgileri -->
            <div class="col-md-4 mb-4">
                <div class="card shadow-sm">
                    <div class="card-body text-center">
                        <div class="mb-3">
                        </div>
                        <h5 class="mb-1"><?= htmlspecialchars($user['name']) ?></h5>
                        <p class="text-secondary mb-3"><?= htmlspecialchars($user['email']) ?></p>

                        <div class="d-grid gap-2">
                            <a href="edit.php" class="btn btn-primary">
                                <i class="fas fa-user-edit"></i> Profili Düzenle
                            </a>
                            <a href="settings.php" class="btn btn-outline-primary">
                                <i class="fas fa-cog"></i> Ayarlar
                            </a>
                        </div>
                    </div>
                    <div class="card-footer bg-light">
                        <div class="row text-center">
                            <div class="col">
                                <h6 class="mb-1"><?= number_format($user['current_weight'], 1) ?> kg</h6>
                                <small class="text-secondary">Mevcut</small>
                            </div>
                            <div class="col border-start">
                                <h6 class="mb-1"><?= number_format($user['target_weight'], 1) ?> kg</h6>
                                <small class="text-secondary">Hedef</small>
                            </div>
                            <div class="col border-start">
                                <h6 class="mb-1"><?= number_format($user['daily_calorie_limit']) ?></h6>
                                <small class="text-secondary">Kalori</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sağlık Bilgileri -->
                <div class="card shadow-sm mt-4">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="fas fa-heartbeat text-primary"></i> Sağlık Bilgileri</h6>
                    </div>
                    <div class="card-body">
                        <ul class="list-unstyled mb-0">
                            <li class="mb-2">
                                <small class="text-secondary">Boy:</small>
                                <span class="float-end"><?= $user['height'] ?? '-' ?> cm</span>
                            </li>
                            <li class="mb-2">
                                <small class="text-secondary">Yaş:</small>
                                <span class="float-end"><?= $user['age'] ?? '-' ?></span>
                            </li>
                            <li class="mb-2">
                                <small class="text-secondary">Cinsiyet:</small>
                                <span class="float-end">
                                <?= $user['gender'] == 'male' ? 'Erkek' : ($user['gender'] == 'female' ? 'Kadın' : '-') ?>
                            </span>
                            </li>
                            <li class="mb-2">
                                <small class="text-secondary">Aktivite Seviyesi:</small>
                                <span class="float-end">
                                <?php
                                $activity_levels = [
                                    'sedentary' => 'Hareketsiz',
                                    'lightly_active' => 'Az Hareketli',
                                    'moderately_active' => 'Orta Hareketli',
                                    'very_active' => 'Çok Hareketli'
                                ];
                                echo $activity_levels[$user['activity_level']] ?? '-';
                                ?>
                            </span>
                            </li>
                            <li>
                                <small class="text-secondary">Diyet Tipi:</small>
                                <span class="float-end">
                                <?php
                                $diet_types = [
                                    'normal' => 'Normal',
                                    'vegetarian' => 'Vejetaryen',
                                    'vegan' => 'Vegan',
                                    'gluten_free' => 'Glutensiz'
                                ];
                                echo $diet_types[$user['diet_type']] ?? 'Normal';
                                ?>
                            </span>
                            </li>
                            <?php if(!empty($healthConditions)): ?>
                                <li class="mt-2">
                                    <small class="text-secondary">Sağlık Durumları:</small>
                                    <div class="mt-1">
                                        <?php foreach($healthConditions as $condition): ?>
                                            <span class="badge bg-info"><?= htmlspecialchars($condition) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Sağ Kolon - İstatistikler ve Grafikler -->
            <div class="col-md-8">
                <!-- Günlük Özet -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="fas fa-chart-pie text-primary"></i> Günlük Özet</h6>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <!-- Kalori -->
                            <div class="col-md-4">
                                <div class="p-3 border rounded bg-light">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <small class="text-secondary">Kalori</small>
                                        <i class="fas fa-fire text-primary"></i>
                                    </div>
                                    <h5 class="mb-0">
                                        <?= number_format($todayCalories) ?> / <?= number_format($user['daily_calorie_limit']) ?>
                                    </h5>
                                    <div class="progress mt-2" style="height: 5px;">
                                        <?php $caloryPercentage = $user['daily_calorie_limit'] > 0 ?
                                            min(($todayCalories / $user['daily_calorie_limit']) * 100, 100) : 0; ?>
                                        <div class="progress-bar" style="width: <?= $caloryPercentage ?>%"></div>
                                    </div>
                                </div>
                            </div>

                            <!-- Su -->
                            <div class="col-md-4">
                                <div class="p-3 border rounded bg-light">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <small class="text-secondary">Su</small>
                                        <i class="fas fa-tint text-info"></i>
                                    </div>
                                    <h5 class="mb-0"><?= number_format($todayWater/1000, 1) ?>L / 3L</h5>
                                    <div class="progress mt-2" style="height: 5px;">
                                        <div class="progress-bar bg-info" style="width: <?= min(($todayWater/3000) * 100, 100) ?>%"></div>
                                    </div>
                                </div>
                            </div>

                            <!-- Kilo -->
                            <div class="col-md-4">
                                <div class="p-3 border rounded bg-light">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <small class="text-secondary">Son Kilo</small>
                                        <i class="fas fa-weight text-success"></i>
                                    </div>
                                    <h5 class="mb-0"><?= number_format($lastWeight['weight'], 1) ?> kg</h5>
                                    <small class="text-secondary">
                                        <?= date('d.m.Y', strtotime($lastWeight['date'])) ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Kilo Takip Grafiği -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-light">
                        <div class="d-flex justify-content-between align-items-center">
                            <h6 class="mb-0"><i class="fas fa-chart-line text-primary"></i> Kilo Takibi</h6>
                            <a href="weight_tracking.php" class="btn btn-sm btn-primary">
                                <i class="fas fa-plus"></i> Kilo Ekle
                            </a>
                        </div>
                    </div>
                    <div class="card-body">
                        <canvas id="weightChart" height="180"></canvas>
                    </div>
                </div>

                <!-- Hızlı Eylemler -->
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="card shadow-sm h-100">
                            <div class="card-body">
                                <h6 class="card-title"><i class="fas fa-utensils text-primary"></i> Öğün Ekle</h6>
                                <p class="text-secondary small">Bugünkü öğünlerinizi kaydedin.</p>
                                <a href="../meals/add_meal.php" class="btn btn-primary btn-sm">
                                    <i class="fas fa-plus"></i> Öğün Ekle
                                </a>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card shadow-sm h-100">
                            <div class="card-body">
                                <h6 class="card-title"><i class="fas fa-calendar-alt text-primary"></i> Diyet Programı</h6>
                                <p class="text-secondary small">Günlük diyet programınızı görüntüleyin.</p>
                                <a href="../diet/view_plan.php" class="btn btn-primary btn-sm">
                                    <i class="fas fa-eye"></i> Programı Gör
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Kilo takip grafiği
        const weightData = <?= json_encode(array_reverse($weightHistory)) ?>;
        const dates = weightData.map(item => item.date);
        const weights = weightData.map(item => parseFloat(item.weight));

        new Chart(document.getElementById('weightChart'), {
            type: 'line',
            data: {
                labels: dates,
                datasets: [{
                    label: 'Kilo (kg)',
                    data: weights,
                    borderColor: '#4e73df',
                    tension: 0.1,
                    fill: false
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: false
                    }
                }
            }
        });
    </script>

<?php require_once '../../includes/footer.php'; ?>