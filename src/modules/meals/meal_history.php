<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Giriş kontrolü
requireLogin();

$error = '';
$success = '';

// Filtreleme parametreleri
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$meal_type = $_GET['meal_type'] ?? 'all';
$sort = $_GET['sort'] ?? 'date_desc';

try {
    $db = new Database();
    $conn = $db->getConnection();

    // Temel sorgu
    $query = "
        SELECT 
            ml.id,
            ml.date,
            ml.time,
            ml.meal_type,
            ml.serving_amount,
            f.name as food_name,
            f.calories,
            f.protein,
            f.carbs,
            f.fat,
            f.serving_type
        FROM meal_logs ml
        JOIN foods f ON f.id = ml.food_id
        WHERE ml.user_id = ? 
        AND DATE(ml.date) BETWEEN ? AND ?
    ";

    // Öğün tipi filtresi
    if ($meal_type != 'all') {
        $query .= " AND ml.meal_type = ?";
    }

    // Sıralama
    switch ($sort) {
        case 'date_asc':
            $query .= " ORDER BY ml.date ASC, ml.time ASC";
            break;
        case 'calories_desc':
            $query .= " ORDER BY (f.calories * ml.serving_amount) DESC";
            break;
        case 'calories_asc':
            $query .= " ORDER BY (f.calories * ml.serving_amount) ASC";
            break;
        default: // date_desc
            $query .= " ORDER BY ml.date DESC, ml.time DESC";
    }

    $stmt = $conn->prepare($query);

    // Parametreleri bind et
    if ($meal_type != 'all') {
        $stmt->execute([$_SESSION['user_id'], $start_date, $end_date, $meal_type]);
    } else {
        $stmt->execute([$_SESSION['user_id'], $start_date, $end_date]);
    }

    $meals = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Toplam değerleri hesapla
    $totals = [
        'calories' => 0,
        'protein' => 0,
        'carbs' => 0,
        'fat' => 0
    ];

    foreach ($meals as $meal) {
        $multiplier = $meal['serving_amount'];
        $totals['calories'] += $meal['calories'] * $multiplier;
        $totals['protein'] += $meal['protein'] * $multiplier;
        $totals['carbs'] += $meal['carbs'] * $multiplier;
        $totals['fat'] += $meal['fat'] * $multiplier;
    }

    // Günlük ortalamalar
    $days = max(1, ceil((strtotime($end_date) - strtotime($start_date)) / (60 * 60 * 24)));
    $averages = array_map(function($value) use ($days) {
        return $value / $days;
    }, $totals);

} catch (PDOException $e) {
    error_log("Meal History Error: " . $e->getMessage());
    $error = 'Bir hata oluştu, lütfen tekrar deneyin.';
}

require_once '../../includes/header.php';
?>

    <div class="container py-4">
        <!-- Filtreler -->
        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Başlangıç Tarihi</label>
                        <input type="date"
                               name="start_date"
                               class="form-control"
                               value="<?= $start_date ?>"
                               max="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Bitiş Tarihi</label>
                        <input type="date"
                               name="end_date"
                               class="form-control"
                               value="<?= $end_date ?>"
                               max="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Öğün Tipi</label>
                        <select name="meal_type" class="form-select">
                            <option value="all" <?= $meal_type == 'all' ? 'selected' : '' ?>>Tümü</option>
                            <option value="breakfast" <?= $meal_type == 'breakfast' ? 'selected' : '' ?>>Kahvaltı</option>
                            <option value="morning_snack" <?= $meal_type == 'morning_snack' ? 'selected' : '' ?>>Kuşluk</option>
                            <option value="lunch" <?= $meal_type == 'lunch' ? 'selected' : '' ?>>Öğle</option>
                            <option value="afternoon_snack" <?= $meal_type == 'afternoon_snack' ? 'selected' : '' ?>>İkindi</option>
                            <option value="dinner" <?= $meal_type == 'dinner' ? 'selected' : '' ?>>Akşam</option>
                            <option value="evening_snack" <?= $meal_type == 'evening_snack' ? 'selected' : '' ?>>Gece</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Sıralama</label>
                        <select name="sort" class="form-select">
                            <option value="date_desc" <?= $sort == 'date_desc' ? 'selected' : '' ?>>Tarih (Yeni-Eski)</option>
                            <option value="date_asc" <?= $sort == 'date_asc' ? 'selected' : '' ?>>Tarih (Eski-Yeni)</option>
                            <option value="calories_desc" <?= $sort == 'calories_desc' ? 'selected' : '' ?>>Kalori (Çok-Az)</option>
                            <option value="calories_asc" <?= $sort == 'calories_asc' ? 'selected' : '' ?>>Kalori (Az-Çok)</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-filter me-2"></i>Filtrele
                        </button>
                        <a href="meal_history.php" class="btn btn-outline-secondary">
                            <i class="fas fa-undo me-2"></i>Sıfırla
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Özet Kartları -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <small class="text-secondary">Toplam Kalori</small>
                                <h5 class="mb-0"><?= number_format($totals['calories']) ?></h5>
                                <small class="text-secondary">
                                    Günlük Ort: <?= number_format($averages['calories'], 1) ?>
                                </small>
                            </div>
                            <div class="icon-box bg-primary-light text-primary">
                                <i class="fas fa-fire"></i>
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
                                <small class="text-secondary">Toplam Protein</small>
                                <h5 class="mb-0"><?= number_format($totals['protein'], 1) ?>g</h5>
                                <small class="text-secondary">
                                    Günlük Ort: <?= number_format($averages['protein'], 1) ?>g
                                </small>
                            </div>
                            <div class="icon-box bg-success-light text-success">
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
                                <small class="text-secondary">Toplam Karbonhidrat</small>
                                <h5 class="mb-0"><?= number_format($totals['carbs'], 1) ?>g</h5>
                                <small class="text-secondary">
                                    Günlük Ort: <?= number_format($averages['carbs'], 1) ?>g
                                </small>
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
                                <small class="text-secondary">Toplam Yağ</small>
                                <h5 class="mb-0"><?= number_format($totals['fat'], 1) ?>g</h5>
                                <small class="text-secondary">
                                    Günlük Ort: <?= number_format($averages['fat'], 1) ?>g
                                </small>
                            </div>
                            <div class="icon-box bg-danger-light text-danger">
                                <i class="fas fa-oil-can"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Öğün Listesi -->
        <div class="card shadow-sm">
            <div class="card-header bg-light">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-history text-primary"></i> Öğün Geçmişi
                    </h5>
                    <button onclick="exportToExcel()" class="btn btn-sm btn-outline-primary">
                        <i class="fas fa-file-excel me-2"></i>Excel'e Aktar
                    </button>
                </div>
            </div>
            <div class="card-body">
                <?php if(empty($meals)): ?>
                    <p class="text-center text-muted my-4">
                        Seçili tarih aralığında öğün kaydı bulunamadı.
                    </p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover" id="mealsTable">
                            <thead>
                            <tr>
                                <th>Tarih</th>
                                <th>Saat</th>
                                <th>Öğün</th>
                                <th>Yemek</th>
                                <th>Miktar</th>
                                <th>Kalori</th>
                                <th>Protein</th>
                                <th>Karb</th>
                                <th>Yağ</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach($meals as $meal): ?>
                                <tr>
                                    <td><?= date('d.m.Y', strtotime($meal['date'])) ?></td>
                                    <td><?= date('H:i', strtotime($meal['time'])) ?></td>
                                    <td>
                                        <?php
                                        $meal_types = [
                                            'breakfast' => 'Kahvaltı',
                                            'morning_snack' => 'Kuşluk',
                                            'lunch' => 'Öğle',
                                            'afternoon_snack' => 'İkindi',
                                            'dinner' => 'Akşam',
                                            'evening_snack' => 'Gece'
                                        ];
                                        echo $meal_types[$meal['meal_type']] ?? $meal['meal_type'];
                                        ?>
                                    </td>
                                    <td><?= htmlspecialchars($meal['food_name']) ?></td>
                                    <td><?= $meal['serving_amount'] . ' ' . $meal['serving_type'] ?></td>
                                    <td><?= number_format($meal['calories'] * $meal['serving_amount'], 1) ?></td>
                                    <td><?= number_format($meal['protein'] * $meal['serving_amount'], 1) ?></td>
                                    <td><?= number_format($meal['carbs'] * $meal['serving_amount'], 1) ?></td>
                                    <td><?= number_format($meal['fat'] * $meal['serving_amount'], 1) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Excel Export Script -->
    <script src="https://unpkg.com/xlsx/dist/xlsx.full.min.js"></script>
    <script>
        function exportToExcel() {
            const table = document.getElementById('mealsTable');
            const wb = XLSX.utils.table_to_book(table, {sheet: "Öğün Geçmişi"});
            XLSX.writeFile(wb, 'ogun_gecmisi.xlsx');
        }
    </script>

<?php require_once '../../includes/footer.php'; ?>