<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Giriş kontrolü
requireLogin();

$error = '';
$success = '';
$month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
$monthStart = $month . '-01';
$monthEnd = date('Y-m-t', strtotime($monthStart));
$selectedDate = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$plannedMeals = []; // Varsayılan değer atandı

try {
    $db = new Database();
    $conn = $db->getConnection();

    // Kullanıcı profilini çek
    $stmt = $conn->prepare("SELECT * FROM user_profiles WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $userProfile = $stmt->fetch(PDO::FETCH_ASSOC);

    // Aktif diyet programını çek
    $stmt = $conn->prepare("
        SELECT * FROM diet_programs 
        WHERE user_id = ? 
        AND (
            (start_date <= ? AND end_date >= ?) OR
            (start_date <= ? AND end_date >= ?)
        )
        AND program_type IN ('monthly', 'weekly')
        ORDER BY created_at DESC 
        LIMIT 1
    ");
    $stmt->execute([
        $_SESSION['user_id'],
        $monthStart, $monthStart,
        $monthEnd, $monthEnd
    ]);
    $currentPlan = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($currentPlan) {
        $planData = json_decode($currentPlan['program_data'], true);
        $selectedDayMenu = getDietPlanForDate(
            $planData,
            $selectedDate,
            $currentPlan['start_date']
        );
        $plannedMeals = getPlannedMealsForDate($selectedDayMenu);
    }

    // Günlük tüketimleri çek
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
    $stmt->execute([$_SESSION['user_id'], $monthStart, $monthEnd]);
    $consumedMeals = $stmt->fetchAll(PDO::FETCH_GROUP);

    // Seçili gün için detaylı verileri çek
    $stmt = $conn->prepare("
        SELECT 
            ml.meal_type,
            ml.serving_amount,
            f.name as food_name,
            f.calories,
            f.protein,
            f.carbs,
            f.fat,
            f.serving_type,
            ml.time
        FROM meal_logs ml
        JOIN foods f ON f.id = ml.food_id
        WHERE ml.user_id = ? AND DATE(ml.date) = ?
        ORDER BY ml.time ASC
    ");
    $stmt->execute([$_SESSION['user_id'], $selectedDate]);
    $dayDetails = $stmt->fetchAll(PDO::FETCH_GROUP);

} catch (PDOException $e) {
    error_log("Monthly Plan Error: " . $e->getMessage());
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

        <div class="row">
            <!-- Sol Kolon - Takvim -->
            <div class="col-lg-7">
                <!-- Ay Seçici -->
                <div class="card shadow-sm mb-3">
                    <div class="card-body py-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <button onclick="changeMonth('prev')" class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-chevron-left"></i>
                            </button>

                            <div class="text-center">
                                <h5 class="mb-0">
                                    <?= getTurkishMonth($monthStart) . ' ' . date('Y', strtotime($monthStart)) ?>
                                </h5>
                            </div>

                            <button onclick="changeMonth('next')" class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-chevron-right"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Takvim -->
                <div class="card shadow-sm">
                    <div class="card-body p-2">
                        <div class="table-responsive">
                            <table class="table table-bordered mb-0">
                                <thead class="table-light">
                                <tr>
                                    <th class="text-center" style="width: 14.28%">Pzt</th>
                                    <th class="text-center" style="width: 14.28%">Sal</th>
                                    <th class="text-center" style="width: 14.28%">Çar</th>
                                    <th class="text-center" style="width: 14.28%">Per</th>
                                    <th class="text-center" style="width: 14.28%">Cum</th>
                                    <th class="text-center" style="width: 14.28%">Cmt</th>
                                    <th class="text-center" style="width: 14.28%">Paz</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php
                                $firstDay = date('N', strtotime($monthStart)) - 1;
                                $totalDays = date('t', strtotime($monthStart));
                                $currentDay = 1;
                                $weeks = ceil(($totalDays + $firstDay) / 7);

                                for ($week = 0; $week < $weeks; $week++):
                                    ?>
                                    <tr>
                                        <?php for ($day = 0; $day < 7; $day++): ?>
                                            <?php if (($week == 0 && $day < $firstDay) || ($currentDay > $totalDays)): ?>
                                                <td class="bg-light" style="height: 90px;"></td>
                                            <?php else:
                                                $currentDate = sprintf('%s-%02d', $month, $currentDay);
                                                $isToday = $currentDate == date('Y-m-d');
                                                $isSelected = $currentDate == $selectedDate;
                                                $dayMeals = $consumedMeals[$currentDate] ?? [];
                                                $totalCalories = array_sum(array_column($dayMeals, 'total_calories'));

                                                // Planlanan menüyü al
                                                $plannedMenu = null;
                                                $hasPlan = false;
                                                if ($currentPlan) {
                                                    $plannedMenu = getDietPlanForDate(
                                                        $planData,
                                                        $currentDate,
                                                        $currentPlan['start_date']
                                                    );
                                                    $hasPlan = !empty($plannedMenu);
                                                }

                                                $plannedCalories = $plannedMenu ? array_sum(array_column($plannedMenu, 'calories')) : $userProfile['daily_calorie_limit'];

                                                // CSS sınıflarını belirle
                                                $tdClass = [];
                                                if ($isSelected) $tdClass[] = 'table-primary';
                                                elseif ($isToday) $tdClass[] = 'table-warning';
                                                elseif ($hasPlan) $tdClass[] = 'table-info';
                                                ?>
                                                <td class="position-relative <?= implode(' ', $tdClass) ?>"
                                                    style="height: 90px; cursor: pointer;"
                                                    onclick="selectDate('<?= $currentDate ?>')">

                                                    <!-- Gün Numarası -->
                                                    <div class="position-absolute top-0 start-0 p-1">
                                                        <span class="badge <?= $isToday ? 'bg-warning' : 'bg-secondary' ?>">
                                                            <?= $currentDay ?>
                                                        </span>
                                                    </div>

                                                    <!-- Kalori Bilgisi -->
                                                    <?php if ($currentDate <= date('Y-m-d')): ?>
                                                        <div class="text-center mt-3">
                                                            <small class="d-block text-muted" style="font-size: 0.8rem;">
                                                                <?= number_format($totalCalories) ?> /
                                                                <?= number_format($plannedCalories) ?>
                                                            </small>

                                                            <!-- İlerleme Çubuğu -->
                                                            <div class="progress mt-1" style="height: 3px;">
                                                                <?php
                                                                $percentage = $plannedCalories > 0 ?
                                                                    min(($totalCalories / $plannedCalories) * 100, 100) : 0;
                                                                ?>
                                                                <div class="progress-bar <?= $percentage > 100 ? 'bg-danger' : 'bg-success' ?>"
                                                                     style="width: <?= $percentage ?>%">
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <?php
                                                $currentDay++;
                                            endif;
                                            ?>
                                        <?php endfor; ?>
                                    </tr>
                                <?php endfor; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sağ Kolon - Seçili Gün Detayları -->
            <div class="col-lg-5">
                <div class="card shadow-sm sticky-top" style="top: 1rem;">
                    <div class="card-header bg-primary text-white py-2">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-calendar-day"></i>
                            <?= date('d', strtotime($selectedDate)) . ' ' . getTurkishMonth($selectedDate) ?>
                        </h5>
                    </div>
                    <div class="card-body">
                        <!-- Planlanan Menü -->
                        <?php if (!empty($plannedMeals)): ?>
                            <div class="mb-4">
                                <h6 class="border-bottom pb-2 text-primary">
                                    <i class="fas fa-clipboard-list"></i> Planlanan Menü
                                </h6>
                                <?php foreach ($plannedMeals as $type => $meal): ?>
                                    <div class="mb-2">
                                        <small class="text-muted"><?= $meal['label'] ?></small>
                                        <div class="d-flex justify-content-between">
                                            <span><?= $meal['meal'] ?></span>
                                            <span class="badge bg-primary"><?= $meal['calories'] ?> kcal</span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Tüketilen Öğünler -->
                        <div class="mb-4">
                            <h6 class="border-bottom pb-2 text-success">
                                <i class="fas fa-utensils"></i> Tüketilen Öğünler
                            </h6>
                            <?php if (empty($dayDetails)): ?>
                                <p class="text-muted small mb-0">Bu tarihte kayıtlı öğün bulunmuyor.</p>
                            <?php else: ?>
                                <?php foreach ($dayDetails as $mealType => $meals): ?>
                                    <div class="mb-3">
                                        <small class="text-muted"><?= getMealTypeLabel($mealType) ?></small>
                                        <?php foreach ($meals as $meal): ?>
                                            <div class="d-flex justify-content-between align-items-center">
                                            <span class="small">
                                                <?= htmlspecialchars($meal['food_name']) ?>
                                                <small class="text-muted">
                                                    (<?= $meal['serving_amount'] . ' ' . $meal['serving_type'] ?>)
                                                </small>
                                            </span>
                                                <span class="badge bg-success">
                                                <?= number_format($meal['calories'] * $meal['serving_amount']) ?>
                                            </span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endforeach; ?>

                                <!-- Günlük Özet -->
                                <?php
                                $dailyTotals = [
                                    'calories' => 0,
                                    'protein' => 0,
                                    'carbs' => 0,
                                    'fat' => 0
                                ];

                                foreach ($dayDetails as $meals) {
                                    foreach ($meals as $meal) {
                                        $dailyTotals['calories'] += $meal['calories'] * $meal['serving_amount'];
                                        $dailyTotals['protein'] += $meal['protein'] * $meal['serving_amount'];
                                        $dailyTotals['carbs'] += $meal['carbs'] * $meal['serving_amount'];
                                        $dailyTotals['fat'] += $meal['fat'] * $meal['serving_amount'];
                                    }
                                }
                                ?>
                                <div class="card bg-light mt-3">
                                    <div class="card-body p-2">
                                        <h6 class="card-title mb-2">Günlük Toplam</h6>
                                        <div class="row g-2">
                                            <div class="col-6">
                                                <small class="text-muted d-block">Kalori</small>
                                                <strong><?= number_format($dailyTotals['calories']) ?> kcal</strong>
                                            </div>
                                            <div class="col-6">
                                                <small class="text-muted d-block">Protein</small>
                                                <strong><?= number_format($dailyTotals['protein'], 1) ?>g</strong>
                                            </div>
                                            <div class="col-6">
                                                <small class="text-muted d-block">Karbonhidrat</small>
                                                <strong><?= number_format($dailyTotals['carbs'], 1) ?>g</strong>
                                            </div>
                                            <div class="col-6">
                                                <small class="text-muted d-block">Yağ</small>
                                                <strong><?= number_format($dailyTotals['fat'], 1) ?>g</strong>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if ($selectedDate <= date('Y-m-d')): ?>
                                <div class="text-center mt-3">
                                    <a href="../meals/add_meal.php?date=<?= $selectedDate ?>" class="btn btn-sm btn-primary">
                                        <i class="fas fa-plus"></i> Öğün Ekle
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function changeMonth(direction) {
            const currentMonth = '<?= $month ?>';
            const [year, month] = currentMonth.split('-');
            const date = new Date(year, parseInt(month) - 1);

            if(direction === 'prev') {
                date.setMonth(date.getMonth() - 1);
            } else {
                date.setMonth(date.getMonth() + 1);
            }

            const newMonth = date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0');
            window.location.href = `?month=${newMonth}&date=<?= $selectedDate ?>`;
        }

        function selectDate(date) {
            window.location.href = `?month=<?= $month ?>&date=${date}`;
        }
    </script>

<?php require_once '../../includes/footer.php'; ?>