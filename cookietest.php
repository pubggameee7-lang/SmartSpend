<?php
session_set_cookie_params([
  'lifetime' => 86400,
  'path' => '/',
  'secure' => false,
  'httponly' => true,
  'samesite' => 'Lax'
]);
session_start();
$_SESSION['test'] = 1;
echo 'OK';