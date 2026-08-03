<section class="panel">
  <div class="section-head">
    <h1>Live: <?= e($game['name']) ?></h1>
    <a class="button" href="<?= e(path('/admin/games/' . $game['id'])) ?>">Tagasi</a>
  </div>
  <div class="score-grid">
    <?php foreach ($scoreboard as $row): ?>
      <div class="score-card">
        <b><?= e($row['name']) ?></b>
        <span><?= e((string)$row['score']) ?> p</span>
        <small><?= e((string)$row['visited']) ?> kohal · <?= e((string)$row['correct_count']) ?> õige · <?= e((string)$row['wrong_count']) ?> vale</small>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="list compact-list">
    <?php foreach ($teams as $team): ?>
      <form class="row" method="post" action="<?= e(path('/admin/teams/' . $team['id'] . '/results')) ?>">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <span><?= e($team['name']) ?></span>
        <label class="checkbox-label"><input name="excluded_from_results" type="checkbox" value="1" <?= (int)($team['excluded_from_results'] ?? 0) === 1 ? 'checked' : '' ?> onchange="this.form.submit()"> Välja arvestusest</label>
      </form>
    <?php endforeach; ?>
  </div>
</section>
<?php if ($speedingEvents): ?>
<section class="panel">
  <h2>Kiiruseületused</h2>
  <div class="list compact-list">
    <?php foreach ($speedingEvents as $event): ?>
      <div class="row">
        <span><b><?= e($event['team_name']) ?></b> · <?= e((string)round((float)$event['max_speed_kmh'])) ?> / <?= e((string)$event['limit_kmh']) ?> km/h · <?= e((string)($event['zone_name'] ?? 'kiirusala')) ?></span>
        <span><?= e($event['status']) ?><?= $event['penalty_points'] ? ' · -' . e((string)$event['penalty_points']) . ' p' : '' ?></span>
        <form method="post" action="<?= e(path('/admin/speeding/' . $event['id'] . '/' . ($event['status'] === 'dismissed' ? 'confirm' : 'dismiss'))) ?>">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <button class="icon-button"><?= $event['status'] === 'dismissed' ? 'Kinnita' : 'Tühista' ?></button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>
<section class="panel">
  <h2>Asukohad ja liikumised</h2>
  <div class="leaflet-map live-map" data-admin-live-map data-game-id="<?= e((string)$game['id']) ?>"></div>
</section>
<section class="panel">
  <h2>Viimased vastused</h2>
  <div class="list">
    <?php foreach ($submissions as $s): ?>
      <form class="submission-row" method="post" action="<?= e(path('/admin/submissions/' . $s['id'] . '/adjust')) ?>">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <div>
          <b><?= e($s['team_name']) ?></b><br>
          <small>Punkt <?= e($s['checkpoint_number']) ?> · <?= e($s['created_at']) ?> · <?= $s['is_correct'] ? 'õige' : 'vale' ?></small>
          <p><b>Küsimus:</b> <?= e($s['question_text']) ?></p>
          <p><b>Vastus:</b> <?= e((string)($s['ok_answer'] ? 'OK / kohal' : ($s['answer_label'] ?? 'vastus puudub'))) ?></p>
        </div>
        <select name="admin_correct_override">
          <option value="">Automaatne</option>
          <option value="1" <?= $s['admin_correct_override'] === 1 ? 'selected' : '' ?>>Loe õigeks</option>
          <option value="0" <?= $s['admin_correct_override'] === 0 ? 'selected' : '' ?>>Loe valeks</option>
        </select>
        <input name="admin_score_adjustment" type="number" value="<?= e((string)$s['admin_score_adjustment']) ?>">
        <input name="admin_note" value="<?= e((string)$s['admin_note']) ?>" placeholder="Märkus">
        <button class="button">Salvesta</button>
      </form>
    <?php endforeach; ?>
  </div>
</section>
