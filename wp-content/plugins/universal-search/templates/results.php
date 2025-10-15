<?php
$q = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';
$mode = isset($_GET['mode']) ? sanitize_key($_GET['mode']) : 'text';
$rq = isset($_GET['rq']) ? json_decode(base64_decode(sanitize_text_field(wp_unslash($_GET['rq']))), true) : ['query'=>$q,'limit'=>24,'page'=>1];

get_header();
?>
<main class="us-results container">
  <h1>Search results</h1>
  <p><strong>Mode:</strong> <?php echo esc_html($mode); ?> · <strong>Query:</strong> <?php echo esc_html($rq['query'] ?? $q); ?></p>
  <?php
  try {
    $resp = \UNIV_SEARCH\Typesense::search(is_array($rq) ? $rq : ['query'=>$q,'limit'=>24,'page'=>1]);
    if (!empty($resp['hits'])): ?>
      <ul class="us-grid">
        <?php foreach ($resp['hits'] as $hit): $d = $hit['document']; ?>
          <li class="us-card">
            <a href="<?php echo esc_url($d['permalink'] ?? '#'); ?>">
              <h3><?php echo esc_html($d['title'] ?? 'Untitled'); ?></h3>
              <p><?php echo esc_html(wp_trim_words($d['excerpt'] ?? ($d['content'] ?? ''), 24)); ?></p>
              <small><?php echo esc_html($d['post_type'] ?? ''); ?></small>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <p>No results.</p>
    <?php endif;
  } catch (\Throwable $e) {
    echo '<p>Search error: ' . esc_html($e->getMessage()) . '</p>';
  }
  ?>
</main>
<?php get_footer(); ?>
