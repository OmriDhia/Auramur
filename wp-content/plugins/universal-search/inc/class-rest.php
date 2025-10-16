<?php
namespace UNIV_SEARCH;

if (!defined('ABSPATH')) exit;

class REST {
  public static function init() {
    add_action('rest_api_init', function () {
      register_rest_route('univ-search/v1', '/voice', ['methods' => 'POST','permission_callback' => '__return_true','callback' => [__CLASS__, 'voice']]);
      register_rest_route('univ-search/v1', '/image', ['methods' => 'POST','permission_callback' => '__return_true','callback' => [__CLASS__, 'image']]);
      register_rest_route('univ-search/v1', '/run',   ['methods' => 'POST','permission_callback' => '__return_true','callback' => [__CLASS__, 'run']]);
    });
  }

  public static function voice(\WP_REST_Request $req) {
    $file = $req->get_file_params()['file'] ?? null;
    if (!$file || !empty($file['error'])) return self::err('No audio file.');
    $hash = hash_file('sha256', $file['tmp_name']);
    if ($cached = get_transient("us_voice_$hash")) return ['query' => $cached];

    // Size guard (20MB)
    if (filesize($file['tmp_name']) > 20 * 1024 * 1024) return self::err('Audio too large (max 20MB).');

    $transcript = AI::transcribe_audio($file['tmp_name'], $file['type'] ?? 'audio/webm');
    if (!$transcript) return self::err('Could not transcribe audio.');
    $queryJson  = AI::extract_query($transcript);
    if (!$queryJson) return self::err('Could not extract query.');
    set_transient("us_voice_$hash", $queryJson, DAY_IN_SECONDS);
    return ['query' => $queryJson];
  }

  public static function image(\WP_REST_Request $req) {
    $file = $req->get_file_params()['image'] ?? null;
    if (!$file || !empty($file['error'])) return self::err('No image file.');
    $hash = hash_file('sha256', $file['tmp_name']);
    if ($cached = get_transient("us_image_$hash")) return ['query' => $cached];

    // Size/type guard
    $allowed = ['image/jpeg','image/png','image/webp'];
    if (!in_array($file['type'] ?? '', $allowed, true)) return self::err('Unsupported image type.');
    if (filesize($file['tmp_name']) > 10 * 1024 * 1024) return self::err('Image too large (max 10MB).');

    $labels = AI::analyze_image($file['tmp_name'], $file['type']);
    if (!$labels) return self::err('Could not analyze image.');
    $queryJson = AI::labels_to_query($labels);
    set_transient("us_image_$hash", $queryJson, 7 * DAY_IN_SECONDS);
    return ['query' => $queryJson];
  }

  public static function run(\WP_REST_Request $req) {
    $payload = json_decode($req->get_body(), true);
    if (!$payload) return self::err('Bad JSON.');
    $payload = \UNIV_SEARCH\us_sanitize_array($payload);
    try {
      $results = Typesense::search($payload);

      return ['results' => $results];
    } catch (\Throwable $e) {
      error_log('Universal Search REST search error: ' . $e->getMessage());
      return self::err(__('Search service unavailable. Please try again later.', 'universal-search'), 503);
    }

  }

  private static function err($msg, $code=400){ return new \WP_REST_Response(['message'=>$msg], $code); }
}
