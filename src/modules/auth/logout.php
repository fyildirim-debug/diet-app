<?php
require_once '../../config/config.php';
require_once '../../includes/functions.php';

// Oturum sonlandırma
session_start();
session_destroy();

// Çerezleri temizle
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time()-3600, '/');
}

// "Beni Hatırla" çerezini temizle
if (isset($_COOKIE['remember_token'])) {
    setcookie('remember_token', '', time()-3600, '/');
}

// Ana sayfaya yönlendir
redirect('/modules/auth/login.php');