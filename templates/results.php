<section class="panel">
  <h1>Tulemused: <?= e($game['name']) ?></h1>
  <div class="podium">
    <?php foreach (array_slice($scoreboard, 0, 3) as $index => $row): ?>
      <div class="podium-place place-<?= e((string)($index + 1)) ?>">
        <small><?= e((string)($index + 1)) ?>. koht</small>
        <b><?= e($row['name']) ?></b>
        <span><?= e((string)$row['score']) ?> p</span>
        <small>Aeg <?= e(format_elapsed_seconds($row['elapsed_seconds'])) ?></small>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="score-grid">
    <?php foreach ($scoreboard as $index => $row): ?>
      <div class="score-card <?= $index === 0 ? 'winner' : '' ?>">
        <b><?= $index === 0 ? 'Võitja: ' : '' ?><?= e($row['name']) ?></b>
        <span><?= e((string)$row['score']) ?> p</span>
        <small><?= e((string)$row['visited']) ?> kohal · <?= e((string)$row['correct_count']) ?> õige · <?= e((string)$row['wrong_count']) ?> vale · aeg <?= e(format_elapsed_seconds($row['elapsed_seconds'])) ?></small>
      </div>
    <?php endforeach; ?>
  </div>
</section>
<section class="panel">
  <h2>Salvestatud liikumine</h2>
  <?php if ($paths): ?>
    <div class="section-head">
      <p class="muted">Iga tiimi liikumine on kaardil oma värviga. Animatsioon mängib salvestatud punktid ajalises järjekorras läbi.</p>
      <div class="actions">
        <button class="button primary" type="button" data-results-play>Käivita</button>
        <button class="button" type="button" data-results-reset>Lähtesta</button>
      </div>
    </div>
    <div class="leaflet-map results-map" data-results-map data-paths='<?= e(json_encode($paths, JSON_UNESCAPED_UNICODE)) ?>'></div>
    <div class="timeline-control">
      <input type="range" min="0" max="0" value="0" step="1" data-results-timeline>
      <div class="timeline-labels">
        <span data-results-start>Aeg puudub</span>
        <b data-results-current>Aeg puudub</b>
        <span data-results-end>Aeg puudub</span>
      </div>
    </div>
    <div class="map-legend" data-results-legend></div>
  <?php else: ?>
    <p class="muted">Liikumisjälgi ei ole salvestatud.</p>
  <?php endif; ?>
</section>
