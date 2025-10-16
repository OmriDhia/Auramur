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
              <?php if (!empty($d['image'])): ?>
                <div class="us-card-thumb"><img src="<?php echo esc_url($d['image']); ?>" alt="" loading="lazy" /></div>
              <?php endif; ?>
              <h3><?php echo esc_html($d['title'] ?? 'Untitled'); ?></h3>
              <p><?php echo esc_html(wp_trim_words($d['excerpt'] ?? ($d['content'] ?? ''), 24)); ?></p>
              <div class="us-card-meta">
                <span class="us-card-type"><?php echo esc_html($d['post_type'] ?? ''); ?></span>
                <?php
                $price = isset($d['price']) ? floatval($d['price']) : 0;
                if ($price > 0) {
                  if (function_exists('wc_price')) {
                    echo '<span class="us-card-price">' . wp_kses_post(wc_price($price)) . '</span>';
                  } else {
                    echo '<span class="us-card-price">' . esc_html(number_format($price, 2)) . '</span>';
                  }
                }
                ?>
              </div>
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
