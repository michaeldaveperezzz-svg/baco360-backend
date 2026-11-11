<?php
define('PREVENT_DIRECT_ACCESS', TRUE);

/*
 * ------------------------------------------------------
 * CORS Configuration
 * ------------------------------------------------------
 */

$allowed_origins = 'http://localhost:5173';

$frontend_url = getenv('FRONTEND_URL');
if ($frontend_url !== false && trim($frontend_url) !== '') {
    $allowed_origins = rtrim(trim($frontend_url), '/');
}

$request_origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (
    $request_origin !== '' &&
    $request_origin === $allowed_origins
) {
    header("Access-Control-Allow-Origin: $request_origin");
    header("Access-Control-Allow-Credentials: true");
    header("Vary: Origin");
}

header("Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Max-Age: 86400");

// Handle browser preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/*
 * ---------------------------------------------------------------
 * SYSTEM DIRECTORY NAME
 * ---------------------------------------------------------------
 */

$system_path = 'scheme';

/*
 * ---------------------------------------------------------------
 * APPLICATION DIRECTORY NAME
 * ---------------------------------------------------------------
 */

$application_folder = 'app';

/*
 * ---------------------------------------------------------------
 * PUBLIC DIRECTORY NAME
 * ---------------------------------------------------------------
 */

$public_folder = 'public';

/*
 * ------------------------------------------------------
 * Define Application Constants
 * ------------------------------------------------------
 */

define('ROOT_DIR', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('SYSTEM_DIR', ROOT_DIR . $system_path . DIRECTORY_SEPARATOR);
define('APP_DIR', ROOT_DIR . $application_folder . DIRECTORY_SEPARATOR);
define('PUBLIC_DIR', ROOT_DIR . $public_folder . DIRECTORY_SEPARATOR);

/*
 * ------------------------------------------------------
 * Load LavaLust Framework
 * ------------------------------------------------------
 */

require_once SYSTEM_DIR . 'kernel/LavaLust.php';
