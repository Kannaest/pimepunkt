<section class="game-tabs">
  <button class="tab-button active" data-tab="map">Kaart</button>
  <button class="tab-button" data-tab="questions">Küsimused</button>
</section>

<section id="tab-map" class="game-tab active">
  <div class="map-shell" data-map-shell>
    <button class="map-reset-button" type="button" data-map-reset>Reset</button>
    <?php if ($game['map_path']): ?>
      <img data-panzoom src="<?= e(path($game['map_path'])) ?>" alt="Mängukaart">
    <?php else: ?>
      <div class="empty-map">Kaarti pole veel lisatud.</div>
    <?php endif; ?>
  </div>
</section>

<section id="tab-questions" class="game-tab">
  <div class="panel">
    <?php
      $totalQuestions = count($checkpoints);
      $answeredQuestions = count(array_filter($checkpoints, fn($cp) => !empty($cp['submission_id'])));
    ?>
    <h1>Küsimused</h1>
    <div class="progress-card <?= $totalQuestions > 0 && $answeredQuestions === $totalQuestions ? 'complete' : '' ?>">
      <b><?= e((string)$answeredQuestions) ?>/<?= e((string)$totalQuestions) ?> tehtud</b>
      <span><?= $totalQuestions > 0 && $answeredQuestions === $totalQuestions ? 'Mäng edukalt läbitud. Aitäh!' : 'Ava lähim sobiv punkt ja vasta siis, kui oled alas.' ?></span>
    </div>
    <div class="gps-warning" data-gps-warning hidden></div>
    <p class="muted" id="gps-status">Asukohta kasutatakse küsimuste sorteerimiseks ja vastamiseks.</p>
    <button class="button" data-refresh-location>Uuenda asukohta</button>
    <div class="question-list" data-question-list>
      <?php foreach ($checkpoints as $cp): ?>
        <?php if ($cp['submission_id']): ?>
          <div class="question-row done">
            <b><?= e($cp['number']) ?></b>
            <span><?= e($cp['title']) ?></span>
            <small>Vastatud</small>
          </div>
          <?php continue; ?>
        <?php endif; ?>
        <form class="question-row" method="post" action="<?= e(path('/answer')) ?>" data-lat="<?= e((string)$cp['lat']) ?>" data-lng="<?= e((string)$cp['lng']) ?>" data-radius="<?= e((string)$cp['radius_m']) ?>">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="checkpoint_id" value="<?= e((string)$cp['id']) ?>">
          <input type="hidden" name="lat" data-answer-lat>
          <input type="hidden" name="lng" data-answer-lng>
          <input type="hidden" name="accuracy" data-answer-accuracy>
          <button class="question-summary" type="button" data-question-toggle>
            <span><b><?= e($cp['number']) ?></b> <?= e($cp['title']) ?></span>
            <small><span data-distance>asukoht teadmata</span> · raadius <?= e((string)$cp['radius_m']) ?> m</small>
          </button>
          <div class="question-main" hidden>
            <p><?= e($cp['question_text']) ?></p>
            <?php if ($cp['question_type'] === 'ok'): ?>
              <input type="hidden" name="ok" value="1">
            <?php else: ?>
              <?php foreach (($options[(int)$cp['question_id']] ?? []) as $option): ?>
                <label class="choice"><input type="radio" name="answer_option_id" value="<?= e((string)$option['id']) ?>" required><span><?= e($option['label']) ?></span></label>
              <?php endforeach; ?>
            <?php endif; ?>
            <button class="button primary" data-answer-button disabled>Vasta</button>
          </div>
        </form>
      <?php endforeach; ?>
    </div>
  </div>
</section>
