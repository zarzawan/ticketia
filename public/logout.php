<?php
require_once __DIR__ . '/../src/arranque.php';

auth_logout($pdo);
header('Location: login.php');
exit;
