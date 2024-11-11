<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Giriş kontrolü
requireLogin();

$error = '';
$success = '';
$period = $_GET['period'] ?? '30'; // Varsayılan 30 günlük görünüm

try {
    $db = new Database();
    $conn = $db->getConnection();

    // Kullanıcı profilini çek
    $stmt = $conn->prepare("
        SELECT * FROM user_profiles 
        WHERE user_id = ?
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);

    // Kilo takibi
    $stmt = $conn->prepare("
        SELECT weight, date 
        FROM weight_tracking 
        WHERE user_id = ? 
        AND date >= DATE_SUB(CURRENT_DATE, INTERVAL ? DAY)
        ORDER BY date ASC
    ");
    $stmt->execute([$_SESSION['user_id'], $period]);
    $weightData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Kalori takibi
    $stmt = $conn->prepare("
        SELECT 
            DATE(ml.date) as date,
            SUM(f.calories * ml.serving_amount) as total_calories
        FROM meal_logs ml
        JOIN foods f ON f.id = ml.food_id
        WHERE ml.user_id = ?
        AND DATE(ml.date) >= DATE_SUB(CURRENT_DATE, INTERVAL ? DAY)
        GROUP BY DATE(ml.date)
        ORDER BY date ASC
    ");
    $stmt->execute([$_SESSION['user_id'], $period]);
    $calorieData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Besin değerleri takibi
    $stmt = $conn->prepare("
        SELECT 
            DATE(ml.date) as date,
            SUM(f.protein * ml.serving_amount) as total_protein,
            SUM(f.carbs * ml.serving_amount) as total_carbs,
            SUM(f.fat * ml.serving_amount) as total_fat
        FROM meal_logs ml
        JOIN foods f ON f.id = ml.food_id
        WHERE ml.user_id = ?
        AND DATE(ml.date) >= DATE_SUB(CURRENT_DATE, INTERVAL ? DAY)
        GROUP BY DATE(ml.date)
        ORDER BY date ASC
    ");
    $stmt->execute([$_SESSION['user_id'], $period]);
    $nutritionData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Su tüketimi
    $stmt = $conn->prepare("
        SELECT 
            DATE(date) as date,
            SUM(amount) as total_water
        FROM water_tracking
        WHERE user_id = ?
        AND date >= DATE_SUB(CURRENT_DATE, INTERVAL ? DAY)
        GROUP BY DATE(date)
        ORDER BY date ASC
    ");
    $stmt->execute([$_SESSION['user_id'], $period]);
    $waterData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Uyku takibi
    $stmt = $conn->prepare("
        SELECT 
            date,
            duration,
            quality
        FROM sleep_tracking
        WHERE user_id = ?
        AND date >= DATE_SUB(CURRENT_DATE, INTERVAL ? DAY)
        ORDER BY date ASC
    ");
    $stmt->execute([$_SESSION['user_id'], $period]);
    $sleepData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // İstatistiksel hesaplamalar
    $stats = [
        'weight' => [
            'start' => $weightData[0]['weight'] ?? 0,
            'current' => end($weightData)['weight'] ?? 0,
            'change' => 0
        ],
        'calories' => [
            'avg' => 0,
            'min' => 0,
            'max' => 0
        ],
        'water' => [
            'avg' => 0
        ],
        'sleep' => [
            'avg' => 0
        ]
    ];

    // Kilo değişimi
    $stats['weight']['change'] = $stats['weight']['current'] - $stats['weight']['start'];

    // Kalori istatistikleri
    if (!empty($calorieData)) {
        $calories = array_column($calorieData, 'total_calories');
        $stats['calories']['avg'] = array_sum($calories) / count($calories);
        $stats['calories']['min'] = min($calories);
        $stats['calories']['max'] = max($calories);
    }

    // Su tüketimi ortalaması
    if (!empty($waterData)) {
        $water = array_column($waterData, 'total_water');
        $stats['water']['avg'] = array_sum($water) / count($water);
    }

    // Uyku süresi ortalaması
    if (!empty($sleepData)) {
        $sleep = array_column($sleepData, 'duration');
        $stats['sleep']['avg'] = array_sum($sleep) / count($sleep);
    }

} catch (PDOException $e) {
    error_log("Progress Error: " . $e->getMessage());
    $error = 'Bir hata oluştu, lütfen tekrar deneyin.';
}

require_once '../../includes/header.php';
?>

    <div class="container py-4">
        <!-- Dönem Seçici -->
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="fas fa-chart-line text-primary"></i> İlerleme Raporu
                    </h5>
                    <div class="btn-group">
                        <a href="?period=7" class="btn btn-outline-primary <?= $period == 7 ? 'active' : '' ?>">7 Gün</a>
                        <a href="?period=30" class="btn btn-outline-primary <?= $period == 30 ? 'active' : '' ?>">30 Gün</a>
                        <a href="?period=90" class="btn btn-outline-primary <?= $period == 90 ? 'active' : '' ?>">90 Gün</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Özet Kartları -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <h6 class="text-secondary mb-2">Kilo Değişimi</h6>
                        <h4 class="mb-0">
                            <?php if($stats['weight']['change'] != 0): ?>
                                <span class="text-<?= $stats['weight']['change'] < 0 ? 'success' : 'danger' ?>">
                                <?= $stats['weight']['change'] > 0 ? '+' : '' ?><?= number_format($stats['weight']['change'], 1) ?> kg
                            </span>
                            <?php else: ?>
                                <span class="text-secondary">Değişim yok</span>
                            <?php endif; ?>
                        </h4>
                        <small class="text-secondary">
                            <?= number_format($stats['weight']['start'], 1) ?> kg →
                            <?= number_format($stats['weight']['current'], 1) ?> kg
                        </small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <h6 class="text-secondary mb-2">Ortalama Kalori</h6>
                        <h4 class="mb-0"><?= number_format($stats['calories']['avg']) ?></h4>
                        <small class="text-secondary">
                            Min: <?= number_format($stats['calories']['min']) ?> /
                            Max: <?= number_format($stats['calories']['max']) ?>
                        </small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <h6 class="text-secondary mb-2">Ortalama Su</h6>
                        <h4 class="mb-0"><?= number_format($stats['water']['avg']/1000, 1) ?>L</h4>
                        <small class="text-secondary">Günlük ortalama</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <h6 class="text-secondary mb-2">Ortalama Uyku</h6>
                        <h4 class="mb-0"><?= number_format($stats['sleep']['avg']/60, 1) ?></h4>
                        <small class="text-secondary">Saat/gün</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Sol Kolon - Grafikler -->
            <div class="col-md-8">
                <!-- Kilo Grafiği -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-light">
                        <h6 class="card-title mb-0">Kilo Takibi</h6>
                    </div>
                    <div class="card-body">
                        <canvas id="weightChart" height="180"></canvas>
                    </div>
                </div>

                <!-- Kalori Grafiği -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-light">
                        <h6 class="card-title mb-0">Kalori Takibi</h6>
                    </div>
                    <div class="card-body">
                        <canvas id="calorieChart" height="180"></canvas>
                    </div>
                </div>

                <!-- Besin Değerleri Grafiği -->
                <div class="card shadow-sm">
                    <div class="card-header bg-light">
                        <h6 class="card-title mb-0">Besin Değerleri</h6>
                    </div>
                    <div class="card-body">
                        <canvas id="nutritionChart" height="180"></canvas>
                    </div>
                </div>
            </div>

            <!-- Sağ Kolon - Su ve Uyku -->
            <div class="col-md-4">
                <!-- Su Takibi -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-light">
                        <h6 class="card-title mb-0">Su Tüketimi</h6>
                    </div>
                    <div class="card-body">
                        <canvas id="waterChart" height="180"></canvas>
                    </div>
                </div>

                <!-- Uyku Takibi -->
                <div class="card shadow-sm">
                    <div class="card-header bg-light">
                        <h6 class="card-title mb-0">Uyku Takibi</h6>
                    </div>
                    <div class="card-body">
                        <canvas id="sleepChart" height="180"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Kilo grafiği
        const weightData = <?= json_encode($weightData) ?>;
        new Chart(document.getElementById('weightChart'), {
            type: 'line',
            data: {
                labels: weightData.map(item => item.date),
                datasets: [{
                    label: 'Kilo (kg)',
                    data: weightData.map(item => item.weight),
                    borderColor: '#4e73df',
                    tension: 0.1
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        display: false
                    }
                }
            }
        });

        // Kalori grafiği
        const calorieData = <?= json_encode($calorieData) ?>;
        new Chart(document.getElementById('calorieChart'), {
            type: 'bar',
            data: {
                labels: calorieData.map(item => item.date),
                datasets: [{
                    label: 'Kalori',
                    data: calorieData.map(item => item.total_calories),
                    backgroundColor: '#4e73df'
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        display: false
                    }
                }
            }
        });

        // Besin değerleri grafiği
        const nutritionData = <?= json_encode($nutritionData) ?>;
        new Chart(document.getElementById('nutritionChart'), {
            type: 'line',
            data: {
                labels: nutritionData.map(item => item.date),
                datasets: [
                    {
                        label: 'Protein',
                        data: nutritionData.map(item => item.total_protein),
                        borderColor: '#1cc88a'
                    },
                    {
                        label: 'Karbonhidrat',
                        data: nutritionData.map(item => item.total_carbs),
                        borderColor: '#f6c23e'
                    },
                    {
                        label: 'Yağ',
                        data: nutritionData.map(item => item.total_fat),
                        borderColor: '#e74a3b'
                    }
                ]
            },
            options: {
                responsive: true
            }
        });

        // Su grafiği
        const waterData = <?= json_encode($waterData) ?>;
        new Chart(document.getElementById('waterChart'), {
            type: 'bar',
            data: {
                labels: waterData.map(item => item.date),
                datasets: [{
                    label: 'Su (L)',
                    data: waterData.map(item => item.total_water/1000),
                    backgroundColor: '#36b9cc'
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        display: false
                    }
                }
            }
        });

        // Uyku grafiği
        const sleepData = <?= json_encode($sleepData) ?>;
        new Chart(document.getElementById('sleepChart'), {
            type: 'line',
            data: {
                labels: sleepData.map(item => item.date),
                datasets: [{
                    label: 'Uyku (Saat)',
                    data: sleepData.map(item => item.duration/60),
                    borderColor: '#6f42c1',
                    tension: 0.1
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        display: false
                    }
                }
            }
        });
    </script>

<?php require_once '../../includes/footer.php'; ?>