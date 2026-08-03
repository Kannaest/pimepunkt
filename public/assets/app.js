(function () {
  function qs(selector, root) { return (root || document).querySelector(selector); }
  function qsa(selector, root) { return Array.from((root || document).querySelectorAll(selector)); }

  qsa('[data-tab]').forEach(function (button) {
    button.addEventListener('click', function () {
      qsa('[data-tab]').forEach(function (b) { b.classList.remove('active'); });
      qsa('.game-tab').forEach(function (tab) { tab.classList.remove('active'); });
      button.classList.add('active');
      qs('#tab-' + button.dataset.tab).classList.add('active');
    });
  });

  qsa('[data-copy-target]').forEach(function (button) {
    button.addEventListener('click', function () {
      var target = qs(button.dataset.copyTarget);
      if (!target) return;
      var text = target.value || target.textContent || '';
      navigator.clipboard.writeText(text).then(function () {
        var original = button.textContent;
        button.textContent = 'Kopeeritud';
        setTimeout(function () { button.textContent = original; }, 1600);
      }).catch(function () {
        target.focus();
        target.select();
      });
    });
  });

  qsa('[data-register-form]').forEach(function (form) {
    form.addEventListener('submit', function () {
      var button = qs('[data-register-submit]', form);
      var status = qs('[data-register-status]', form);
      form.classList.add('is-sending');
      if (button) {
        button.disabled = true;
        button.textContent = 'Saadan...';
      }
      if (status) {
        status.textContent = 'Saadame registreerimislingi e-mailile. Kontrolli postkasti samas telefonis.';
        status.classList.add('success');
      }
    });
  });

  qsa('[data-question-toggle]').forEach(function (button) {
    button.addEventListener('click', function () {
      var row = button.closest('.question-row');
      var main = qs('.question-main', row);
      if (!main) return;
      var willOpen = main.hidden;
      qsa('.question-row .question-main').forEach(function (item) {
        item.hidden = true;
        if (item.parentElement) item.parentElement.classList.remove('open');
      });
      main.hidden = !willOpen;
      row.classList.toggle('open', willOpen);
    });
  });

  var mapImage = qs('[data-panzoom]');
  if (mapImage) {
    var state = JSON.parse(localStorage.getItem('pimepunkt-map') || '{"x":0,"y":0,"scale":1}');
    var pointers = new window.Map();
    var last = null;
    var pinchStart = null;
    var apply = function () {
      mapImage.style.transform = 'translate(' + state.x + 'px,' + state.y + 'px) scale(' + state.scale + ')';
      localStorage.setItem('pimepunkt-map', JSON.stringify(state));
    };
    var resetButton = qs('[data-map-reset]');
    if (resetButton) {
      resetButton.addEventListener('click', function () {
        state = { x: 0, y: 0, scale: 1 };
        apply();
      });
    }
    var pointerDistance = function () {
      var values = Array.from(pointers.values());
      if (values.length < 2) return 0;
      var dx = values[0].x - values[1].x;
      var dy = values[0].y - values[1].y;
      return Math.sqrt(dx * dx + dy * dy);
    };
    apply();
    mapImage.parentElement.addEventListener('pointerdown', function (event) {
      pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
      last = { x: event.clientX, y: event.clientY };
      if (pointers.size === 2) {
        pinchStart = { distance: pointerDistance(), scale: state.scale };
      }
      mapImage.parentElement.setPointerCapture(event.pointerId);
    });
    mapImage.parentElement.addEventListener('pointermove', function (event) {
      if (!pointers.has(event.pointerId)) return;
      pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
      if (pointers.size >= 2 && pinchStart) {
        state.scale = Math.min(6, Math.max(0.5, pinchStart.scale * (pointerDistance() / pinchStart.distance)));
      } else if (last) {
        state.x += event.clientX - last.x;
        state.y += event.clientY - last.y;
        last = { x: event.clientX, y: event.clientY };
      }
      apply();
    });
    ['pointerup', 'pointercancel'].forEach(function (name) {
      mapImage.parentElement.addEventListener(name, function (event) {
        pointers.delete(event.pointerId);
        pinchStart = null;
        last = null;
      });
    });
    mapImage.parentElement.addEventListener('wheel', function (event) {
      event.preventDefault();
      var next = Math.min(5, Math.max(0.5, state.scale + (event.deltaY < 0 ? 0.15 : -0.15)));
      state.scale = next;
      apply();
    }, { passive: false });
  }

  function distanceMeters(a, b, c, d) {
    var r = 6371000;
    var toRad = function (v) { return v * Math.PI / 180; };
    var dLat = toRad(c - a);
    var dLng = toRad(d - b);
    var x = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
      Math.cos(toRad(a)) * Math.cos(toRad(c)) * Math.sin(dLng / 2) * Math.sin(dLng / 2);
    return r * 2 * Math.atan2(Math.sqrt(x), Math.sqrt(1 - x));
  }

  function updateLocation(onComplete) {
    var status = qs('#gps-status');
    var warning = qs('[data-gps-warning]');
    var rows = qsa('.question-row[data-lat]');
    if (!rows.length) return;
    if (!navigator.geolocation) {
      showGpsWarning('Sinu brauser ei toeta asukoha jagamist. Ava mäng Chrome, Safari või Edge brauseris ja proovi uuesti.');
      if (status) status.textContent = 'Asukohta ei saa selles brauseris kasutada.';
      return;
    }
    hideGpsWarning();
    if (status) status.textContent = 'Küsin asukohta...';
    navigator.geolocation.getCurrentPosition(function (pos) {
      var lat = pos.coords.latitude;
      var lng = pos.coords.longitude;
      var accuracy = pos.coords.accuracy || '';
      lastLocationAt = Date.now();
      renderTelemetryClock();
      hideGpsWarning();
      if (status) status.textContent = 'Asukoht uuendatud. Täpsus umbes ' + Math.round(accuracy) + ' m.';
      rows.forEach(function (row) {
        var d = distanceMeters(lat, lng, parseFloat(row.dataset.lat), parseFloat(row.dataset.lng));
        row.dataset.distance = String(d);
        qs('[data-distance]', row).textContent = formatDistance(d);
        qsa('[data-answer-lat]', row).forEach(function (el) { el.value = lat; });
        qsa('[data-answer-lng]', row).forEach(function (el) { el.value = lng; });
        qsa('[data-answer-accuracy]', row).forEach(function (el) { el.value = accuracy; });
        var button = qs('[data-answer-button]', row);
        if (button) button.disabled = button.dataset.gameBlocked === '1' || d > parseFloat(row.dataset.radius);
      });
      qsa('[data-pause-lat]').forEach(function (el) { el.value = lat; });
      qsa('[data-pause-lng]').forEach(function (el) { el.value = lng; });
      var list = qs('[data-question-list]');
      rows.sort(function (a, b) { return parseFloat(a.dataset.distance) - parseFloat(b.dataset.distance); })
        .forEach(function (row) { list.appendChild(row); });
      logLocation(lat, lng, accuracy).then(function (telemetry) {
        renderSpeedTelemetry(telemetry);
        if (typeof onComplete === 'function') onComplete(Boolean(telemetry));
      });
    }, function (error) {
      showGpsWarning(gpsErrorMessage(error));
      if (status) status.textContent = 'GPS ei ole lubatud või asukohta ei saanud kätte.';
      if (typeof onComplete === 'function') onComplete(false);
    }, { enableHighAccuracy: true, timeout: 12000, maximumAge: 10000 });

    function showGpsWarning(message) {
      if (!warning) return;
      warning.textContent = message;
      warning.hidden = false;
    }

    function hideGpsWarning() {
      if (!warning) return;
      warning.hidden = true;
      warning.textContent = '';
    }
  }

  function gpsErrorMessage(error) {
    if (error && error.code === 1) {
      return 'Asukoha luba on keelatud. Luba brauseris selle lehe asukoht: vajuta aadressirea luku või saidi seadete ikooni, vali Asukoht ja pane Luba. iPhone Safaris kontrolli ka Settings > Privacy & Security > Location Services > Safari Websites. Android Chrome’is: lukuikoon > Permissions > Location > Allow. Seejärel vajuta "Uuenda asukohta".';
    }
    if (error && error.code === 3) {
      return 'Asukoha leidmine võttis liiga kaua. Mine võimalusel õue või akna lähedale, lülita telefonis Location Services/GPS sisse ja vajuta "Uuenda asukohta".';
    }
    return 'Asukohta ei saanud kätte. Kontrolli, et telefonis on Location Services/GPS sees, brauseril on asukoha luba ja internetiühendus töötab. Seejärel vajuta "Uuenda asukohta".';
  }

  function formatDistance(meters) {
    if (meters < 1000) return Math.max(100, Math.round(meters / 100) * 100) + ' m';
    if (meters < 10000) return (Math.round(meters / 500) * 500 / 1000).toFixed(1).replace('.0', '') + ' km';
    return Math.round(meters / 1000) + ' km';
  }

  function logLocation(lat, lng, accuracy) {
    if (!window.PIMEPUNKT) return Promise.resolve(null);
    return fetch(window.PIMEPUNKT.basePath + '/location', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ lat: lat, lng: lng, accuracy: accuracy })
    }).then(function (response) {
      if (!response.ok || response.status === 204) return null;
      return response.json().catch(function () { return null; });
    }).catch(function () { return null; });
  }

  var lastLocationAt = null;
  var speedingSince = null;
  var speedState = qs('[data-speed-state]');
  var speedValue = qs('[data-current-speed]');
  var speedLimit = qs('[data-speed-limit]');
  var locationAge = qs('[data-location-age]');
  var speedingDuration = qs('[data-speeding-duration]');

  function renderSpeedTelemetry(data) {
    if (!data || data.ignored === 'too_frequent') return;
    if (speedValue) {
      speedValue.textContent = typeof data.speed === 'number' ? Math.round(data.speed) + ' km/h' : '— km/h';
    }
    if (speedLimit) {
      speedLimit.textContent = typeof data.limit === 'number' ? data.limit + ' km/h' : 'Puudub';
    }
    if (data.speeding) {
      speedingSince = Date.now() - Math.max(0, Number(data.speeding_seconds) || 0) * 1000;
    } else {
      speedingSince = null;
    }
    if (speedState) speedState.classList.toggle('over-limit', Boolean(data.speeding));
    renderTelemetryClock();
  }

  function renderTelemetryClock() {
    if (locationAge) {
      locationAge.textContent = lastLocationAt === null
        ? 'Ootan GPS-i'
        : Math.max(0, Math.floor((Date.now() - lastLocationAt) / 1000)) + ' s tagasi';
    }
    if (speedingDuration) {
      if (speedingSince === null) {
        speedingDuration.hidden = true;
        speedingDuration.textContent = '';
      } else {
        var seconds = Math.max(0, Math.floor((Date.now() - speedingSince) / 1000));
        speedingDuration.hidden = false;
        speedingDuration.textContent = seconds > 10 ? 'Ületus ' + seconds + ' s · karistus' : 'Ületus ' + seconds + ' / 10 s';
      }
    }
  }

  if (locationAge) {
    renderTelemetryClock();
    setInterval(renderTelemetryClock, 1000);
  }

  var refresh = qs('[data-refresh-location]');
  if (refresh) {
    refresh.addEventListener('click', updateLocation);
    qsa('.question-row[data-lat]').forEach(function (row) {
      row.addEventListener('submit', function (event) {
        var lat = qs('[data-answer-lat]', row);
        var lng = qs('[data-answer-lng]', row);
        if (!lat || !lng || !lat.value || !lng.value) {
          event.preventDefault();
          var warning = qs('[data-gps-warning]');
          if (warning) {
            warning.textContent = 'Vastamiseks on vaja asukohta. Luba brauseris asukoht ja vajuta "Uuenda asukohta". Kui luba oli keelatud, ava aadressirea luku või saidi seadete alt Asukoht ja vali Luba.';
            warning.hidden = false;
          }
          updateLocation();
        }
      });
    });
    updateLocation();
    setInterval(updateLocation, 30000);
    if (navigator.geolocation) {
      var lastWatchUpdate = 0;
      navigator.geolocation.watchPosition(function () {
        if (Date.now() - lastWatchUpdate < 5000) return;
        lastWatchUpdate = Date.now();
        updateLocation();
      }, function (error) {
        var warning = qs('[data-gps-warning]');
        if (warning) {
          warning.textContent = gpsErrorMessage(error);
          warning.hidden = false;
        }
      }, { enableHighAccuracy: true, maximumAge: 15000, timeout: 12000 });
    }
  }

  var refreshNearest = qs('[data-refresh-nearest]');
  if (refreshNearest) {
    refreshNearest.addEventListener('click', function () {
      refreshNearest.disabled = true;
      refreshNearest.textContent = 'Leian asukohta...';
      updateLocation(function (ok) {
        if (ok) {
          window.location.reload();
        } else {
          refreshNearest.disabled = false;
          refreshNearest.textContent = 'Leia uuesti lähimad punktid';
        }
      });
    });
  }

  var pauseForm = qs('[data-pause-form]');
  if (pauseForm) {
    pauseForm.addEventListener('submit', function (event) {
      var lat = qs('[data-pause-lat]', pauseForm);
      var lng = qs('[data-pause-lng]', pauseForm);
      if (!lat.value || !lng.value) {
        event.preventDefault();
        updateLocation();
        var warning = qs('[data-gps-warning]');
        if (warning) {
          warning.textContent = 'Pausi või jätkamise kinnitamiseks oota, kuni GPS-asukoht on leitud, ja proovi uuesti.';
          warning.hidden = false;
        }
      }
    });
  }

  var gameClock = qs('[data-game-clock]');
  if (gameClock) {
    var clockValue = qs('[data-game-clock-value]', gameClock);
    var renderClock = function () {
      if (gameClock.dataset.paused === '1') {
        clockValue.textContent = 'Pausil';
        return;
      }
      var remaining = Math.max(0, Math.floor((new Date(gameClock.dataset.deadline).getTime() - Date.now()) / 1000));
      var hours = Math.floor(remaining / 3600);
      var minutes = Math.floor((remaining % 3600) / 60);
      var seconds = remaining % 60;
      clockValue.textContent = [hours, minutes, seconds].map(function (v) { return String(v).padStart(2, '0'); }).join(':');
      gameClock.classList.toggle('expired', remaining === 0);
    };
    renderClock();
    setInterval(renderClock, 1000);
  }

  function maaametLayer(layerName) {
    var layer = layerName || 'kaart';
    var ext = layer === 'foto' ? 'jpg' : 'png';
    return L.tileLayer('https://tiles.maaamet.ee/tm/tms/1.0.0/' + layer + '@GMC/{z}/{x}/{y}.' + ext + '&ASUTUS=MAAAMET&KESKKOND=LIVE&IS=PIMEPUNKT', {
      tms: true,
      minZoom: 0,
      maxZoom: 18,
      attribution: 'Maa- ja Ruumiamet'
    });
  }

  function initRegistrationAreaMap() {
    var el = qs('[data-registration-area-map]');
    if (!el || !window.L) return;
    var bounds = JSON.parse(el.dataset.bounds || '[]');
    if (!Array.isArray(bounds) || bounds.length !== 2) return;
    var leaflet = L.map(el, { scrollWheelZoom: false, attributionControl: true });
    maaametLayer('hallkaart').addTo(leaflet);
    var area = L.latLngBounds(bounds);
    L.rectangle(area, {
      color: '#d22f2f',
      weight: 4,
      opacity: 1,
      fillColor: '#d22f2f',
      fillOpacity: 0.08,
      interactive: false
    }).addTo(leaflet);
    leaflet.fitBounds(area, { padding: [34, 34], maxZoom: 13 });
  }

  function initPauseLocationMap() {
    var el = qs('[data-pause-location-map]');
    if (!el || !window.L) return;
    var lat = parseFloat(el.dataset.lat);
    var lng = parseFloat(el.dataset.lng);
    if (Number.isNaN(lat) || Number.isNaN(lng)) return;
    var leaflet = L.map(el, { scrollWheelZoom: false }).setView([lat, lng], 16);
    maaametLayer('hallkaart').addTo(leaflet);
    L.circle([lat, lng], {
      radius: 100,
      color: '#d22f2f',
      weight: 3,
      fillColor: '#d22f2f',
      fillOpacity: 0.09
    }).addTo(leaflet);
    L.circleMarker([lat, lng], {
      radius: 6,
      color: '#fff',
      weight: 2,
      fillColor: '#d22f2f',
      fillOpacity: 1
    }).addTo(leaflet).bindTooltip('Pausikoht', { permanent: true, direction: 'top' });
  }

  function initAdminCheckpointMap() {
    var el = qs('[data-admin-checkpoint-map]');
    if (!el || !window.L) return;
    var points = JSON.parse(el.dataset.points || '[]');
    var center = points.length ? [points[0].lat, points[0].lng] : [58.75, 25.0];
    var denseMap = points.length > 500;
    var leaflet = L.map(el, { preferCanvas: denseMap }).setView(center, points.length ? 13 : 7);
    var newForm = qs('[data-new-checkpoint-form]');
    var status = qs('[data-map-edit-status]');
    var selectedForm = newForm;
    var markers = {};
    var markerLayer = L.layerGroup().addTo(leaflet);
    var currentBaseLayer = null;
    var numbersHidden = false;
    var rotation = 0;

    function setStatus(text) {
      if (status) status.textContent = text;
    }

    function markActive(form) {
      qsa('[data-new-checkpoint-form], [data-checkpoint-form]').forEach(function (item) {
        item.classList.toggle('map-target-active', item === form);
      });
    }

    function setSelectedForm(form, focusMap) {
      if (!form) return;
      selectedForm = form;
      markActive(form);
      if (form.dataset.checkpointId) {
        setStatus('Kaardi klõps muudab punkti ' + form.dataset.checkpointNumber + ' koordinaate.');
        var lat = parseFloat(qs('[data-map-lat]', form).value);
        var lng = parseFloat(qs('[data-map-lng]', form).value);
        if (!Number.isNaN(lat) && !Number.isNaN(lng)) {
          leaflet.flyTo([lat, lng], Math.max(leaflet.getZoom(), 15), { duration: 0.35 });
          if (markers[form.dataset.checkpointId]) markers[form.dataset.checkpointId].openPopup();
        }
      } else {
        setStatus('Kaardi klõps täidab uue punkti koordinaadid.');
      }
      if (focusMap) {
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    }

    function setFormLatLng(form, latLng) {
      var lat = qs('[data-map-lat]', form);
      var lng = qs('[data-map-lng]', form);
      if (lat && lng) {
        lat.value = latLng.lat.toFixed(7);
        lng.value = latLng.lng.toFixed(7);
      }
    }

    function setBaseLayer(layerName) {
      if (currentBaseLayer) leaflet.removeLayer(currentBaseLayer);
      currentBaseLayer = maaametLayer(layerName).addTo(leaflet);
      currentBaseLayer.bringToBack();
    }

    function checkpointIcon(point) {
      var difficulty = Math.max(1, Math.min(6, parseInt(point.difficulty, 10) || 1));
      var shapes = {
        1: '<circle cx="18" cy="18" r="11"></circle>',
        2: '<polygon points="18,5 31,30 5,30"></polygon>',
        3: '<rect x="7" y="7" width="22" height="22"></rect>',
        4: '<polygon points="18,4 32,15 27,31 9,31 4,15"></polygon>',
        5: '<polygon points="9,5 27,5 33,18 27,31 9,31 3,18"></polygon>',
        6: '<polygon points="18,3 30,9 33,22 25,32 11,32 3,22 6,9"></polygon>'
      };
      var number = String(point.number).replace(/[&<>"']/g, function (character) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[character];
      });
      return L.divIcon({
        html: '<span class="checkpoint-marker checkpoint-marker-difficulty-' + difficulty + '"><svg viewBox="0 0 36 36" aria-hidden="true">' + shapes[difficulty] + '<circle class="checkpoint-marker-center" cx="18" cy="18" r="3.5"></circle></svg><b>' + number + '</b></span>',
        className: 'checkpoint-marker-wrap' + (numbersHidden ? ' numbers-hidden' : ''),
        iconSize: [36, 36],
        iconAnchor: [18, 18],
        popupAnchor: [0, -18]
      });
    }

    function denseCheckpointMarker(point) {
      var difficulty = Math.max(1, Math.min(6, parseInt(point.difficulty, 10) || 1));
      var marker = L.featureGroup();
      marker.setLatLng = function (latLng) {
        var center = L.latLng(latLng);
        var pathOptions = { color: '#d22f2f', weight: 2, opacity: 1, fill: false };
        marker.clearLayers();
        if (difficulty === 1) {
          marker.addLayer(L.circleMarker(center, Object.assign({ radius: 8 }, pathOptions)));
        } else {
          var centerPoint = leaflet.latLngToLayerPoint(center);
          var sides = difficulty + 1;
          var vertices = [];
          for (var index = 0; index < sides; index += 1) {
            var angle = -Math.PI / 2 + index * 2 * Math.PI / sides;
            vertices.push(leaflet.layerPointToLatLng([
              centerPoint.x + Math.cos(angle) * 9,
              centerPoint.y + Math.sin(angle) * 9
            ]));
          }
          marker.addLayer(L.polygon(vertices, pathOptions));
        }
        marker.addLayer(L.circleMarker(center, {
          radius: 3,
          stroke: false,
          fillColor: '#d22f2f',
          fillOpacity: 1
        }));
        return marker;
      };
      return marker.setLatLng([point.lat, point.lng]);
    }

    function drawMarkers() {
      markerLayer.clearLayers();
      markers = {};
      points.forEach(function (p) {
        var popup = document.createElement('span');
        popup.textContent = p.number + ' ' + p.title;
        var marker = denseMap
          ? denseCheckpointMarker(p)
          : L.marker([p.lat, p.lng], { icon: checkpointIcon(p) });
        marker.addTo(markerLayer).bindPopup(popup);
        markers[String(p.id)] = marker;
        marker.on('click', function () {
          var form = qs('[data-checkpoint-form][data-checkpoint-id="' + p.id + '"]');
          if (form) {
            setSelectedForm(form, false);
            form.scrollIntoView({ behavior: 'smooth', block: 'center' });
          } else {
            window.location.href = window.location.pathname + '?checkpoint_id=' + encodeURIComponent(p.id) + '#checkpoint-' + encodeURIComponent(p.id);
          }
        });
      });
    }

    function applyRotation() {
      el.classList.toggle('map-rotated-90', rotation % 360 !== 0);
      setStatus(rotation % 360 === 0 ? 'Kaardi klõps täidab valitud punkti koordinaadid.' : 'Kaart on visuaalselt 90° pööratud. Täpseks punkti tõstmiseks keera tagasi.');
      setTimeout(function () { leaflet.invalidateSize(); }, 80);
    }

    setBaseLayer('kaart');
    if (points.length) {
      leaflet.fitBounds(points.map(function (p) { return [p.lat, p.lng]; }), { padding: [30, 30] });
    }
    drawMarkers();
    if (denseMap) {
      leaflet.on('zoomend', drawMarkers);
    }
    leaflet.on('click', function (event) {
      setFormLatLng(selectedForm || newForm, event.latlng);
      if (selectedForm && selectedForm.dataset.checkpointId && markers[selectedForm.dataset.checkpointId]) {
        markers[selectedForm.dataset.checkpointId].setLatLng(event.latlng);
      }
    });

    qsa('[data-select-checkpoint]').forEach(function (button) {
      button.addEventListener('click', function () {
        setSelectedForm(button.closest('[data-checkpoint-form]'), true);
      });
    });
    var selectNew = qs('[data-select-new-checkpoint]');
    if (selectNew) {
      selectNew.addEventListener('click', function () {
        setSelectedForm(newForm, true);
      });
    }
    var layerSelect = qs('[data-admin-map-layer]');
    if (layerSelect) {
      layerSelect.addEventListener('change', function () {
        setBaseLayer(layerSelect.value);
      });
    }
    var hideNumbers = qs('[data-admin-hide-numbers]');
    if (hideNumbers) {
      hideNumbers.addEventListener('change', function () {
        numbersHidden = hideNumbers.checked;
        drawMarkers();
      });
    }
    var rotateButton = qs('[data-admin-rotate-map]');
    if (rotateButton) {
      rotateButton.addEventListener('click', function () {
        rotation = (rotation + 90) % 180;
        rotateButton.textContent = rotation ? 'Keera tagasi' : 'Keera 90°';
        applyRotation();
      });
    }
    var largeButton = qs('[data-admin-large-map]');
    if (largeButton) {
      largeButton.addEventListener('click', function () {
        el.classList.toggle('large-map');
        largeButton.textContent = el.classList.contains('large-map') ? 'Tavaline kaart' : 'Suur kaart';
        setTimeout(function () { leaflet.invalidateSize(); }, 80);
      });
    }
    setSelectedForm(newForm, false);
  }

  function initAdminLiveMap() {
    var el = qs('[data-admin-live-map]');
    if (!el || !window.L || !window.PIMEPUNKT) return;
    var leaflet = L.map(el).setView([58.75, 25.0], 7);
    var liveLayer = L.layerGroup().addTo(leaflet);
    var trafficLayer = L.layerGroup().addTo(leaflet);
    var didFit = false;
    function popupContent(lines) {
      var container = document.createElement('div');
      lines.filter(Boolean).forEach(function (line, index) {
        if (index) container.appendChild(document.createElement('br'));
        var span = document.createElement(index === 0 ? 'b' : 'span');
        span.textContent = String(line);
        container.appendChild(span);
      });
      return container;
    }
    maaametLayer().addTo(leaflet);
    function loadLiveLocations() {
      fetch(window.PIMEPUNKT.basePath + '/admin/locations/' + el.dataset.gameId)
        .then(function (r) { return r.json(); })
        .then(function (rows) {
          liveLayer.clearLayers();
          var byTeam = {};
          rows.forEach(function (r) {
            (byTeam[r.team_id] = byTeam[r.team_id] || { name: r.name, points: [] }).points.push([parseFloat(r.lat), parseFloat(r.lng)]);
          });
          var bounds = [];
          Object.keys(byTeam).forEach(function (id) {
            var item = byTeam[id];
            if (!item.points.length) return;
            L.polyline(item.points, { weight: 4 }).addTo(liveLayer).bindPopup(item.name);
            L.marker(item.points[item.points.length - 1]).addTo(liveLayer).bindPopup(item.name);
            bounds = bounds.concat(item.points);
          });
          if (bounds.length && !didFit) {
            leaflet.fitBounds(bounds, { padding: [30, 30] });
            didFit = true;
          }
        });
    }
    loadLiveLocations();
    setInterval(loadLiveLocations, 5000);
    fetch(window.PIMEPUNKT.basePath + '/games/' + el.dataset.gameId + '/traffic')
      .then(function (r) { return r.json(); })
      .then(function (data) {
        L.geoJSON(data.restrictions || { type: 'FeatureCollection', features: [] }, {
          style: function (feature) {
            return { color: feature.properties && feature.properties.effect === 'COMPLETE_CLOSURE' ? '#b3261e' : '#f08b32', weight: 5, dashArray: '8 5' };
          },
          onEachFeature: function (feature, layer) {
            var p = feature.properties || {};
            layer.bindPopup(popupContent([p.road_name || 'Teeinfo', p.effect, p.extra_info]));
          }
        }).addTo(trafficLayer);
        (data.speed_zones || []).forEach(function (zone) {
          var layer;
          if (zone.geometry_type === 'circle') {
            layer = L.circle([parseFloat(zone.center_lat), parseFloat(zone.center_lng)], { radius: parseInt(zone.radius_m, 10), color: '#3b7f57', fillOpacity: 0.08 });
          } else {
            layer = L.polyline(JSON.parse(zone.geometry_json || '[]'), { color: '#3b7f57', weight: 4, opacity: 0.7 });
          }
          layer.addTo(trafficLayer).bindPopup(popupContent([zone.name, zone.speed_limit_kmh + ' km/h']));
        });
      }).catch(function () {});
  }

  function initResultsMap() {
    var el = qs('[data-results-map]');
    if (!el || !window.L) return;
    var rows = JSON.parse(el.dataset.paths || '[]')
      .filter(function (row) { return row.lat !== null && row.lng !== null; });
    if (!rows.length) return;

    var colors = ['#EFA1BD', '#8FB89A', '#F4B26A', '#9DB7C7', '#F3DFA2', '#252525', '#C884A6', '#6E9C79'];
    var byTeam = {};
    rows.forEach(function (row) {
      var id = String(row.id);
      if (!byTeam[id]) byTeam[id] = { name: row.name, points: [] };
      byTeam[id].points.push({
        lat: parseFloat(row.lat),
        lng: parseFloat(row.lng),
        time: row.created_at || ''
      });
    });

    var leaflet = L.map(el).setView([58.75, 25.0], 7);
    maaametLayer().addTo(leaflet);
    var bounds = [];
    var animatedMarkers = [];
    var allSteps = [];
    var legend = qs('[data-results-legend]');

    Object.keys(byTeam).forEach(function (teamId, index) {
      var team = byTeam[teamId];
      var color = colors[index % colors.length];
      var latLngs = team.points.map(function (point) { return [point.lat, point.lng]; });
      if (!latLngs.length) return;
      bounds = bounds.concat(latLngs);
      L.polyline(latLngs, { color: color, weight: 5, opacity: 0.82 }).addTo(leaflet).bindPopup(team.name);
      L.circleMarker(latLngs[0], { radius: 6, color: color, fillColor: color, fillOpacity: 0.95 }).addTo(leaflet).bindPopup(team.name + ' start');
      L.circleMarker(latLngs[latLngs.length - 1], { radius: 8, color: color, fillColor: color, fillOpacity: 0.95 }).addTo(leaflet).bindPopup(team.name + ' lõpp');
      var marker = L.circleMarker(latLngs[0], {
        radius: 10,
        color: '#252525',
        weight: 2,
        fillColor: color,
        fillOpacity: 1
      }).addTo(leaflet).bindTooltip(team.name, { permanent: true, direction: 'top', className: 'point-number-label' });
      animatedMarkers.push({ marker: marker, points: team.points });
      team.points.forEach(function (point, stepIndex) {
        allSteps.push({ teamIndex: animatedMarkers.length - 1, stepIndex: stepIndex, time: point.time });
      });
      if (legend) {
        var item = document.createElement('span');
        item.className = 'legend-item';
        item.innerHTML = '<i style="background:' + color + '"></i>' + team.name;
        legend.appendChild(item);
      }
    });

    if (bounds.length) leaflet.fitBounds(bounds, { padding: [30, 30] });
    allSteps.sort(function (a, b) { return String(a.time).localeCompare(String(b.time)); });

    var timer = null;
    var playButton = qs('[data-results-play]');
    var resetButton = qs('[data-results-reset]');
    var timeline = qs('[data-results-timeline]');
    var startLabel = qs('[data-results-start]');
    var currentLabel = qs('[data-results-current]');
    var endLabel = qs('[data-results-end]');

    function formatTime(value) {
      if (!value) return 'Aeg puudub';
      var date = new Date(String(value).replace(' ', 'T'));
      if (Number.isNaN(date.getTime())) return value;
      return date.toLocaleString('et-EE', {
        day: '2-digit',
        month: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit'
      });
    }

    function placeMarkersAt(cursor) {
      var latestByTeam = {};
      for (var i = 0; i <= cursor && i < allSteps.length; i++) {
        latestByTeam[allSteps[i].teamIndex] = allSteps[i].stepIndex;
      }
      animatedMarkers.forEach(function (item, teamIndex) {
        var pointIndex = latestByTeam[teamIndex] == null ? 0 : latestByTeam[teamIndex];
        var point = item.points[pointIndex];
        if (point) item.marker.setLatLng([point.lat, point.lng]);
      });
      if (timeline) timeline.value = String(Math.max(0, Math.min(cursor, allSteps.length - 1)));
      if (currentLabel && allSteps.length) currentLabel.textContent = formatTime(allSteps[Math.max(0, Math.min(cursor, allSteps.length - 1))].time);
    }

    if (timeline && allSteps.length) {
      timeline.max = String(allSteps.length - 1);
      timeline.value = '0';
      if (startLabel) startLabel.textContent = formatTime(allSteps[0].time);
      if (currentLabel) currentLabel.textContent = formatTime(allSteps[0].time);
      if (endLabel) endLabel.textContent = formatTime(allSteps[allSteps.length - 1].time);
      timeline.addEventListener('input', function () {
        if (timer) {
          clearInterval(timer);
          timer = null;
          if (playButton) playButton.textContent = 'Käivita';
        }
        placeMarkersAt(parseInt(timeline.value, 10) || 0);
      });
    }

    function resetAnimation() {
      if (timer) {
        clearInterval(timer);
        timer = null;
      }
      placeMarkersAt(0);
      if (playButton) playButton.textContent = 'Käivita';
    }

    function playAnimation() {
      if (timer) {
        resetAnimation();
        return;
      }
      var cursor = 0;
      if (playButton) playButton.textContent = 'Peata';
      timer = setInterval(function () {
        if (cursor >= allSteps.length) {
          resetAnimation();
          return;
        }
        placeMarkersAt(cursor);
        cursor++;
      }, 650);
    }

    if (playButton) playButton.addEventListener('click', playAnimation);
    if (resetButton) resetButton.addEventListener('click', resetAnimation);
  }

  initRegistrationAreaMap();
  initPauseLocationMap();
  initAdminCheckpointMap();
  initAdminLiveMap();
  initResultsMap();
})();
