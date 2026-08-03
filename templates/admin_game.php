<section class="panel">
  <div class="section-head">
    <h1><?= e($game['name']) ?></h1>
    <div class="actions">
      <form method="post" action="<?= e(path('/admin/games/' . $game['id'] . '/play')) ?>">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <button class="button">Mängi</button>
      </form>
      <a class="button" href="<?= e(path('/admin/live/' . $game['id'])) ?>">Live tulemused</a>
      <?php if ((int)$admin['is_super'] === 1 || (int)$game['created_by_admin_id'] === (int)$admin['id']): ?>
        <form method="post" action="<?= e(path('/admin/games/' . $game['id'] . '/delete')) ?>" onsubmit="return confirm('Kustutan selle mängu koos tiimide, vastuste ja punktidega?')">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <button class="button warn">Kustuta</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
  <form method="post" class="inline-form">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <label>Nimi <input name="name" value="<?= e($game['name']) ?>" required></label>
    <label>Olek
      <select name="status">
        <?php foreach (['draft','registration_open','waiting_start','running','finished','results_review','results_public'] as $status): ?>
          <option value="<?= e($status) ?>" <?= $game['status'] === $status ? 'selected' : '' ?>><?= e($status) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Kohal <input name="default_visit_points" type="number" value="<?= e((string)$game['default_visit_points']) ?>"></label>
    <label>Vale miinus <input name="default_wrong_penalty" type="number" value="<?= e((string)$game['default_wrong_penalty']) ?>"></label>
    <label>Ajapiirang minutites <input name="duration_minutes" type="number" min="0" value="<?= e((string)($game['duration_minutes'] ?? '')) ?>" placeholder="Piiramata"></label>
    <label>Stardiaken algab <input name="start_window_from" type="datetime-local" value="<?= e($game['start_window_from'] ? date('Y-m-d\TH:i', strtotime($game['start_window_from'])) : '') ?>"></label>
    <label>Stardiaken lõpeb <input name="start_window_to" type="datetime-local" value="<?= e($game['start_window_to'] ? date('Y-m-d\TH:i', strtotime($game['start_window_to'])) : '') ?>"></label>
    <?php if (config()['speed_tracking_enabled']): ?>
      <label>Kiiruseületuse miinus <input name="speeding_penalty" type="number" min="0" value="<?= e((string)($game['speeding_penalty'] ?? 7)) ?>"></label>
    <?php else: ?>
      <input name="speeding_penalty" type="hidden" value="<?= e((string)($game['speeding_penalty'] ?? 7)) ?>">
    <?php endif; ?>
    <label class="checkbox-label"><input name="auto_approve_teams" type="checkbox" value="1" <?= (int)($game['auto_approve_teams'] ?? 0) === 1 ? 'checked' : '' ?>> Kinnita registreerujad automaatselt</label>
    <label class="checkbox-label"><input name="public_results_enabled" type="checkbox" value="1" <?= (int)$game['public_results_enabled'] === 1 ? 'checked' : '' ?>> Tulemused avalikus vaates</label>
    <label class="checkbox-label"><input name="allow_gpx_export" type="checkbox" value="1" <?= (int)($game['allow_gpx_export'] ?? 0) === 1 ? 'checked' : '' ?>> Luba mängijal GPX eksport</label>
    <label class="checkbox-label"><input name="show_traffic_restrictions" type="checkbox" value="1" <?= (int)($game['show_traffic_restrictions'] ?? 1) === 1 ? 'checked' : '' ?>> Näita Tarktee piiranguid admini kaardil</label>
    <button class="button primary">Salvesta</button>
  </form>
  <div class="share-box">
    <label>Osalejate registreerimislink
      <input id="game-register-link" value="<?= e($registerLink) ?>" readonly>
    </label>
    <button class="button" type="button" data-copy-target="#game-register-link">Kopeeri link</button>
  </div>
</section>

<section class="grid two">
  <div class="panel">
    <h2>Kaart</h2>
    <?php if ($game['map_path']): ?>
      <img class="map-preview" src="<?= e(path($game['map_path'])) ?>" alt="Mängukaart">
    <?php endif; ?>
    <form method="post" action="<?= e(path('/admin/games/' . $game['id'] . '/map')) ?>" enctype="multipart/form-data">
      <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
      <label>JPG/PNG kaart <input name="map" type="file" accept="image/jpeg,image/png" required></label>
      <button class="button">Lae kaart</button>
    </form>
    <form method="post" action="<?= e(path('/admin/games/' . $game['id'] . '/map-generate')) ?>">
      <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
      <button class="button">Genereeri halltoonides kaart</button>
      <p class="muted">Loob Maa- ja Ruumiameti hallkaardist detailse 3200×2000 PNG-pildi. Vaade kohandub punktide ulatusega.</p>
    </form>
    <div class="prompt-box">
      <div class="section-head">
        <h3>AI kaardiprompt</h3>
        <button class="icon-button" type="button" data-copy-target="#map-ai-prompt">Kopeeri</button>
      </div>
      <p class="muted">Kasuta seda Nanobanana, Gemini, ChatGPT või muu pildigeneraatoriga ning lae valmis JPG/PNG siia üles.</p>
      <textarea id="map-ai-prompt" readonly rows="12"><?= e($mapPrompt) ?></textarea>
    </div>
    <div class="prompt-box">
      <div class="section-head">
        <h3>GPX import</h3>
        <a class="icon-button" href="<?= e(path('/admin/games/' . $game['id'] . '/gpx-sample')) ?>">Näidis GPX</a>
      </div>
      <form method="post" action="<?= e(path('/admin/games/' . $game['id'] . '/gpx')) ?>" enctype="multipart/form-data">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <label>GPX fail <input name="gpx" type="file" accept=".gpx,application/gpx+xml,text/xml,application/xml" required></label>
        <label class="checkbox-label"><input name="overwrite_gpx" type="checkbox" value="1"> Asenda olemasolevad punktid enne importi</label>
        <p class="muted">Kui asendamist ei vali, jäetakse sama numbriga olemasolevad punktid vahele.</p>
        <button class="button">Impordi punktid</button>
      </form>
      <div class="section-head">
        <h3>AI GPX prompt</h3>
        <button class="icon-button" type="button" data-copy-target="#gpx-ai-prompt">Kopeeri</button>
      </div>
      <textarea id="gpx-ai-prompt" readonly rows="9"><?= e($gpxPrompt) ?></textarea>
    </div>
  </div>
  <div class="panel">
    <h2>Tiimid</h2>
    <div class="list">
      <?php foreach ($teams as $team): ?>
        <div class="team-row">
          <div><b><?= e($team['name']) ?></b><br><small><?= e($team['email']) ?> · <?= e($team['status']) ?></small></div>
          <form method="post" action="<?= e(path('/admin/teams/' . $team['id'] . '/approve')) ?>"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><button class="icon-button">Kinnita</button></form>
          <form method="post" action="<?= e(path('/admin/teams/' . $team['id'] . '/reject')) ?>"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><button class="icon-button warn">Keeldu</button></form>
          <form method="post" action="<?= e(path('/admin/teams/' . $team['id'] . '/results')) ?>"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><label class="checkbox-label"><input name="excluded_from_results" type="checkbox" value="1" <?= (int)($team['excluded_from_results'] ?? 0) === 1 ? 'checked' : '' ?> onchange="this.form.submit()"> Välja arvestusest</label></form>
          <form method="post" action="<?= e(path('/admin/teams/' . $team['id'] . '/note')) ?>" class="note-form"><input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>"><input name="admin_note" value="<?= e((string)$team['admin_note']) ?>" placeholder="Märkus"><button class="icon-button">Märkus</button></form>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<?php if (config()['speed_tracking_enabled']): ?>
<section class="panel">
  <div class="section-head">
    <h2>Kiiruspiirangud</h2>
    <form method="post" action="<?= e(path('/admin/games/' . $game['id'] . '/speed-sync')) ?>">
      <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
      <button class="button">Sünkrooni OpenStreetMapist</button>
    </form>
  </div>
  <p class="muted">Sünkroonitakse ainult teed, millel on numbriline maxspeed. Tarktee ajutised sulgemised kuvatakse live-kaardil eraldi.</p>
  <form class="inline-form" method="post" action="<?= e(path('/admin/games/' . $game['id'] . '/speed-zones')) ?>">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <label>Nimi <input name="name" required></label>
    <label>Lat <input name="lat" inputmode="decimal" required></label>
    <label>Lng <input name="lng" inputmode="decimal" required></label>
    <label>Raadius m <input name="radius_m" type="number" min="10" value="100" required></label>
    <label>Piirang km/h <input name="speed_limit_kmh" type="number" min="5" max="200" required></label>
    <button class="button">Lisa ala</button>
  </form>
  <div class="list compact-list">
    <?php foreach ($speedZones as $zone): ?>
      <div class="row">
        <span><b><?= e($zone['name']) ?></b> · <?= e((string)$zone['speed_limit_kmh']) ?> km/h · <?= e($zone['source']) ?></span>
        <form method="post" action="<?= e(path('/admin/speed-zones/' . $zone['id'] . '/delete')) ?>">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <button class="icon-button warn">Kustuta</button>
        </form>
      </div>
    <?php endforeach; ?>
  </div>
</section>
<?php else: ?>
<section class="panel">
  <h2>Kiirusehaldus</h2>
  <p class="muted">Brauseriversioonis on kiiruse arvutamine ja karistused pausil. Asukohalogimine jätkub ilma kiiruse hindamiseta.</p>
</section>
<?php endif; ?>

<?php $canManageGameAdmins = (int)$admin['is_super'] === 1 || (int)$game['created_by_admin_id'] === (int)$admin['id']; ?>
<section class="grid two">
  <div class="panel">
    <h2>Mängu adminid</h2>
    <div class="list">
      <?php foreach ($gameAdmins as $row): ?>
        <div class="row">
          <span><?= e($row['email']) ?><?= (int)$game['created_by_admin_id'] === (int)$row['id'] ? ' · looja' : '' ?></span>
          <?php if ($canManageGameAdmins && (int)$game['created_by_admin_id'] !== (int)$row['id']): ?>
            <form method="post" action="<?= e(path('/admin/games/' . $game['id'] . '/admins/' . $row['id'] . '/delete')) ?>">
              <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
              <button class="icon-button warn">Eemalda</button>
            </form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php if ($canManageGameAdmins): ?>
    <div class="panel">
      <h2>Lisa mängu admin</h2>
      <form method="post" action="<?= e(path('/admin/games/' . $game['id'] . '/admins')) ?>">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <label>Admin
          <select name="admin_id" required>
            <?php foreach ($allAdmins as $row): ?>
              <option value="<?= e((string)$row['id']) ?>"><?= e($row['email']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <button class="button primary">Lisa mängule</button>
      </form>
    </div>
  <?php endif; ?>
</section>

<section class="grid two">
  <div class="panel">
    <h2>Punktide kaart</h2>
    <div class="map-toolbar">
      <label>Kiht
        <select data-admin-map-layer>
          <option value="kaart">Eesti kaart</option>
          <option value="hallkaart">Hall kaart</option>
          <option value="foto">Ortofoto</option>
          <option value="hybriid">Hübriid</option>
        </select>
      </label>
      <label class="checkbox-label"><input type="checkbox" data-admin-hide-numbers> Peida numbrid</label>
      <button class="icon-button" type="button" data-admin-rotate-map>Keera 90°</button>
      <button class="icon-button" type="button" data-admin-large-map>Suur kaart</button>
    </div>
    <div class="leaflet-map" data-admin-checkpoint-map data-points='<?= e(json_encode(array_map(fn($cp) => [
      "id" => (int)$cp["id"],
      "number" => $cp["number"],
      "title" => $cp["title"],
      "lat" => (float)$cp["lat"],
      "lng" => (float)$cp["lng"],
      "difficulty" => checkpoint_difficulty($cp["difficulty"] ?? 1),
    ], $mapPoints), JSON_UNESCAPED_UNICODE)) ?>'></div>
    <div class="difficulty-legend" aria-label="Punktide raskused">
      <?php foreach ([1, 2, 3, 4, 5, 6] as $difficulty): ?>
        <span><i class="difficulty-icon difficulty-<?= e((string)$difficulty) ?>"></i><?= e(checkpoint_difficulty_label($difficulty)) ?> · <?= e((string)((int)$game['default_visit_points'] + checkpoint_difficulty_bonus($difficulty))) ?> p</span>
      <?php endforeach; ?>
    </div>
    <p class="muted" data-map-edit-status>Klõps kaardil täidab uue punkti koordinaadid.</p>
  </div>
  <div class="panel">
    <div class="section-head">
      <h2>Uus punkt</h2>
      <button class="icon-button" type="button" data-select-new-checkpoint>Lisa uus punkt</button>
    </div>
    <form method="post" action="<?= e(path('/admin/games/' . $game['id'] . '/checkpoints')) ?>" data-new-checkpoint-form>
      <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
      <label>Number <input name="number" value="<?= e($nextNumber) ?>" required></label>
      <label>Nimi <input name="title" required></label>
      <div class="grid two compact"><label>Lat <input name="lat" data-map-lat required></label><label>Lng <input name="lng" data-map-lng required></label></div>
      <label>Raadius meetrites <input name="radius_m" type="number" value="50" required></label>
      <label>Raskus
        <select name="difficulty">
          <option value="1">Ring · kerge · <?= e((string)$game['default_visit_points']) ?> p</option>
          <option value="2">Kolmnurk · keerukam · <?= e((string)((int)$game['default_visit_points'] + 2)) ?> p</option>
          <option value="3">Nelinurk · keerukas · <?= e((string)((int)$game['default_visit_points'] + 4)) ?> p</option>
          <option value="4">Viisnurk · eriti keerukas · <?= e((string)((int)$game['default_visit_points'] + 7)) ?> p</option>
          <option value="5">Kuusnurk · väga keerukas · <?= e((string)((int)$game['default_visit_points'] + 10)) ?> p</option>
          <option value="6">Seitsenurk · ekstreemne · <?= e((string)((int)$game['default_visit_points'] + 13)) ?> p</option>
        </select>
      </label>
      <div class="grid two compact"><label>Punktid <input name="visit_points" type="number" placeholder="<?= e((string)$game['default_visit_points']) ?>"></label><label>Vale miinus <input name="wrong_penalty" type="number" placeholder="default"></label></div>
      <label>Küsimuse tüüp
        <select name="question_type">
          <option value="choice">Valikvastus</option>
          <option value="ok">OK / kohal</option>
        </select>
      </label>
      <label>Küsimus <textarea name="question_text" required></textarea></label>
      <?php for ($i = 0; $i < 5; $i++): ?>
        <label>Valik <?= e((string)($i + 1)) ?> <input name="option_label[<?= e((string)$i) ?>]"></label>
      <?php endfor; ?>
      <label>Õige valik
        <select name="correct_option">
          <?php for ($i = 0; $i < 5; $i++): ?>
            <option value="<?= e((string)$i) ?>"><?= e((string)($i + 1)) ?></option>
          <?php endfor; ?>
        </select>
      </label>
      <button class="button primary">Lisa punkt</button>
    </form>
  </div>
  <div class="panel span-two">
    <div class="section-head">
      <div>
        <h2>Punktid</h2>
        <p class="muted"><?= e((string)$checkpointCount) ?> tulemust · leht <?= e((string)$checkpointPage) ?>/<?= e((string)$checkpointPages) ?></p>
      </div>
      <?php if ($selectedCheckpointId): ?><a class="button" href="<?= e(path('/admin/games/' . $game['id'])) ?>#checkpoints">Näita kõiki</a><?php endif; ?>
    </div>
    <form class="search-form checkpoint-search" method="get" action="<?= e(path('/admin/games/' . $game['id'])) ?>">
      <label>Otsi numbri või nime järgi <input name="checkpoint_search" value="<?= e($checkpointSearch) ?>" placeholder="Punkt"></label>
      <button class="button">Otsi</button>
      <?php if ($checkpointSearch !== ''): ?><a class="button" href="<?= e(path('/admin/games/' . $game['id'])) ?>#checkpoints">Tühjenda</a><?php endif; ?>
    </form>
    <div id="checkpoints" class="pagination" aria-label="Punktide lehed">
      <?php if ($checkpointPage > 1): ?>
        <a class="button" href="<?= e(path('/admin/games/' . $game['id']) . '?' . http_build_query(['checkpoint_page' => $checkpointPage - 1, 'checkpoint_search' => $checkpointSearch])) ?>#checkpoints">Eelmine</a>
      <?php endif; ?>
      <span>Leht <?= e((string)$checkpointPage) ?>/<?= e((string)$checkpointPages) ?></span>
      <?php if ($checkpointPage < $checkpointPages): ?>
        <a class="button" href="<?= e(path('/admin/games/' . $game['id']) . '?' . http_build_query(['checkpoint_page' => $checkpointPage + 1, 'checkpoint_search' => $checkpointSearch])) ?>#checkpoints">Järgmine</a>
      <?php endif; ?>
    </div>
    <div class="list">
      <?php foreach ($checkpoints as $cp): ?>
        <?php
          $cpOptions = array_values($checkpointOptions[(int)$cp['question_id']] ?? []);
          $correctIndex = 0;
          foreach ($cpOptions as $index => $option) {
              if ((int)$option['is_correct'] === 1) {
                  $correctIndex = $index;
              }
          }
        ?>
        <form id="checkpoint-<?= e((string)$cp['id']) ?>" class="checkpoint-edit" method="post" action="<?= e(path('/admin/checkpoints/' . $cp['id'])) ?>" data-checkpoint-form data-checkpoint-id="<?= e((string)$cp['id']) ?>" data-checkpoint-number="<?= e($cp['number']) ?>">
          <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
          <div class="section-head">
            <h3><span class="point-badge"><?= e($cp['number']) ?></span> <?= e($cp['title']) ?></h3>
            <div class="actions">
              <button class="icon-button" type="button" data-select-checkpoint>Muuda kaardil</button>
              <button class="icon-button">Salvesta</button>
              <button class="icon-button warn" formaction="<?= e(path('/admin/checkpoints/' . $cp['id'] . '/delete')) ?>" formmethod="post" formnovalidate onclick="return confirm('Kustutan selle punkti koos küsimuse ja vastustega?')">Kustuta</button>
            </div>
          </div>
          <div class="grid two compact">
            <label>Number <input name="number" value="<?= e($cp['number']) ?>" required></label>
            <label>Nimi <input name="title" value="<?= e($cp['title']) ?>" required></label>
          </div>
          <div class="grid two compact">
            <label>Lat <input name="lat" value="<?= e((string)$cp['lat']) ?>" data-map-lat required></label>
            <label>Lng <input name="lng" value="<?= e((string)$cp['lng']) ?>" data-map-lng required></label>
          </div>
          <div class="grid two compact">
            <label>Raadius <input name="radius_m" type="number" value="<?= e((string)$cp['radius_m']) ?>" required></label>
            <label>Raskus
              <select name="difficulty">
                <?php foreach ([1 => 'Ring · kerge', 2 => 'Kolmnurk · keerukam', 3 => 'Nelinurk · keerukas', 4 => 'Viisnurk · eriti keerukas', 5 => 'Kuusnurk · väga keerukas', 6 => 'Seitsenurk · ekstreemne'] as $difficulty => $label): ?>
                  <option value="<?= e((string)$difficulty) ?>" <?= checkpoint_difficulty($cp['difficulty'] ?? 1) === $difficulty ? 'selected' : '' ?>><?= e($label) ?> · <?= e((string)((int)$game['default_visit_points'] + checkpoint_difficulty_bonus($difficulty))) ?> p</option>
                <?php endforeach; ?>
              </select>
            </label>
          </div>
          <div class="grid two compact">
            <label>Punktid <input name="visit_points" type="number" value="<?= e((string)($cp['visit_points'] ?? '')) ?>" placeholder="<?= e((string)checkpoint_visit_points($cp, $game)) ?>"></label>
            <label>Vale miinus <input name="wrong_penalty" type="number" value="<?= e((string)($cp['wrong_penalty'] ?? '')) ?>" placeholder="default"></label>
            <label>Küsimuse tüüp
              <select name="question_type">
                <option value="choice" <?= ($cp['question_type'] ?? '') === 'choice' ? 'selected' : '' ?>>Valikvastus</option>
                <option value="ok" <?= ($cp['question_type'] ?? '') === 'ok' ? 'selected' : '' ?>>OK / kohal</option>
              </select>
            </label>
          </div>
          <label>Küsimus <textarea name="question_text" required><?= e((string)($cp['question_text'] ?? '')) ?></textarea></label>
          <div class="grid two compact">
            <?php for ($i = 0; $i < 5; $i++): ?>
              <label>Valik <?= e((string)($i + 1)) ?> <input name="option_label[<?= e((string)$i) ?>]" value="<?= e((string)($cpOptions[$i]['label'] ?? '')) ?>"></label>
            <?php endfor; ?>
            <label>Õige valik
              <select name="correct_option">
                <?php for ($i = 0; $i < 5; $i++): ?>
                  <option value="<?= e((string)$i) ?>" <?= $correctIndex === $i ? 'selected' : '' ?>><?= e((string)($i + 1)) ?></option>
                <?php endfor; ?>
              </select>
            </label>
          </div>
        </form>
      <?php endforeach; ?>
    </div>
    <?php if ($checkpointPages > 1): ?>
      <div class="pagination" aria-label="Punktide lehed">
        <?php if ($checkpointPage > 1): ?><a class="button" href="<?= e(path('/admin/games/' . $game['id']) . '?' . http_build_query(['checkpoint_page' => $checkpointPage - 1, 'checkpoint_search' => $checkpointSearch])) ?>#checkpoints">Eelmine</a><?php endif; ?>
        <span>Leht <?= e((string)$checkpointPage) ?>/<?= e((string)$checkpointPages) ?></span>
        <?php if ($checkpointPage < $checkpointPages): ?><a class="button" href="<?= e(path('/admin/games/' . $game['id']) . '?' . http_build_query(['checkpoint_page' => $checkpointPage + 1, 'checkpoint_search' => $checkpointSearch])) ?>#checkpoints">Järgmine</a><?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</section>
