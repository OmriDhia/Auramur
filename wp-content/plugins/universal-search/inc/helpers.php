<?php
namespace UNIV_SEARCH;

if (!defined('ABSPATH')) exit;

function us_sanitize_array($arr) {
  if (!is_array($arr)) return [];
  return array_map(function($v){
    if (is_array($v)) return us_sanitize_array($v);
    return sanitize_text_field((string)$v);
  }, $arr);
}
