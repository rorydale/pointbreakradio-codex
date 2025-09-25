<?php
/**
 * Plugin Name: Point Break Radio Core
 * Description: Core functionality for the Point Break Radio MVP, exposing REST endpoints backed by JSON seed data.
 * Version: 0.1.0
 * Author: Point Break Radio
 */

if (! defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/bootstrap.php';

\PBR\Core\bootstrap();
