<?php if (!empty($teamGames)): ?>
  <section class="panel my-games-panel">
    <div class="section-head">
      <h1>Minu mängud</h1>
      <?php if (!empty($showRegisterLink)): ?><a href="<?= e(path('/register')) ?>">Registreeru uuele</a><?php endif; ?>
    </div>
    <div class="list my-games-list">
      <?php foreach ($teamGames as $registeredTeam): ?>
        <div class="row my-game-row">
          <div>
            <b><?= e($registeredTeam['game_name']) ?></b>
            <small><?= e($registeredTeam['name']) ?> · <?= e(team_game_status_label($registeredTeam)) ?></small>
          </div>
          <form method="post" action="<?= e(path('/game/select')) ?>">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="team_id" value="<?= e((string)$registeredTeam['id']) ?>">
            <button class="button <?= (int)$registeredTeam['id'] === (int)$team['id'] ? 'primary' : '' ?>">
              <?= (int)$registeredTeam['id'] === (int)$team['id'] ? 'Jätka' : 'Ava' ?>
            </button>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
<?php endif; ?>
