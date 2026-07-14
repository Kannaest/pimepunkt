<section class="panel narrow">
  <h1><?= e($game['name']) ?></h1>
  <?php if ($team['status'] === 'pending'): ?>
    <p>Registreeritud. Ootad korraldaja kinnitust.</p>
  <?php elseif ($team['status'] === 'rejected'): ?>
    <p>Registreeringut ei kinnitatud.</p>
  <?php elseif ($game['status'] !== 'running'): ?>
    <p>Oled mängule kinnitatud. Oota starti.</p>
  <?php else: ?>
    <p>Mäng ei ole hetkel avatud.</p>
  <?php endif; ?>
</section>
