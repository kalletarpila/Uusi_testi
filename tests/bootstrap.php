<?php declare(strict_types=1);

/**
 * PHPUnit Bootstrap File
 *
 * Lataa invite.php:n funktiot testejä varten
 */

// Määritä testauskonteksti
define('TESTING', true);

// Mockaa $_SERVER muuttujat ennen invite.php:n lataamista
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SCRIPT_NAME'] = '/test.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

// Aloita output buffering ennen invite.php:n lataamista
ob_start();

// Lataa pääsovellus (funktiot)
require_once __DIR__ . '/../invite.php';

// Tyhjennä ja hylkää HTML-output
ob_end_clean();

// Luo testimode data-hakemisto
define('TEST_DATA_DIR', __DIR__ . '/test_data');
if (!is_dir(TEST_DATA_DIR)) {
    mkdir(TEST_DATA_DIR, 0700, true);
}

// Mock email-lähetys testeissä
if (!function_exists('test_mode_enabled')) {
    function test_mode_enabled(): bool {
        return defined('TESTING') && TESTING === true;
    }
}
