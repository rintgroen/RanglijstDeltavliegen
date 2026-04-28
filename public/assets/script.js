(function () {
  function setupMemoryShowcase(showcase) {
    var slides = Array.prototype.slice.call(showcase.querySelectorAll('.memory-slide'));
    if (slides.length === 0) {
      return;
    }

    var current = 0;
    var visibleMs = 7800;
    var gapMs = 320;
    var timer = null;
    var progress = showcase.querySelector('.memory-progress span');

    function restartTypewriter(slide) {
      var text = slide.querySelector('.memory-copy p');
      if (!text) {
        return;
      }
      text.style.animation = 'none';
      text.offsetHeight;
      text.style.animation = '';
    }

    function show(index) {
      slides.forEach(function (slide, slideIndex) {
        slide.classList.toggle('is-active', slideIndex === index);
      });
      restartProgress();
      restartTypewriter(slides[index]);
    }

    function restartProgress() {
      if (!progress) {
        return;
      }
      showcase.classList.remove('is-running');
      progress.style.animation = 'none';
      progress.offsetHeight;
      progress.style.animation = '';
      showcase.classList.add('is-running');
    }

    function scheduleNext() {
      if (slides.length < 2) {
        return;
      }
      timer = window.setTimeout(function () {
        slides[current].classList.remove('is-active');
        timer = window.setTimeout(function () {
          current = (current + 1) % slides.length;
          show(current);
          scheduleNext();
        }, gapMs);
      }, visibleMs);
    }

    showcase.classList.add('is-ready');
    show(current);

    if (slides.length > 1 && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      scheduleNext();
      document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
          window.clearTimeout(timer);
          timer = null;
          showcase.classList.remove('is-running');
        } else if (timer === null) {
          restartProgress();
          scheduleNext();
        }
      });
    }
  }

  function parseJsonScript(dataEl) {
    try {
      return JSON.parse(dataEl.textContent || '{}');
    } catch (error) {
      return null;
    }
  }

  function setMapUnavailable(mapEl, message) {
    var status = mapEl.querySelector('.track-preview-loading');
    if (status) {
      status.textContent = message;
    }
  }

  function createStreetMap(mapEl) {
    var map = window.L.map(mapEl, {
      attributionControl: true,
      scrollWheelZoom: false,
      zoomControl: false
    });
    window.L.control.zoom({ position: 'topright' }).addTo(map);
    var fallbackActive = false;
    var tileLayer = window.L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
      detectRetina: true,
      maxZoom: 19
    }).addTo(map);
    tileLayer.on('tileerror', function () {
      if (fallbackActive) {
        return;
      }
      fallbackActive = true;
      map.removeLayer(tileLayer);
      window.L.tileLayer('https://{s}.tile.openstreetmap.fr/hot/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
        detectRetina: true,
        maxZoom: 19,
        subdomains: ['a', 'b', 'c']
      }).addTo(map);
    });
    return map;
  }

  function toLatLng(point) {
    if (!Array.isArray(point) || point.length < 2) {
      return null;
    }
    var lat = Number(point[0]);
    var lon = Number(point[1]);
    if (!isFinite(lat) || !isFinite(lon)) {
      return null;
    }
    return [lat, lon];
  }

  function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, function (character) {
      return {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
      }[character];
    });
  }

  function routeAngleDegrees(from, to) {
    var avgLat = ((from[0] + to[0]) / 2) * Math.PI / 180;
    var dx = (to[1] - from[1]) * Math.cos(avgLat);
    var dy = from[0] - to[0];
    return Math.atan2(dy, dx) * 180 / Math.PI;
  }

  function midpoint(from, to) {
    return [(from[0] + to[0]) / 2, (from[1] + to[1]) / 2];
  }

  function setupTrackPreviewMap(preview) {
    var mapEl = preview.querySelector('.track-preview-map');
    var dataEl = preview.querySelector('.track-preview-data');
    if (!mapEl || !dataEl) {
      return;
    }

    if (!window.L) {
      setMapUnavailable(mapEl, 'Kaartvoorbeeld niet beschikbaar.');
      return;
    }

    var data = parseJsonScript(dataEl);
    if (!data) {
      return;
    }

    var latLngs = Array.isArray(data.points) ? data.points.map(function (point) {
      return toLatLng(point);
    }).filter(Boolean) : [];

    if (latLngs.length === 0) {
      return;
    }

    var loading = mapEl.querySelector('.track-preview-loading');
    if (loading) {
      loading.remove();
    }

    var map = createStreetMap(mapEl);

    var start = latLngs[0];
    var finish = latLngs[latLngs.length - 1];
    if (latLngs.length > 1) {
      var line = window.L.polyline(latLngs, {
        color: '#c43131',
        lineCap: 'round',
        lineJoin: 'round',
        opacity: 0.95,
        weight: 4
      }).addTo(map);
      var bounds = line.getBounds();
      if (typeof bounds.isValid === 'function' && bounds.isValid()) {
        map.fitBounds(bounds.pad(0.18), { maxZoom: 14, padding: [16, 16] });
      } else {
        map.setView(start, 13);
      }
    } else {
      map.setView(start, 13);
    }

    window.L.circleMarker(start, {
      color: '#0f6fa8',
      fillColor: '#ffffff',
      fillOpacity: 1,
      radius: 5,
      weight: 3
    }).addTo(map);
    if (start[0] !== finish[0] || start[1] !== finish[1]) {
      window.L.circleMarker(finish, {
        color: '#c43131',
        fillColor: '#ffffff',
        fillOpacity: 1,
        radius: 5,
        weight: 3
      }).addTo(map);
    }

    window.setTimeout(function () {
      map.invalidateSize();
    }, 0);
  }

  function setupTaskMap(container) {
    var mapEl = container.querySelector('.task-map-canvas');
    var dataEl = container.querySelector('.task-map-data');
    if (!mapEl || !dataEl) {
      return;
    }

    if (!window.L) {
      setMapUnavailable(mapEl, 'Taakkaart niet beschikbaar.');
      return;
    }

    var data = parseJsonScript(dataEl);
    var turnpoints = data && Array.isArray(data.turnpoints) ? data.turnpoints.map(function (tp) {
      var lat = Number(tp.lat);
      var lon = Number(tp.lon);
      if (!isFinite(lat) || !isFinite(lon)) {
        return null;
      }
      return {
        center: [lat, lon],
        name: tp.name || 'Taakpunt',
        radius: Math.max(0, Number(tp.radius_m) || 0),
        role: tp.role,
        sequence: tp.sequence || ''
      };
    }).filter(Boolean) : [];
    if (turnpoints.length === 0) {
      setMapUnavailable(mapEl, 'Geen geldige taakpunten voor de kaart.');
      return;
    }

    var loading = mapEl.querySelector('.track-preview-loading');
    if (loading) {
      loading.remove();
    }

    var map = createStreetMap(mapEl);
    map.setView(turnpoints[0].center, 12);
    var styles = {
      normal: { color: '#0f6fa8', fillColor: '#0f6fa8', fillOpacity: 0.08, weight: 2, dashArray: '6 5' },
      sss: { color: '#2f7d32', fillColor: '#2f7d32', fillOpacity: 0.12, weight: 4, dashArray: null },
      ess: { color: '#b42318', fillColor: '#b42318', fillOpacity: 0.12, weight: 4, dashArray: '10 5' },
      sss_ess: { color: '#6f42c1', fillColor: '#6f42c1', fillOpacity: 0.12, weight: 4, dashArray: '3 5' }
    };
    var roleLabels = {
      normal: 'Normaal',
      sss: 'SSS',
      ess: 'ESS',
      sss_ess: 'SSS / ESS'
    };
    var layers = [];

    turnpoints.forEach(function (tp) {
      var role = styles[tp.role] ? tp.role : 'normal';
      var style = styles[role];
      var circle = window.L.circle(tp.center, {
        color: style.color,
        dashArray: style.dashArray,
        fillColor: style.fillColor,
        fillOpacity: style.fillOpacity,
        radius: tp.radius,
        weight: style.weight
      }).addTo(map);
      var label = escapeHtml(tp.sequence + '. ' + tp.name);
      circle.bindPopup('<strong>' + label + '</strong><br>' + escapeHtml(roleLabels[role]) + '<br>Radius: ' + Math.round(tp.radius) + ' m');
      layers.push(circle);
      var marker = window.L.circleMarker(tp.center, {
        color: style.color,
        fillColor: '#ffffff',
        fillOpacity: 1,
        radius: role === 'normal' ? 4 : 6,
        weight: role === 'normal' ? 2 : 3
      }).addTo(map);
      layers.push(marker);
    });

    var route = Array.isArray(data.route) ? data.route.map(function (point) {
      return toLatLng(point);
    }).filter(Boolean) : [];
    if (route.length > 1) {
      var line = window.L.polyline(route, {
        color: '#102436',
        lineCap: 'round',
        lineJoin: 'round',
        opacity: 0.9,
        weight: 3
      }).addTo(map);
      layers.push(line);
      for (var i = 1; i < route.length; i++) {
        if (route[i - 1][0] === route[i][0] && route[i - 1][1] === route[i][1]) {
          continue;
        }
        var arrow = window.L.marker(midpoint(route[i - 1], route[i]), {
          icon: window.L.divIcon({
            className: 'task-route-arrow',
            html: '<span style="transform: rotate(' + routeAngleDegrees(route[i - 1], route[i]) + 'deg)"></span>',
            iconAnchor: [12, 12],
            iconSize: [24, 24]
          }),
          interactive: false
        }).addTo(map);
        layers.push(arrow);
      }
    }

    if (layers.length > 0) {
      var group = window.L.featureGroup(layers);
      var bounds = group.getBounds();
      if (bounds && typeof bounds.isValid === 'function' && bounds.isValid()) {
        map.fitBounds(bounds.pad(0.16), { maxZoom: 14, padding: [18, 18] });
      }
    }

    window.setTimeout(function () {
      map.invalidateSize();
    }, 0);
  }

  function setupTabs(tabset) {
    var buttons = Array.prototype.slice.call(tabset.querySelectorAll('[data-tab-target]'));
    var panels = Array.prototype.slice.call(tabset.querySelectorAll('[data-tab-panel]'));
    if (buttons.length === 0 || panels.length === 0) {
      return;
    }

    function activateTab(key, updateHash) {
      var matched = buttons.some(function (button) {
        return button.getAttribute('data-tab-target') === key;
      });
      if (!matched) {
        return false;
      }

      buttons.forEach(function (button) {
        var isActive = button.getAttribute('data-tab-target') === key;
        button.classList.toggle('is-active', isActive);
        button.setAttribute('aria-selected', isActive ? 'true' : 'false');
        button.setAttribute('tabindex', isActive ? '0' : '-1');
      });

      panels.forEach(function (panel) {
        panel.hidden = panel.getAttribute('data-tab-panel') !== key;
      });
      tabset.setAttribute('data-active-tab', key);
      window.setTimeout(function () {
        if (typeof window.Event === 'function') {
          window.dispatchEvent(new Event('resize'));
        }
      }, 0);

      if (updateHash && window.history && window.history.replaceState) {
        window.history.replaceState(null, '', window.location.pathname + window.location.search + '#' + encodeURIComponent(key));
      }
      return true;
    }

    function currentHashTab() {
      if (!window.location.hash) {
        return '';
      }
      try {
        return decodeURIComponent(window.location.hash.slice(1));
      } catch (error) {
        return '';
      }
    }

    buttons.forEach(function (button) {
      button.addEventListener('click', function () {
        activateTab(button.getAttribute('data-tab-target'), true);
      });
      button.addEventListener('keydown', function (event) {
        var index = buttons.indexOf(button);
        var nextIndex = index;
        if (event.key === 'ArrowRight') {
          nextIndex = (index + 1) % buttons.length;
        } else if (event.key === 'ArrowLeft') {
          nextIndex = (index - 1 + buttons.length) % buttons.length;
        } else if (event.key === 'Home') {
          nextIndex = 0;
        } else if (event.key === 'End') {
          nextIndex = buttons.length - 1;
        } else {
          return;
        }
        event.preventDefault();
        buttons[nextIndex].focus();
        activateTab(buttons[nextIndex].getAttribute('data-tab-target'), true);
      });
    });

    var initialHash = currentHashTab();
    if (!activateTab(initialHash, false)) {
      activateTab(tabset.getAttribute('data-active-tab') || buttons[0].getAttribute('data-tab-target'), false);
    }

    window.addEventListener('hashchange', function () {
      activateTab(currentHashTab(), false);
    });
  }

  function setupPublicNavToggle() {
    var button = document.querySelector('[data-public-nav-toggle]');
    if (!button) {
      return;
    }

    var navId = button.getAttribute('aria-controls');
    var nav = navId ? document.getElementById(navId) : null;
    if (!nav) {
      return;
    }

    nav.classList.add('is-collapsible');

    function setOpen(isOpen) {
      nav.classList.toggle('is-open', isOpen);
      button.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      button.setAttribute('aria-label', isOpen ? 'Menu sluiten' : 'Menu openen');
    }

    button.addEventListener('click', function () {
      setOpen(button.getAttribute('aria-expanded') !== 'true');
    });

    nav.addEventListener('click', function (event) {
      if (event.target.closest && event.target.closest('a')) {
        setOpen(false);
      }
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        setOpen(false);
      }
    });

    setOpen(false);
  }

  document.addEventListener('DOMContentLoaded', function () {
    setupPublicNavToggle();
    document.querySelectorAll('.memory-showcase').forEach(setupMemoryShowcase);
    document.querySelectorAll('[data-tabs]').forEach(setupTabs);
    document.querySelectorAll('.track-preview').forEach(setupTrackPreviewMap);
    document.querySelectorAll('.task-map').forEach(setupTaskMap);
  });
})();
