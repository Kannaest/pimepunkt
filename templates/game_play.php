<section class="game-tabs">
  <button class="tab-button active" data-tab="map">Kaart</button>
  <button class="tab-button" data-tab="questions">Küsimused</button>
</section>

<?php if ($game['duration_minutes']): ?>
  <section class="game-clock <?= $team['paused_at'] ? 'paused' : '' ?>" data-game-clock data-deadline="<?= e($deadline ? $deadline->format(DateTimeInterface::ATOM) : '') ?>" data-paused="<?= $team['paused_at'] ? '1' : '0' ?>">
    <div><small>Mänguaeg</small><b data-game-clock-value><?= $team['paused_at'] ? 'Pausil' : 'Arvutan...' ?></b></div>
    <form method="post" action="<?= e(path($team['paused_at'] ? '/game/resume' : '/game/pause')) ?>" data-pause-form>
      <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="lat" data-pause-lat>
      <input type="hidden" name="lng" data-pause-lng>
      <button class="button" type="submit"><?= $team['paused_at'] ? 'Jätka' : 'Paus' ?></button>
    </form>
  </section>
<?php endif; ?>
<?php if (!empty($team['paused_at']) && $team['pause_lat'] !== null && $team['pause_lng'] !== null): ?>
  <section class="panel pause-location-panel">
    <div class="section-head">
      <div>
        <h2>Mäng on pausil</h2>
        <p class="muted">Kaart näitab pausikohta ja 100 m ala, kus saad mängu jätkata.</p>
      </div>
      <div class="actions">
        <?php if ((int)($game['allow_gpx_export'] ?? 0) === 1): ?>
          <a class="button" href="https://www.google.com/maps/dir/?api=1&amp;destination=<?= e(rawurlencode((string)$team['pause_lat'] . ',' . (string)$team['pause_lng'])) ?>&amp;travelmode=driving" target="_blank" rel="noopener noreferrer">Navigeeri Google Mapsis</a>
          <a class="button" href="<?= e(path('/games/' . $game['id'] . '/checkpoints.gpx')) ?>" download>GPX</a>
        <?php endif; ?>
      </div>
    </div>
    <div class="leaflet-map pause-location-map" data-pause-location-map data-lat="<?= e((string)$team['pause_lat']) ?>" data-lng="<?= e((string)$team['pause_lng']) ?>"></div>
  </section>
<?php endif; ?>
<?php if ($timeExpired): ?><div class="notice warn">Mänguaeg on lõppenud. Vastuseid enam esitada ei saa.</div><?php endif; ?>

<section id="tab-map" class="game-tab active">
  <div class="map-shell" data-map-shell>
    <button class="map-reset-button" type="button" data-map-reset>Reset</button>
    <?php if ($game['map_path']): ?>
      <img data-panzoom src="<?= e(path($game['map_path'])) ?>" alt="Mängukaart">
    <?php else: ?>
      <div class="empty-map">Kaarti pole veel lisatud.</div>
    <?php endif; ?>
  </div>
  <?php if ((int)($game['allow_gpx_export'] ?? 0) === 1): ?>
    <a class="button map-export-button" href="<?= e(path('/games/' . $game['id'] . '/checkpoints.gpx')) ?>" download>Laadi punktid GPX-failina</a>
  <?php endif; ?>
</section>

<section id="tab-questions" class="game-tab">
  <div class="panel">
    <?php
      $totalQuestions = (int)$progress['total_count'];
      $answeredQuestions = (int)$progress['answered_count'];
    ?>
    <div class="questions-overview">
      <h1>Küsimused</h1>
      <div class="progress-card <?= $totalQuestions > 0 && $answeredQuestions === $totalQuestions ? 'complete' : '' ?>">
        <b><?= e((string)$answeredQuestions) ?>/<?= e((string)$totalQuestions) ?> tehtud</b>
        <span><?= $totalQuestions > 0 && $answeredQuestions === $totalQuestions ? 'Mäng edukalt läbitud. Aitäh!' : 'Ava lähim sobiv punkt ja vasta siis, kui oled alas.' ?></span>
      </div>
      <div class="gps-telemetry" aria-live="polite">
        <div data-speed-state><small>Kiirus</small><b data-current-speed>— km/h</b></div>
        <div><small>Asukoht</small><b data-location-age>Ootan GPS-i</b></div>
        <div><small>Piirang</small><b data-speed-limit>—</b><span data-speeding-duration hidden></span></div>
      </div>
      <div class="gps-warning" data-gps-warning hidden></div>
      <div class="location-controls">
        <p class="muted" id="gps-status">Asukohta kasutatakse küsimuste sorteerimiseks ja vastamiseks.</p>
        <div class="actions">
          <button class="button" data-refresh-location>Uuenda asukohta</button>
          <?php if ($questionsLimited): ?><button class="button" data-refresh-nearest>Leia uuesti lähimad</button><?php endif; ?>
        </div>
        <?php if ($questionsLimited): ?>
          <small class="muted">Kuvatakse kuni 60 lähimat vastamata punkti<?= $hasLocationForQuestions ? ' viimase asukoha järgi' : '. Esimesel korral luba GPS ja uuenda nimekirja' ?>.</small>
        <?php endif; ?>
      </div>
    </div>
    <div class="question-list" data-question-list>
      <?php foreach ($checkpoints as $cp): ?>
        <form class="question-row" method="post" action="<?= e(path('/answer')) ?>" data-lat="<?= e((string)$cp['lat']) ?>" data-lng="<?= e((string)$cp['lng']) ?>" data-radius="<?= e((string)$cp['radius_m']) ?>">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="checkpoint_id" value="<?= e((string)$cp['id']) ?>">
          <input type="hidden" name="lat" data-answer-lat>
          <input type="hidden" name="lng" data-answer-lng>
          <input type="hidden" name="accuracy" data-answer-accuracy>
          <button class="question-summary" type="button" data-question-toggle aria-expanded="false" aria-controls="question-main-<?= e((string)$cp['id']) ?>">
            <span><b><?= e($cp['number']) ?></b><span><?= e($cp['title']) ?></span></span>
          </button>
          <div class="question-main" id="question-main-<?= e((string)$cp['id']) ?>" hidden>
            <small class="question-meta"><i class="difficulty-icon difficulty-<?= e((string)checkpoint_difficulty($cp['difficulty'] ?? 1)) ?>"></i><?= e(checkpoint_difficulty_label($cp['difficulty'] ?? 1)) ?> · <?= e((string)checkpoint_visit_points($cp, $game)) ?> p · <span data-distance>asukoht teadmata</span> · raadius <?= e((string)$cp['radius_m']) ?> m</small>
            <p><?= e($cp['question_text']) ?></p>
            <?php if ($cp['question_type'] === 'ok'): ?>
              <input type="hidden" name="ok" value="1">
            <?php else: ?>
              <?php foreach (($options[(int)$cp['question_id']] ?? []) as $option): ?>
                <label class="choice"><input type="radio" name="answer_option_id" value="<?= e((string)$option['id']) ?>" required><span><?= e($option['label']) ?></span></label>
              <?php endforeach; ?>
            <?php endif; ?>
            <button class="button primary" data-answer-button disabled <?= ($team['paused_at'] || $timeExpired) ? 'data-game-blocked="1"' : '' ?>>Vasta</button>
          </div>
        </form>
      <?php endforeach; ?>
    </div>
  </div>
</section>
