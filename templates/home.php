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
