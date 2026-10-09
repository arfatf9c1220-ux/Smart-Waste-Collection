<?php
/**
 * api/auth/logout.php      POST
 * Ends the session and returns where the browser should go next.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';

logout_user();
json_success('You have been logged out.', ['redirect' => '/index.php']);
