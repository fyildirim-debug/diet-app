<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Giriş kontrolü
requireLogin();

$error = '';
$success = '';
$currentPlan = null;
$date = $_GET['date'] ?? date('Y-m-d');

try {
    $db = new Database();
    $conn = $db->getConnection();

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

        // Bugünkü tüketilen kalorileri hesapla
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(f.calories * ml.serving_amount), 0) as total_calories
            FROM meal_logs ml
            JOIN foods f ON f.id = ml.food_id
            WHERE ml.user_id = ? AND DATE(ml.date) = ?
        ");
        $stmt->execute([$_SESSION['user_id'], $date]);
        $consumedCalories = $stmt->fetch(PDO::FETCH_ASSOC)['total_calories'];
    }

} catch (PDOException $e) {
    error_log("Diet Plan View Error: " . $e->getMessage());
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

        <?php if(!$currentPlan): ?>
            <div class="text-center py-5">
                <div class="mb-4">
                    <i class="fas fa-calendar-times fa-4x text-secondary"></i>
                </div>
                <h4>Aktif Diyet Programı Bulunamadı</h4>
                <p class="text-secondary">Yeni bir diyet programı oluşturmak için aşağıdaki butonu kullanabilirsiniz.</p>
                <a href="generate_plan.php" class="btn btn-primary">
                    <i class="fas fa-magic me-2"></i>Yeni Program Oluştur
                </a>
            </div>
        <?php else: ?>
            <!-- Tarih Seçici ve Program Bilgisi -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="card shadow-sm">
                        <div class="card-body">
                            <form class="d-flex gap-2">
                                <input type="date"
                                       name="date"
                                       class="form-control"
                                       value="<?= $date ?>"
                                       min="<?= $currentPlan['start_date'] ?>"
                                       max="<?= $currentPlan['end_date'] ?>"
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
                                    <small class="text-secondary">Program Tipi</small>
                                    <h6 class="mb-0">
                                        <?php
                                        $types = [
                                            'daily' => 'Günlük Program',
                                            'weekly' => 'Haftalık Program',
                                            'monthly' => 'Aylık Program'
                                        ];
                                        echo $types[$currentPlan['program_type']];
                                        ?>
                                    </h6>
                                </div>
                                <div>
                                    <small class="text-secondary">Bitiş Tarihi</small>
                                    <h6 class="mb-0"><?= date('d.m.Y', strtotime($currentPlan['end_date'])) ?></h6>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Günlük Program -->
            <div class="row">
                <!-- Sol Kolon - Öğünler -->
                <div class="col-md-8 mb-4">
                    <?php
                    $mealTypes = [
                        'breakfast' => ['Kahvaltı', 'sun-rise'],
                        'morning_snack' => ['Kuşluk', 'coffee'],
                        'lunch' => ['Öğle Yemeği', 'utensils'],
                        'afternoon_snack' => ['İkindi', 'apple-alt'],
                        'dinner' => ['Akşam Yemeği', 'moon'],
                        'evening_snack' => ['Gece Atıştırması', 'cookie']
                    ];
                    ?>

                    <?php foreach($mealTypes as $type => $info): ?>
                        <div class="card shadow-sm mb-3">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h5 class="mb-0">
                                        <i class="fas fa-<?= $info[1] ?> text-primary me-2"></i>
                                        <?= $info[0] ?>
                                    </h5>
                                    <span class="badge bg-primary">
                                    <?= $todaysPlan[$type]['calories'] ?> kcal
                                </span>
                                </div>

                                <p class="mb-0"><?= htmlspecialchars($todaysPlan[$type]['meal']) ?></p>

                                <div class="mt-3">
                                    <a href="../meals/add_meal.php?meal_type=<?= $type ?>&suggested_meal=<?= urlencode($todaysPlan[$type]['meal']) ?>"
                                       class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-plus me-1"></i>Öğünü Ekle
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Sağ Kolon - Özet ve Öneriler -->
                <div class="col-md-4">
                    <!-- Kalori Takibi -->
                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-light">
                            <h6 class="card-title mb-0">
                                <i class="fas fa-fire text-primary"></i> Kalori Takibi
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="d-flex justify-content-between mb-2">
                                <span>Hedef:</span>
                                <span class="fw-bold">
                                <?= number_format($planData['daily_calories'] ?? 2000) ?> kcal
                            </span>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Tüketilen:</span>
                                <span class="fw-bold">
                                <?= number_format($consumedCalories) ?> kcal
                            </span>
                            </div>
                            <div class="d-flex justify-content-between mb-3">
                                <span>Kalan:</span>
                                <span class="fw-bold text-<?= ($planData['daily_calories'] - $consumedCalories) < 0 ? 'danger' : 'success' ?>">
                                <?= number_format(($planData['daily_calories'] ?? 2000) - $consumedCalories) ?> kcal
                            </span>
                            </div>
                            <div class="progress" style="height: 10px;">
                                <?php
                                $percentage = min(($consumedCalories / ($planData['daily_calories'] ?? 2000)) * 100, 100);
                                ?>
                                <div class="progress-bar <?= $percentage > 100 ? 'bg-danger' : '' ?>"
                                     style="width: <?= $percentage ?>%">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Öneriler -->
                    <?php if(!empty($planData['notes'])): ?>
                        <div class="card shadow-sm mb-4">
                            <div class="card-header bg-light">
                                <h6 class="card-title mb-0">
                                    <i class="fas fa-lightbulb text-primary"></i> Öneriler
                                </h6>
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

                    <!-- Hızlı Eylemler -->
                    <div class="d-grid gap-2">
                        <a href="generate_plan.php" class="btn btn-outline-primary">
                            <i class="fas fa-magic me-2"></i>Yeni Program Oluştur
                        </a>
                        <button onclick="window.print()" class="btn btn-outline-secondary">
                            <i class="fas fa-print me-2"></i>Yazdır
                        </button>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

<?php require_once '../../includes/footer.php'; ?>