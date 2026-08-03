<section class="panel narrow game-start-panel">
  <h1><?= e($game['name']) ?></h1>
  <?php partial('game_area_map', ['overviewBounds' => $overviewBounds ?? null]); ?>
  <p>Kui vajutad <b>Alusta mängu</b>, hakkab <?= (int)$game['duration_minutes'] === 360 ? '6 tunni' : e((string)$game['duration_minutes']) . ' minuti' ?> pikkune mänguaeg kohe jooksma.</p>
  <?php if ($game['start_window_from'] || $game['start_window_to']): ?>
    <p class="muted">Stardiaken: <?= e((string)($game['start_window_from'] ?: 'kohe')) ?> kuni <?= e((string)($game['start_window_to'] ?: 'avatud')) ?>.</p>
  <?php endif; ?>
  <form method="post" action="<?= e(path('/game/start')) ?>">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <button class="button primary">Alusta mängu</button>
  </form>
</section>
