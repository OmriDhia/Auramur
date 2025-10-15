<?php
namespace UNIV_SEARCH;

if (!defined('ABSPATH')) exit;

class AI {
  public static function init() {}

  public static function transcribe_audio($path, $mime) {
    $apiKey = Settings::get('openai_key');
    if (!$apiKey) return '';
    $boundary = wp_generate_password(24, false, false);
    $body = '';
    $eol = "\r\n";
    $body .= '--' . $boundary . $eol;
    $body .= 'Content-Disposition: form-data; name="model"' . $eol . $eol . 'whisper-1' . $eol;
    $body .= '--' . $boundary . $eol;
    $body .= 'Content-Disposition: form-data; name="file"; filename="audio"' . $eol;
    $body .= 'Content-Type: ' . esc_attr($mime) . $eol . $eol;
    $body .= file_get_contents($path) . $eol;
    $body .= '--' . $boundary . '--' . $eol;

    $res = wp_remote_post('https://api.openai.com/v1/audio/transcriptions', [
      'headers' => [
        'Authorization' => 'Bearer ' . $apiKey,
        'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
      ],
      'timeout' => 60,
      'body'    => $body,
    ]);

    if (is_wp_error($res)) return '';
    $json = json_decode(wp_remote_retrieve_body($res), true);
    return $json['text'] ?? '';
  }

  public static function extract_query($text) {
    $apiKey = Settings::get('openai_key');
    if (!$apiKey) return [];
    $system = "You turn raw queries into a JSON for search. Output ONLY valid JSON matching this schema: {query: string, synonyms: string[], filters: {post_type?: string[], taxonomy?: object, price?: {gte?: number, lte?: number}}, sort?: {field:string,order:'asc'|'desc'}[], limit?: number, page?: number}";
    $user = "Text: " . $text;

    $res = wp_remote_post('https://api.openai.com/v1/chat/completions', [
      'headers' => ['Authorization' => 'Bearer ' . $apiKey, 'Content-Type'=>'application/json'],
      'timeout' => 60,
      'body'    => wp_json_encode([
        'model' => 'gpt-4o-mini',
        'response_format' => ['type' => 'json_object'],
        'messages' => [
          ['role'=>'system','content'=>$system],
          ['role'=>'user','content'=>$user],
        ],
        'temperature' => 0.2
      ])
    ]);
    if (is_wp_error($res)) return [];
    $json = json_decode(wp_remote_retrieve_body($res), true);
    $content = $json['choices'][0]['message']['content'] ?? '{}';
    $decoded = json_decode($content, true);
    return is_array($decoded) ? $decoded : [];
  }

  public static function analyze_image($path, $mime) {
    $apiKey = Settings::get('openai_key');
    if (!$apiKey) return [];
    $b64 = base64_encode(file_get_contents($path));
    $messages = [
      ['role'=>'system','content'=>'Describe the image briefly and list 8-15 concise shopping/search keywords and categories. Respond as JSON with {description:string, keywords:string[], categories:string[]}'],
      ['role'=>'user','content'=>[
        ['type'=>'text','text'=>'Analyze this image.'],
        ['type'=>'input_image','image_url'=>['url'=>"data:$mime;base64,$b64"]],
      ]]
    ];
    $res = wp_remote_post('https://api.openai.com/v1/chat/completions', [
      'headers'=>['Authorization'=>'Bearer '.$apiKey,'Content-Type'=>'application/json'],
      'timeout' => 60,
      'body'=>wp_json_encode(['model'=>'gpt-4o-mini','messages'=>$messages,'response_format'=>['type'=>'json_object']])
    ]);
    if (is_wp_error($res)) return [];
    $json = json_decode(wp_remote_retrieve_body($res), true);
    $content = $json['choices'][0]['message']['content'] ?? '{}';
    $decoded = json_decode($content, true);
    return is_array($decoded) ? $decoded : [];
  }

  public static function labels_to_query($labels) {
    $q = ($labels['description'] ?? '');
    $kw = array_slice(array_unique($labels['keywords'] ?? []), 0, 10);
    return [
      'query' => trim($q . ' ' . implode(' ', $kw)),
      'synonyms' => $kw,
      'filters' => ['post_type' => ['product','post']],
      'limit' => 24, 'page' => 1
    ];
  }
}
