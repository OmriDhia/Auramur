<?php
namespace UNIV_SEARCH;

if (!defined('ABSPATH')) exit;

class Settings {
  public static function init() {
    add_action('admin_menu', function(){
      add_options_page('Universal Search', 'Universal Search', 'manage_options', 'univ-search', [__CLASS__, 'page']);
    });
    add_action('admin_init', [__CLASS__, 'register']);
  }
  public static function register() {
    register_setting('univ_search', 'univ_search_options');
    add_settings_section('univ_search_main', 'API Settings', '__return_false', 'univ-search');
    foreach ([
      'openai_key'=>'OpenAI API Key',
      'typesense_key'=>'Typesense API Key',
      'typesense_host'=>'Typesense Host',
      'typesense_port'=>'Typesense Port',
      'typesense_proto'=>'Typesense Protocol (http/https)',
      'typesense_collection'=>'Typesense Collection',
    ] as $key=>$label) {
      add_settings_field($key, $label, function() use ($key){
        $v = esc_attr(self::get($key,''));
        printf('<input type="text" name="univ_search_options[%s]" value="%s" class="regular-text" />',$key,$v);
      }, 'univ-search', 'univ_search_main');
    }
  }
  public static function page() {
    ?>
    <div class="wrap"><h1>Universal Search Settings</h1>
      <form method="post" action="options.php">
        <?php settings_fields('univ_search'); do_settings_sections('univ-search'); submit_button(); ?>
      </form>
      <p><strong>Note:</strong> Install Composer dependencies in this plugin directory: <code>composer require typesense/typesense-php</code></p>
    </div>
    <?php
  }
  public static function get($key,$default=null){
    $opt = get_option('univ_search_options', []);
    return $opt[$key] ?? $default;
  }
}
