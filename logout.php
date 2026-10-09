<?php
/**
 * logout.php  —  ends the session and returns to the landing page.
 */
require_once __DIR__ . '/includes/auth.php';

logout_user();

// logout_user() destroys the session, so start a fresh one purely to carry the
// goodbye message back to the landing page.
session_start();
$_SESSION['flash'] = 'You have been logged out. Thank you for using ' . APP_SHORT . '.';

redirect('/index.php');
