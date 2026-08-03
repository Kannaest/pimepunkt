<?php if (!empty($overviewBounds)): ?>
  <div
    class="registration-area-map"
    data-registration-area-map
    data-bounds='<?= e(json_encode([
      [(float)$overviewBounds['min_lat'], (float)$overviewBounds['min_lng']],
      [(float)$overviewBounds['max_lat'], (float)$overviewBounds['max_lng']],
    ], JSON_THROW_ON_ERROR)) ?>'
    aria-label="Mänguala kaart"
  ></div>
<?php endif; ?>
