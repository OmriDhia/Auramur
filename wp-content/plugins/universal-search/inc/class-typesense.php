<?php
namespace UNIV_SEARCH;
use Typesense\Client;

if (!defined('ABSPATH')) exit;

class Typesense {
  private static $client;
  private static $collectionEnsured = false;

  public static function init() {
    // Autoload vendor if present
    $vendor = dirname(__FILE__,2).'/vendor/autoload.php';
    if (file_exists($vendor)) {
      require_once $vendor;
    }

    add_action('update_option_univ_search_options', [__CLASS__, 'handle_option_update'], 10, 2);
    add_action('univ_search_sync_all', [__CLASS__, 'sync_all']);

    if (!self::client()) {
      return;
    }

    add_action('init', [__CLASS__, 'ensure_collection']);
    add_action('save_post', [__CLASS__, 'handle_save_post'], 20, 3);
    add_action('delete_post', [__CLASS__, 'delete_post']);
    add_action('trashed_post', [__CLASS__, 'delete_post']);
    add_action('untrashed_post', [__CLASS__, 'index_post']);
    add_action('transition_post_status', [__CLASS__, 'handle_status_transition'], 10, 3);

    self::schedule_full_sync();
  }

  public static function client() {
    if (self::$client instanceof Client) {
      return self::$client;
    }
    $key = Settings::get('typesense_key');
    $host = Settings::get('typesense_host');
    $port = Settings::get('typesense_port');
    $proto= Settings::get('typesense_proto', 'https');
    if ($key && $host && $port) {
      try {
        self::$client = new Client([
          'api_key' => $key,
          'nodes'   => [['host'=>$host,'port'=>(int)$port,'protocol'=>$proto]],
          'connection_timeout_seconds' => 5
        ]);
      } catch (\Throwable $e) {
        error_log('Universal Search Typesense connection error: ' . $e->getMessage());
        self::$client = null;
      }
    }
    return self::$client;
  }

  public static function collection_name(): string {
    $name = Settings::get('typesense_collection', 'site_content');
    return $name ?: 'site_content';
  }

  public static function ensure_collection() {
    $client = self::client();
    if (!$client || self::$collectionEnsured) {
      return;
    }
    $collection = self::collection_name();
    try {
      $client->collections[$collection]->retrieve();
      self::$collectionEnsured = true;
      return;
    } catch (\Throwable $e) {
      // Create collection if missing
      try {
        $client->collections->create([
          'name' => $collection,
          'fields' => [
            ['name' => 'id',          'type' => 'string'],
            ['name' => 'title',       'type' => 'string'],
            ['name' => 'content',     'type' => 'string'],
            ['name' => 'excerpt',     'type' => 'string'],
            ['name' => 'permalink',   'type' => 'string'],
            ['name' => 'image',       'type' => 'string'],
            ['name' => 'post_type',   'type' => 'string', 'facet' => true],
            ['name' => 'date',        'type' => 'int64'],
            ['name' => 'categories',  'type' => 'string[]', 'facet' => true],
            ['name' => 'tags',        'type' => 'string[]', 'facet' => true],
            ['name' => 'author',      'type' => 'string'],
            ['name' => 'price',       'type' => 'float'],
            ['name' => 'sku',         'type' => 'string'],
            ['name' => 'stock_status','type' => 'string'],
          ],
          'default_sorting_field' => 'date',
        ]);
        self::$collectionEnsured = true;
      } catch (\Throwable $ex) {
        error_log('Universal Search Typesense collection error: ' . $ex->getMessage());
      }
    }
  }

  public static function search(array $queryJson) {
    $client = self::client();
    if (!$client) {
      throw new \Exception('Typesense not configured.');
    }
    self::ensure_collection();

    $collection = self::collection_name();
    $q = $queryJson['query'] ?? '';
    $filter_by = self::build_filter($queryJson['filters'] ?? []);
    $sort_by   = self::build_sort($queryJson['sort'] ?? []);
    $params = [
      'q' => $q ?: '*',
      'query_by' => 'title,content,excerpt,tags,categories,sku',
      'per_page' => max(1, intval($queryJson['limit'] ?? 24)),
      'page'     => max(1, intval($queryJson['page'] ?? 1)),
      'highlight_full_fields' => 'excerpt,content',
    ];
    if ($filter_by) {
      $params['filter_by'] = $filter_by;
    } else {
      $types = self::get_indexable_post_types();
      if (!empty($types)) {
        $params['filter_by'] = self::build_filter(['post_type' => $types]);
      }
    }
    if ($sort_by) $params['sort_by'] = $sort_by;

    return $client->collections[$collection]->documents->search($params);
  }

  private static function build_filter(array $f): string {
    $parts = [];
    if (!empty($f['post_type']) && is_array($f['post_type'])) {
      $vals = array_map(function($v){ return addslashes($v); }, $f['post_type']);
      $parts[] = 'post_type:=['.implode(',', array_map(function($v){ return '"'.$v.'"'; }, $vals)).']';
    }
    if (!empty($f['taxonomy']) && is_array($f['taxonomy'])) {
      foreach ($f['taxonomy'] as $tax=>$vals) {
        $vals = array_map(function($v){ return addslashes($v); }, (array)$vals);
        $parts[] = $tax . ':=[' . implode(',', array_map(function($v){ return '"'.$v.'"'; }, $vals)) . ']';
      }
    }
    if (!empty($f['price']['gte'])) $parts[] = 'price:>=' . floatval($f['price']['gte']);
    if (!empty($f['price']['lte'])) $parts[] = 'price:<=' . floatval($f['price']['lte']);
    return implode(' && ', array_filter($parts));
  }

  private static function build_sort(array $s): string {
    if (!$s) return '';
    $first = $s[0];
    $field = preg_replace('/[^a-z0-9_]/i', '', $first['field'] ?? 'date');
    $order = (isset($first['order']) && strtolower($first['order']) === 'asc') ? 'asc' : 'desc';
    return $field . ':' . $order;
  }

  public static function get_indexable_post_types(): array {
    $types = Settings::get('typesense_post_types', []);
    if (empty($types)) {
      $types = ['post'];
      if (post_type_exists('product')) {
        $types[] = 'product';
      }
    }
    return array_values(array_unique(array_map('sanitize_key', (array) $types)));
  }

  public static function handle_save_post($post_id, $post, $update) {
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
      return;
    }
    self::index_post($post_id, $post);
  }

  public static function handle_status_transition($new_status, $old_status, $post) {
    if (!is_object($post)) return;
    if ('publish' !== $new_status) {
      self::delete_post($post->ID);
    }
  }

  public static function index_post($post_id, $post = null) {
    $client = self::client();
    if (!$client) return;
    $post = $post instanceof \WP_Post ? $post : get_post($post_id);
    if (!$post || 'revision' === $post->post_type) return;
    $types = self::get_indexable_post_types();
    if (!in_array($post->post_type, $types, true)) {
      self::delete_post($post_id);
      return;
    }
    if ('publish' !== $post->post_status) {
      self::delete_post($post_id);
      return;
    }

    self::ensure_collection();

    $document = self::build_document($post);
    if (!$document) {
      return;
    }

    try {
      $client->collections[self::collection_name()]->documents->upsert($document);
    } catch (\Throwable $e) {
      error_log('Universal Search index error: ' . $e->getMessage());
    }
  }

  public static function delete_post($post_id) {
    $client = self::client();
    if (!$client) return;
    try {
      $client->collections[self::collection_name()]->documents[(string) $post_id]->delete();
    } catch (\Throwable $e) {
      // swallow missing document errors
    }
  }

  public static function handle_option_update($old_value, $value) {
    if (!self::client()) {
      return;
    }

    $old_types = isset($old_value['typesense_post_types']) ? (array) $old_value['typesense_post_types'] : [];
    $new_types = isset($value['typesense_post_types']) ? (array) $value['typesense_post_types'] : [];
    $removed = array_diff($old_types, $new_types);
    if ($removed) {
      self::delete_by_post_type($removed);
    }
    self::schedule_full_sync();
    self::$collectionEnsured = false; // refresh schema when settings change
  }

  protected static function delete_by_post_type(array $post_types) {
    $client = self::client();
    if (!$client || empty($post_types)) return;
    $filter = 'post_type:=[' . implode(',', array_map(function($type){ return '"'.addslashes($type).'"'; }, $post_types)) . ']';
    try {
      $client->collections[self::collection_name()]->documents->delete(['filter_by' => $filter]);
    } catch (\Throwable $e) {
      error_log('Universal Search bulk delete error: ' . $e->getMessage());
    }
  }

  protected static function schedule_full_sync() {
    if (!function_exists('wp_next_scheduled')) return;
    if (!wp_next_scheduled('univ_search_sync_all')) {
      wp_schedule_single_event(time() + 5, 'univ_search_sync_all');
    }
  }

  public static function sync_all() {
    $client = self::client();
    if (!$client) return;
    self::ensure_collection();
    $types = self::get_indexable_post_types();
    if (empty($types)) return;

    $args = [
      'post_type'      => $types,
      'post_status'    => 'publish',
      'posts_per_page' => 100,
      'orderby'        => 'ID',
      'order'          => 'ASC',
      'no_found_rows'  => true,
    ];
    $page = 1;
    do {
      $query = new \WP_Query(array_merge($args, ['paged' => $page, 'fields' => 'ids']));
      if (empty($query->posts)) {
        break;
      }
      foreach ($query->posts as $post_id) {
        $post = get_post($post_id);
        if ($post) {
          self::index_post($post->ID, $post);
        }
      }
      $page++;
      wp_reset_postdata();
    } while (count($query->posts) === $args['posts_per_page']);
  }

  protected static function build_document(\WP_Post $post) {
    $content = apply_filters('the_content', $post->post_content);
    $clean_content = wp_strip_all_tags($content);
    $excerpt = $post->post_excerpt ?: wp_trim_words($clean_content, 40);
    $permalink = get_permalink($post);
    if (!$permalink) {
      return null;
    }

    $document = [
      'id'          => (string) $post->ID,
      'title'       => html_entity_decode(get_the_title($post), ENT_QUOTES | ENT_HTML5, get_bloginfo('charset')),
      'content'     => $clean_content,
      'excerpt'     => wp_strip_all_tags($excerpt),
      'permalink'   => $permalink,
      'image'       => get_the_post_thumbnail_url($post, 'large') ?: '',
      'post_type'   => $post->post_type,
      'date'        => (int) get_post_time('U', true, $post),
      'categories'  => [],
      'tags'        => [],
      'author'      => get_the_author_meta('display_name', $post->post_author),
      'price'       => 0.0,
      'sku'         => '',
      'stock_status'=> '',
    ];

    $terms = get_object_taxonomies($post->post_type, 'objects');
    foreach ($terms as $taxonomy => $obj) {
      if (!is_taxonomy_viewable($taxonomy)) {
        continue;
      }
      $names = wp_get_post_terms($post->ID, $taxonomy, ['fields' => 'names']);
      if (empty($names) || is_wp_error($names)) continue;
      if ($obj->hierarchical) {
        $document['categories'] = array_values(array_unique(array_merge($document['categories'], $names)));
      } else {
        $document['tags'] = array_values(array_unique(array_merge($document['tags'], $names)));
      }
    }

    if (function_exists('wc_get_product') && 'product' === $post->post_type) {
      $product = wc_get_product($post->ID);
      if ($product) {
        $document['price'] = (float) $product->get_price();
        $document['sku'] = (string) $product->get_sku();
        $document['stock_status'] = (string) $product->get_stock_status();
        if (!$document['image'] && $product->get_image_id()) {
          $document['image'] = wp_get_attachment_url($product->get_image_id()) ?: '';
        }
      }
    }

    return $document;
  }
}
