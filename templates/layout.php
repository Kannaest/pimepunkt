<!doctype html>
<html lang="et">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($appName) ?></title>
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
  <link rel="stylesheet" href="<?= e(path('/assets/app.css') . '?v=' . asset_version('/assets/app.css')) ?>">
</head>
<body>
  <header class="topbar">
    <a class="brand" href="<?= e(path('/')) ?>"><span class="brand-mark"></span>Pimepunkt</a>
    <nav>
      <?php if (!empty($currentAdmin)): ?>
        <span class="role-pill"><?= (int)$currentAdmin['is_super'] === 1 ? 'Peadmin' : 'Admin' ?></span>
        <?php if (!empty($currentTeam) && (int)$currentAdmin['is_super'] !== 1): ?>
          <a href="<?= e(path('/game')) ?>" title="<?= e((string)$currentTeam['game_name']) ?>">Mängi</a>
        <?php endif; ?>
        <a href="<?= e(path('/about')) ?>">Info</a>
        <a href="<?= e(path('/admin')) ?>">Admin</a>
        <form method="post" action="<?= e(path('/admin/logout')) ?>" class="nav-form">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <button>Välju</button>
        </form>
      <?php else: ?>
        <a href="<?= e(path('/about')) ?>">Info</a>
        <a href="<?= e(path('/admin/login')) ?>">Admin</a>
      <?php endif; ?>
    </nav>
  </header>
  <main class="shell">
    <?php if ($flash): ?><div class="notice"><?= e($flash) ?></div><?php endif; ?>
    <?php partial($template, array_merge($data ?? [], ['flash' => $flash])); ?>
  </main>
  <script>
    window.PIMEPUNKT = { basePath: <?= json_encode(config()['base_path']) ?>, csrf: <?= json_encode(csrf_token()) ?> };
  </script>
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <script src="<?= e(path('/assets/app.js') . '?v=' . asset_version('/assets/app.js')) ?>"></script>
</body>
</html>
