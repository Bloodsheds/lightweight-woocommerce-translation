(function () {
  'use strict';

  function getTinyMCE(id) {
    return window.tinymce && window.tinymce.get ? window.tinymce.get(id) : null;
  }

  function adapter(element, editorId) {
    return {
      get: function () {
        var editor = getTinyMCE(editorId);
        return editor && !editor.isHidden() ? editor.getContent() : element.value;
      },
      set: function (value) {
        element.value = value;
        var editor = getTinyMCE(editorId);
        if (editor) editor.setContent(value || '');
        element.dispatchEvent(new Event('input', { bubbles: true }));
        element.dispatchEvent(new Event('change', { bubbles: true }));
      }
    };
  }

  function translationInput(root, field, language) {
    var row = root.querySelector('[data-lt-field="' + field + '"][data-lt-language="' + language + '"]');
    return row ? row.querySelector('.lt-target input:not([type="hidden"]), .lt-target textarea') : null;
  }

  function defaultInput(root, field) {
    return root.querySelector('[data-lt-default-field="' + field + '"]');
  }

  function changedInput(input) {
    var row = input ? input.closest('[data-lt-field][data-lt-language]') : null;
    return row ? row.querySelector('[data-lt-changed]') : null;
  }

  function makeControl(root, field, fieldAdapter, languages, mount, placement) {
    if (!mount || !fieldAdapter || mount.querySelector('[data-lt-native-control="' + field + '"]')) return;
    var defaultLanguage = root.getAttribute('data-lt-default-language');
    var storedDefault = defaultInput(root, field);
    var state = { current: defaultLanguage, defaultValue: storedDefault ? storedDefault.value : fieldAdapter.get(), fallback: false };
    var secondaryState = document.createElement('input');
    secondaryState.type = 'hidden';
    secondaryState.name = 'lt_native_secondary[' + field + ']';
    secondaryState.value = '0';
    root.appendChild(secondaryState);
    var control = document.createElement('div');
    control.className = 'lt-native-language-control';
    control.setAttribute('data-lt-native-control', field);
    var label = document.createElement('label');
    label.textContent = (window.ltAdminI18n || {}).language || 'Language';
    var select = document.createElement('select');
    select.className = 'lt-native-language-select';
    languages.forEach(function (language) {
      var option = document.createElement('option');
      option.value = language.code;
      option.textContent = language.label;
      select.appendChild(option);
    });
    var fallback = document.createElement('span');
    fallback.className = 'lt-fallback-note';
    fallback.textContent = (window.ltAdminI18n || {}).fallback || 'Using the original language';
    fallback.hidden = true;
    label.appendChild(select);
    control.appendChild(label);
    control.appendChild(fallback);

    function persist() {
      var value = fieldAdapter.get();
      if (state.current === defaultLanguage) {
        state.defaultValue = value;
        if (storedDefault) storedDefault.value = value;
        return;
      }
      var target = translationInput(root, field, state.current);
      if (!target) return;
      if (!(state.fallback && value === state.defaultValue) && target.value !== value) {
        target.value = value;
        var changed = changedInput(target);
        if (changed) changed.value = '1';
      }
    }

    function load(language) {
      state.current = language;
      secondaryState.value = language === defaultLanguage ? '0' : '1';
      if (language === defaultLanguage) {
        state.fallback = false;
        fallback.hidden = true;
        fieldAdapter.set(state.defaultValue);
        return;
      }
      var target = translationInput(root, field, language);
      var translated = target ? target.value : '';
      state.fallback = translated.trim() === '';
      fallback.hidden = !state.fallback;
      fieldAdapter.set(state.fallback ? state.defaultValue : translated);
    }

    languages.forEach(function (language) {
      if (language.code === defaultLanguage) return;
      var target = translationInput(root, field, language.code);
      if (!target) return;
      target.addEventListener('input', function () {
        var changed = changedInput(target);
        if (changed) changed.value = '1';
        if (state.current !== language.code) return;
        state.fallback = target.value.trim() === '';
        fallback.hidden = !state.fallback;
        fieldAdapter.set(state.fallback ? state.defaultValue : target.value);
      });
    });

    select.addEventListener('change', function () { persist(); load(select.value); });
    var form = mount.closest('form') || document.getElementById('post');
    if (form) form.addEventListener('submit', function () { persist(); secondaryState.value = '0'; fieldAdapter.set(state.defaultValue); });
    if (placement === 'append') mount.appendChild(control); else mount.insertAdjacentElement('afterend', control);
  }

  function editorMount(postboxSelector, fallback) {
    var postbox = document.querySelector(postboxSelector);
    if (!postbox) return { element: fallback, placement: 'after' };
    var header = postbox.querySelector(':scope > .postbox-header') || postbox.querySelector(':scope > h2.hndle');
    return header ? { element: header, placement: 'append' } : { element: fallback, placement: 'after' };
  }

  function setBottomLanguage(root, language) {
    root.querySelectorAll('[data-lt-tab]').forEach(function (tab) {
      var active = tab.getAttribute('data-lt-tab') === language;
      tab.classList.toggle('is-active', active);
      tab.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    root.querySelectorAll('[data-lt-panel]').forEach(function (panel) {
      panel.classList.toggle('is-active', panel.getAttribute('data-lt-panel') === language);
    });
  }

  function init(root) {
    var secondary = Array.prototype.slice.call(root.querySelectorAll('.lt-language-tab')).map(function (tab) {
      return { code: tab.getAttribute('data-lt-tab'), label: tab.textContent.trim() };
    });
    var languages = [{ code: root.getAttribute('data-lt-default-language'), label: root.getAttribute('data-lt-default-label') }].concat(secondary);
    root.querySelectorAll('.lt-target input:not([type="hidden"]), .lt-target textarea').forEach(function (input) {
      input.addEventListener('input', function () { var changed = changedInput(input); if (changed) changed.value = '1'; });
    });
    root.querySelectorAll('.lt-language-tab').forEach(function (tab) { tab.addEventListener('click', function () { setBottomLanguage(root, tab.getAttribute('data-lt-tab')); }); });
    var layout = root.getAttribute('data-lt-layout') || 'both';
    if (layout !== 'next' && layout !== 'both') return;

    var title = document.getElementById('title');
    if (title) makeControl(root, 'title', adapter(title, 'title'), languages, document.getElementById('titlewrap') || title, 'after');

    var content = document.getElementById('content');
    if (content) {
      var contentMount = editorMount('#postdivrich', document.getElementById('wp-content-wrap') || content);
      makeControl(root, 'content', adapter(content, 'content'), languages, contentMount.element, contentMount.placement);
    }

    var excerpt = document.getElementById('excerpt');
    if (excerpt) {
      var excerptMount = editorMount('#postexcerpt', document.getElementById('wp-excerpt-wrap') || excerpt);
      makeControl(root, 'short_description', adapter(excerpt, 'excerpt'), languages, excerptMount.element, excerptMount.placement);
    }
  }

  /**
   * Categories, tags, attribute values, product attributes: a "Language" selector at the top of the
   * native form switches the Name / Slug / Description fields between the original and its translations.
   * Before WordPress saves (normal submit or AJAX "Add new"), the original values are put back.
   */
  function initNativeTranslations(container) {
    var config;
    try { config = JSON.parse(container.getAttribute('data-lt-native')); } catch (e) { return; }
    var form = container.closest('form');
    if (!form || form.querySelector('.lt-native-language-control')) return;
    var fields = {};
    Object.keys(config.fields).forEach(function (key) {
      var el = form.querySelector(config.fields[key]);
      if (el) fields[key] = { el: el, api: adapter(el, el.id), placeholder: el.getAttribute('placeholder') || '' };
    });
    if (!Object.keys(fields).length) return;
    var state = { current: config['default'].code, originals: {} };
    function store(lang, key) { return container.querySelector('[data-lt-store="' + lang + '|' + key + '"]'); }

    var bar = document.createElement('div');
    bar.className = 'lt-native-language-control lt-term-language-control';
    var label = document.createElement('label');
    label.textContent = config.i18n.language + ' ';
    var select = document.createElement('select');
    select.className = 'lt-native-language-select';
    [config['default']].concat(config.languages).forEach(function (language, index) {
      var option = document.createElement('option');
      option.value = language.code;
      option.textContent = language.label + (index === 0 ? ' ★' : '');
      select.appendChild(option);
    });
    var note = document.createElement('span');
    note.className = 'lt-fallback-note';
    note.textContent = config.i18n.translating;
    note.hidden = true;
    label.appendChild(select);
    bar.appendChild(label);
    bar.appendChild(note);

    function persist() {
      Object.keys(fields).forEach(function (key) {
        var value = fields[key].api.get();
        if (state.current === config['default'].code) state.originals[key] = value;
        else { var input = store(state.current, key); if (input) input.value = value; }
      });
    }
    function load(lang) {
      state.current = lang;
      var translating = lang !== config['default'].code;
      Object.keys(fields).forEach(function (key) {
        var f = fields[key];
        if (!translating) { f.api.set(state.originals[key] || ''); f.el.setAttribute('placeholder', f.placeholder); return; }
        var input = store(lang, key);
        f.api.set(input ? input.value : '');
        f.el.setAttribute('placeholder', String(state.originals[key] || '').slice(0, 80));
      });
      select.value = lang;
      note.hidden = !translating;
      form.classList.toggle('lt-is-translating', translating);
    }
    function restoreOriginal() { persist(); load(config['default'].code); }

    select.addEventListener('change', function () { persist(); load(select.value); });
    // Capture phase: runs before WordPress' own validation / AJAX submit of the "Add new" form.
    document.addEventListener('click', function (event) {
      var button = event.target.closest('input[type="submit"], button[type="submit"], #submit');
      if (button && form.contains(button)) restoreOriginal();
    }, true);
    form.addEventListener('submit', restoreOriginal, true);
    form.addEventListener('keydown', function (event) { if (event.key === 'Enter' && event.target.tagName === 'INPUT') restoreOriginal(); }, true);
    // After "Add new category" (AJAX), WordPress empties the form: empty the translations too.
    if (window.jQuery) window.jQuery(document).ajaxSuccess(function (event, xhr, settings) {
      if (settings && typeof settings.data === 'string' && settings.data.indexOf('action=add-tag') !== -1 && form.id === 'addtag') {
        container.querySelectorAll('[data-lt-store]').forEach(function (input) { input.value = ''; });
        state.originals = {};
      }
    });

    form.insertBefore(bar, form.firstChild);
    persist(); // remember the original values loaded by WordPress
  }

  function protectAutosave(payload) {
    if (!payload) return;
    document.querySelectorAll('[data-lt-editor]').forEach(function (root) {
      ['title', 'content', 'short_description', 'excerpt'].forEach(function (field) {
        var state = root.querySelector('[name="lt_native_secondary[' + field + ']"]');
        var original = defaultInput(root, field);
        if (!state || state.value !== '1' || !original) return;
        if (field === 'title') payload.post_title = original.value;
        if (field === 'content') payload.content = original.value;
        if (field === 'short_description' || field === 'excerpt') payload.excerpt = original.value;
      });
    });
  }

  function initAutosaveProtection() {
    if (!window.jQuery) return;
    window.jQuery(document).on('before-autosave.ltTranslations', function (event, data) { protectAutosave(data); });
    window.jQuery(document).on('heartbeat-send.ltTranslations', function (event, data) {
      if (data && data.wp_autosave) protectAutosave(data.wp_autosave);
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-lt-editor]').forEach(init);
    document.querySelectorAll('[data-lt-native]').forEach(initNativeTranslations);
    document.querySelectorAll('.lt-term-translation').forEach(function (input) {
      input.addEventListener('input', function () {
        var changed = input.parentNode.querySelector('[data-lt-term-changed]');
        if (changed) changed.value = '1';
      });
    });
    initAutosaveProtection();
  });
}());
