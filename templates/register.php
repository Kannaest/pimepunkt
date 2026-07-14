<section class="panel register-hero">
  <div>
    <h1><?= !empty($selectedGameId) ? 'Registreeru mängule' : 'Vali mäng' ?></h1>
    <p class="muted">
      <?= !empty($selectedGameId)
        ? 'Sisesta tiimi nimi ja e-mail. Turvaline link seob selle brauseri valitud mänguga.'
        : 'Leia õige mäng ja registreeri tiim e-mailile saadetava turvalise lingiga.' ?>
    </p>
  </div>
  <?php if (empty($selectedGameId)): ?>
    <form method="get" class="search-form">
      <label>Otsi mängu <input name="q" value="<?= e($query ?? '') ?>" placeholder="Mängu nimi"></label>
      <button class="button">Otsi</button>
    </form>
  <?php else: ?>
    <a class="button" href="<?= e(path('/register')) ?>">Vaata kõiki mänge</a>
  <?php endif; ?>
</section>

<section class="register-layout">
  <div class="panel">
    <div class="section-head">
      <h2><?= !empty($selectedGameId) ? 'Valitud mäng' : 'Avatud mängud' ?></h2>
      <small><?= e((string)count($games)) ?> mängu</small>
    </div>
    <?php if (!$games): ?>
      <p class="muted"><?= !empty($selectedGameId) ? 'Seda mängu ei leitud või registreerimine ei ole avatud.' : 'Hetkel ei ole registreerimiseks avatud mängu.' ?></p>
    <?php endif; ?>
    <div class="game-list">
      <?php foreach ($games as $game): ?>
        <article class="game-card">
          <div class="game-card-title">
            <b><?= e($game['name']) ?></b>
            <small><?= e($game['status']) ?></small>
          </div>
          <form method="post" class="register-form" data-register-form>
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="game_id" value="<?= e((string)$game['id']) ?>">
            <label>Tiimi nimi <input name="team_name" required></label>
            <label>E-mail <input name="email" type="email" required></label>
            <button class="button primary" data-register-submit>Registreeru</button>
            <p class="form-status <?= str_starts_with((string)($flash ?? ''), 'E-mail on saadetud') ? 'success' : '' ?>" data-register-status>
              <?= str_starts_with((string)($flash ?? ''), 'E-mail on saadetud') ? e((string)$flash) : '' ?>
            </p>
          </form>
        </article>
      <?php endforeach; ?>
    </div>
  </div>

  <aside class="panel">
    <h2>Mängitud mängud</h2>
    <?php if (!$playedGames): ?>
      <p class="muted">Avalikke tulemusi ei ole veel.</p>
    <?php endif; ?>
    <div class="list">
      <?php foreach ($playedGames as $game): ?>
        <a class="row" href="<?= e(path('/results/' . $game['id'])) ?>">
          <span><?= e($game['name']) ?></span>
          <b>Tulemused</b>
        </a>
      <?php endforeach; ?>
    </div>
  </aside>
</section>
