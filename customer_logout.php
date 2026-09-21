<?php
session_start();

unset(
    $_SESSION['user_id'],
    $_SESSION['user_fullname'],
    $_SESSION['user_email'],
    $_SESSION['user_mobile'],
    $_SESSION['pending_booking'],
    $_SESSION['payment_receipt']
);

header('Location: home.html');
exit;