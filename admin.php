<?php
require_once __DIR__ . '/admin_access.php';
requireAdminSession();
readfile(__DIR__ . '/admin.html');
