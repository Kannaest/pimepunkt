<section class="panel narrow">
  <h1>Registreeru adminiks</h1>
  <p class="muted">Sisesta e-mail. Saad turvalise sisselogimislingi ja pärast avamist saad luua oma mänge.</p>
  <form method="post">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <label>E-mail <input name="email" type="email" autocomplete="email" required></label>
    <button class="button primary">Saada admini link</button>
  </form>
  <p class="muted auth-alt">Admin juba olemas? <a href="<?= e(path('/admin/login')) ?>">Küsi sisselogimislink</a>.</p>
</section>
