<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Giriş kontrolü
requireLogin();

$error = '';
$success = '';
$weekStart = isset($_GET['week']) ? $_GET['week'] : date('Y-m-d', strtotime('monday this week'));
$weekEnd = date('Y-m-d', strtotime($weekStart . ' +6 days'));

try {
    $db = new Database();
    $conn = $db->getConnection();

    // Aktif diyet programını çek
    $stmt = $conn->prepare("
        SELECT * FROM diet_programs 
        WHERE user_id = ? 
        AND ? BETWEEN start_date AND end_date 
        AND program_type IN ('weekly', 'monthly')
        ORDER BY created_at DESC 
        LIMIT 1
    ");
    $stmt->execute([$_SESSION['user_id'], $weekStart]);
    $currentPlan = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($currentPlan) {
        $planData = json_decode($currentPlan['program_data'], true);

        // Haftalık program için günleri hesapla
        $startDate = new DateTime($currentPlan['start_date']);
        $currentWeekStart = new DateTime($weekStart);
        $dayDiff = $startDate->diff($currentWeekStart)->days;
        $totalDays = count($planData['meals']);
        $weekIndex = ($dayDiff % $totalDays) % 7;

        // Haftalık menüyü oluştur
        $weeklyMenu = array_slice($planData['meals'], $weekIndex * 7, 7);
    }

    // Haftalık tüketim verilerini çek
    $stmt = $conn->prepare("
        SELECT 
            DATE(ml.date) as date,
            ml.meal_type,
            SUM(f.calories * ml.serving_amount) as total_calories,
            SUM(f.protein * ml.serving_amount) as total_protein,
            SUM(f.carbs * ml.serving_amount) as total_carbs,
            SUM(f.fat * ml.serving_amount) as total_fat
        FROM meal_logs ml
        JOIN foods f ON f.id = ml.food_id
        WHERE ml.user_id = ? 
        AND DATE(ml.date) BETWEEN ? AND ?
        GROUP BY DATE(ml.date), ml.meal_type
    ");
    $stmt->execute([$_SESSION['user_id'], $weekStart, $weekEnd]);
    $consumedMeals = $stmt->fetchAll(PDO::FETCH_GROUP);

    // Haftalık toplam ve ortalamalar
    $weeklyTotals = [
        'calories' => 0,
        'protein' => 0,
        'carbs' => 0,
        'fat' => 0
    ];

    foreach ($consumedMeals as $date => $meals) {
        foreach ($meals as $meal) {
            $weeklyTotals['calories'] += $meal['total_calories'];
            $weeklyTotals['protein'] += $meal['total_protein'];
            $weeklyTotals['carbs'] += $meal['total_carbs'];
            $weeklyTotals['fat'] += $meal['total_fat'];
        }
    }

    $dailyAverages = array_map(function($total) {
        return $total / 7;
    }, $weeklyTotals);

} catch (PDOException $e) {
    error_log("Weekly Plan Error: " . $e->getMessage());
    $error = 'Bir hata oluştu, lütfen tekrar deneyin.';
}

require_once '../../includes/header.php';
?>

    <style>
        .custom-container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 0 15px;
        }
        .card {
            margin-bottom: 0.75rem;
        }
        .card-body {
            padding: 1rem;
        }
        .card-header {
            padding: 0.75rem 1rem;
        }
        .meal-item {
            padding: 0.75rem;
            margin-bottom: 0.5rem;
            border-radius: 4px;
            background-color: rgba(0,0,0,.01);
        }
        .meal-item:last-child {
            margin-bottom: 0;
        }
        .icon-box {
            width: 28px;
            height: 28px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
        }
        .progress {
            height: 4px;
        }
        .small-text {
            font-size: 0.9rem;
        }
        .meal-title {
            font-size: 0.95rem;
        }
        .sidebar {
            width: 240px;
        }
        .main-content {
            width: calc(100% - 260px);
        }
        @media (max-width: 992px) {
            .sidebar, .main-content {
                width: 100%;
            }
        }
        .btn-xs {
            padding: 0.25rem 0.5rem;
            font-size: 0.875rem;
        }
        .card-title {
            font-size: 1rem;
            margin-bottom: 0;
        }
        .badge {
            font-size: 0.875rem;
        }
        .text-muted {
            font-size: 0.9rem;
        }
        .progress + .small-text {
            margin-top: 0.25rem;
        }
        .meal-item .d-flex {
            gap: 0.5rem;
        }
    </style>

    <div class="custom-container py-2">
        <div class="d-flex flex-wrap gap-3">
            <!-- Sol Sidebar - Haftalık Özet -->
            <div class="sidebar">
                <div class="position-sticky" style="top: 1rem;">
                    <!-- Hafta Seçici -->
                    <div class="card shadow-sm">
                        <div class="card-body p-2">
                            <div class="d-flex justify-content-between align-items-center">
                                <button onclick="changeWeek('prev')" class="btn btn-outline-primary btn-xs">
                                    <i class="fas fa-chevron-left"></i>
                                </button>

                                <div class="text-center">
                                    <div class="small-text fw-bold">
                                        <?= date('d', strtotime($weekStart)) ?> -
                                        <?= date('d F Y', strtotime($weekEnd)) ?>
                                    </div>
                                    <div class="small-text text-muted">Hafta <?= date('W', strtotime($weekStart)) ?></div>
                                </div>

                                <button onclick="changeWeek('next')" class="btn btn-outline-primary btn-xs">
                                    <i class="fas fa-chevron-right"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Haftalık İstatistikler -->
                    <div class="card shadow-sm">
                        <div class="card-header bg-light py-2">
                            <div class="small-text fw-bold mb-0">
                                <i class="fas fa-chart-pie text-primary"></i> Haftalık Özet
                            </div>
                        </div>
                        <div class="card-body">
                            <!-- Kalori -->
                            <div class="mb-2">
                                <div class="d-flex justify-content-between mb-1">
                                    <span class="small-text text-muted">Toplam Kalori</span>
                                    <span class="small-text fw-bold"><?= number_format($weeklyTotals['calories']) ?></span>
                                </div>
                                <div class="progress mb-1">
                                    <div class="progress-bar bg-primary" style="width: 100%"></div>
                                </div>
                                <div class="small-text text-muted">
                                    Günlük Ort: <?= number_format($dailyAverages['calories'], 1) ?>
                                </div>
                            </div>

                            <!-- Protein -->
                            <div class="mb-2">
                                <div class="d-flex justify-content-between mb-1">
                                    <span class="small-text text-muted">Protein</span>
                                    <span class="small-text fw-bold"><?= number_format($weeklyTotals['protein'], 1) ?>g</span>
                                </div>
                                <div class="progress mb-1">
                                    <div class="progress-bar bg-success" style="width: 100%"></div>
                                </div>
                                <div class="small-text text-muted">
                                    Günlük Ort: <?= number_format($dailyAverages['protein'], 1) ?>g
                                </div>
                            </div>

                            <!-- Karbonhidrat -->
                            <div class="mb-2">
                                <div class="d-flex justify-content-between mb-1">
                                    <span class="small-text text-muted">Karbonhidrat</span>
                                    <span class="small-text fw-bold"><?= number_format($weeklyTotals['carbs'], 1) ?>g</span>
                                </div>
                                <div class="progress mb-1">
                                    <div class="progress-bar bg-warning" style="width: 100%"></div>
                                </div>
                                <div class="small-text text-muted">
                                    Günlük Ort: <?= number_format($dailyAverages['carbs'], 1) ?>g
                                </div>
                            </div>

                            <!-- Yağ -->
                            <div>
                                <div class="d-flex justify-content-between mb-1">
                                    <span class="small-text text-muted">Yağ</span>
                                    <span class="small-text fw-bold"><?= number_format($weeklyTotals['fat'], 1) ?>g</span>
                                </div>
                                <div class="progress mb-1">
                                    <div class="progress-bar bg-danger" style="width: 100%"></div>
                                </div>
                                <div class="small-text text-muted">
                                    Günlük Ort: <?= number_format($dailyAverages['fat'], 1) ?>g
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Ana İçerik - Haftalık Plan -->
            <div class="main-content">
                <?php if(!$currentPlan): ?>
                    <div class="card shadow-sm">
                        <div class="card-body text-center py-4">
                            <i class="fas fa-calendar-times fa-3x text-secondary mb-3"></i>
                            <h5>Aktif Plan Bulunamadı</h5>
                            <p class="text-secondary small-text">Yeni bir program oluşturmak için butonu kullanın.</p>
                            <a href="generate_plan.php" class="btn btn-primary btn-sm">
                                <i class="fas fa-magic me-1"></i>Program Oluştur
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Günlük Kartlar -->
                    <div class="row g-2">
                        <?php
                        $days = ['Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi', 'Pazar'];
                        foreach($days as $index => $day):
                            $currentDate = date('Y-m-d', strtotime($weekStart . " +$index days"));
                            $isToday = $currentDate == date('Y-m-d');
                            $dayData = $weeklyMenu[$index] ?? [];
                            $consumed = $consumedMeals[$currentDate] ?? [];
                            ?>
                            <div class="col-md-6">
                                <div class="card shadow-sm h-100 <?= $isToday ? 'border-primary' : '' ?>">
                                    <div class="card-header bg-light py-2">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div class="small-text fw-bold">
                                                <?= $day ?>
                                                <span class="text-muted ms-1"><?= date('d.m', strtotime($currentDate)) ?></span>
                                            </div>
                                            <?php if($isToday): ?>
                                                <span class="badge bg-primary">Bugün</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="card-body">
                                        <?php
                                        $mealTypes = [
                                            'breakfast' => ['Kahvaltı', 'sun-rise', 'bg-warning-light'],
                                            'morning_snack' => ['Kuşluk', 'coffee', 'bg-info-light'],
                                            'lunch' => ['Öğle', 'utensils', 'bg-success-light'],
                                            'afternoon_snack' => ['İkindi', 'apple-alt', 'bg-info-light'],
                                            'dinner' => ['Akşam', 'moon', 'bg-primary-light'],
                                            'evening_snack' => ['Gece', 'cookie', 'bg-secondary-light']
                                        ];

                                        foreach($mealTypes as $type => $info):
                                            $planned = $dayData[$type] ?? ['meal' => '-', 'calories' => 0];
                                            $consumedMeal = array_filter($consumed, function($meal) use ($type) {
                                                return $meal['meal_type'] == $type;
                                            });
                                            $consumedCalories = !empty($consumedMeal) ? current($consumedMeal)['total_calories'] : 0;
                                            ?>
                                            <div class="meal-item">
                                                <div class="d-flex justify-content-between align-items-center mb-1">
                                                    <span class="small-text text-muted"><?= $info[0] ?></span>
                                                    <span class="small-text <?= $consumedCalories > $planned['calories'] ? 'text-danger' : 'text-success' ?>">
                                                    <?= number_format($consumedCalories) ?>/<?= number_format($planned['calories']) ?>
                                                </span>
                                                </div>
                                                <div class="d-flex align-items-center">
                                                    <div class="icon-box <?= $info[2] ?> me-2">
                                                        <i class="fas fa-<?= $info[1] ?>"></i>
                                                    </div>
                                                    <div class="flex-grow-1 meal-title">
                                                        <?= htmlspecialchars($planned['meal']) ?>
                                                    </div>
                                                    <?php if($currentDate <= date('Y-m-d')): ?>
                                                        <a href="daily_plan.php?date=<?= $currentDate ?>"
                                                           class="btn btn-outline-primary btn-xs">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if($isToday): ?>
                                                    <div class="progress mt-1">
                                                        <?php
                                                        $percentage = $planned['calories'] > 0 ?
                                                            min(($consumedCalories / $planned['calories']) * 100, 100) : 0;
                                                        ?>
                                                        <div class="progress-bar <?= $percentage > 100 ? 'bg-danger' : '' ?>"
                                                             style="width: <?= $percentage ?>%">
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        function changeWeek(direction) {
            const currentWeek = '<?= $weekStart ?>';
            const newWeek = new Date(currentWeek);

            if(direction === 'prev') {
                newWeek.setDate(newWeek.getDate() - 7);
            } else {
                newWeek.setDate(newWeek.getDate() + 7);
            }

            const formattedDate = newWeek.toISOString().split('T')[0];
            window.location.href = `?week=${formattedDate}`;
        }
    </script>

<?php require_once '../../includes/footer.php'; ?>