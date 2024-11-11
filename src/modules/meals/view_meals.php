<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Giriş kontrolü
requireLogin();

$error = '';
$success = '';
$date = $_GET['date'] ?? date('Y-m-d');
$meals = [];
$dailyTotals = [
    'calories' => 0,
    'protein' => 0,
    'carbs' => 0,
    'fat' => 0,
    'fiber' => 0
];

try {
    $db = new Database();
    $conn = $db->getConnection();

    // Kullanıcının günlük limitlerini çek
    $stmt = $conn->prepare("
        SELECT daily_calorie_limit, daily_protein_limit, daily_carb_limit, daily_fat_limit 
        FROM user_profiles 
        WHERE user_id = ?
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $userLimits = $stmt->fetch(PDO::FETCH_ASSOC);

    // Seçili tarihteki öğünleri çek
    $stmt = $conn->prepare("
        SELECT 
            ml.id,
            ml.meal_type,
            ml.serving_amount,
            ml.time,
            f.name as food_name,
            f.calories,
            f.protein,
            f.carbs,
            f.fat,
            f.fiber,
            f.serving_type
        FROM meal_logs ml
        JOIN foods f ON f.id = ml.food_id
        WHERE ml.user_id = ? AND DATE(ml.date) = ?
        ORDER BY ml.time ASC
    ");
    $stmt->execute([$_SESSION['user_id'], $date]);
    $allMeals = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Öğünleri kategorize et
    $mealTypes = [
        'breakfast' => 'Kahvaltı',
        'morning_snack' => 'Kuşluk',
        'lunch' => 'Öğle Yemeği',
        'afternoon_snack' => 'İkindi',
        'dinner' => 'Akşam Yemeği',
        'evening_snack' => 'Gece Atıştırması'
    ];

    foreach ($mealTypes as $type => $label) {
        $meals[$type] = [
            'label' => $label,
            'items' => [],
            'totals' => [
                'calories' => 0,
                'protein' => 0,
                'carbs' => 0,
                'fat' => 0,
                'fiber' => 0
            ]
        ];
    }

    // Öğünleri ve toplam değerleri hesapla
    foreach ($allMeals as $meal) {
        $multiplier = $meal['serving_amount'];

        $mealData = [
            'id' => $meal['id'],
            'name' => $meal['food_name'],
            'amount' => $meal['serving_amount'],
            'unit' => $meal['serving_type'],
            'time' => $meal['time'],
            'calories' => $meal['calories'] * $multiplier,
            'protein' => $meal['protein'] * $multiplier,
            'carbs' => $meal['carbs'] * $multiplier,
            'fat' => $meal['fat'] * $multiplier,
            'fiber' => $meal['fiber'] * $multiplier
        ];

        $meals[$meal['meal_type']]['items'][] = $mealData;

        // Öğün toplamlarını güncelle
        $meals[$meal['meal_type']]['totals']['calories'] += $mealData['calories'];
        $meals[$meal['meal_type']]['totals']['protein'] += $mealData['protein'];
        $meals[$meal['meal_type']]['totals']['carbs'] += $mealData['carbs'];
        $meals[$meal['meal_type']]['totals']['fat'] += $mealData['fat'];
        $meals[$meal['meal_type']]['totals']['fiber'] += $mealData['fiber'];

        // Günlük toplamları güncelle
        $dailyTotals['calories'] += $mealData['calories'];
        $dailyTotals['protein'] += $mealData['protein'];
        $dailyTotals['carbs'] += $mealData['carbs'];
        $dailyTotals['fat'] += $mealData['fat'];
        $dailyTotals['fiber'] += $mealData['fiber'];
    }

    // Öğün silme işlemi
    if(isset($_POST['delete_meal'])) {
        $meal_id = clean($_POST['meal_id']);
        $stmt = $conn->prepare("DELETE FROM meal_logs WHERE id = ? AND user_id = ?");
        $stmt->execute([$meal_id, $_SESSION['user_id']]);
        $success = 'Öğün başarıyla silindi.';
        header("Location: view_meals.php?date=" . $date);
        exit;
    }

} catch (PDOException $e) {
    error_log("Meal View Error: " . $e->getMessage());
    $error = 'Bir hata oluştu, lütfen tekrar deneyin.';
}

require_once '../../includes/header.php';
?>

    <div class="container py-4">
        <!-- Tarih Seçici ve Özet -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card shadow-sm">
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
            </div>
            <div class="col-md-6">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <h6 class="mb-0">Günlük Kalori</h6>
                            <span class="badge bg-<?= $dailyTotals['calories'] > $userLimits['daily_calorie_limit'] ? 'danger' : 'success' ?>">
                            <?= number_format($dailyTotals['calories']) ?> / <?= number_format($userLimits['daily_calorie_limit']) ?> kcal
                        </span>
                        </div>
                        <div class="progress mt-2" style="height: 5px;">
                            <?php $percentage = min(($dailyTotals['calories'] / $userLimits['daily_calorie_limit']) * 100, 100); ?>
                            <div class="progress-bar <?= $percentage > 100 ? 'bg-danger' : '' ?>"
                                 style="width: <?= $percentage ?>%">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Besin Değerleri Özeti -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <small class="text-secondary">Protein</small>
                                <h5 class="mb-0"><?= number_format($dailyTotals['protein'], 1) ?>g</h5>
                            </div>
                            <div class="icon-box bg-primary-light text-primary">
                                <i class="fas fa-drumstick-bite"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <small class="text-secondary">Karbonhidrat</small>
                                <h5 class="mb-0"><?= number_format($dailyTotals['carbs'], 1) ?>g</h5>
                            </div>
                            <div class="icon-box bg-warning-light text-warning">
                                <i class="fas fa-bread-slice"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <small class="text-secondary">Yağ</small>
                                <h5 class="mb-0"><?= number_format($dailyTotals['fat'], 1) ?>g</h5>
                            </div>
                            <div class="icon-box bg-danger-light text-danger">
                                <i class="fas fa-oil-can"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <small class="text-secondary">Lif</small>
                                <h5 class="mb-0"><?= number_format($dailyTotals['fiber'], 1) ?>g</h5>
                            </div>
                            <div class="icon-box bg-success-light text-success">
                                <i class="fas fa-seedling"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Öğünler -->
        <div class="row">
            <div class="col-12">
                <?php foreach($meals as $type => $mealData): ?>
                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-light">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-utensils text-primary me-2"></i>
                                    <?= $mealData['label'] ?>
                                </h5>
                                <span class="badge bg-primary">
                                <?= number_format($mealData['totals']['calories']) ?> kcal
                            </span>
                            </div>
                        </div>
                        <?php if(!empty($mealData['items'])): ?>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead class="table-light">
                                        <tr>
                                            <th>Saat</th>
                                            <th>Yemek</th>
                                            <th>Miktar</th>
                                            <th>Kalori</th>
                                            <th>Protein</th>
                                            <th>Karb</th>
                                            <th>Yağ</th>
                                            <th>Lif</th>
                                            <th></th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach($mealData['items'] as $item): ?>
                                            <tr>
                                                <td><?= date('H:i', strtotime($item['time'])) ?></td>
                                                <td><?= htmlspecialchars($item['name']) ?></td>
                                                <td><?= $item['amount'] . ' ' . $item['unit'] ?></td>
                                                <td><?= number_format($item['calories'], 1) ?></td>
                                                <td><?= number_format($item['protein'], 1) ?></td>
                                                <td><?= number_format($item['carbs'], 1) ?></td>
                                                <td><?= number_format($item['fat'], 1) ?></td>
                                                <td><?= number_format($item['fiber'], 1) ?></td>
                                                <td>
                                                    <form method="POST" class="d-inline"
                                                          onsubmit="return confirm('Bu öğünü silmek istediğinizden emin misiniz?')">
                                                        <input type="hidden" name="meal_id" value="<?= $item['id'] ?>">
                                                        <button type="submit"
                                                                name="delete_meal"
                                                                class="btn btn-sm btn-outline-danger">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <!-- Öğün Toplamları -->
                                        <tr class="table-light">
                                            <td colspan="3"><strong>Toplam</strong></td>
                                            <td><strong><?= number_format($mealData['totals']['calories'], 1) ?></strong></td>
                                            <td><strong><?= number_format($mealData['totals']['protein'], 1) ?></strong></td>
                                            <td><strong><?= number_format($mealData['totals']['carbs'], 1) ?></strong></td>
                                            <td><strong><?= number_format($mealData['totals']['fat'], 1) ?></strong></td>
                                            <td><strong><?= number_format($mealData['totals']['fiber'], 1) ?></strong></td>
                                            <td></td>
                                        </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="card-body text-center text-muted">
                                <p class="mb-0">Bu öğünde henüz yemek eklenmemiş.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Hızlı Eylemler -->
        <div class="position-fixed bottom-0 end-0 p-3" style="z-index: 1000;">
            <a href="add_meal.php" class="btn btn-primary btn-lg rounded-circle shadow">
                <i class="fas fa-plus"></i>
            </a>
        </div>
    </div>

<?php require_once '../../includes/footer.php'; ?>