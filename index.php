<?php
require_once 'model/Auth.php';

if (Auth::isLoggedIn()) {
    header('Location: selection_portail.php');
} else {
    header('Location: login.php');
}
exit;
