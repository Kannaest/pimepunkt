<section class="panel narrow">
  <h1>Admin</h1>
  <p class="muted">Sisesta admini e-mail. Saad ühekordse sisselogimislingi.</p>
  <form method="post">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <label>E-mail <input name="email" type="email" required></label>
    <button class="button primary">Saada sisselogimislink</button>
  </form>
  <p class="muted auth-alt">Uus admin? <a href="<?= e(path('/admin/register')) ?>">Registreeru adminiks</a>.</p>
</section>
