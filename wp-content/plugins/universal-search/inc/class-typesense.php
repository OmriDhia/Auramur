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

  public static function collection_schema(): array {
    return [
      'name' => self::collection_name(),
      'fields' => [
        ['name' => 'id',          'type' => 'string'],
        ['name' => 'title',       'type' => 'string'],
        ['name' => 'content',     'type' => 'string'],
        ['name' => 'excerpt',     'type' => 'string'],
        ['name' => 'permalink',   'type' => 'string'],
        ['name' => 'image',       'type' => 'string'],
        ['name' => 'post_type',   'type' => 'string',   'facet' => true],
        ['name' => 'categories',  'type' => 'string[]', 'facet' => true],
        ['name' => 'tags',        'type' => 'string[]', 'facet' => true],
        ['name' => 'product_cat', 'type' => 'string[]', 'facet' => true],
        ['name' => 'brand',       'type' => 'string[]', 'facet' => true],
        ['name' => 'sku',         'type' => 'string'],
        ['name' => 'price',       'type' => 'float'],
        ['name' => 'popularity',  'type' => 'float'],
        ['name' => 'timestamp',   'type' => 'int64'],
        ['name' => 'author',      'type' => 'string'],
      ],
      'default_sorting_field' => 'timestamp',
    ];
  }

  public static function schema_matches(array $existing): bool {
    $expected = self::collection_schema();
    $expected_fields = array_map(static function ($field) {
      return is_array($field) && isset($field['name']) ? $field['name'] : '';
    }, $expected['fields'] ?? []);

    $existing_fields = array_map(static function ($field) {
      return is_array($field) && isset($field['name']) ? $field['name'] : '';
    }, $existing['fields'] ?? []);

    foreach ($expected_fields as $field_name) {
      if ($field_name === '') {
        continue;
      }
      if (!in_array($field_name, $existing_fields, true)) {
        return false;
      }
    }

    $expected_sort = $expected['default_sorting_field'] ?? '';
    if ($expected_sort && ($existing['default_sorting_field'] ?? '') !== $expected_sort) {
      return false;
    }

    return true;
  }

  public static function ensure_collection() {
    $client = self::client();
    if (!$client || self::$collectionEnsured) {
      return;
    }
    $collection = self::collection_name();
    $schema = self::collection_schema();

    try {
      $existing = $client->collections[$collection]->retrieve();
      if (!self::schema_matches($existing)) {
        try {
          $client->collections[$collection]->delete();
        } catch (\Throwable $delete_exception) {
          error_log('Universal Search Typesense schema delete error: ' . $delete_exception->getMessage());
        }
        $client->collections->create($schema);
      }
      self::$collectionEnsured = true;
      return;
    } catch (\Throwable $e) {
      // Fall through to creation
    }

    try {
      $client->collections->create($schema);
      self::$collectionEnsured = true;
    } catch (\Throwable $ex) {
      error_log('Universal Search Typesense collection error: ' . $ex->getMessage());
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
      'query_by' => 'title,content,excerpt,tags,categories,product_cat,brand,sku',
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
    foreach (['categories', 'tags', 'product_cat', 'brand'] as $listField) {
      if (!empty($f[$listField]) && is_array($f[$listField])) {
        $vals = array_map(function ($v) { return addslashes($v); }, $f[$listField]);
        $parts[] = $listField . ':=[' . implode(',', array_map(function ($v) { return '"' . $v . '"'; }, $vals)) . ']';
      }
    }
    if (!empty($f['taxonomy']) && is_array($f['taxonomy'])) {
      foreach ($f['taxonomy'] as $tax=>$vals) {
        $vals = array_map(function($v){ return addslashes($v); }, (array)$vals);
        $parts[] = $tax . ':=[' . implode(',', array_map(function($v){ return '"'.$v.'"'; }, $vals)) . ']';
      }
    }
    if (!empty($f['price']['gte'])) $parts[] = 'price:>=' . floatval($f['price']['gte']);
    if (!empty($f['price']['lte'])) $parts[] = 'price:<=' . floatval($f['price']['lte']);
    if (!empty($f['popularity']['gte'])) $parts[] = 'popularity:>=' . floatval($f['popularity']['gte']);
    if (!empty($f['popularity']['lte'])) $parts[] = 'popularity:<=' . floatval($f['popularity']['lte']);
    if (!empty($f['timestamp']['gte'])) $parts[] = 'timestamp:>=' . intval($f['timestamp']['gte']);
    if (!empty($f['timestamp']['lte'])) $parts[] = 'timestamp:<=' . intval($f['timestamp']['lte']);
    if (!empty($f['sku'])) {
      $sku = addslashes((string) $f['sku']);
      if ($sku !== '') {
        $parts[] = 'sku:="' . $sku . '"';
      }
    }
    return implode(' && ', array_filter($parts));
  }

  private static function build_sort(array $s): string {
    if (!$s) return '';
    $first = $s[0];
    $field = preg_replace('/[^a-z0-9_]/i', '', $first['field'] ?? 'timestamp');
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

  public static function build_document(\WP_Post $post) {
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
      'categories'  => [],
      'tags'        => [],
      'product_cat' => [],
      'brand'       => [],
      'author'      => get_the_author_meta('display_name', $post->post_author),
      'price'       => 0.0,
      'sku'         => '',
      'popularity'  => 0.0,
      'timestamp'   => (int) get_post_time('U', true, $post),
    ];

    $terms = get_object_taxonomies($post->post_type, 'objects');
    foreach ($terms as $taxonomy => $obj) {
      if (!is_taxonomy_viewable($taxonomy)) {
        continue;
      }
      $slugs = wp_get_post_terms($post->ID, $taxonomy, ['fields' => 'slugs']);
      if (empty($slugs) || is_wp_error($slugs)) continue;
      $sanitized = array_values(array_unique(array_filter(array_map('sanitize_title', (array) $slugs))));
      if (empty($sanitized)) continue;

      if ($taxonomy === 'product_cat') {
        $document['product_cat'] = array_values(array_unique(array_merge($document['product_cat'], $sanitized)));
        continue;
      }

      if (in_array($taxonomy, ['product_brand', 'pa_brand'], true)) {
        $document['brand'] = array_values(array_unique(array_merge($document['brand'], $sanitized)));
        continue;
      }

      if ($obj->hierarchical) {
        $document['categories'] = array_values(array_unique(array_merge($document['categories'], $sanitized)));
      } else {
        $document['tags'] = array_values(array_unique(array_merge($document['tags'], $sanitized)));
      }
    }

    if (function_exists('wc_get_product') && 'product' === $post->post_type) {
      $product = wc_get_product($post->ID);
      if ($product) {
        $document['price'] = (float) $product->get_price();
        $document['sku'] = (string) $product->get_sku();
        if (!$document['image'] && $product->get_image_id()) {
          $document['image'] = wp_get_attachment_url($product->get_image_id()) ?: '';
        }
      }

      $total_sales   = (float) get_post_meta($post->ID, 'total_sales', true);
      $review_count  = (float) get_post_meta($post->ID, '_wc_review_count', true);
      $average_rating = (float) get_post_meta($post->ID, '_wc_average_rating', true);
      $document['popularity'] = max(0.0, $total_sales) + max(0.0, $review_count) + max(0.0, $average_rating) / 5;
    }

    if (empty($document['brand']) && taxonomy_exists('product_brand')) {
      $brand_slugs = self::get_term_slugs($post->ID, 'product_brand');
      if ($brand_slugs) {
        $document['brand'] = $brand_slugs;
      }
    }
    if (empty($document['brand']) && taxonomy_exists('pa_brand')) {
      $brand_slugs = self::get_term_slugs($post->ID, 'pa_brand');
      if ($brand_slugs) {
        $document['brand'] = $brand_slugs;
      }
    }

    return $document;
  }

  public static function basic_search(array $payload) {
    $query = isset($payload['query']) ? sanitize_text_field($payload['query']) : '';
    if ($query === '') {
      return [
        'hits' => [],
        'found' => 0,
        'page' => 1,
        'fallback' => true,
      ];
    }

    $limit = max(1, min(50, intval($payload['limit'] ?? 5)));
    $page  = max(1, intval($payload['page'] ?? 1));

    $filters = is_array($payload['filters'] ?? null) ? $payload['filters'] : [];
    $postTypes = [];
    if (!empty($filters['post_type']) && is_array($filters['post_type'])) {
      $postTypes = array_filter(array_map('sanitize_key', $filters['post_type']));
    }
    if (empty($postTypes)) {
      $postTypes = self::get_indexable_post_types();
    }

    if (!class_exists('\WP_Query')) {
      return null;
    }

    $args = [
      's' => $query,
      'post_type' => $postTypes,
      'posts_per_page' => $limit,
      'paged' => $page,
      'post_status' => 'publish',
      'no_found_rows' => false,
      'ignore_sticky_posts' => true,
    ];

    $wp_query = new \WP_Query($args);
    $hits = [];

    if ($wp_query->have_posts()) {
      foreach ($wp_query->posts as $post) {
        $post_id = $post->ID;
        $post_type = get_post_type($post_id);
        $thumbnail = get_the_post_thumbnail_url($post_id, 'medium');
        $categories = self::get_term_slugs($post_id, 'category');
        $tags = self::get_term_slugs($post_id, 'post_tag');

        $product_cats = [];
        $brand_terms = [];
        if ($post_type === 'product') {
          $product_cats = self::get_term_slugs($post_id, 'product_cat');
          if ($product_cats) {
            $categories = array_values(array_unique(array_merge($categories, $product_cats)));
          }
          $product_tags = self::get_term_slugs($post_id, 'product_tag');
          if ($product_tags) {
            $tags = array_values(array_unique(array_merge($tags, $product_tags)));
          }
          $brand_terms = array_values(array_unique(array_merge(
            self::get_term_slugs($post_id, 'product_brand'),
            self::get_term_slugs($post_id, 'pa_brand')
          )));
        }

        $timestamp = function_exists('get_post_timestamp')
          ? get_post_timestamp($post_id)
          : strtotime(get_post_time('c', true, $post_id));

        $document = [
          'id' => (string) $post_id,
          'title' => get_the_title($post_id),
          'excerpt' => wp_strip_all_tags(get_the_excerpt($post_id)),
          'content' => '',
          'permalink' => get_permalink($post_id),
          'image' => $thumbnail ?: '',
          'post_type' => $post_type,
          'timestamp' => $timestamp ?: 0,
          'categories' => $categories,
          'tags' => $tags,
          'product_cat' => $product_cats,
          'brand' => $brand_terms,
          'author' => get_the_author_meta('display_name', $post->post_author),
          'price' => 0.0,
          'sku' => '',
          'popularity' => max(0, (int) get_comments_number($post_id)),
        ];

        if ($post_type === 'product' && function_exists('wc_get_product')) {
          $product = wc_get_product($post_id);
          if ($product) {
            $document['price'] = (float) $product->get_price();
            $document['sku'] = (string) $product->get_sku();
            $total_sales   = (float) get_post_meta($post_id, 'total_sales', true);
            $review_count  = (float) get_post_meta($post_id, '_wc_review_count', true);
            $average_rating = (float) get_post_meta($post_id, '_wc_average_rating', true);
            $document['popularity'] = max(0.0, $total_sales) + max(0.0, $review_count) + max(0.0, $average_rating) / 5;
          }
        }

        $hits[] = [
          'document' => $document,
          'highlights' => [],
        ];
      }
      wp_reset_postdata();
    }

    return [
      'hits' => $hits,
      'found' => intval($wp_query->found_posts),
      'page' => $page,
      'fallback' => true,
    ];
  }

  private static function get_term_slugs($post_id, $taxonomy) {
    $terms = get_the_terms($post_id, $taxonomy);
    if (!is_array($terms)) {
      return [];
    }
    return array_values(array_unique(array_map(function ($term) {
      return sanitize_title($term->slug ?? '');
    }, array_filter($terms, function ($term) {
      return $term instanceof \WP_Term;
    }))));
  }
}
