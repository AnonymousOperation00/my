/* =========================================================
   nasze zeszyty — notatki w bazie (MariaDB przez API PHP)
   oraz galeria zdjęć. Działa też bez serwera (localStorage).
   ========================================================= */
(function () {
  'use strict';

  var API = 'api';
  var hasFetch = typeof window.fetch === 'function';

  /* ---------------- pomocnicze ---------------- */

  function q(root, selector) {
    return root.querySelector(selector);
  }

  function qa(root, selector) {
    return Array.prototype.slice.call(root.querySelectorAll(selector));
  }

  function api(method, path, body) {
    var options = { method: method, headers: {} };

    if (body !== undefined) {
      options.headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify(body);
    }

    return window.fetch(API + path, options).then(function (response) {
      if (!response.ok) throw new Error('HTTP ' + response.status);
      return response.json();
    });
  }

  var store = {
    read: function (key) {
      try {
        var raw = window.localStorage.getItem(key);
        return raw ? JSON.parse(raw) : null;
      } catch (e) {
        return null;
      }
    },
    write: function (key, value) {
      try {
        window.localStorage.setItem(key, JSON.stringify(value));
        return true;
      } catch (e) {
        return false;
      }
    }
  };

  function formatDate(value) {
    if (!value) return '—';
    var date = new Date(value);
    if (isNaN(date.getTime())) return '—';
    try {
      return date.toLocaleString('pl-PL', {
        day: 'numeric', month: 'long', year: 'numeric',
        hour: '2-digit', minute: '2-digit'
      });
    } catch (e) {
      return date.toLocaleString();
    }
  }

  function nowIso() {
    return new Date().toISOString();
  }

  function pad2(value) {
    return value < 10 ? '0' + value : String(value);
  }

  /* ---------------- tryb po zerwaniu ---------------- */

  var sadMode = false;
  var sadAt = 0;

  /* po zerwaniu zegary stają na chwili, w której to się stało */
  function nowMs() {
    return sadMode ? sadAt : Date.now();
  }

  function applySad(at) {
    var moment = at ? new Date(at).getTime() : NaN;

    sadMode = true;
    sadAt = isNaN(moment) ? Date.now() : moment;
    document.body.classList.add('is-sad');
  }

  /* ---------------- zeszyt ---------------- */

  function createNotebook(section) {
    var notebook = section.getAttribute('data-notebook') || 'moj';
    var localKey = 'zeszyt:' + notebook;

    var ui = {
      status: q(section, '[data-status]'),
      title: q(section, '[data-title]'),
      body: q(section, '[data-body]'),
      date: q(section, '[data-date]'),
      counter: q(section, '[data-counter]'),
      prev: q(section, '[data-prev]'),
      next: q(section, '[data-next]'),
      add: q(section, '[data-add]'),
      remove: q(section, '[data-remove]'),
      save: q(section, '[data-save]')
    };

    if (!ui.body) return;

    var state = { pages: [], index: 0, mode: 'server', timer: 0 };
    var dirty = false;

    function setStatus(text, kind) {
      if (!ui.status) return;
      ui.status.textContent = text;
      ui.status.classList.remove('is-saved', 'is-saving', 'is-local', 'is-error');
      if (kind) ui.status.classList.add('is-' + kind);
    }

    function blankPage() {
      return {
        id: null,
        notebook: notebook,
        title: '',
        content: '',
        created_at: nowIso(),
        updated_at: nowIso()
      };
    }

    function isServerPage(page) {
      return state.mode === 'server' && page && typeof page.id === 'number';
    }

    function render() {
      if (!state.pages.length) state.pages = [blankPage()];
      if (state.index < 0) state.index = 0;
      if (state.index > state.pages.length - 1) state.index = state.pages.length - 1;

      var page = state.pages[state.index];
      ui.title.value = page.title || '';
      ui.body.value = page.content || '';
      if (ui.date) ui.date.textContent = formatDate(page.updated_at);
      if (ui.counter) ui.counter.textContent = 'strona ' + (state.index + 1) + ' z ' + state.pages.length;
      if (ui.prev) ui.prev.disabled = state.index === 0;
      if (ui.next) ui.next.disabled = state.index === state.pages.length - 1;
      if (ui.remove) ui.remove.disabled = state.pages.length <= 1;

      fitBody();
    }

    /* pole tekstowe rośnie razem z treścią — jak kartka w zeszycie */
    function fitBody() {
      ui.body.style.height = 'auto';
      ui.body.style.height = ui.body.scrollHeight + 'px';
    }

    function loadLocal() {
      state.mode = 'local';
      var saved = store.read(localKey);
      state.pages = (saved && saved.length) ? saved : [blankPage()];
      state.index = state.pages.length - 1;
      render();
      setStatus('zapis lokalny w tej przeglądarce', 'local');
    }

    function load() {
      if (!hasFetch) {
        loadLocal();
        return;
      }

      setStatus('wczytywanie…', 'saving');

      api('GET', '/notes?notebook=' + encodeURIComponent(notebook))
        .then(function (pages) {
          state.mode = 'server';
          state.pages = pages || [];
          if (!state.pages.length) {
            render();
            createPage(true);
            return;
          }
          state.index = state.pages.length - 1;
          render();
          setStatus('zapisano w bazie', 'saved');
        })
        .catch(function () {
          loadLocal();
        });
    }

    function createPage(silent) {
      if (state.mode === 'server') {
        api('POST', '/notes', { notebook: notebook, title: '', content: '' })
          .then(function (created) {
            state.pages.push(created);
            state.index = state.pages.length - 1;
            render();
            if (!silent) {
              setStatus('nowa strona w bazie', 'saved');
              ui.body.focus();
            }
          })
          .catch(function () {
            setStatus('nie udało się dodać strony', 'error');
          });
        return;
      }

      var page = blankPage();
      page.id = 'local-' + Date.now();
      state.pages.push(page);
      state.index = state.pages.length - 1;
      store.write(localKey, state.pages);
      render();
      if (!silent) {
        setStatus('nowa strona (lokalnie)', 'local');
        ui.body.focus();
      }
    }

    function savePage() {
      var page = state.pages[state.index];
      if (!page || !dirty) return;
      dirty = false;

      page.title = ui.title.value;
      page.content = ui.body.value;
      page.updated_at = nowIso();
      if (ui.date) ui.date.textContent = formatDate(page.updated_at);

      if (!isServerPage(page)) {
        var ok = store.write(localKey, state.pages);
        setStatus(ok ? 'zapisano lokalnie' : 'brak miejsca na zapis', ok ? 'local' : 'error');
        return;
      }

      setStatus('zapisywanie…', 'saving');

      api('PUT', '/notes/' + page.id, { title: page.title, content: page.content })
        .then(function (updated) {
          page.updated_at = updated.updated_at || page.updated_at;
          if (ui.date) ui.date.textContent = formatDate(page.updated_at);
          setStatus('zapisano w bazie', 'saved');
        })
        .catch(function () {
          dirty = true;
          setStatus('błąd zapisu — spróbuj ponownie', 'error');
        });
    }

    function saveNow() {
      window.clearTimeout(state.timer);
      state.timer = 0;
      savePage();
    }

    function scheduleSave() {
      window.clearTimeout(state.timer);
      setStatus('zapiszę za chwilę…', 'saving');
      state.timer = window.setTimeout(saveNow, 900);
    }

    function markDirty() {
      dirty = true;
      fitBody();
      scheduleSave();
    }

    function goTo(index) {
      if (index < 0 || index > state.pages.length - 1) return;
      saveNow();
      state.index = index;
      render();
    }

    function removePage() {
      if (state.pages.length <= 1) return;
      if (!window.confirm('Usunąć tę stronę? Tego nie można cofnąć.')) return;

      var page = state.pages[state.index];

      function finish() {
        state.pages.splice(state.index, 1);
        if (state.index > state.pages.length - 1) state.index = state.pages.length - 1;

        if (!state.pages.length) {
          createPage(true);
        } else {
          if (state.mode === 'local') store.write(localKey, state.pages);
          render();
        }
        setStatus('strona usunięta', 'saved');
      }

      if (!isServerPage(page)) {
        finish();
        return;
      }

      api('DELETE', '/notes/' + page.id)
        .then(finish)
        .catch(function () {
          setStatus('nie udało się usunąć strony', 'error');
        });
    }

    /* ---------------- zdarzenia ---------------- */

    ui.title.addEventListener('input', markDirty);
    ui.body.addEventListener('input', markDirty);
    ui.body.addEventListener('blur', saveNow);

    if (ui.prev) ui.prev.addEventListener('click', function () { goTo(state.index - 1); });
    if (ui.next) ui.next.addEventListener('click', function () { goTo(state.index + 1); });
    if (ui.add) ui.add.addEventListener('click', function () { saveNow(); createPage(false); });
    if (ui.save) ui.save.addEventListener('click', function () { dirty = true; saveNow(); });
    if (ui.remove) ui.remove.addEventListener('click', removePage);

    section.addEventListener('keydown', function (event) {
      if ((event.ctrlKey || event.metaKey) && (event.key === 's' || event.key === 'S')) {
        event.preventDefault();
        dirty = true;
        saveNow();
      }
    });

    document.addEventListener('visibilitychange', function () {
      if (document.hidden && state.timer) {
        dirty = true;
        saveNow();
      }
    });

    load();

  }

  /* ---------------- galeria ---------------- */

  var lightbox = null;

  function createGallery(section) {
    var grid = q(section, '[data-grid]');
    var empty = q(section, '[data-empty]');
    var statusEl = q(section, '[data-gallery-status]');
    var dropZone = q(section, '[data-drop]');
    var uploadButton = q(section, '[data-upload]');
    var input = q(section, '[data-upload-input]');
    var recordButton = q(section, '[data-record]');
    var recordLabel = q(section, '[data-record-label]');

    if (!grid) return;

    var state = { items: [], mode: 'server' };

    function setStatus(text, kind) {
      if (!statusEl) return;
      statusEl.textContent = text;
      statusEl.classList.remove('is-error', 'is-saved');
      if (kind) statusEl.classList.add('is-' + kind);
    }

    function offline() {
      state.mode = 'offline';
      state.items = [];
      render();
      setStatus('galeria wymaga serwera — uruchom php -S 0.0.0.0:8000 router.php', 'error');
    }

    function load() {
      if (!hasFetch) { offline(); return; }

      setStatus('wczytywanie wspomnień…');

      api('GET', '/photos')
        .then(function (data) {
          state.mode = 'server';
          state.items = (data.items || []).map(function (item) {
            return { src: item.url, name: item.name, kind: item.kind || 'photo' };
          });

          render();
          setStatus(state.items.length ? state.items.length + ' wspomnień' : '');
        })
        .catch(offline);
    }

    function render() {
      var list = state.items;
      grid.textContent = '';

      if (!list.length) {
        empty.hidden = false;
        empty.innerHTML = state.mode === 'offline'
          ? 'Wspomnienia pojawią się, gdy uruchomisz serwer:<br><strong>php -S 0.0.0.0:8000 router.php</strong>'
          : 'Jeszcze pusto. Dodaj zdjęcia lub nagrania przyciskiem <strong>＋ zdjęcia / nagrania</strong>,' +
            ' naciśnij <strong>nagraj</strong> albo wrzuć pliki do <strong>photos/wspomnienia</strong>.';
        return;
      }

      empty.hidden = true;

      list.forEach(function (item, index) {
        var figure = document.createElement('figure');
        figure.className = 'shot';

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'shot__btn shot__btn--' + item.kind;
        button.setAttribute(
          'aria-label',
          (item.kind === 'photo' ? 'Powiększ zdjęcie ' : 'Odtwórz nagranie ') + (index + 1) + ': ' + item.name
        );

        if (item.kind === 'photo') {
          var img = document.createElement('img');
          img.src = item.src;
          img.alt = 'Nasze wspomnienie — ' + item.name;
          img.loading = 'lazy';
          img.decoding = 'async';
          button.appendChild(img);
        } else {
          var icon = document.createElement('span');
          icon.className = 'shot__icon';
          icon.setAttribute('aria-hidden', 'true');
          icon.textContent = '▶';

          var kindLabel = document.createElement('span');
          kindLabel.className = 'shot__kind';
          kindLabel.textContent = item.kind === 'audio' ? 'nagranie' : 'wideo';

          button.appendChild(icon);
          button.appendChild(kindLabel);
        }

        button.addEventListener('click', function () {
          if (lightbox) lightbox.open(list, index);
        });

        figure.appendChild(button);
        grid.appendChild(figure);
      });
    }

    /* dodawanie plików — wszystko ląduje w photos/wspomnienia */

    var ALLOWED = /^(image|audio|video)\//i;
    var MAX_BYTES = 60 * 1024 * 1024;

    function readBlob(blob) {
      return new Promise(function (resolve, reject) {
        var reader = new FileReader();
        reader.onload = function () { resolve(String(reader.result)); };
        reader.onerror = function () { reject(new Error('read error')); };
        reader.readAsDataURL(blob);
      });
    }

    function uploadItems(items) {
      if (!items.length) return;
      if (state.mode !== 'server') {
        setStatus('dodawanie wspomnień wymaga serwera', 'error');
        return;
      }

      var accepted = [];
      var skipped = 0;

      items.forEach(function (item) {
        var type = (item.blob && item.blob.type) || '';
        if (!ALLOWED.test(type) || (item.blob && item.blob.size > MAX_BYTES)) {
          skipped += 1;
          return;
        }
        accepted.push(item);
      });

      if (!accepted.length) {
        setStatus('nieobsługiwany plik albo za duży (limit 60 MB)', 'error');
        return;
      }

      var done = 0;
      setStatus('wysyłam 1 z ' + accepted.length + '…');

      var chain = Promise.resolve();

      accepted.forEach(function (item) {
        chain = chain.then(function () {
          return readBlob(item.blob).then(function (dataUrl) {
            return api('POST', '/photos', {
              filename: item.name,
              data: dataUrl.slice(dataUrl.indexOf(',') + 1)
            }).then(function () {
              done += 1;
              if (done < accepted.length) {
                setStatus('wysyłam ' + (done + 1) + ' z ' + accepted.length + '…');
              }
            });
          });
        });
      });

      chain
        .then(function () {
          setStatus('dodano ' + done + (skipped ? ' (pominięto ' + skipped + ')' : ''), 'saved');
          load();
        })
        .catch(function () {
          setStatus('nie udało się dodać plików', 'error');
        });
    }

    function uploadFiles(files) {
      var items = Array.prototype.slice.call(files || []).map(function (file) {
        return { blob: file, name: file.name || ('plik-' + Date.now()) };
      });
      uploadItems(items);
    }

    /* nagrywanie głosu z mikrofonu */

    var recorder = null;
    var chunks = [];
    var recTimer = 0;
    var recStart = 0;

    function stamp() {
      var d = new Date();

      function p(n) { return n < 10 ? '0' + n : String(n); }

      return d.getFullYear() + p(d.getMonth() + 1) + p(d.getDate()) + '-' +
        p(d.getHours()) + p(d.getMinutes()) + p(d.getSeconds());
    }

    function audioFormat() {
      if (typeof window.MediaRecorder === 'undefined') return null;

      var options = [
        { mime: 'audio/webm;codecs=opus', ext: '.weba' },
        { mime: 'audio/webm', ext: '.weba' },
        { mime: 'audio/mp4', ext: '.m4a' },
        { mime: 'audio/ogg;codecs=opus', ext: '.ogg' }
      ];

      for (var i = 0; i < options.length; i++) {
        if (!window.MediaRecorder.isTypeSupported || window.MediaRecorder.isTypeSupported(options[i].mime)) {
          return options[i];
        }
      }

      return { mime: '', ext: '.weba' };
    }

    function paintRecording(on, seconds) {
      if (!recordButton) return;

      recordButton.classList.toggle('is-recording', !!on);
      recordButton.setAttribute('aria-pressed', String(!!on));

      if (!recordLabel) return;
      if (!on) {
        recordLabel.textContent = 'nagraj';
        return;
      }

      var s = seconds || 0;
      recordLabel.textContent = '■ zakończ ' + Math.floor(s / 60) + ':' + pad2(s % 60);
    }

    function stopRecording() {
      if (recorder && recorder.state !== 'inactive') recorder.stop();
      window.clearInterval(recTimer);
      recTimer = 0;
    }

    function startRecording() {
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !audioFormat()) {
        setStatus('ta przeglądarka nie potrafi nagrywać', 'error');
        return;
      }

      if (window.isSecureContext === false) {
        setStatus('nagrywanie działa na localhost albo przez HTTPS', 'error');
        return;
      }

      setStatus('proszę o dostęp do mikrofonu…');

      navigator.mediaDevices.getUserMedia({ audio: true })
        .then(function (stream) {
          var format = audioFormat();

          try {
            recorder = format.mime
              ? new window.MediaRecorder(stream, { mimeType: format.mime })
              : new window.MediaRecorder(stream);
          } catch (e) {
            stream.getTracks().forEach(function (track) { track.stop(); });
            setStatus('nie udało się rozpocząć nagrania', 'error');
            return;
          }

          chunks = [];

          recorder.addEventListener('dataavailable', function (event) {
            if (event.data && event.data.size) chunks.push(event.data);
          });

          recorder.addEventListener('stop', function () {
            stream.getTracks().forEach(function (track) { track.stop(); });

            var blob = new Blob(chunks, { type: recorder.mimeType || 'audio/webm' });
            chunks = [];

            window.clearInterval(recTimer);
            recTimer = 0;
            paintRecording(false);

            if (!blob.size) {
              setStatus('nagranie wyszło puste', 'error');
              return;
            }

            setStatus('zapisuję nagranie…');
            uploadItems([{ blob: blob, name: 'nagranie-' + stamp() + format.ext }]);
          });

          recorder.start();
          recStart = Date.now();
          paintRecording(true, 0);

          recTimer = window.setInterval(function () {
            paintRecording(true, Math.floor((Date.now() - recStart) / 1000));
          }, 500);
        })
        .catch(function () {
          setStatus('brak dostępu do mikrofonu', 'error');
        });
    }

    if (uploadButton && input) {
      uploadButton.addEventListener('click', function () { input.click(); });

      input.addEventListener('change', function () {
        uploadFiles(input.files);
        input.value = '';
      });
    }

    if (recordButton) {
      recordButton.addEventListener('click', function () {
        if (recorder && recorder.state === 'recording') stopRecording();
        else startRecording();
      });
    }

    /* przeciągnij i upuść (desktop) */

    if (dropZone) {
      ['dragenter', 'dragover'].forEach(function (type) {
        dropZone.addEventListener(type, function (event) {
          event.preventDefault();
          dropZone.classList.add('is-over');
        });
      });

      ['dragleave', 'dragend'].forEach(function (type) {
        dropZone.addEventListener(type, function () {
          dropZone.classList.remove('is-over');
        });
      });

      dropZone.addEventListener('drop', function (event) {
        event.preventDefault();
        dropZone.classList.remove('is-over');

        var transfer = event.dataTransfer;
        if (!transfer || !transfer.files || !transfer.files.length) return;

        uploadFiles(transfer.files);
      });
    }


    load();
  }

  /* ---------------- humor dnia ---------------- */

  function createMood(section) {
    var chips = qa(section, '[data-mood-value]');
    var note = q(section, '[data-mood-note]');
    var localKey = 'humor';
    var labels = {};

    chips.forEach(function (chip) {
      labels[chip.getAttribute('data-mood-value')] = chip.textContent.trim();
    });

    function paint(mood, createdAt, local) {
      chips.forEach(function (chip) {
        var active = chip.getAttribute('data-mood-value') === mood;
        chip.classList.toggle('is-active', active);
        chip.setAttribute('aria-pressed', String(active));
      });

      if (!note) return;

      if (!mood) {
        note.textContent = 'dotknij, żeby zapisać';
        return;
      }

      note.textContent = 'dziś: ' + (labels[mood] || mood) +
        (createdAt ? ' · ' + formatDate(createdAt) : '') +
        (local ? ' · lokalnie' : '');
    }

    function load() {
      var cached = store.read(localKey);

      if (!hasFetch) {
        if (cached) paint(cached.mood, cached.created_at, true);
        return;
      }

      api('GET', '/moods')
        .then(function (data) {
          if (data.current) {
            store.write(localKey, data.current);
            paint(data.current.mood, data.current.created_at, false);
          } else if (cached) {
            paint(cached.mood, cached.created_at, true);
          }
        })
        .catch(function () {
          if (cached) paint(cached.mood, cached.created_at, true);
        });
    }

    chips.forEach(function (chip) {
      chip.addEventListener('click', function () {
        var mood = chip.getAttribute('data-mood-value');
        var stamp = nowIso();

        paint(mood, stamp, !hasFetch);
        store.write(localKey, { mood: mood, created_at: stamp });

        if (!hasFetch) return;

        api('POST', '/moods', { mood: mood })
          .then(function (data) {
            var current = data.current || { mood: mood, created_at: stamp };
            store.write(localKey, current);
            paint(current.mood, current.created_at, false);
          })
          .catch(function () {
            paint(mood, stamp, true);
          });
      });
    });

    load();
  }

  /* ---------------- sekret zapisany po cichu ---------------- */

  function createSecret(section) {
    var form = q(section, '[data-secret-form]');
    var input = q(section, '[data-secret-input]');
    var statusEl = q(section, '[data-secret-status]');
    var list = q(section, '[data-secret-list]');

    if (!form || !input || !list) return;

    function setStatus(text, kind) {
      if (!statusEl) return;
      statusEl.textContent = text;
      statusEl.classList.remove('is-saved', 'is-error');
      if (kind) statusEl.classList.add('is-' + kind);
    }

    function render(items) {
      list.textContent = '';

      if (!items.length) {
        var empty = document.createElement('li');
        empty.className = 'secret__empty';
        empty.textContent = 'Tu jeszcze nic nie ma. Pierwszy sekret możesz zostawić teraz.';
        list.appendChild(empty);
        return;
      }

      items.forEach(function (item) {
        var li = document.createElement('li');
        li.className = 'secret__item ' + (item.locked ? 'is-locked' : 'is-open');

        var meta = document.createElement('p');
        meta.className = 'secret__meta';
        meta.textContent = item.locked
          ? 'zapieczętowane · otworzysz ' + formatDate(item.reveal_at)
          : 'otwarte · zapisane ' + formatDate(item.created_at);
        li.appendChild(meta);

        if (item.locked) {
          var hint = document.createElement('p');
          hint.className = 'secret__hint';
          hint.textContent = 'Treść jest schowana — pokaże się sama przy naszym spotkaniu.';
          li.appendChild(hint);
        } else {
          var content = document.createElement('p');
          content.className = 'secret__content';
          content.textContent = item.content || '';
          li.appendChild(content);
        }

        list.appendChild(li);
      });
    }

    function load() {
      if (!hasFetch) {
        setStatus('sekret potrzebuje serwera', 'error');
        list.textContent = '';
        return;
      }

      api('GET', '/secrets')
        .then(function (data) {
          render(data.items || []);
        })
        .catch(function () {
          setStatus('nie udało się wczytać sekretów', 'error');
        });
    }

    form.addEventListener('submit', function (event) {
      event.preventDefault();

      var content = input.value.trim();
      if (!content) {
        setStatus('napisz najpierw kilka słów', 'error');
        input.focus();
        return;
      }

      if (!hasFetch) {
        setStatus('zapis sekretu wymaga serwera', 'error');
        return;
      }

      setStatus('chowam do koperty…');

      api('POST', '/secrets', { content: content })
        .then(function (data) {
          input.value = '';
          setStatus('zapisane po cichu · otworzysz ' + formatDate(data.reveal_at), 'saved');
          load();
        })
        .catch(function () {
          setStatus('nie udało się zapisać — spróbuj ponownie', 'error');
        });
    });

    load();
  }

  /* ---------------- od kiedy się znamy ---------------- */

  function createSince(section) {
    var daysEl = q(section, '[data-since-days]');
    var hoursEl = q(section, '[data-since-hours]');
    var minutesEl = q(section, '[data-since-minutes]');
    var secondsEl = q(section, '[data-since-seconds]');
    var dateEl = q(section, '[data-since-date]');
    var editButton = q(section, '[data-since-edit]');
    var dateInput = q(section, '[data-since-input]');

    if (!daysEl) return;

    var STORAGE_KEY = 'poznanie';
    var DEFAULT_DATE = '2025-03-18';     // 18.03.2025
    var since = parseDay(DEFAULT_DATE);
    var timer = 0;

    function parseDay(value) {
      var parts = String(value || '').split('-');
      if (parts.length !== 3) return null;

      var year = parseInt(parts[0], 10);
      var month = parseInt(parts[1], 10);
      var day = parseInt(parts[2], 10);
      if (!year || !month || !day) return null;

      return new Date(year, month - 1, day, 0, 0, 0, 0);   // lokalna północ
    }

    function toDayValue(date) {
      return date.getFullYear() + '-' + pad2(date.getMonth() + 1) + '-' + pad2(date.getDate());
    }

    function prettyDay(value) {
      var date = parseDay(value);
      if (!date) return value;

      try {
        return date.toLocaleDateString('pl-PL', { day: 'numeric', month: 'long', year: 'numeric' });
      } catch (e) {
        return value;
      }
    }

    function tick() {
      var diff = nowMs() - since.getTime();
      if (diff < 0) diff = 0;

      var total = Math.floor(diff / 1000);

      daysEl.textContent = String(Math.floor(total / 86400));
      hoursEl.textContent = pad2(Math.floor((total % 86400) / 3600));
      minutesEl.textContent = pad2(Math.floor((total % 3600) / 60));
      secondsEl.textContent = pad2(total % 60);
    }

    function start() {
      tick();
      window.clearInterval(timer);
      timer = sadMode ? 0 : window.setInterval(tick, 1000);   // sekunda po sekundzie
    }

    function setDay(value, keepLocal) {
      var date = parseDay(value);
      if (!date) return;

      since = date;
      if (keepLocal !== false) store.write(STORAGE_KEY, value);
      if (dateEl) dateEl.textContent = prettyDay(value);
      start();
    }

    function load() {
      var cached = store.read(STORAGE_KEY);
      if (cached) setDay(cached, false);

      if (!hasFetch) {
        if (!cached) setDay(DEFAULT_DATE);
        return;
      }

      api('GET', '/since')
        .then(function (data) {
          if (data && data.since) setDay(data.since);
          else if (!cached) setDay(DEFAULT_DATE);
        })
        .catch(function () {
          if (!cached) setDay(DEFAULT_DATE);
        });
    }

    if (editButton && dateInput) {
      editButton.addEventListener('click', function () {
        dateInput.hidden = false;
        dateInput.value = toDayValue(since);
        if (dateInput.showPicker) {
          try { dateInput.showPicker(); } catch (e) { /* starsze przeglądarki */ }
        } else {
          dateInput.focus();
        }
      });

      dateInput.addEventListener('change', function () {
        var value = dateInput.value;
        if (!value) return;

        dateInput.hidden = true;
        setDay(value);

        if (hasFetch) {
          api('PUT', '/since', { since: value }).catch(function () { /* zostaje kopia lokalna */ });
        }
      });
    }

    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) tick();
    });

    load();
  }

  /* ---------------- odliczanie do spotkania ---------------- */

  function createCountdown(section) {
    var daysEl = q(section, '[data-cd-days]');
    var hoursEl = q(section, '[data-cd-hours]');
    var minutesEl = q(section, '[data-cd-minutes]');
    var secondsEl = q(section, '[data-cd-seconds]');
    var noteEl = q(section, '[data-cd-note]');
    var editButton = q(section, '[data-cd-edit]');
    var dateInput = q(section, '[data-cd-input]');

    if (!daysEl) return;

    var STORAGE_KEY = 'spotkanie';
    var WEEK = 7 * 24 * 60 * 60 * 1000;   // domyślnie tydzień
    var target = Date.now() + WEEK;       // zanim przyjdzie data z serwera
    var timer = 0;

    function setTarget(time, keepLocal) {
      if (isNaN(time)) return;
      target = time;
      if (keepLocal !== false) store.write(STORAGE_KEY, new Date(target).toISOString());
      start();
    }

    /* data jest wspólna (baza), a kopia lokalna działa bez serwera */
    function loadTarget() {
      var saved = store.read(STORAGE_KEY);
      var cached = saved ? new Date(saved).getTime() : NaN;
      if (!isNaN(cached)) setTarget(cached, false);

      if (!hasFetch) {
        if (isNaN(cached)) setTarget(Date.now() + WEEK);
        return;
      }

      api('GET', '/meeting')
        .then(function (data) {
          var time = new Date(data.target).getTime();
          if (!isNaN(time)) setTarget(time);
        })
        .catch(function () {
          if (isNaN(cached)) setTarget(Date.now() + WEEK);
        });
    }

    function pad(value) {
      return value < 10 ? '0' + value : String(value);
    }

    function tick() {
      var left = target - nowMs();
      if (left < 0) left = 0;

      var total = Math.floor(left / 1000);

      daysEl.textContent = String(Math.floor(total / 86400));
      hoursEl.textContent = pad(Math.floor((total % 86400) / 3600));
      minutesEl.textContent = pad(Math.floor((total % 3600) / 60));
      secondsEl.textContent = pad(total % 60);

      if (left <= 0) {
        section.classList.add('is-now');
        if (noteEl) noteEl.textContent = 'to już dziś — jesteśmy razem';
      } else {
        section.classList.remove('is-now');
        if (noteEl) noteEl.textContent = 'zostało nam';
      }
    }

    function start() {
      tick();
      window.clearInterval(timer);
      timer = sadMode ? 0 : window.setInterval(tick, 1000);   // sekunda po sekundzie
    }

    function toInputValue(time) {
      var local = new Date(time - new Date().getTimezoneOffset() * 60000);
      return local.toISOString().slice(0, 16);
    }

    if (editButton && dateInput) {
      editButton.addEventListener('click', function () {
        dateInput.hidden = false;
        dateInput.value = toInputValue(target);
        if (dateInput.showPicker) {
          try { dateInput.showPicker(); } catch (e) { /* starsze przeglądarki */ }
        } else {
          dateInput.focus();
        }
      });

      dateInput.addEventListener('change', function () {
        var time = new Date(dateInput.value).getTime();
        if (isNaN(time)) return;

        dateInput.hidden = true;
        setTarget(time);

        if (hasFetch) {
          api('PUT', '/meeting', { target: new Date(time).toISOString() })
            .catch(function () { /* zostaje kopia lokalna */ });
        }
      });
    }

    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) tick();
    });

    loadTarget();
  }

  /* ---------------- pełnoekranowy podgląd zdjęcia ---------------- */

  function createLightbox(root) {
    if (!root) return null;

    var img = q(root, '[data-lb-img]');
    var audioEl = q(root, '[data-lb-audio]');
    var videoEl = q(root, '[data-lb-video]');
    var caption = q(root, '[data-lb-caption]');
    var counter = q(root, '[data-lb-counter]');
    var closeBtn = q(root, '[data-lb-close]');
    var prevBtn = q(root, '[data-lb-prev]');
    var nextBtn = q(root, '[data-lb-next]');

    var items = [];
    var index = 0;
    var startX = 0;
    var startY = 0;

    function clearMedia() {
      if (img) {
        img.removeAttribute('src');
        img.hidden = true;
      }
      if (audioEl) {
        audioEl.pause();
        audioEl.removeAttribute('src');
        audioEl.hidden = true;
      }
      if (videoEl) {
        videoEl.pause();
        videoEl.removeAttribute('src');
        videoEl.hidden = true;
      }
    }

    function show(i) {
      if (!items.length) return;

      index = (i + items.length) % items.length;
      var item = items[index];
      var kind = item.kind || 'photo';

      clearMedia();

      if (kind === 'photo' && img) {
        img.src = item.src;
        img.alt = 'Nasze wspomnienie — ' + item.name;
        img.hidden = false;
      } else if (kind === 'audio' && audioEl) {
        audioEl.src = item.src;
        audioEl.hidden = false;
      } else if (kind === 'video' && videoEl) {
        videoEl.src = item.src;
        videoEl.hidden = false;
      }

      if (caption) caption.textContent = item.name;
      if (counter) counter.textContent = (index + 1) + ' / ' + items.length;
    }

    function open(list, i) {
      items = list.slice();
      root.hidden = false;
      document.body.style.overflow = 'hidden';
      show(i);
      if (closeBtn) closeBtn.focus();
    }

    function close() {
      root.hidden = true;
      document.body.style.overflow = '';
      clearMedia();
    }

    if (closeBtn) closeBtn.addEventListener('click', close);
    if (prevBtn) prevBtn.addEventListener('click', function () { show(index - 1); });
    if (nextBtn) nextBtn.addEventListener('click', function () { show(index + 1); });

    root.addEventListener('click', function (event) {
      if (event.target === root) close();
    });

    document.addEventListener('keydown', function (event) {
      if (root.hidden) return;
      if (event.key === 'Escape') close();
      else if (event.key === 'ArrowLeft') show(index - 1);
      else if (event.key === 'ArrowRight') show(index + 1);
    });

    /* przesuwanie palcem (Android / iOS) */
    root.addEventListener('touchstart', function (event) {
      var touch = event.changedTouches[0];
      startX = touch.clientX;
      startY = touch.clientY;
    }, { passive: true });

    root.addEventListener('touchend', function (event) {
      var touch = event.changedTouches[0];
      var dx = touch.clientX - startX;
      var dy = touch.clientY - startY;
      if (Math.abs(dx) > 55 && Math.abs(dx) > Math.abs(dy)) {
        show(index + (dx < 0 ? 1 : -1));
      }
    }, { passive: true });

    return { open: open };
  }



  /* ---------------- „zerwij połączenie” ---------------- */

  function createFarewell() {
    var breakButton = q(document, '[data-breakup]');
    var restoreButton = q(document, '[data-restore]');

    if (breakButton) {
      breakButton.addEventListener('click', function () {
        var sure = window.confirm(
          'Na pewno?\n\nZegar stanie, a serce przestanie bić.\nTa zmiana zostanie zapisana.'
        );
        if (!sure) return;

        if (!hasFetch) {
          window.alert('Ta zmiana wymaga uruchomionego serwera.');
          return;
        }

        api('PUT', '/status', { state: 'rozstanie' })
          .then(function () { window.location.reload(); })
          .catch(function () { window.alert('Nie udało się zapisać. Spróbuj ponownie.'); });
      });
    }

    if (restoreButton) {
      restoreButton.addEventListener('click', function () {
        if (!window.confirm('Przywrócić wszystko tak, jak było?')) return;

        if (!hasFetch) {
          window.alert('Ta zmiana wymaga uruchomionego serwera.');
          return;
        }

        api('PUT', '/status', { state: 'razem' })
          .then(function () { window.location.reload(); })
          .catch(function () { window.alert('Nie udało się przywrócić.'); });
      });
    }
  }

  /* ---------------- start ---------------- */

  function startModules() {
    var since = q(document, '[data-since]');
    if (since) createSince(since);

    var countdown = q(document, '[data-countdown]');
    if (countdown) createCountdown(countdown);

    var mood = q(document, '[data-mood]');
    if (mood) createMood(mood);

    var secret = q(document, '[data-secret]');
    if (secret) createSecret(secret);

    qa(document, '[data-notebook]').forEach(createNotebook);

    var gallery = q(document, '.gallery');
    if (gallery) createGallery(gallery);

    createFarewell();
  }

  function boot() {
    lightbox = createLightbox(q(document, '[data-lightbox]'));

    if (!hasFetch) {
      startModules();
      return;
    }

    /* najpierw stan: po zerwaniu zegary stają i widać list */
    api('GET', '/status')
      .then(function (data) {
        if (data && data.state === 'rozstanie') applySad(data.at);
      })
      .catch(function () { /* bez serwera: tryb normalny */ })
      .then(startModules);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
