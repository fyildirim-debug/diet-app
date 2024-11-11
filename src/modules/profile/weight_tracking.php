<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Giriş kontrolü
requireLogin();

$error = '';
$success = '';

try {
    $db = new Database();
    $conn = $db->getConnection();

    // Kullanıcı hedef bilgilerini çek
    $stmt = $conn->prepare("SELECT current_weight, target_weight FROM user_profiles WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);

    // Yeni kilo kaydı ekleme
    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        $weight = clean($_POST['weight']);
        $date = clean($_POST['date']);
        $note = clean($_POST['note']);

        if(empty($weight) || empty($date)) {
            $error = 'Kilo ve tarih alanları zorunludur.';
        } elseif(!is_numeric($weight) || $weight <= 0) {
            $error = 'Geçerli bir kilo değeri girin.';
        } else {
            $stmt = $conn->prepare("
                INSERT INTO weight_tracking (user_id, weight, date, note) 
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$_SESSION['user_id'], $weight, $date, $note]);
            $success = 'Kilo kaydı başarıyla eklendi.';
        }
    }

    // Kilo geçmişini çek
    $stmt = $conn->prepare("
        SELECT * FROM weight_tracking 
        WHERE user_id = ? 
        ORDER BY date DESC 
        LIMIT 30
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $weightHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Weight Tracking Error: " . $e->getMessage());
    $error = 'Bir hata oluştu, lütfen tekrar deneyin.';
}

require_once '../../includes/header.php';
?>

    <div class="container py-4">
        <div class="row">
            <!-- Sol Kolon - Kilo Ekleme Formu -->
            <div class="col-md-4 mb-4">
                <div class="card shadow-sm">
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
                                       min="0"
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
                                <label class="form-label">Not (İsteğe bağlı)</label>
                                <textarea name="note"
                                          class="form-control"
                                          rows="2"><?= isset($_POST['note']) ? clean($_POST['note']) : '' ?></textarea>
                            </div>

                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-plus me-2"></i>Kilo Ekle
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Hedef Bilgileri -->
                <div class="card shadow-sm mt-4">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-bullseye text-primary"></i> Hedef Bilgileri
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <span>Başlangıç:</span>
                            <span class="badge bg-secondary"><?= number_format($profile['current_weight'], 1) ?> kg</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <span>Hedef:</span>
                            <span class="badge bg-primary"><?= number_format($profile['target_weight'], 1) ?> kg</span>
                        </div>
                        <?php if(!empty($weightHistory)): ?>
                            <div class="d-flex justify-content-between align-items-center">
                                <span>Mevcut:</span>
                                <span class="badge bg-success"><?= number_format($weightHistory[0]['weight'], 1) ?> kg</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Sağ Kolon - Kilo Geçmişi ve Grafik -->
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

                <!-- Kilo Geçmişi Tablosu -->
                <div class="card shadow-sm">
                    <div class="card-header bg-light">
                        <h5 class="card-title mb-0">
                            <i class="fas fa-history text-primary"></i> Kilo Geçmişi
                        </h5>
                    </div>
                    <div class="card-body">
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
                                        <td><?= htmlspecialchars($record['note']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
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
        const weights = weightData.map(item => parseFloat(item.weight));
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