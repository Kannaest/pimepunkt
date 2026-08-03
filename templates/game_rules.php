<?php if (!empty($gameRules)): ?>
  <section class="game-rules" aria-labelledby="game-rules-title">
    <h2 id="game-rules-title">Mängu reeglid</h2>
    <dl>
      <div>
        <dt>Aeg</dt>
        <dd>
          <?php if (!empty($game['duration_minutes'])): ?>
            Mänguaeg on <?= e(duration_label((int)$game['duration_minutes'])) ?> ja algab nupust <b>Alusta mängu</b>. Aja lõppedes enam vastata ei saa.
          <?php else: ?>
            Mängul ei ole ajalist piirangut. Vastata saab seni, kuni korraldaja mängu lõpetab.
          <?php endif; ?>
        </dd>
      </div>
      <?php if (!empty($game['duration_minutes'])): ?>
        <div>
          <dt>Paus</dt>
          <dd>Paus peatab mänguaja, vastamise ja asukoha salvestamise. Jätkata saab kuni 100 m kaugusel kohast, kus paus algas.</dd>
        </div>
      <?php endif; ?>
      <div>
        <dt>Punktid</dt>
        <dd>
          <?php if ((int)$gameRules['checkpoint_count'] > 0): ?>
            Mängus on <?= e((string)$gameRules['checkpoint_count']) ?> kontrollpunkti. Külastus annab
            <?php if ((int)$gameRules['visit_min'] === (int)$gameRules['visit_max']): ?>
              <?= e((string)$gameRules['visit_min']) ?> punkti.
            <?php else: ?>
              <?= e((string)$gameRules['visit_min']) ?> kuni <?= e((string)$gameRules['visit_max']) ?> punkti vastavalt punkti raskusele.
            <?php endif; ?>
          <?php else: ?>
            Külastus annab vaikimisi <?= e((string)$game['default_visit_points']) ?> punkti.
          <?php endif; ?>
          Vale vastus vähendab punkti tulemust
          <?php if ((int)$gameRules['wrong_min'] === (int)$gameRules['wrong_max']): ?>
            <?= e((string)$gameRules['wrong_min']) ?> punkti võrra.
          <?php else: ?>
            <?= e((string)$gameRules['wrong_min']) ?> kuni <?= e((string)$gameRules['wrong_max']) ?> punkti võrra.
          <?php endif; ?>
        </dd>
      </div>
      <div>
        <dt>Vastamine</dt>
        <dd>Küsimus avaneb GPS-ala sees ja sellele saab vastata ühe korra. Vastuse õigsust näidatakse alles avalikes lõpptulemustes.</dd>
      </div>
      <?php if (!empty($gameRules['has_speed_zones'])): ?>
        <div>
          <dt>Kiirus</dt>
          <dd>Üle 10 sekundi kestev ja üle 10% kiirusepiirangut ületav sõit vähendab tulemust <?= e((string)$game['speeding_penalty']) ?> punkti võrra.</dd>
        </div>
      <?php endif; ?>
    </dl>
  </section>
<?php endif; ?>
