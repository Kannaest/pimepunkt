<?php if (!empty($teamGames)): ?>
  <section class="panel my-games-panel">
    <div class="section-head">
      <h1>Minu mängud</h1>
      <a href="<?= e(path('/register')) ?>">Registreeru uuele</a>
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

<section class="hero">
  <h1>Pimepunkt</h1>
  <p>Pimekaardiga asukohapõhine seltskonnamäng, kus tiim otsib kaardil märgitud punkte, liigub päriselt kohale ja vastab küsimustele alles õiges alas.</p>
  <div class="actions">
    <?php if ($team): ?>
      <a class="button primary" href="<?= e(path('/game')) ?>">Jätka mängu</a>
    <?php else: ?>
      <a class="button primary" href="<?= e(path('/register')) ?>">Vali mäng</a>
    <?php endif; ?>
    <a class="button" href="<?= e(path('/about')) ?>">Kuidas mängida</a>
  </div>
</section>

<section class="grid two">
  <div class="panel">
    <h2>Kuidas mäng käib</h2>
    <div class="list">
      <div class="row"><span>1. Registreeri tiim e-mailiga ja ava saadetud link samas telefonis.</span></div>
      <div class="row"><span>2. Oota admini kinnitust ja mängu starti.</span></div>
      <div class="row"><span>3. Kasuta pimekaarti, liigu punktile ja ava küsimuste vaade.</span></div>
      <div class="row"><span>4. Vasta küsimusele, kui GPS näitab, et oled õiges alas.</span></div>
    </div>
  </div>
  <div class="panel">
    <h2>Mida mängijalt vaja on</h2>
    <p class="muted">Telefon, internet, brauseri asukoha luba ja natuke uudishimu. Õigeid/valesid vastuseid mängu ajal ei näidata, tulemused avaldab korraldaja hiljem.</p>
  </div>
</section>
