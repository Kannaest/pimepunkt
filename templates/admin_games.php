<section class="grid two">
  <div class="panel">
    <div class="section-head">
      <h1>Mängud</h1>
      <small><?= e($admin['email']) ?><?= (int)$admin['is_super'] === 1 ? ' · peadmin' : '' ?></small>
    </div>
    <div class="list">
      <?php foreach ($games as $game): ?>
        <div class="row game-admin-row">
          <a href="<?= e(path('/admin/games/' . $game['id'])) ?>">
            <b><?= e($game['name']) ?></b><br>
            <small><?= e($game['owner_email'] ?? 'looja puudub') ?></small>
          </a>
          <div class="actions">
            <b><?= e($game['status']) ?></b>
            <form method="post" action="<?= e(path('/admin/games/' . $game['id'] . '/play')) ?>">
              <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
              <button class="icon-button" title="Mängi adminina">Mängi</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="panel">
    <h2>Uus mäng</h2>
    <form method="post" action="<?= e(path('/admin/games')) ?>">
      <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
      <label>Nimi <input name="name" required></label>
      <label>Kohal käimise punktid <input name="default_visit_points" type="number" value="3" required></label>
      <label>Vale vastuse miinus <input name="default_wrong_penalty" type="number" value="2" required></label>
      <button class="button primary">Loo mäng</button>
    </form>
  </div>
</section>

<?php if ((int)$admin['is_super'] === 1): ?>
  <section class="panel">
    <div class="section-head">
      <div>
        <h2>Nutilogi Nägemata Eesti</h2>
        <small>
          Seotud <?= e((string)($nutilogi['linked_games'] ?? 0)) ?> mängu
          <?= !empty($nutilogi['last_synced_at']) ? ' · viimane sünkroon ' . e((string)$nutilogi['last_synced_at']) : '' ?>
        </small>
      </div>
      <div class="actions">
        <form method="post" action="<?= e(path('/admin/nutilogi-sync')) ?>">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <button class="button">Kontrolli uuendusi</button>
        </form>
        <form method="post" action="<?= e(path('/admin/nutilogi-sync')) ?>" onsubmit="return confirm('Sünkroonin 20 viimast avaldatud Nägemata Eesti mängu? Aktiivseid ja mängitud mänge ei muudeta.')">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="apply" value="1">
          <button class="button primary">Sünkrooni</button>
        </form>
      </div>
    </div>
  </section>
  <section class="grid two">
    <div class="panel">
      <h2>Adminid</h2>
      <div class="list">
        <?php foreach ($admins as $row): ?>
          <div class="row">
            <span><?= e($row['email']) ?><?= (int)$row['is_super'] === 1 ? ' · peadmin' : '' ?></span>
            <?php if ((int)$row['is_super'] !== 1 && (int)$row['id'] !== (int)$admin['id']): ?>
              <form method="post" action="<?= e(path('/admin/admins/' . $row['id'] . '/delete')) ?>">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <button class="icon-button warn">Eemalda</button>
              </form>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="panel">
      <h2>Lisa admin</h2>
      <form method="post" action="<?= e(path('/admin/admins')) ?>">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <label>E-mail <input name="email" type="email" required></label>
        <button class="button primary">Lisa admin</button>
      </form>
    </div>
  </section>
<?php endif; ?>
