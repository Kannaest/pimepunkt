<section class="panel narrow">
  <h1>Kinnituskood</h1>
  <form method="post">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <label>Kood <input name="code" inputmode="numeric" autocomplete="one-time-code" required></label>
    <button class="button primary">Kinnita</button>
  </form>
</section>
