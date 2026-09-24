<?php
require_once __DIR__ . '/../includes/auth.php';

logoutUser();

header('Location: /healthqueue/index.php');
exit;
