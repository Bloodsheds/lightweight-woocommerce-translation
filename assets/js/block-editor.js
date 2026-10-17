/**
 * Lightweight Translation – Gutenberg in-place translation.
 *
 * 1. Choose a language (sidebar "Translation" or the "Language" panel of the Post tab).
 * 2. The canvas now shows that language: click any text and type the translation.
 * 3. Press "Save translation". Back to the original language with one click.
 *
 * While a secondary language is active, WordPress' own Save/Update is locked and any REST write
 * to the post is blocked, so the original post can never be overwritten by a translation.
 */
(function (wp, config) {
  'use strict';
  if (!wp || !config || !config.postId || !wp.data || !wp.blocks || !wp.plugins) return;

  var el = wp.element.createElement;
  var Fragment = wp.element.Fragment;
  var useState = wp.element.useState;
  var useEffect = wp.element.useEffect;
  var __ = wp.i18n.__;
  var sprintf = wp.i18n.sprintf;
  var C = wp.components;
  var editorPkg = wp.editor || {};
  var editPostPkg = wp.editPost || {};
  var PluginSidebar = editorPkg.PluginSidebar || editPostPkg.PluginSidebar;
  var PluginDocumentSettingPanel = editorPkg.PluginDocumentSettingPanel || editPostPkg.PluginDocumentSettingPanel;

  var LOCK = 'lt-translation';
  var NOTICE = 'lt-translation-mode';
  var DEFAULT = config.defaultLanguage;
  var translations = config.translations || {};

  var state = { active: DEFAULT, original: null, drafts: {}, slugs: {}, saving: false };
  var listeners = [];
  Object.keys(translations).forEach(function (code) { state.slugs[code] = translations[code].slug || ''; });

  function emit() { listeners.forEach(function (listener) { listener(Object.assign({}, state)); }); }
  function select() { return wp.data.select('core/editor'); }
  function editor() { return wp.data.dispatch('core/editor'); }
  function notices() { return wp.data.dispatch('core/notices'); }
  function info(code) { return (config.languages || []).filter(function (l) { return l.code === code; })[0] || { code: code, label: code.toUpperCase(), flag: '🌐' }; }
  function name(code) { var l = info(code); return l.flag + ' ' + l.label; }

  function normalize(content) {
    try { return wp.blocks.serialize(wp.blocks.parse(content || '')); } catch (e) { return content || ''; }
  }

  function snapshot() {
    var s = select();
    return { title: s.getEditedPostAttribute('title') || '', content: s.getEditedPostContent() || '', excerpt: s.getEditedPostAttribute('excerpt') || '' };
  }

  /** The translation is always based on the saved original: refuse to switch while the original has unsaved edits. */
  function originalHasUnsavedChanges() {
    var s = select();
    if (s.isEditedPostNew() || s.getCurrentPostAttribute('status') === 'auto-draft') return true;
    if ((s.getEditedPostAttribute('title') || '') !== (s.getCurrentPostAttribute('title') || '')) return true;
    if (config.supportsExcerpt && (s.getEditedPostAttribute('excerpt') || '') !== (s.getCurrentPostAttribute('excerpt') || '')) return true;
    return normalize(s.getEditedPostContent()) !== normalize(s.getCurrentPostAttribute('content'));
  }

  function apply(values, isOriginal) {
    var blocks = wp.blocks.parse(values.content || '');
    editor().resetEditorBlocks(blocks, { __unstableShouldCreateUndoLevel: false });
    var edits = { title: values.title };
    if (config.supportsExcerpt) edits.excerpt = values.excerpt;
    // Back on the original: give the saved values back so the post is no longer "dirty".
    if (isOriginal) edits.content = select().getCurrentPostAttribute('content');
    editor().editPost(edits, { undoIgnore: true });
  }

  function valuesFor(code) {
    if (state.drafts[code]) return state.drafts[code];
    var t = translations[code] || {};
    var o = state.original;
    return { title: t.title != null ? t.title : o.title, content: t.content != null ? t.content : o.content, excerpt: t.excerpt != null ? t.excerpt : o.excerpt };
  }

  function lock(on) {
    var d = editor();
    if (on) { d.lockPostSaving(LOCK); if (d.lockPostAutosaving) d.lockPostAutosaving(LOCK); }
    else { d.unlockPostSaving(LOCK); if (d.unlockPostAutosaving) d.unlockPostAutosaving(LOCK); }
  }

  function showModeNotice(code) {
    notices().removeNotice(NOTICE);
    if (code === DEFAULT) return;
    notices().createNotice('info', sprintf(__('Translation mode: %s. Click any text in the page and type the translation. The original is not modified.', 'lightweight-translation'), name(code)), {
      id: NOTICE,
      isDismissible: false,
      actions: [
        { label: __('Save translation', 'lightweight-translation'), onClick: save },
        { label: sprintf(__('Back to %s', 'lightweight-translation'), name(DEFAULT)), onClick: function () { switchTo(DEFAULT); } }
      ]
    });
  }

  function switchTo(code) {
    if (code === state.active || state.saving) return;
    if (state.active === DEFAULT) {
      if (originalHasUnsavedChanges()) {
        notices().createNotice('warning', __('Save (or publish) the original first, then switch language to translate it.', 'lightweight-translation'), { id: 'lt-unsaved', isDismissible: true });
        return;
      }
      notices().removeNotice('lt-unsaved');
      state.original = snapshot();
    } else {
      state.drafts[state.active] = snapshot(); // keep unsaved work in memory while switching
    }
    state.active = code;
    if (code === DEFAULT) { apply(state.original, true); lock(false); }
    else { apply(valuesFor(code), false); lock(true); }
    showModeNotice(code);
    emit();
  }

  function save() {
    var code = state.active;
    if (code === DEFAULT || state.saving) return;
    var values = snapshot();
    var data = { language: code, title: values.title, content: values.content, slug: state.slugs[code] || '' };
    if (config.supportsExcerpt) data.excerpt = values.excerpt;
    state.saving = true; emit();
    wp.apiFetch({ path: config.restPath, method: 'POST', data: data }).then(function (response) {
      translations[code] = (response && response.translation) || translations[code] || {};
      translations[code].stale = false;
      state.slugs[code] = translations[code].slug || data.slug;
      delete state.drafts[code];
      notices().createNotice('success', sprintf(__('%s translation saved.', 'lightweight-translation'), name(code)), { type: 'snackbar', isDismissible: true });
    }).catch(function (error) {
      notices().createNotice('error', (error && error.message) || __('Unable to save the translation.', 'lightweight-translation'), { id: 'lt-save-error', isDismissible: true });
    }).then(function () { state.saving = false; emit(); });
  }

  /** Deletes the stored translation of the current language and reloads the original text in the canvas. */
  function reset() {
    var code = state.active;
    if (code === DEFAULT || state.saving) return;
    if (!window.confirm(sprintf(__('Delete the %s translation of this page? The original is not touched.', 'lightweight-translation'), name(code)))) return;
    state.saving = true; emit();
    wp.apiFetch({ path: config.restPath + '/reset', method: 'POST', data: { language: code } }).then(function () {
      translations[code] = {};
      state.slugs[code] = '';
      delete state.drafts[code];
      apply(valuesFor(code), false); // the original text is copied again, ready to translate
      notices().createNotice('success', sprintf(__('%s translation reset. The page cache of every language was cleared.', 'lightweight-translation'), name(code)), { type: 'snackbar', isDismissible: true });
    }).catch(function (error) {
      notices().createNotice('error', (error && error.message) || __('Unable to reset the translation.', 'lightweight-translation'), { isDismissible: true });
    }).then(function () { state.saving = false; emit(); });
  }

  // Safety net: no REST write to this post while a translation is displayed (Ctrl+S, autosave, plugins…).
  var postWrite = new RegExp('(^|/)wp/v2/[^/?]+/' + config.postId + '(/autosaves)?([/?]|$)');
  wp.apiFetch.use(function (options, next) {
    var method = String(options.method || 'GET').toUpperCase();
    var target = String(options.path || options.url || '');
    if (state.active !== DEFAULT && method !== 'GET' && target.indexOf('light-translation/v1') === -1 && postWrite.test(target)) {
      return Promise.reject({ code: 'lt_translation_mode', message: sprintf(__('Translation mode (%s) is active: use "Save translation", or go back to the original language to update the post.', 'lightweight-translation'), name(state.active)) });
    }
    return next(options);
  });

  // Ctrl/Cmd+S saves the translation instead of the post while translating.
  window.addEventListener('keydown', function (event) {
    if (state.active === DEFAULT || !(event.ctrlKey || event.metaKey) || String(event.key).toLowerCase() !== 's') return;
    event.preventDefault();
    event.stopPropagation();
    save();
  }, true);

  function useTranslationState() {
    var pair = useState(Object.assign({}, state));
    useEffect(function () {
      var listener = pair[1];
      listeners.push(listener);
      return function () { listeners = listeners.filter(function (l) { return l !== listener; }); };
    }, []);
    return pair[0];
  }

  function LanguagePanel() {
    var s = useTranslationState();
    var code = s.active;
    var current = translations[code] || {};
    var translating = code !== DEFAULT;

    var buttons = el('div', { style: { display: 'flex', flexWrap: 'wrap', gap: '6px', marginBottom: '12px' } },
      (config.languages || []).map(function (language) {
        return el(C.Button, {
          key: language.code,
          variant: language.code === code ? 'primary' : 'secondary',
          isPressed: language.code === code,
          disabled: s.saving,
          onClick: function () { switchTo(language.code); }
        }, language.flag + ' ' + language.label + (language.code === DEFAULT ? ' ★' : ''));
      })
    );

    if (!translating) {
      return el(Fragment, null, buttons,
        el('p', { className: 'components-base-control__help' }, sprintf(__('You are editing the original (%s). Choose a language, then click on the text to translate it.', 'lightweight-translation'), name(DEFAULT))));
    }

    return el(Fragment, null, buttons,
      el('p', null, sprintf(__('Editing the %s translation directly in the page.', 'lightweight-translation'), name(code))),
      current.content == null && !s.drafts[code] ? el(C.Notice, { status: 'info', isDismissible: false }, __('Not translated yet: the original text was copied, type over it.', 'lightweight-translation')) : null,
      current.stale ? el(C.Notice, { status: 'warning', isDismissible: false }, __('The original changed since this translation was saved. Check the text, then save again.', 'lightweight-translation')) : null,
      config.isFrontPage ? el('p', { className: 'components-base-control__help' }, sprintf(__('Home page: its address stays %s, without slug.', 'lightweight-translation'), '/' + code + '/')) : el(C.TextControl, {
        label: __('Translated URL slug', 'lightweight-translation'),
        help: __('Optional. Leave empty to keep the original slug.', 'lightweight-translation'),
        value: s.slugs[code] || '',
        onChange: function (value) { state.slugs[code] = value; emit(); }
      }),
      el('div', { style: { display: 'flex', gap: '8px', flexWrap: 'wrap' } },
        el(C.Button, { variant: 'primary', isBusy: s.saving, disabled: s.saving, onClick: save }, __('Save translation', 'lightweight-translation')),
        el(C.Button, { variant: 'tertiary', disabled: s.saving, onClick: function () { switchTo(DEFAULT); } }, sprintf(__('Back to %s', 'lightweight-translation'), name(DEFAULT)))
      ),
      el('div', { style: { marginTop: '16px', paddingTop: '12px', borderTop: '1px solid #ddd' } },
        el(C.Button, { variant: 'link', isDestructive: true, disabled: s.saving, onClick: reset }, sprintf(__('Reset the %s translation', 'lightweight-translation'), name(code))),
        el('p', { className: 'components-base-control__help' }, __('Deletes the stored translation (text, title, slug) and starts again from the original. Useful when a translated page shows old content or old links.', 'lightweight-translation'))
      )
    );
  }

  wp.plugins.registerPlugin('lightweight-translation-editor', {
    icon: 'translation',
    render: function () {
      return el(Fragment, null,
        PluginDocumentSettingPanel ? el(PluginDocumentSettingPanel, { name: 'lt-language-panel', title: __('Language / Translation', 'lightweight-translation') }, el(LanguagePanel)) : null,
        PluginSidebar ? el(PluginSidebar, { name: 'lt-translation-sidebar', title: __('Translation', 'lightweight-translation'), icon: 'translation' }, el(C.PanelBody, null, el(LanguagePanel))) : null
      );
    }
  });

  // ?lt_lang=fr (e.g. from the "Translate this page" admin-bar link) opens the editor directly in that language.
  if (config.initialLanguage && config.initialLanguage !== DEFAULT) {
    wp.domReady(function () {
      var tries = 0;
      var timer = setInterval(function () {
        var s = select();
        var ready = s && s.getCurrentPostId && s.getCurrentPostId() && (!s.__unstableIsEditorReady || s.__unstableIsEditorReady());
        if (ready || ++tries > 50) { clearInterval(timer); if (ready) switchTo(config.initialLanguage); }
      }, 200);
    });
  }
}(window.wp, window.LTBlockTranslation));
