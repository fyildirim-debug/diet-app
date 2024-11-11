<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// Giriş kontrolü
requireLogin();

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['program_id'])) {
    try {
        $db = new Database();
        $conn = $db->getConnection();

        $stmt = $conn->prepare("DELETE FROM diet_programs WHERE id = ? AND user_id = ?");
        $stmt->execute([clean($_POST['program_id']), $_SESSION['user_id']]);

        $_SESSION['success'] = 'Program başarıyla silindi.';
    } catch (PDOException $e) {
        error_log("Delete Program Error: " . $e->getMessage());
        $_SESSION['error'] = 'Program silinirken bir hata oluştu.';
    }
}

redirect('/modules/diet/generate_plan.php');