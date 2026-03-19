<?php
include 'db.php';
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = $_POST['username'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $stmt = $conn->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
    $stmt->bind_param("ss", $username, $password);
    $stmt->execute();
    header("Location: login.php");
}
?>
<form method="post">
  <h2>Register</h2>
  <input type="text" name="username" required><br>
  <input type="password" name="password" required><br>
  <!--<input type="email" name="email" required> -->
  <select name="role" required>
    <option value="admin">Admin</option>
    <option value="tester">Tester</option>
    <option value="viewer">Viewer</option>
  </select><br>
  <button type="submit">Register</button>
</form>

