<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Giriş kontrolü
requireLogin();

$error = '';
$success = '';
$date = $_GET['date'] ?? date('Y-m-d');

// İstatistikleri başlangıçta tanımla
$stats = [
    'total_loss' => 0,
    'monthly_loss' => 0,
    'weekly_loss' => 0,
    'remaining' => 0
];

try {
    $db = new Database();
    $conn = $db->getConnection();

    // Kullanıcı hedeflerini çek
    $stmt = $conn->prepare("SELECT current_weight, target_weight FROM user_profiles WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);

    // Profil yoksa veya eksik bilgi varsa setup sayfasına yönlendir
    if (!$profile || !isset($profile['current_weight']) || !isset($profile['target_weight'])) {
        $_SESSION['error'] = 'Lütfen önce profil bilgilerinizi tamamlayın.';
        header("Location: " . SITE_URL . "/modules/profile/setup.php");
        exit;
    }

    // Yeni kilo kaydı ekleme
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_weight'])) {
        $weight = clean($_POST['weight']);
        $date = clean($_POST['date']);
        $notes = clean($_POST['notes']) ?: '';

        if(empty($weight) || empty($date)) {
            $error = 'Kilo ve tarih alanları zorunludur.';
        } elseif(!is_numeric($weight) || $weight <= 0) {
            $error = 'Geçerli bir kilo değeri girin.';
        } else {
            try {
                // Aynı tarihte kayıt var mı kontrol et
                $stmt = $conn->prepare("SELECT id FROM weight_tracking WHERE user_id = ? AND date = ?");
                $stmt->execute([$_SESSION['user_id'], $date]);

                if($stmt->fetch()) {
                    // Güncelle
                    $stmt = $conn->prepare("
                        UPDATE weight_tracking 
                        SET weight = ?, notes = ? 
                        WHERE user_id = ? AND date = ?
                    ");
                    $stmt->execute([$weight, $notes, $_SESSION['user_id'], $date]);
                } else {
                    // Yeni kayıt ekle
                    $stmt = $conn->prepare("
                        INSERT INTO weight_tracking (user_id, weight, date, notes) 
                        VALUES (?, ?, ?, ?)
                    ");
                    $stmt->execute([$_SESSION['user_id'], $weight, $date, $notes]);
                }

                $success = 'Kilo kaydı başarıyla eklendi.';
                header("Location: " . $_SERVER['PHP_SELF']);
                exit;
            } catch (PDOException $e) {
                error_log("Weight Insert Error: " . $e->getMessage());
                $error = 'Kilo kaydı eklenirken bir hata oluştu.';
            }
        }
    }

    // Kilo geçmişini çek
    $stmt = $conn->prepare("
        SELECT * FROM weight_tracking 
        WHERE user_id = ? 
        ORDER BY date DESC 
        LIMIT 90
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $weightHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // İstatistikleri hesapla
    if(!empty($weightHistory)) {
        $currentWeight = $weightHistory[0]['weight'];
        $stats['total_loss'] = $profile['current_weight'] - $currentWeight;
        $stats['remaining'] = $currentWeight - $profile['target_weight'];

        // Son 30 günlük değişim
        $monthAgo = array_filter($weightHistory, function($record) {
            return strtotime($record['date']) <= strtotime('-30 days');
        });
        if(!empty($monthAgo)) {
            $stats['monthly_loss'] = reset($monthAgo)['weight'] - $currentWeight;
        }

        // Son 7 günlük değişim
        $weekAgo = array_filter($weightHistory, function($record) {
            return strtotime($record['date']) <= strtotime('-7 days');
        });
        if(!empty($weekAgo)) {
            $stats['weekly_loss'] = reset($weekAgo)['weight'] - $currentWeight;
        }
    }

} catch (PDOException $e) {
    error_log("Weight Tracking Error: " . $e->getMessage());
    $error = 'Bir hata oluştu, lütfen tekrar deneyin.';
}

require_once '../../includes/header.php';
?>


    <div class="container py-4">
        <div class="row">
            <!-- Sol Kolon - Kilo Ekleme ve İstatistikler -->
            <div class="col-md-4 mb-4">
                <!-- Kilo Ekleme Formu -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-weight text-primary"></i> Kilo Ekle
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if($error): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="fas fa-exclamation-circle me-2"></i><?= $error ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <?php if($success): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <i class="fas fa-check-circle me-2"></i><?= $success ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="" class="needs-validation" novalidate>
                            <div class="mb-3">
                                <label class="form-label">Kilo (kg)</label>
                                <input type="number"
                                       name="weight"
                                       class="form-control"
                                       required
                                       step="0.1"
                                       min="30"
                                       max="300"
                                       value="<?= isset($_POST['weight']) ? clean($_POST['weight']) : '' ?>">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Tarih</label>
                                <input type="date"
                                       name="date"
                                       class="form-control"
                                       required
                                       max="<?= date('Y-m-d') ?>"
                                       value="<?= isset($_POST['date']) ? clean($_POST['date']) : date('Y-m-d') ?>">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Notlar</label>
                                <textarea name="notes"
                                          class="form-control"
                                          rows="2"><?= isset($_POST['notes']) ? clean($_POST['notes']) : '' ?></textarea>
                            </div>

                            <div class="d-grid">
                                <button type="submit" name="add_weight" class="btn btn-primary">
                                    <i class="fas fa-plus me-2"></i>Kaydet
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- İstatistikler -->
                <div class="card shadow-sm">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-chart-pie text-primary"></i> İstatistikler
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-4">
                            <small class="text-secondary">Başlangıç Kilo</small>
                            <h5 class="mb-0"><?= number_format($profile['current_weight'], 1) ?> kg</h5>
                        </div>

                        <div class="mb-4">
                            <small class="text-secondary">Hedef Kilo</small>
                            <h5 class="mb-0"><?= number_format($profile['target_weight'], 1) ?> kg</h5>
                            <?php if($stats['remaining'] > 0): ?>
                                <small class="text-secondary">
                                    <?= number_format($stats['remaining'], 1) ?> kg kaldı
                                </small>
                            <?php else: ?>
                                <small class="text-success">
                                    <i class="fas fa-check-circle"></i> Hedefe ulaşıldı!
                                </small>
                            <?php endif; ?>
                        </div>

                        <div class="mb-4">
                            <small class="text-secondary">Toplam Değişim</small>
                            <h5 class="mb-0 <?= $stats['total_loss'] > 0 ? 'text-success' : 'text-danger' ?>">
                                <?= $stats['total_loss'] > 0 ? '-' : '+' ?><?= number_format(abs($stats['total_loss']), 1) ?> kg
                            </h5>
                        </div>

                        <div class="mb-4">
                            <small class="text-secondary">Aylık Değişim</small>
                            <h5 class="mb-0 <?= $stats['monthly_loss'] > 0 ? 'text-success' : 'text-danger' ?>">
                                <?= $stats['monthly_loss'] > 0 ? '-' : '+' ?><?= number_format(abs($stats['monthly_loss']), 1) ?> kg
                            </h5>
                        </div>

                        <div>
                            <small class="text-secondary">Haftalık Değişim</small>
                            <h5 class="mb-0 <?= $stats['weekly_loss'] > 0 ? 'text-success' : 'text-danger' ?>">
                                <?= $stats['weekly_loss'] > 0 ? '-' : '+' ?><?= number_format(abs($stats['weekly_loss']), 1) ?> kg
                            </h5>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sağ Kolon - Grafik ve Geçmiş -->
            <div class="col-md-8">
                <!-- Kilo Grafiği -->
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-chart-line text-primary"></i> Kilo Takibi
                        </h5>
                    </div>
                    <div class="card-body">
                        <canvas id="weightChart" height="300"></canvas>
                    </div>
                </div>

                <!-- Kilo Geçmişi -->
                <div class="card shadow-sm">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-history text-primary"></i> Kilo Geçmişi
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if(empty($weightHistory)): ?>
                            <p class="text-center text-muted my-4">
                                Henüz kilo kaydı bulunmuyor.
                            </p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                    <tr>
                                        <th>Tarih</th>
                                        <th>Kilo</th>
                                        <th>Değişim</th>
                                        <th>Not</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php
                                    $prevWeight = null;
                                    foreach($weightHistory as $record):
                                        $change = $prevWeight ? $record['weight'] - $prevWeight : 0;
                                        $prevWeight = $record['weight'];
                                        ?>
                                        <tr>
                                            <td><?= date('d.m.Y', strtotime($record['date'])) ?></td>
                                            <td><?= number_format($record['weight'], 1) ?> kg</td>
                                            <td>
                                                <?php if($change != 0): ?>
                                                    <span class="badge bg-<?= $change < 0 ? 'success' : 'danger' ?>">
                                                    <?= $change > 0 ? '+' : '' ?><?= number_format($change, 1) ?> kg
                                                </span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= htmlspecialchars($record['notes'] ?? '') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Kilo takip grafiği
        const weightData = <?= json_encode(array_reverse($weightHistory)) ?>;
        const dates = weightData.map(item => item.date);
        const weights = weightData.map(item => item.weight);
        const targetWeight = <?= $profile['target_weight'] ?>;

        new Chart(document.getElementById('weightChart'), {
            type: 'line',
            data: {
                labels: dates,
                datasets: [
                    {
                        label: 'Kilo (kg)',
                        data: weights,
                        borderColor: '#4e73df',
                        tension: 0.1,
                        fill: false
                    },
                    {
                        label: 'Hedef',
                        data: Array(dates.length).fill(targetWeight),
                        borderColor: '#1cc88a',
                        borderDash: [5, 5],
                        fill: false
                    }
                ]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'top'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: false,
                        ticks: {
                            callback: function(value) {
                                return value + ' kg';
                            }
                        }
                    }
                }
            }
        });

        // Form doğrulama
        (function () {
            'use strict'
            var forms = document.querySelectorAll('.needs-validation')
            Array.prototype.slice.call(forms).forEach(function (form) {
                form.addEventListener('submit', function (event) {
                    if (!form.checkValidity()) {
                        event.preventDefault()
                        event.stopPropagation()
                    }
                    form.classList.add('was-validated')
                }, false)
            })
        })()
    </script>

<?php require_once '../../includes/footer.php'; ?>