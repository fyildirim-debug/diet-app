<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Giriş kontrolü
requireLogin();

$error = '';
$success = '';
$date = $_GET['date'] ?? date('Y-m-d');

try {
    $db = new Database();
    $conn = $db->getConnection();

    // Kullanıcı hedeflerini çek
    $stmt = $conn->prepare("
        SELECT daily_calorie_limit, daily_protein_limit, daily_carb_limit, daily_fat_limit 
        FROM user_profiles 
        WHERE user_id = ?
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $userLimits = $stmt->fetch(PDO::FETCH_ASSOC);

    // Günlük besin değerlerini çek
    $stmt = $conn->prepare("
        SELECT 
            ml.meal_type,
            f.name as food_name,
            ml.serving_amount,
            f.serving_type,
            f.calories * ml.serving_amount as total_calories,
            f.protein * ml.serving_amount as total_protein,
            f.carbs * ml.serving_amount as total_carbs,
            f.fat * ml.serving_amount as total_fat,
            f.fiber * ml.serving_amount as total_fiber
        FROM meal_logs ml
        JOIN foods f ON f.id = ml.food_id
        WHERE ml.user_id = ? AND DATE(ml.date) = ?
        ORDER BY ml.time ASC
    ");
    $stmt->execute([$_SESSION['user_id'], $date]);
    $meals = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Öğün bazında toplamları hesapla
    $mealTotals = [];
    $dailyTotals = [
        'calories' => 0,
        'protein' => 0,
        'carbs' => 0,
        'fat' => 0,
        'fiber' => 0
    ];

    foreach ($meals as $meal) {
        if (!isset($mealTotals[$meal['meal_type']])) {
            $mealTotals[$meal['meal_type']] = [
                'calories' => 0,
                'protein' => 0,
                'carbs' => 0,
                'fat' => 0,
                'fiber' => 0
            ];
        }

        $mealTotals[$meal['meal_type']]['calories'] += $meal['total_calories'];
        $mealTotals[$meal['meal_type']]['protein'] += $meal['total_protein'];
        $mealTotals[$meal['meal_type']]['carbs'] += $meal['total_carbs'];
        $mealTotals[$meal['meal_type']]['fat'] += $meal['total_fat'];
        $mealTotals[$meal['meal_type']]['fiber'] += $meal['total_fiber'];

        $dailyTotals['calories'] += $meal['total_calories'];
        $dailyTotals['protein'] += $meal['total_protein'];
        $dailyTotals['carbs'] += $meal['total_carbs'];
        $dailyTotals['fat'] += $meal['total_fat'];
        $dailyTotals['fiber'] += $meal['total_fiber'];
    }

    // Son 7 günlük besin değerleri
    $stmt = $conn->prepare("
        SELECT 
            DATE(ml.date) as date,
            SUM(f.calories * ml.serving_amount) as total_calories,
            SUM(f.protein * ml.serving_amount) as total_protein,
            SUM(f.carbs * ml.serving_amount) as total_carbs,
            SUM(f.fat * ml.serving_amount) as total_fat
        FROM meal_logs ml
        JOIN foods f ON f.id = ml.food_id
        WHERE ml.user_id = ? 
        AND DATE(ml.date) >= DATE_SUB(?, INTERVAL 7 DAY)
        GROUP BY DATE(ml.date)
        ORDER BY date ASC
    ");
    $stmt->execute([$_SESSION['user_id'], $date]);
    $weeklyData = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Nutrition Report Error: " . $e->getMessage());
    $error = 'Bir hata oluştu, lütfen tekrar deneyin.';
}

require_once '../../includes/header.php';
?>

    <div class="container py-4">
        <?php if($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle me-2"></i><?= $error ?>
            </div>
        <?php endif; ?>

        <!-- Tarih Seçici -->
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <form class="d-flex gap-2">
                    <input type="date"
                           name="date"
                           class="form-control"
                           value="<?= $date ?>"
                           max="<?= date('Y-m-d') ?>"
                           onchange="this.form.submit()">
                    <button type="button"
                            class="btn btn-outline-primary"
                            onclick="location.href='?date=<?= date('Y-m-d') ?>'">
                        Bugün
                    </button>
                </form>
            </div>
        </div>

        <div class="row">
            <!-- Sol Kolon - Günlük Özet -->
            <div class="col-md-4 mb-4">
                <!-- Makro Besinler -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-chart-pie text-primary"></i> Makro Besinler
                        </h5>
                    </div>
                    <div class="card-body">
                        <!-- Kalori -->
                        <div class="mb-4">
                            <div class="d-flex justify-content-between mb-2">
                                <span>Kalori</span>
                                <span>
                                <?= number_format($dailyTotals['calories']) ?> /
                                <?= number_format($userLimits['daily_calorie_limit']) ?> kcal
                            </span>
                            </div>
                            <div class="progress" style="height: 10px;">
                                <?php
                                $caloriePercentage = min(($dailyTotals['calories'] / $userLimits['daily_calorie_limit']) * 100, 100);
                                ?>
                                <div class="progress-bar <?= $caloriePercentage > 100 ? 'bg-danger' : '' ?>"
                                     style="width: <?= $caloriePercentage ?>%">
                                </div>
                            </div>
                        </div>

                        <!-- Protein -->
                        <div class="mb-4">
                            <div class="d-flex justify-content-between mb-2">
                                <span>Protein</span>
                                <span><?= number_format($dailyTotals['protein'], 1) ?>g</span>
                            </div>
                            <div class="progress" style="height: 10px;">
                                <div class="progress-bar bg-success" style="width: <?= ($dailyTotals['protein'] / 150) * 100 ?>%"></div>
                            </div>
                        </div>

                        <!-- Karbonhidrat -->
                        <div class="mb-4">
                            <div class="d-flex justify-content-between mb-2">
                                <span>Karbonhidrat</span>
                                <span><?= number_format($dailyTotals['carbs'], 1) ?>g</span>
                            </div>
                            <div class="progress" style="height: 10px;">
                                <div class="progress-bar bg-warning" style="width: <?= ($dailyTotals['carbs'] / 300) * 100 ?>%"></div>
                            </div>
                        </div>

                        <!-- Yağ -->
                        <div class="mb-4">
                            <div class="d-flex justify-content-between mb-2">
                                <span>Yağ</span>
                                <span><?= number_format($dailyTotals['fat'], 1) ?>g</span>
                            </div>
                            <div class="progress" style="height: 10px;">
                                <div class="progress-bar bg-danger" style="width: <?= ($dailyTotals['fat'] / 65) * 100 ?>%"></div>
                            </div>
                        </div>

                        <!-- Lif -->
                        <div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Lif</span>
                                <span><?= number_format($dailyTotals['fiber'], 1) ?>g</span>
                            </div>
                            <div class="progress" style="height: 10px;">
                                <div class="progress-bar bg-info" style="width: <?= ($dailyTotals['fiber'] / 25) * 100 ?>%"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Makro Dağılımı -->
                <div class="card shadow-sm">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-chart-pie text-primary"></i> Makro Dağılımı
                        </h5>
                    </div>
                    <div class="card-body">
                        <canvas id="macroChart" height="200"></canvas>
                    </div>
                </div>
            </div>

            <!-- Sağ Kolon - Öğün Detayları -->
            <div class="col-md-8">
                <!-- Haftalık Grafik -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-chart-line text-primary"></i> Haftalık Takip
                        </h5>
                    </div>
                    <div class="card-body">
                        <canvas id="weeklyChart" height="200"></canvas>
                    </div>
                </div>

                <!-- Öğün Detayları -->
                <?php
                $mealTypes = [
                    'breakfast' => ['Kahvaltı', 'sun-rise'],
                    'morning_snack' => ['Kuşluk', 'coffee'],
                    'lunch' => ['Öğle Yemeği', 'utensils'],
                    'afternoon_snack' => ['İkindi', 'apple-alt'],
                    'dinner' => ['Akşam Yemeği', 'moon'],
                    'evening_snack' => ['Gece Atıştırması', 'cookie']
                ];

                foreach($mealTypes as $type => $info):
                    $mealItems = array_filter($meals, function($meal) use ($type) {
                        return $meal['meal_type'] == $type;
                    });

                    if(!empty($mealItems)):
                        ?>
                        <div class="card shadow-sm mb-4">
                            <div class="card-header bg-light">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5 class="card-title mb-0">
                                        <i class="fas fa-<?= $info[1] ?> text-primary"></i> <?= $info[0] ?>
                                    </h5>
                                    <span class="badge bg-primary">
                                <?= number_format($mealTotals[$type]['calories']) ?> kcal
                            </span>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                        <tr>
                                            <th>Besin</th>
                                            <th>Miktar</th>
                                            <th>Kalori</th>
                                            <th>Protein</th>
                                            <th>Karb</th>
                                            <th>Yağ</th>
                                            <th>Lif</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach($mealItems as $item): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($item['food_name']) ?></td>
                                                <td><?= $item['serving_amount'] . ' ' . $item['serving_type'] ?></td>
                                                <td><?= number_format($item['total_calories'], 1) ?></td>
                                                <td><?= number_format($item['total_protein'], 1) ?></td>
                                                <td><?= number_format($item['total_carbs'], 1) ?></td>
                                                <td><?= number_format($item['total_fat'], 1) ?></td>
                                                <td><?= number_format($item['total_fiber'], 1) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    <?php
                    endif;
                endforeach;
                ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Makro dağılımı grafiği
        new Chart(document.getElementById('macroChart'), {
            type: 'doughnut',
            data: {
                labels: ['Protein', 'Karbonhidrat', 'Yağ'],
                datasets: [{
                    data: [
                        <?= $dailyTotals['protein'] * 4 ?>,
                        <?= $dailyTotals['carbs'] * 4 ?>,
                        <?= $dailyTotals['fat'] * 9 ?>
                    ],
                    backgroundColor: ['#1cc88a', '#f6c23e', '#e74a3b']
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });

        // Haftalık takip grafiği
        const weeklyData = <?= json_encode($weeklyData) ?>;
        new Chart(document.getElementById('weeklyChart'), {
            type: 'line',
            data: {
                labels: weeklyData.map(item => new Date(item.date).toLocaleDateString('tr-TR', {weekday: 'short'})),
                datasets: [
                    {
                        label: 'Kalori',
                        data: weeklyData.map(item => item.total_calories),
                        borderColor: '#4e73df',
                        tension: 0.1
                    },
                    {
                        label: 'Protein',
                        data: weeklyData.map(item => item.total_protein),
                        borderColor: '#1cc88a',
                        tension: 0.1
                    },
                    {
                        label: 'Karbonhidrat',
                        data: weeklyData.map(item => item.total_carbs),
                        borderColor: '#f6c23e',
                        tension: 0.1
                    },
                    {
                        label: 'Yağ',
                        data: weeklyData.map(item => item.total_fat),
                        borderColor: '#e74a3b',
                        tension: 0.1
                    }
                ]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
    </script>

<?php require_once '../../includes/footer.php'; ?>