<?php
namespace UNIV_SEARCH;

if (!defined('ABSPATH')) exit;

class Render {
  private static bool $block_injected = false;

  public static function init() {
    add_action('wp_enqueue_scripts', [__CLASS__, 'assets']);
    add_filter('wp_nav_menu_items', [__CLASS__, 'inject_menu_widget'], 10, 2);
    add_filter('render_block_core/navigation', [__CLASS__, 'inject_block_navigation_widget'], 10, 2);
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
    return $items . self::get_widget_markup('classic');
  }

  public static function inject_block_navigation_widget($block_content, $block) {
    if (self::$block_injected) return $block_content;
    if (!is_array($block) || ($block['blockName'] ?? '') !== 'core/navigation') return $block_content;

    $registered_locations = get_registered_nav_menus();
    if (!array_key_exists('primary', $registered_locations)) return $block_content;

    $marker_position = strpos($block_content, 'wp-block-navigation__container');
    if ($marker_position === false) return $block_content;

    $ul_start = strrpos(substr($block_content, 0, $marker_position), '<ul');
    if ($ul_start === false) return $block_content;

    $ul_open_end = strpos($block_content, '>', $marker_position);
    if ($ul_open_end === false) return $block_content;

    $search_markup = self::get_widget_markup('block');

    $block_content = substr($block_content, 0, $ul_open_end + 1)
      . $search_markup
      . substr($block_content, $ul_open_end + 1);

    self::$block_injected = true;

    return $block_content;
  }

  public static function register_results_route() {
    add_rewrite_rule('^search-all/?', 'index.php?univ_search=1', 'top');
    add_rewrite_tag('%univ_search%', '1');
  }

  public static function results_template($template) {
    if (get_query_var('univ_search') !== '1') return $template;
    return plugin_dir_path(__FILE__) . '../templates/results.php';
  }

  private static function get_widget_markup(string $context): string {
    $classes = ['menu-item', 'univ-search-item'];
    if ($context === 'block') {
      $classes[] = 'wp-block-navigation-item';
    }

    ob_start();
    ?>
      <li class="<?php echo esc_attr(implode(' ', $classes)); ?>">
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
    return trim(ob_get_clean());
  }
}
