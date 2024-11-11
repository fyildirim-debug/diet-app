<?php
// Database Configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'your_database_user');
define('DB_PASS', 'your_database_password');
define('DB_NAME', 'diet_app');

// OpenAI Configuration
define('OPENAI_API_KEY', 'your-api-key-here');
define('OPENAI_API_ENDPOINT', 'https://api.openai.com/v1/chat/completions');
define('OPENAI_MODEL', 'gpt-3.5-turbo');

// Site Configuration
define('SITE_URL', 'http://localhost/diet-app');
define('SITE_NAME', 'Diet App');

// Session Configuration
session_start();

// Error Reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Timezone
date_default_timezone_set('Europe/Istanbul');

if (!isset($_SESSION['theme'])) {
    $_SESSION['theme'] = 'light'; // varsayılan tema
}