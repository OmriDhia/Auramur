<?php
namespace UNIV_SEARCH;

if (!defined('ABSPATH')) {
  exit;
}

class CLI {
  public static function register(): void {
    if (!defined('WP_CLI') || !WP_CLI) {
      return;
    }

    \WP_CLI::add_command('univ-search typesense-health', [__CLASS__, 'check_health']);
    \WP_CLI::add_command('univ-search backfill-products', [__CLASS__, 'backfill_products']);
  }

  public static function check_health($args, $assoc_args): void {
    $config = self::get_typesense_config();
    $health = self::health_status($config);

    if (!$health['ok']) {
      \WP_CLI::error(sprintf('Typesense health check failed: %s', $health['message']));
    }

    \WP_CLI::success(sprintf('Typesense at %s is healthy.', $config['base_url']));
    \WP_CLI::log(sprintf('Collection: %s', $config['collection']));
    \WP_CLI::log(sprintf('WordPress URL: %s', home_url('/')));
    $host = getenv('HOSTNAME') ?: php_uname('n');
    if (!empty($host)) {
      \WP_CLI::log(sprintf('WP-CLI container host: %s (try: docker compose exec wordpress wp)', $host));
    }
  }

  public static function backfill_products($args, $assoc_args): void {
    $config = self::get_typesense_config();

    $health = self::health_status($config);
    if (!$health['ok']) {
      \WP_CLI::error(sprintf('Typesense health check failed: %s', $health['message']));
    }

    self::ensure_collection_http($config);

    if (!post_type_exists('product')) {
      \WP_CLI::error('WooCommerce product post type is not registered.');
    }

    $per_page = isset($assoc_args['per_page']) ? max(1, (int) $assoc_args['per_page']) : 100;
    $query_args = [
      'post_type'      => 'product',
      'post_status'    => 'publish',
      'posts_per_page' => $per_page,
      'orderby'        => 'ID',
      'order'          => 'ASC',
      'fields'         => 'ids',
      'no_found_rows'  => true,
    ];

    $page = 1;
    $total_indexed = 0;
    $batch = [];
    $batch_size = isset($assoc_args['batch']) ? max(1, (int) $assoc_args['batch']) : 40;

    do {
      $query = new \WP_Query(array_merge($query_args, ['paged' => $page]));
      if (empty($query->posts)) {
        break;
      }

      foreach ($query->posts as $post_id) {
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'product' || $post->post_status !== 'publish') {
          continue;
        }

        $document = Typesense::build_document($post);
        if (!is_array($document) || ($document['post_type'] ?? '') !== 'product') {
          continue;
        }

        $batch[] = $document;
        if (count($batch) >= $batch_size) {
          self::send_import_batch($config, $batch);
          $total_indexed += count($batch);
          $batch = [];
          \WP_CLI::log(sprintf('Indexed %d products so far…', $total_indexed));
        }
      }

      $page++;
      wp_reset_postdata();
    } while (true);

    if (!empty($batch)) {
      self::send_import_batch($config, $batch);
      $total_indexed += count($batch);
    }

    \WP_CLI::success(sprintf('Indexed %d products into Typesense collection "%s".', $total_indexed, $config['collection']));
  }

  private static function get_typesense_config(): array {
    $host = Settings::get('typesense_host');
    $port = Settings::get('typesense_port');
    $proto = Settings::get('typesense_proto', 'http');
    $key = Settings::get('typesense_key');
    $collection = Settings::get('typesense_collection', 'site_content');

    if (!$host || !$port || !$key) {
      \WP_CLI::error('Typesense connection is not configured. Set host, port, protocol, key, and collection in the Universal Search settings.');
    }

    $base_url = sprintf('%s://%s:%s', $proto ?: 'http', $host, $port);

    return [
      'host' => $host,
      'port' => $port,
      'proto' => $proto ?: 'http',
      'key' => $key,
      'collection' => $collection ?: 'site_content',
      'base_url' => rtrim($base_url, '/'),
    ];
  }

  private static function health_status(array $config): array {
    $url = $config['base_url'] . '/health';
    $response = wp_remote_get($url, [
      'headers' => self::headers($config),
      'timeout' => 10,
    ]);

    if (is_wp_error($response)) {
      return ['ok' => false, 'message' => $response->get_error_message()];
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    if ($code !== 200) {
      return ['ok' => false, 'message' => sprintf('Unexpected HTTP %d: %s', $code, $body)];
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded) || empty($decoded['ok'])) {
      return ['ok' => false, 'message' => 'Health endpoint did not return ok=true'];
    }

    return ['ok' => true, 'message' => 'ok'];
  }

  private static function ensure_collection_http(array $config): void {
    $collection = $config['collection'];
    $url = $config['base_url'] . '/collections/' . rawurlencode($collection);
    $response = wp_remote_get($url, [
      'headers' => self::headers($config),
      'timeout' => 15,
    ]);

    $schema = Typesense::collection_schema();

    if (!is_wp_error($response)) {
      $code = wp_remote_retrieve_response_code($response);
      if ($code === 200) {
        $existing = json_decode(wp_remote_retrieve_body($response), true);
        if (is_array($existing) && Typesense::schema_matches($existing)) {
          return;
        }

        \WP_CLI::log(sprintf('Updating Typesense collection schema for "%s"…', $collection));
        $delete = wp_remote_request($url, [
          'method' => 'DELETE',
          'headers' => self::headers($config),
          'timeout' => 15,
        ]);
        if (is_wp_error($delete)) {
          \WP_CLI::error(sprintf('Failed to delete existing collection: %s', $delete->get_error_message()));
        }
      } elseif ($code !== 404) {
        \WP_CLI::error(sprintf('Unable to inspect Typesense collection: HTTP %d %s', $code, wp_remote_retrieve_body($response)));
      }
    } elseif ($response->get_error_code() !== 'http_request_failed') {
      \WP_CLI::error(sprintf('Error checking collection: %s', $response->get_error_message()));
    }

    $create = wp_remote_post($config['base_url'] . '/collections', [
      'headers' => self::headers($config) + ['Content-Type' => 'application/json'],
      'body'    => wp_json_encode($schema),
      'timeout' => 20,
    ]);

    if (is_wp_error($create)) {
      \WP_CLI::error(sprintf('Failed to create collection: %s', $create->get_error_message()));
    }

    $code = wp_remote_retrieve_response_code($create);
    if ($code >= 300) {
      \WP_CLI::error(sprintf('Failed to create collection: HTTP %d %s', $code, wp_remote_retrieve_body($create)));
    }

    \WP_CLI::log(sprintf('Typesense collection "%s" is ready.', $collection));
  }

  private static function send_import_batch(array $config, array $documents): void {
    if (empty($documents)) {
      return;
    }

    $endpoint = $config['base_url'] . '/collections/' . rawurlencode($config['collection']) . '/documents/import';
    $endpoint = add_query_arg([
      'action' => 'upsert',
      'dirty_values' => 'coerce_or_drop',
    ], $endpoint);

    $payload = implode("\n", array_map('wp_json_encode', $documents));

    $response = wp_remote_post($endpoint, [
      'headers' => self::headers($config) + ['Content-Type' => 'text/plain; charset=utf-8'],
      'body'    => $payload,
      'timeout' => 30,
    ]);

    if (is_wp_error($response)) {
      \WP_CLI::error(sprintf('Failed to import documents: %s', $response->get_error_message()));
    }

    $code = wp_remote_retrieve_response_code($response);
    if ($code >= 300) {
      \WP_CLI::error(sprintf('Failed to import documents: HTTP %d %s', $code, wp_remote_retrieve_body($response)));
    }
  }

  private static function headers(array $config): array {
    return [
      'X-TYPESENSE-API-KEY' => $config['key'],
      'Accept' => 'application/json',
    ];
  }
}
