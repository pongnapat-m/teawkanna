<?php
if (session_status() === PHP_SESSION_NONE) session_start();
session_destroy();
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time()-3600, '/');
}
header("Location: /tkn/login");
exit();
?>