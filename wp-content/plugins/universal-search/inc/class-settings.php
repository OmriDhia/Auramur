<?php
namespace UNIV_SEARCH;

if (!defined('ABSPATH')) exit;

class Settings {
  public static function init() {
    add_action('init', [__CLASS__, 'bootstrap_defaults']);
    add_action('admin_menu', function(){
      add_options_page('Universal Search', 'Universal Search', 'manage_options', 'univ-search', [__CLASS__, 'page']);
    });
    add_action('admin_init', [__CLASS__, 'register']);
  }
  public static function register() {
    register_setting('univ_search', 'univ_search_options', [
      'sanitize_callback' => [__CLASS__, 'sanitize'],
      'default' => [],
    ]);

    add_settings_section('univ_search_main', 'API Settings', '__return_false', 'univ-search');
    foreach ([
      'openai_key'          => 'OpenAI API Key',
      'typesense_key'       => 'Typesense API Key',
      'typesense_host'      => 'Typesense Host',
      'typesense_port'      => 'Typesense Port',
      'typesense_proto'     => 'Typesense Protocol (http/https)',
      'typesense_collection'=> 'Typesense Collection',
    ] as $key=>$label) {
      add_settings_field($key, $label, function() use ($key){
        $v = esc_attr(self::get($key,''));
        printf('<input type="text" name="univ_search_options[%s]" value="%s" class="regular-text" />',$key,$v);
      }, 'univ-search', 'univ_search_main');
    }

    add_settings_section('univ_search_indexing', 'Indexing Settings', '__return_false', 'univ-search');
    add_settings_field('typesense_post_types', 'Post types to index', [__CLASS__, 'field_post_types'], 'univ-search', 'univ_search_indexing');
  }

  public static function page() {
    ?>
    <div class="wrap"><h1>Universal Search Settings</h1>
      <form method="post" action="options.php">
        <?php settings_fields('univ_search'); do_settings_sections('univ-search'); submit_button(); ?>
      </form>
      <p><strong>Note:</strong> Install Composer dependencies in this plugin directory: <code>composer install</code></p>
    </div>
    <?php
  }

  public static function get($key,$default=null){
    $opt = get_option('univ_search_options', []);
    return $opt[$key] ?? $default;
  }

  public static function field_post_types() {
    $selected = (array) self::get('typesense_post_types', []);
    $post_types = get_post_types(['public' => true], 'objects');
    if (empty($post_types)) {
      echo '<p>' . esc_html__('No public post types found.', 'universal-search') . '</p>';
      return;
    }
    echo '<fieldset><legend class="screen-reader-text">' . esc_html__('Post types to index', 'universal-search') . '</legend>';
    foreach ($post_types as $type => $obj) {
      if (in_array($type, ['attachment', 'nav_menu_item', 'revision'], true)) continue;
      $checked = in_array($type, $selected, true) ? 'checked' : '';
      printf(
        '<label style="display:block;margin-bottom:.35rem"><input type="checkbox" name="univ_search_options[typesense_post_types][]" value="%1$s" %3$s /> %2$s</label>',
        esc_attr($type),
        esc_html($obj->labels->singular_name ?? $type),
        $checked
      );
    }
    echo '</fieldset>';
    echo '<p class="description">' . esc_html__('Choose the post types that should be indexed in Typesense (e.g. Posts, Pages, Products).', 'universal-search') . '</p>';
  }

  public static function sanitize($opts) {
    $opts = is_array($opts) ? $opts : [];
    $sanitized = [];
    foreach (['openai_key','typesense_key','typesense_host','typesense_port','typesense_proto','typesense_collection'] as $key) {
      if (isset($opts[$key])) {
        $sanitized[$key] = sanitize_text_field($opts[$key]);
      }
    }
    if (!empty($opts['typesense_post_types']) && is_array($opts['typesense_post_types'])) {
      $sanitized['typesense_post_types'] = array_values(array_unique(array_filter(array_map('sanitize_key', $opts['typesense_post_types']))));
    } else {
      $sanitized['typesense_post_types'] = [];
    }
    return $sanitized;
  }

  public static function bootstrap_defaults() {
    if (!function_exists('get_option')) {
      return;
    }

    $defaults = [
      'typesense_host'       => getenv('TYPESENSE_HOST') ?: 'typesense',
      'typesense_port'       => getenv('TYPESENSE_PORT') ?: '8108',
      'typesense_proto'      => getenv('TYPESENSE_PROTOCOL') ?: 'http',
      'typesense_key'        => getenv('TYPESENSE_API_KEY') ?: 'eSiSArntEnTinEQuOunCutaIGEtoReag',
      'typesense_collection' => getenv('TYPESENSE_COLLECTION') ?: 'site_content',
    ];

    $options = get_option('univ_search_options', []);
    $options = is_array($options) ? $options : [];
    $updated = false;

    foreach ($defaults as $key => $value) {
      if (empty($options[$key]) && !empty($value)) {
        $options[$key] = $value;
        $updated = true;
      }
    }

    if (empty($options['typesense_post_types']) && post_type_exists('product')) {
      $options['typesense_post_types'] = ['product'];
      $updated = true;
    }

    if ($updated) {
      update_option('univ_search_options', $options);
    }
  }
}
