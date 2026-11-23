<?php
require_once dirname(__DIR__) . '/includes/auth.php';
auth_logout();
redirect('index.php');
