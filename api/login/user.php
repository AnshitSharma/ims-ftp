<?php
require_once(__DIR__ . '/../../includes/db_config.php');
require_once(__DIR__ . '/../../includes/QueryModel.php');
require_once(__DIR__ . '/../../includes/BaseFunctions.php');

session_start();

// Check if user is logged in
if (!isUserLoggedIn($pdo)) {
  session_unset();
  session_destroy();
  header("Location: /bdc_ims/api/login/login.php");
  exit();
}

// Redirect to the new dashboard
header("Location: /bdc_ims/api/login/dashboard.php");
exit();
?>