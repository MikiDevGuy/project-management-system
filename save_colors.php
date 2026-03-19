<?php
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = file_get_contents('php://input');
    $colors = json_decode($input, true);
    
    if ($colors) {
        $_SESSION['dashboard_colors'] = $colors;
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false]);
    }
}
?>