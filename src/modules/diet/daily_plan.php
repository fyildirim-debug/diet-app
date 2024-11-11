<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Giriş kontrolü
requireLogin();

$error = '';
$success = '';
$date = $_GET['date'] ?? date('Y-m-d');
$currentPlan = null;
$suggestions = null;

try {
    $db = new Database();
    $conn = $db->getConnection();

    // Kullanıcı profilini çek
    $stmt = $conn->prepare("
        SELECT up.*, u.name 
        FROM user_profiles up 
        JOIN users u ON u.id = up.user_id 
        WHERE up.user_id = ?
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $userProfile = $stmt->fetch(PDO::FETCH_ASSOC);

    // Aktif diyet programını çek
    $stmt = $conn->prepare("
        SELECT * FROM diet_programs 
        WHERE user_id = ? 
        AND ? BETWEEN start_date AND end_date 
        ORDER BY created_at DESC 
        LIMIT 1
    ");
    $stmt->execute([$_SESSION['user_id'], $date]);
    $currentPlan = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($currentPlan) {
        $planData = json_decode($currentPlan['program_data'], true);

        // Günlük program için o günün verilerini al
        if ($currentPlan['program_type'] == 'daily') {
            $dayIndex = 0;
        } else {
            // Haftalık/aylık program için hangi güne denk geldiğini hesapla
            $startDate = new DateTime($currentPlan['start_date']);
            $currentDate = new DateTime($date);
            $dayDiff = $startDate->diff($currentDate)->days;
            $dayIndex = $dayDiff % count($planData['meals']);
        }

        $todaysPlan = $planData['meals'][$dayIndex];
    }

    // Bugünkü tüketilen besinleri çek
    $stmt = $conn->prepare("
        SELECT 
            ml.meal_type,
            SUM(f.calories * ml.serving_amount) as total_calories,
            SUM(f.protein * ml.serving_amount) as total_protein,
            SUM(f.carbs * ml.serving_amount) as total_carbs,
            SUM(f.fat * ml.serving_amount) as total_fat
        FROM meal_logs ml
        JOIN foods f ON f.id = ml.food_id
        WHERE ml.user_id = ? AND DATE(ml.date) = ?
        GROUP BY ml.meal_type
    ");
    $stmt->execute([$_SESSION['user_id'], $date]);
    $consumedMeals = $stmt->fetchAll(PDO::FETCH_GROUP);

    // Kalan kalori için öneriler al
    $remainingCalories = $userProfile['daily_calorie_limit'];
    foreach ($consumedMeals as $type => $meal) {
        $remainingCalories -= $meal[0]['total_calories'];
    }

    if ($remainingCalories > 0) {
        $prompt = "Kalan {$remainingCalories} kalori için sağlıklı öneriler ver. 
                  Diyet tipi: {$userProfile['diet_type']}
                  Lütfen önerileri JSON formatında döndür:
                  {
                    'suggestions': [
                      {'meal': 'Yemek adı', 'calories': 300, 'description': 'Kısa açıklama'}
                    ]
                  }";

        $response = askOpenAI($prompt);
        $suggestions = json_decode($response['choices'][0]['message']['content'], true);
    }

} catch (PDOException $e) {
    error_log("Daily Plan Error: " . $e->getMessage());
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
                            <div>
                                <small class="text-secondary">Kalan Kalori</small>
                                <h4 class="mb-0"><?= number_format($remainingCalories) ?> kcal</h4>
                            </div>
                            <div class="progress" style="width: 100px; height: 100px;">
                                <?php
                                $percentage = min(($userProfile['daily_calorie_limit'] - $remainingCalories) / $userProfile['daily_calorie_limit'] * 100, 100);
                                ?>
                                <div class="progress-bar <?= $percentage > 100 ? 'bg-danger' : '' ?>"
                                     role="progressbar"
                                     style="width: <?= $percentage ?>%">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Sol Kolon - Günlük Plan -->
            <div class="col-md-8 mb-4">
                <?php if(!$currentPlan): ?>
                    <div class="card shadow-sm">
                        <div class="card-body text-center py-5">
                            <i class="fas fa-calendar-times fa-4x text-secondary mb-3"></i>
                            <h4>Aktif Diyet Programı Bulunamadı</h4>
                            <p class="text-secondary">Yeni bir diyet programı oluşturmak için aşağıdaki butonu kullanabilirsiniz.</p>
                            <a href="generate_plan.php" class="btn btn-primary">
                                <i class="fas fa-magic me-2"></i>Yeni Program Oluştur
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <?php
                    $mealTypes = [
                        'breakfast' => ['Kahvaltı', 'sun-rise', 'bg-warning-light'],
                        'morning_snack' => ['Kuşluk', 'coffee', 'bg-info-light'],
                        'lunch' => ['Öğle Yemeği', 'utensils', 'bg-success-light'],
                        'afternoon_snack' => ['İkindi', 'apple-alt', 'bg-info-light'],
                        'dinner' => ['Akşam Yemeği', 'moon', 'bg-primary-light'],
                        'evening_snack' => ['Gece Atıştırması', 'cookie', 'bg-secondary-light']
                    ];

                    foreach($mealTypes as $type => $info):
                        $consumed = isset($consumedMeals[$type]) ? $consumedMeals[$type][0] : ['total_calories' => 0];
                        $planned = $todaysPlan[$type] ?? ['meal' => '-', 'calories' => 0];
                        ?>
                        <div class="card shadow-sm mb-3">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <div class="d-flex align-items-center">
                                        <div class="icon-box <?= $info[2] ?> me-3">
                                            <i class="fas fa-<?= $info[1] ?>"></i>
                                        </div>
                                        <div>
                                            <h5 class="mb-0"><?= $info[0] ?></h5>
                                            <small class="text-secondary">
                                                <?= date('H:i', strtotime($todaysPlan[$type]['time'] ?? '00:00')) ?>
                                            </small>
                                        </div>
                                    </div>
                                    <div class="text-end">
                                        <h6 class="mb-0">
                                            <?= number_format($consumed['total_calories']) ?> /
                                            <?= number_format($planned['calories']) ?> kcal
                                        </h6>
                                        <small class="text-<?= $consumed['total_calories'] > $planned['calories'] ? 'danger' : 'success' ?>">
                                            <?php
                                            $diff = $planned['calories'] - $consumed['total_calories'];
                                            echo $diff >= 0 ? $diff . ' kcal kaldı' : abs($diff) . ' kcal aşıldı';
                                            ?>
                                        </small>
                                    </div>
                                </div>

                                <p class="mb-3"><?= htmlspecialchars($planned['meal']) ?></p>

                                <div class="d-flex gap-2">
                                    <a href="../meals/add_meal.php?meal_type=<?= $type ?>&suggested_meal=<?= urlencode($planned['meal']) ?>"
                                       class="btn btn-sm btn-primary">
                                        <i class="fas fa-plus me-1"></i>Öğün Ekle
                                    </a>
                                    <button type="button"
                                            class="btn btn-sm btn-outline-primary"
                                            onclick="showNutrition('<?= htmlspecialchars($planned['meal']) ?>')">
                                        <i class="fas fa-info-circle me-1"></i>Besin Değerleri
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Sağ Kolon - Öneriler ve İpuçları -->
            <div class="col-md-4">
                <?php if($suggestions && !empty($suggestions['suggestions'])): ?>
                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-light">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-lightbulb text-warning"></i> Öneriler
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php foreach($suggestions['suggestions'] as $suggestion): ?>
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <h6 class="mb-0"><?= htmlspecialchars($suggestion['meal']) ?></h6>
                                        <span class="badge bg-primary"><?= $suggestion['calories'] ?> kcal</span>
                                    </div>
                                    <small class="text-secondary"><?= htmlspecialchars($suggestion['description']) ?></small>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if($currentPlan && !empty($planData['notes'])): ?>
                    <div class="card shadow-sm">
                        <div class="card-header bg-light">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-info-circle text-info"></i> İpuçları
                            </h5>
                        </div>
                        <div class="card-body">
                            <ul class="list-unstyled mb-0">
                                <?php foreach($planData['notes'] as $note): ?>
                                    <li class="mb-2">
                                        <i class="fas fa-check text-success me-2"></i>
                                        <?= htmlspecialchars($note) ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Besin Değerleri Modal -->
    <div class="modal fade" id="nutritionModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-info-circle text-primary me-2"></i>
                        Besin Değerleri
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="nutritionContent">
                        <div class="text-center">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Yükleniyor...</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-2"></i>Kapat
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        async function showNutrition(meal) {
            const modal = new bootstrap.Modal(document.getElementById('nutritionModal'));
            const content = document.getElementById('nutritionContent');

            modal.show();
            content.innerHTML = '<div class="text-center"><div class="spinner-border text-primary" role="status"></div></div>';

            try {
                const response = await fetch('get_nutrition.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ meal: meal })
                });

                const data = await response.json();

                if (data.success) {
                    content.innerHTML = `
                <div class="table-responsive">
                    <table class="table table-bordered mb-0">
                        <tbody>
                            <tr>
                                <td class="fw-bold">Kalori</td>
                                <td>${data.nutrition.calories} kcal</td>
                            </tr>
                            <tr>
                                <td class="fw-bold">Protein</td>
                                <td>${data.nutrition.protein} g</td>
                            </tr>
                            <tr>
                                <td class="fw-bold">Karbonhidrat</td>
                                <td>${data.nutrition.carbs} g</td>
                            </tr>
                            <tr>
                                <td class="fw-bold">Yağ</td>
                                <td>${data.nutrition.fat} g</td>
                            </tr>
                            <tr>
                                <td class="fw-bold">Lif</td>
                                <td>${data.nutrition.fiber} g</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            `;
                } else {
                    content.innerHTML = `
                <div class="alert alert-danger mb-0">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    ${data.error || 'Besin değerleri alınamadı.'}
                </div>
            `;
                }
            } catch (error) {
                content.innerHTML = `
            <div class="alert alert-danger mb-0">
                <i class="fas fa-exclamation-circle me-2"></i>
                Bir hata oluştu. Lütfen tekrar deneyin.
            </div>
        `;
            }
        }
    </script>

<?php require_once '../../includes/footer.php'; ?>