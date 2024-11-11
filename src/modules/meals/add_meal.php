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

    // Öğün silme işlemi
    if(isset($_POST['delete_meal'])) {
        $meal_id = clean($_POST['meal_id']);
        $stmt = $conn->prepare("DELETE FROM meal_logs WHERE id = ? AND user_id = ?");
        $stmt->execute([$meal_id, $_SESSION['user_id']]);
        $success = 'Öğün başarıyla silindi.';
    }

    // Günlük öğünleri çek
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
            f.serving_type,
            (f.calories * ml.serving_amount) as total_calories,
            (f.protein * ml.serving_amount) as total_protein,
            (f.carbs * ml.serving_amount) as total_carbs,
            (f.fat * ml.serving_amount) as total_fat
        FROM meal_logs ml
        JOIN foods f ON f.id = ml.food_id
        WHERE ml.user_id = ? AND DATE(ml.date) = ?
        ORDER BY ml.time ASC
    ");
    $stmt->execute([$_SESSION['user_id'], $date]);
    $meals = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Son eklenen yemekleri çek (öneriler için)
    $stmt = $conn->prepare("
        SELECT DISTINCT f.name
        FROM meal_logs ml
        JOIN foods f ON f.id = ml.food_id
        WHERE ml.user_id = ?
        ORDER BY ml.date DESC, ml.time DESC
        LIMIT 10
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $recentFoods = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Günlük toplamları hesapla
    $dailyTotals = [
        'calories' => 0,
        'protein' => 0,
        'carbs' => 0,
        'fat' => 0
    ];

    foreach ($meals as $meal) {
        $dailyTotals['calories'] += $meal['total_calories'];
        $dailyTotals['protein'] += $meal['total_protein'];
        $dailyTotals['carbs'] += $meal['total_carbs'];
        $dailyTotals['fat'] += $meal['total_fat'];
    }

} catch (PDOException $e) {
    error_log("Meal History Error: " . $e->getMessage());
    $error = 'Bir hata oluştu, lütfen tekrar deneyin.';
}

require_once '../../includes/header.php';
?>

    <div class="container py-4">
        <!-- Başlık ve Tarih Seçici -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h3 class="mb-1">Öğün Geçmişi</h3>
                <p class="text-muted mb-0">Günlük beslenme kayıtlarınız</p>
            </div>
            <div class="d-flex gap-3">
                <input type="date"
                       class="form-control"
                       value="<?= $date ?>"
                       max="<?= date('Y-m-d') ?>"
                       onchange="window.location.href='?date='+this.value">
            </div>
        </div>

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

        <div class="row">
            <!-- Sol Kolon - Öğün Ekleme ve Liste -->
            <div class="col-lg-8">
                <!-- Öğün Ekleme Formu -->
                <div class="card shadow-sm mb-4">
                    <div class="card-body">
                        <h5 class="card-title mb-4">
                            <i class="fas fa-plus-circle text-primary me-2"></i>Hızlı Öğün Ekle
                        </h5>

                        <form id="mealForm" class="needs-validation" novalidate>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Öğün Tipi</label>
                                    <select name="meal_type" class="form-select" required>
                                        <option value="breakfast">🌅 Kahvaltı</option>
                                        <option value="morning_snack">🥪 Kuşluk</option>
                                        <option value="lunch">🍽️ Öğle Yemeği</option>
                                        <option value="afternoon_snack">🍎 İkindi</option>
                                        <option value="dinner">🌙 Akşam Yemeği</option>
                                        <option value="evening_snack">🍪 Gece Atıştırması</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Saat</label>
                                    <input type="time" name="time" class="form-control" required value="<?= date('H:i') ?>">
                                </div>
                                <div class="col-md-12">
                                    <label class="form-label">Besin Adı</label>
                                    <div class="input-group">
                                    <span class="input-group-text">
                                        <i class="fas fa-search"></i>
                                    </span>
                                        <input type="text"
                                               name="food_name"
                                               class="form-control"
                                               required
                                               list="foodSuggestions"
                                               placeholder="Besin adı girin...">
                                        <datalist id="foodSuggestions">
                                            <?php foreach($recentFoods as $food): ?>
                                            <option value="<?= htmlspecialchars($food) ?>">
                                                <?php endforeach; ?>
                                        </datalist>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Miktar</label>
                                    <input type="number"
                                           name="serving_amount"
                                           class="form-control"
                                           required
                                           step="0.1"
                                           min="0"
                                           placeholder="0.0">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Birim</label>
                                    <select name="serving_type" class="form-select" required>
                                        <option value="gram">Gram</option>
                                        <option value="piece">Adet</option>
                                        <option value="tablespoon">Yemek Kaşığı</option>
                                        <option value="teaspoon">Çay Kaşığı</option>
                                        <option value="cup">Su Bardağı</option>
                                        <option value="plate">Tabak</option>
                                        <option value="portion">Porsiyon</option>
                                    </select>
                                </div>
                                <input type="hidden" name="date" value="<?= $date ?>">
                            </div>

                            <div class="d-grid mt-3">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-plus-circle me-2"></i>Öğün Ekle
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Öğün Listesi -->
                <?php
                $mealTypes = [
                    'breakfast' => ['Kahvaltı', 'sun-rise', 'bg-warning-subtle'],
                    'morning_snack' => ['Kuşluk', 'coffee', 'bg-info-subtle'],
                    'lunch' => ['Öğle Yemeği', 'utensils', 'bg-success-subtle'],
                    'afternoon_snack' => ['İkindi', 'apple-alt', 'bg-info-subtle'],
                    'dinner' => ['Akşam Yemeği', 'moon', 'bg-primary-subtle'],
                    'evening_snack' => ['Gece Atıştırması', 'cookie', 'bg-secondary-subtle']
                ];

                foreach($mealTypes as $type => $info):
                    $mealItems = array_filter($meals, function($meal) use ($type) {
                        return $meal['meal_type'] == $type;
                    });
                    ?>
                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-white">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <i class="fas fa-<?= $info[1] ?> text-primary me-2"></i>
                                    <strong><?= $info[0] ?></strong>
                                </div>
                                <?php if(!empty($mealItems)): ?>
                                    <span class="badge bg-primary">
                                    <?= number_format(array_sum(array_column($mealItems, 'total_calories'))) ?> kcal
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if(empty($mealItems)): ?>
                            <div class="card-body text-center text-muted py-4">
                                <i class="fas fa-utensils mb-2"></i>
                                <p class="mb-0">Bu öğünde kayıt bulunmuyor</p>
                            </div>
                        <?php else: ?>
                            <div class="card-body p-0">
                                <div class="list-group list-group-flush">
                                    <?php foreach($mealItems as $meal): ?>
                                        <div class="list-group-item">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <h6 class="mb-1"><?= htmlspecialchars($meal['food_name']) ?></h6>
                                                    <small class="text-muted">
                                                        <?= $meal['serving_amount'] ?> <?= $meal['serving_type'] ?> •
                                                        <?= date('H:i', strtotime($meal['time'])) ?>
                                                    </small>
                                                </div>
                                                <div class="d-flex align-items-center gap-3">
                                                    <div class="text-end">
                                                        <div class="badge bg-primary-subtle text-primary mb-1">
                                                            <?= number_format($meal['total_calories']) ?> kcal
                                                        </div>
                                                        <div class="d-flex gap-2">
                                                            <small class="text-muted">
                                                                P: <?= number_format($meal['total_protein'], 1) ?>g
                                                            </small>
                                                            <small class="text-muted">
                                                                K: <?= number_format($meal['total_carbs'], 1) ?>g
                                                            </small>
                                                            <small class="text-muted">
                                                                Y: <?= number_format($meal['total_fat'], 1) ?>g
                                                            </small>
                                                        </div>
                                                    </div>
                                                    <form method="POST" onsubmit="return confirm('Bu öğünü silmek istediğinizden emin misiniz?');">
                                                        <input type="hidden" name="meal_id" value="<?= $meal['id'] ?>">
                                                        <button type="submit" name="delete_meal" class="btn btn-outline-danger btn-sm">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Sağ Kolon - Günlük Özet -->
            <div class="col-lg-4">
                <div class="card shadow-sm sticky-top" style="top: 1rem;">
                    <div class="card-body">
                        <h5 class="card-title mb-4">
                            <i class="fas fa-chart-pie text-primary me-2"></i>Günlük Özet
                        </h5>

                        <!-- Kalori -->
                        <div class="mb-4">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="text-muted">Kalori</span>
                                <span class="badge bg-primary">
                                <?= number_format($dailyTotals['calories']) ?> kcal
                            </span>
                            </div>
                            <div class="progress" style="height: 8px;">
                                <div class="progress-bar bg-primary" style="width: 100%"></div>
                            </div>
                        </div>

                        <!-- Protein -->
                        <div class="mb-4">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="text-muted">Protein</span>
                                <span class="badge bg-success">
                                <?= number_format($dailyTotals['protein'], 1) ?> g
                            </span>
                            </div>
                            <div class="progress" style="height: 8px;">
                                <div class="progress-bar bg-success" style="width: 100%"></div>
                            </div>
                        </div>

                        <!-- Karbonhidrat -->
                        <div class="mb-4">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="text-muted">Karbonhidrat</span>
                                <span class="badge bg-warning">
                                <?= number_format($dailyTotals['carbs'], 1) ?> g
                            </span>
                            </div>
                            <div class="progress" style="height: 8px;">
                                <div class="progress-bar bg-warning" style="width: 100%"></div>
                            </div>
                        </div>

                        <!-- Yağ -->
                        <div class="mb-4">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="text-muted">Yağ</span>
                                <span class="badge bg-danger">
                                <?= number_format($dailyTotals['fat'], 1) ?> g
                            </span>
                            </div>
                            <div class="progress" style="height: 8px;">
                                <div class="progress-bar bg-danger" style="width: 100%"></div>
                            </div>
                        </div>

                        <!-- Dağılım -->
                        <div class="mt-4">
                            <h6 class="mb-3">Besin Dağılımı</h6>
                            <canvas id="macroChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Loading Modal -->
    <div class="modal fade" id="loadingModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0">
                <div class="modal-body text-center p-5">
                    <div class="spinner-border text-primary loading-spinner mb-3" role="status">
                        <span class="visually-hidden">Yükleniyor...</span>
                    </div>
                    <h5>Besin bilgileri alınıyor</h5>
                    <p class="text-muted mb-0">Lütfen bekleyin...</p>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Besin dağılımı grafiği
        new Chart(document.getElementById('macroChart'), {
            type: 'doughnut',
            data: {
                labels: ['Protein', 'Karbonhidrat', 'Yağ'],
                datasets: [{
                    data: [
                        <?= $dailyTotals['protein'] ?>,
                        <?= $dailyTotals['carbs'] ?>,
                        <?= $dailyTotals['fat'] ?>
                    ],
                    backgroundColor: [
                        'rgba(25, 135, 84, 0.8)',
                        'rgba(255, 193, 7, 0.8)',
                        'rgba(220, 53, 69, 0.8)'
                    ],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                },
                cutout: '70%'
            }
        });

        // Öğün ekleme formu
        document.getElementById('mealForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const loadingModal = new bootstrap.Modal(document.getElementById('loadingModal'));
            loadingModal.show();

            const formData = new FormData(this);

            try {
                const response = await fetch('add_meal_process.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if(result.success) {
                    // Sayfayı yenile
                    window.location.reload();
                } else {
                    throw new Error(result.message || 'Bir hata oluştu');
                }
            } catch (error) {
                alert(error.message);
            } finally {
                loadingModal.hide();
            }
        });
    </script>

<?php require_once '../../includes/footer.php'; ?>