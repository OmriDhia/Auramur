<?php
namespace UNIV_SEARCH;
use Typesense\Client;

if (!defined('ABSPATH')) exit;

class Typesense {
  private static $client;

  public static function init() {
    // Autoload vendor if present
    $vendor = dirname(__FILE__,2).'/vendor/autoload.php';
    if (file_exists($vendor)) {
      require_once $vendor;
    }
    $key = Settings::get('typesense_key');
    $host = Settings::get('typesense_host');
    $port = Settings::get('typesense_port');
    $proto= Settings::get('typesense_proto', 'https');
    if ($key && $host && $port) {
      self::$client = new Client([
        'api_key' => $key,
        'nodes'   => [['host'=>$host,'port'=>(int)$port,'protocol'=>$proto]],
        'connection_timeout_seconds' => 3
      ]);
    }
  }

  public static function search(array $queryJson) {
    if (!self::$client) throw new \Exception('Typesense not configured.');
    $collection = Settings::get('typesense_collection', 'site_content');
    $q = $queryJson['query'] ?? '';
    $filter_by = self::build_filter($queryJson['filters'] ?? []);
    $sort_by   = self::build_sort($queryJson['sort'] ?? []);
    $params = [
      'q' => $q ?: '*',
      'query_by' => 'title,content,tags,categories',
      'per_page' => max(1, intval($queryJson['limit'] ?? 24)),
      'page'     => max(1, intval($queryJson['page'] ?? 1)),
    ];
    if ($filter_by) $params['filter_by'] = $filter_by;
    if ($sort_by) $params['sort_by'] = $sort_by;

    return self::$client->collections[$collection]->documents->search($params);
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
    $field = preg_replace('/[^a-z0-9_]/i', '', $first['field'] ?? 'popularity');
    $order = (isset($first['order']) && strtolower($first['order']) === 'asc') ? 'asc' : 'desc';
    return $field . ':' . $order;
  }
}
