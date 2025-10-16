<?php
namespace UNIV_SEARCH;

if (!defined('ABSPATH')) exit;

class Render {
  public static function init() {
    add_action('wp_enqueue_scripts', [__CLASS__, 'assets']);
    add_filter('wp_nav_menu_items', [__CLASS__, 'inject_menu_widget'], 10, 2);
    add_action('init', [__CLASS__, 'register_results_route']);
    add_filter('template_include', [__CLASS__, 'results_template']);
  }

  public static function assets() {
    wp_enqueue_style('univ-search', plugins_url('../assets/css/search.css', __FILE__), [], '0.1.0');
    wp_enqueue_script('univ-search', plugins_url('../assets/js/search.js', __FILE__), ['wp-i18n'], '0.1.0', true);
    wp_localize_script('univ-search', 'UnivSearch', [
      'restBase' => esc_url_raw(rest_url('univ-search/v1')),
      'nonce' => wp_create_nonce('wp_rest'),
      'indexedPostTypes' => Typesense::get_indexable_post_types(),
      'resultsBase' => esc_url_raw(home_url('/search-all/')),
      'currency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'USD',
    ]);
  }

  public static function inject_menu_widget($items, $args) {
    if (!isset($args->theme_location) || $args->theme_location !== 'primary') return $items;
    ob_start(); ?>
      <li class="menu-item univ-search-item">
        <button class="us-toggle" aria-expanded="false" aria-controls="us-panel">Search</button>
        <div id="us-panel" class="us-panel" hidden>
          <div class="us-modes" role="tablist">
            <button role="tab" data-mode="text" aria-selected="true">Text</button>
            <button role="tab" data-mode="voice">Voice</button>
            <button role="tab" data-mode="image">Image</button>
          </div>
          <div class="us-mode us-mode-text">
            <form class="us-form-text">
              <input name="q" type="search" placeholder="Search…" autocomplete="off" />
              <button type="submit">Go</button>
            </form>
            <div class="us-instant" hidden>
              <ul class="us-instant-list" role="listbox"></ul>
              <a class="us-instant-more button" href="#">View more results</a>
            </div>
          </div>
          <div class="us-mode us-mode-voice" hidden>
            <button class="us-voice-start" type="button">🎤 Start</button>
            <button class="us-voice-stop" type="button" disabled>■ Stop</button>
            <div class="us-voice-status" aria-live="polite"></div>
          </div>
          <div class="us-mode us-mode-image" hidden>
            <form class="us-form-image">
              <input type="file" name="image" accept="image/*" />
              <button type="submit">Search by image</button>
            </form>
          </div>
        </div>
      </li>
    <?php
    return $items . ob_get_clean();
  }

  public static function register_results_route() {
    add_rewrite_rule('^search-all/?', 'index.php?univ_search=1', 'top');
    add_rewrite_tag('%univ_search%', '1');
  }

  public static function results_template($template) {
    if (get_query_var('univ_search') !== '1') return $template;
    return plugin_dir_path(__FILE__) . '../templates/results.php';
  }
}
