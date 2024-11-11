<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// includes/header.php başına eklenecek:
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

checkProfileSetup();
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= SITE_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="<?= SITE_URL ?>/assets/css/style.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg sticky-top">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center" href="<?= SITE_URL ?>">
            <i class="fas fa-heartbeat text-primary me-2"></i>
            <span><?= SITE_NAME ?></span>
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <i class="fas fa-bars"></i>
        </button>

        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto">
                <?php if(isLoggedIn()): ?>
                    <!-- Profil -->
                    <li class="nav-item">
                        <a class="nav-link" href="<?= SITE_URL ?>/modules/profile/view.php">
                            <i class="fas fa-user-circle"></i> Profilim
                        </a>
                    </li>

                    <!-- Öğünler Menüsü -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-utensils"></i> Öğünler
                        </a>
                        <ul class="dropdown-menu">
                            <li>
                                <a class="dropdown-item" href="<?= SITE_URL ?>/modules/meals/add_meal.php">
                                    <i class="fas fa-plus-circle"></i> Öğün Ekle
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="<?= SITE_URL ?>/modules/meals/view_meals.php">
                                    <i class="fas fa-list"></i> Öğünlerim
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="<?= SITE_URL ?>/modules/meals/meal_history.php">
                                    <i class="fas fa-history"></i> Öğün Geçmişi
                                </a>
                            </li>
                        </ul>
                    </li>

                    <!-- Diyet Programı Menüsü -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-calendar-alt"></i> Diyet Programı
                        </a>
                        <ul class="dropdown-menu">
                            <li>
                                <a class="dropdown-item" href="<?= SITE_URL ?>/modules/diet/daily_plan.php">
                                    <i class="fas fa-calendar-day"></i> Günlük Plan
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="<?= SITE_URL ?>/modules/diet/weekly_plan.php">
                                    <i class="fas fa-calendar-week"></i> Haftalık Plan
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="<?= SITE_URL ?>/modules/diet/monthly_plan.php">
                                    <i class="fas fa-calendar"></i> Aylık Plan
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item" href="<?= SITE_URL ?>/modules/diet/generate_plan.php">
                                    <i class="fas fa-magic"></i> Yeni Plan Oluştur
                                </a>
                            </li>
                        </ul>
                    </li>

                    <!-- Takipler Menüsü -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-chart-line"></i> Takipler
                        </a>
                        <ul class="dropdown-menu">
                            <li>
                                <a class="dropdown-item" href="<?= SITE_URL ?>/modules/tracking/water.php">
                                    <i class="fas fa-tint"></i> Su Takibi
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="<?= SITE_URL ?>/modules/tracking/sleep.php">
                                    <i class="fas fa-bed"></i> Uyku Takibi
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="<?= SITE_URL ?>/modules/tracking/weight.php">
                                    <i class="fas fa-weight"></i> Kilo Takibi
                                </a>
                            </li>
                        </ul>
                    </li>

                    <!-- Raporlar Menüsü -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-file-alt"></i> Raporlar
                        </a>
                        <ul class="dropdown-menu">
                            <li>
                                <a class="dropdown-item" href="<?= SITE_URL ?>/modules/reports/nutrition.php">
                                    <i class="fas fa-pizza-slice"></i> Beslenme Analizi
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="<?= SITE_URL ?>/modules/reports/progress.php">
                                    <i class="fas fa-chart-line"></i> İlerleme Raporu
                                </a>
                            </li>
                        </ul>
                    </li>
                <?php endif; ?>
            </ul>

            <ul class="navbar-nav align-items-center">
                <!-- Tema Değiştirici -->


                <?php if(isLoggedIn()): ?>


                    <!-- Kullanıcı Menüsü -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" role="button" data-bs-toggle="dropdown">
                            <span class="d-none d-md-inline"><?= $_SESSION['user_name'] ?? 'Kullanıcı' ?></span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li>
                                <a class="dropdown-item" href="<?= SITE_URL ?>/modules/profile/edit.php">
                                    <i class="fas fa-user-edit"></i> Profili Düzenle
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="<?= SITE_URL ?>/modules/profile/settings.php">
                                    <i class="fas fa-cog"></i> Ayarlar
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item text-danger" href="<?= SITE_URL ?>/modules/auth/logout.php">
                                    <i class="fas fa-sign-out-alt"></i> Çıkış Yap
                                </a>
                            </li>
                        </ul>
                    </li>
                <?php else: ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= SITE_URL ?>/modules/auth/login.php">
                            <i class="fas fa-sign-in-alt"></i> Giriş
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link btn btn-primary text-white ms-2" href="<?= SITE_URL ?>/modules/auth/register.php">
                            <i class="fas fa-user-plus"></i> Kayıt Ol
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>
<main>