<?php
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/functions.php';

$userProfile = [];
$consumedCalories = 0;
$consumedWater = 0;
$currentWeight = [];

if(isLoggedIn()) {
    try {
        $db = new Database();
        $conn = $db->getConnection();

        // Kullanıcı profili
        $stmt = $conn->prepare("SELECT up.*, u.name FROM user_profiles up JOIN users u ON u.id = up.user_id WHERE up.user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $userProfile = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['daily_calorie_limit' => 2000, 'current_weight' => 0, 'target_weight' => 0];

        // Günlük kalori
        $stmt = $conn->prepare("SELECT COALESCE(SUM(f.calories * ml.serving_amount), 0) as total_calories FROM meal_logs ml JOIN foods f ON f.id = ml.food_id WHERE ml.user_id = ? AND DATE(ml.date) = CURRENT_DATE()");
        $stmt->execute([$_SESSION['user_id']]);
        $consumedCalories = floatval($stmt->fetch(PDO::FETCH_ASSOC)['total_calories']);

        // Su tüketimi
        $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as total_water FROM water_tracking WHERE user_id = ? AND DATE(date) = CURRENT_DATE()");
        $stmt->execute([$_SESSION['user_id']]);
        $consumedWater = floatval($stmt->fetch(PDO::FETCH_ASSOC)['total_water']);

        // Kilo takibi
        $stmt = $conn->prepare("SELECT weight, date FROM weight_tracking WHERE user_id = ? ORDER BY date DESC LIMIT 1");
        $stmt->execute([$_SESSION['user_id']]);
        $currentWeight = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['weight' => $userProfile['current_weight'], 'date' => date('Y-m-d')];
    } catch (PDOException $e) {
        error_log("Database Error: " . $e->getMessage());
    }
}

require_once 'includes/header.php';
?>

    <div class="container py-4">
        <?php if(isLoggedIn()): ?>
            <!-- Ana İstatistikler -->
            <div class="row g-3 mb-4">
                <!-- Kalori Kartı -->
                <div class="col-md-4">
                    <div class="card h-100">
                        <div class="card-body p-3">
                            <div class="d-flex justify-content-between mb-2">
                                <div>
                                    <h6 class="text-secondary mb-1">Günlük Kalori</h6>
                                    <h5 class="mb-0"><?= number_format($consumedCalories) ?> / <?= number_format($userProfile['daily_calorie_limit']) ?></h5>
                                </div>
                                <div class="icon-box text-primary">
                                    <i class="fas fa-fire"></i>
                                </div>
                            </div>
                            <div class="progress" style="height: 6px;">
                                <?php $caloryPercentage = $userProfile['daily_calorie_limit'] > 0 ? min(($consumedCalories / $userProfile['daily_calorie_limit']) * 100, 100) : 0; ?>
                                <div class="progress-bar" role="progressbar" style="width: <?= $caloryPercentage ?>%"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Su Takip Kartı -->
                <div class="col-md-4">
                    <div class="card h-100">
                        <div class="card-body p-3">
                            <div class="d-flex justify-content-between mb-2">
                                <div>
                                    <h6 class="text-secondary mb-1">Su Tüketimi</h6>
                                    <h5 class="mb-0"><?= number_format($consumedWater/1000, 1) ?>L / 3L</h5>
                                </div>
                                <div class="icon-box text-info">
                                    <i class="fas fa-tint"></i>
                                </div>
                            </div>
                            <div class="progress" style="height: 6px;">
                                <div class="progress-bar bg-info" role="progressbar" style="width: <?= min(($consumedWater/3000) * 100, 100) ?>%"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Kilo Takip Kartı -->
                <div class="col-md-4">
                    <div class="card h-100">
                        <div class="card-body p-3">
                            <div class="d-flex justify-content-between mb-2">
                                <div>
                                    <h6 class="text-secondary mb-1">Kilo Takibi</h6>
                                    <h5 class="mb-0"><?= number_format($currentWeight['weight'], 1) ?> kg</h5>
                                </div>
                                <div class="icon-box text-success">
                                    <i class="fas fa-weight"></i>
                                </div>
                            </div>
                            <?php
                            $weightProgress = 0;
                            if ($userProfile['current_weight'] != $userProfile['target_weight']) {
                                $totalToLose = abs($userProfile['current_weight'] - $userProfile['target_weight']);
                                $currentLost = abs($userProfile['current_weight'] - $currentWeight['weight']);
                                $weightProgress = ($currentLost / $totalToLose) * 100;
                            }
                            ?>
                            <div class="progress" style="height: 6px;">
                                <div class="progress-bar bg-success" role="progressbar" style="width: <?= $weightProgress ?>%"></div>
                            </div>
                            <small class="text-secondary mt-2 d-block">Hedef: <?= number_format($userProfile['target_weight'], 1) ?> kg</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Hızlı Eylemler -->
            <div class="card mb-4">
                <div class="card-body p-3">
                    <div class="d-flex gap-2">
                        <a href="modules/meals/add_meal.php" class="btn btn-sm btn-primary">
                            <i class="fas fa-plus"></i> Öğün Ekle
                        </a>
                        <a href="modules/tracking/water.php" class="btn btn-sm btn-info text-white">
                            <i class="fas fa-tint"></i> Su Ekle
                        </a>
                        <a href="modules/profile/weight_tracking.php" class="btn btn-sm btn-success">
                            <i class="fas fa-weight"></i> Kilo Güncelle
                        </a>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <!-- Giriş yapmamış kullanıcılar için karşılama ekranı -->
            <div class="text-center py-5">
                <h1 class="h3 mb-4">Sağlıklı Yaşama Hoş Geldiniz</h1>
                <p class="text-secondary mb-4">Kişiselleştirilmiş diyet programınız ve yapay zeka destekli sağlık asistanınız</p>

                <div class="row g-4 justify-content-center mb-4">
                    <div class="col-md-4">
                        <div class="card h-100">
                            <div class="card-body p-3 text-center">
                                <div class="icon-box mx-auto mb-3">
                                    <i class="fas fa-utensils"></i>
                                </div>
                                <h6>Akıllı Öğün Takibi</h6>
                                <small class="text-secondary">Yapay zeka destekli besin analizi</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="card h-100">
                            <div class="card-body p-3 text-center">
                                <div class="icon-box mx-auto mb-3">
                                    <i class="fas fa-chart-line"></i>
                                </div>
                                <h6>Detaylı Analiz</h6>
                                <small class="text-secondary">Gelişmiş raporlar ve grafikler</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <div class="card h-100">
                            <div class="card-body p-3 text-center">
                                <div class="icon-box mx-auto mb-3">
                                    <i class="fas fa-brain"></i>
                                </div>
                                <h6>Kişisel Asistan</h6>
                                <small class="text-secondary">Size özel diyet tavsiyeleri</small>
                            </div>
                        </div>
                    </div>
                </div>

                <div>
                    <a href="modules/auth/register.php" class="btn btn-primary">
                        Hemen Başla
                    </a>
                    <a href="modules/auth/login.php" class="btn btn-outline-primary ms-2">
                        Giriş Yap
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </div>

<?php require_once 'includes/footer.php'; ?>