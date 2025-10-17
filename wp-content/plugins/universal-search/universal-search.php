<?php
/**
 * Plugin Name: Universal Multi-Modal Search (Typesense)
 * Description: Voice / Text / Image universal search bar in primary menu with Typesense + AI.
 * Version: 0.1.0
 * Author: Webntricks
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/inc/helpers.php';
require_once __DIR__ . '/inc/class-settings.php';
require_once __DIR__ . '/inc/class-typesense.php';
require_once __DIR__ . '/inc/class-ai.php';
require_once __DIR__ . '/inc/class-rest.php';
require_once __DIR__ . '/inc/class-render.php';
require_once __DIR__ . '/inc/class-cli.php';

add_action('plugins_loaded', function () {
  \UNIV_SEARCH\Settings::init();
  \UNIV_SEARCH\Typesense::init();
  \UNIV_SEARCH\AI::init();
  \UNIV_SEARCH\REST::init();
  \UNIV_SEARCH\Render::init();
  \UNIV_SEARCH\CLI::register();
});
