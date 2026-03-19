<?php
if (!function_exists('hasRole')) {
    function hasRole($role) {
        global $conn;
        if (!isset($_SESSION['user_id'])) return false;

        $user_id = $_SESSION['user_id'];
        $stmt = $conn->prepare("SELECT system_role FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();
            $user_role = $user['system_role'];

            if ($role === 'admin') {
                return in_array($user_role, ['admin', 'super_admin']);
            } elseif ($role === 'super_admin') {
                return $user_role === 'super_admin';
            }
        }
        return false;
    }
}
