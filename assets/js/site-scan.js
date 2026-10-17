/**
 * Texts a visitor sees in a translated page that are still in the original language.
 *
 * The translated page and the same page in the original language are both read as the browser shows them
 * (texts written by PHP AND by JavaScript, placeholders, image descriptions, button labels…): every text found
 * in both is "not translated". Used by:
 *  - Light translations › Scan the site: every public page is opened in the background, in every language;
 *  - "Highlight untranslated texts" (admin bar, ?lt_check=1): the texts are outlined in red on the page itself.
 */
(function () {
  'use strict';
  var SKIP = 'script,style,noscript,template,svg,code,pre,textarea,#wpadminbar,#lt-check-bar,#lt-check-detail,.lt-language-switcher-wrap,.lt-menu-switcher-item,.lt-language-switcher-mobile,[data-lt-lang]';
  var ATTRS = ['placeholder', 'title', 'alt', 'aria-label'];

  function norm(text) { return String(text || '').replace(/\s+/g, ' ').trim(); }
  function key(text) { return norm(text).toLowerCase(); }
  /** Not a text to translate: too short, no word, a number, a price, an address. */
  function ignored(text) {
    if (text.length < 2 || !/\p{L}{2}/u.test(text)) return true;
    if (/^[\d\s.,:;€$£%+\-–—\/()x×#*]+$/.test(text)) return true;
    return /^(https?:\/\/|www\.)\S+$|^\S+@\S+\.\S+$/i.test(text);
  }
  function hidden(el) {
    if (el.closest('.screen-reader-text,.sr-only,.visually-hidden,[hidden],[aria-hidden="true"]')) return true;
    if (!el.getClientRects().length) return true; // display:none, closed menu, popup not opened…
    var view = el.ownerDocument.defaultView;
    return view && view.getComputedStyle(el).visibility === 'hidden';
  }
  /** Keep closed interactive UI available for checking, but ignore content disabled everywhere. */
  function belongsToClosedControl(el) {
    return !!el.closest('nav,[role="menu"],[role="tabpanel"],[role="dialog"],dialog,.sub-menu,.menu-item-has-children,.woocommerce-Tabs-panel,.elementor-tab-content,.tab-pane,.wd-accordion,.wd-popup,.wd-dropdown,.modal,.popup,.collapse');
  }

  /** Every text of a page: { key: { text, els: [element…], how: 'text' | attribute name } }. */
  function collect(doc) {
    var found = Object.create(null);
    var body = doc.body;
    if (!body) return found;
    function add(text, el, how) {
      text = norm(text);
      if (!el || ignored(text) || el.closest(SKIP)) return;
      if (hidden(el) && !belongsToClosedControl(el)) return;
      var k = key(text);
      if (!found[k]) found[k] = { text: text, els: [], how: how };
      found[k].els.push(el);
    }
    var walker = doc.createTreeWalker(body, NodeFilter.SHOW_TEXT);
    while (walker.nextNode()) add(walker.currentNode.data, walker.currentNode.parentElement, 'text');
    ATTRS.forEach(function (attribute) {
      body.querySelectorAll('[' + attribute + ']').forEach(function (el) { add(el.getAttribute(attribute), el, attribute); });
    });
    body.querySelectorAll('input[type=submit][value],input[type=button][value],input[type=reset][value],button[value]').forEach(function (el) { add(el.value, el, 'value'); });
    return found;
  }

  /**
   * Texts of the translated page also present in the original page.
   * @return [ { text, visible, how, els } ] visible texts first.
   */
  function compare(original, translated, skip) {
    var out = [];
    Object.keys(translated).forEach(function (k) {
      if (!original[k] || (skip && skip[k])) return;
      var entry = translated[k];
      var visible = entry.els.some(function (el) { return !hidden(el); });
      out.push({ text: entry.text, visible: visible, how: entry.how, els: entry.els });
    });
    out.sort(function (a, b) { return (b.visible ? 1 : 0) - (a.visible ? 1 : 0); });
    return out;
  }

  /**
   * Loads a URL in a hidden frame and gives its document once its scripts had time to write their texts.
   * quiet (ms): faster mode used by the site scan. The page is read as soon as its HTML is ready and nothing
   * has changed in it for that long (still at most "settle" ms), without waiting for images and videos.
   */
  function load(frame, url, settle, quiet) {
    return new Promise(function (resolve, reject) {
      var done = false, previous = null, poll = null, observer = null;
      settle = settle || 2000;
      try { previous = frame.contentDocument; } catch (e) { previous = null; }
      var timer = setTimeout(function () { finish(new Error('timeout')); }, 45000);
      function finish(error) {
        if (done) return;
        done = true;
        clearTimeout(timer);
        clearInterval(poll);
        if (observer) observer.disconnect();
        frame.onload = null;
        if (error) { reject(error); return; }
        try { resolve(frame.contentDocument); } catch (e) { reject(e); }
      }
      var waiting = false;
      function wait(doc) {
        if (waiting || done) return;
        waiting = true;
        clearInterval(poll);
        if (!quiet || !doc || !window.MutationObserver) { setTimeout(function () { finish(null); }, settle); return; }
        var started = Date.now(), last = started;
        observer = new MutationObserver(function () { last = Date.now(); });
        observer.observe(doc.documentElement, { childList: true, subtree: true, characterData: true, attributes: true });
        (function tick() {
          if (done) return;
          var now = Date.now();
          if (now - last >= quiet || now - started >= settle) finish(null);
          else setTimeout(tick, 100);
        })();
      }
      frame.onload = function () { var doc = null; try { doc = frame.contentDocument; } catch (e) {} wait(doc); };
      if (quiet) poll = setInterval(function () {
        var doc = null;
        try { doc = frame.contentDocument; } catch (e) { return; }
        if (doc && doc !== previous && 'about:blank' !== doc.URL && 'loading' !== doc.readyState) wait(doc);
      }, 100);
      frame.src = url;
    });
  }

  /** Texts of one page as [text, visible (1/0)], visible ones first; skip: lowercase texts not to report. */
  function list(found, skip) {
    var out = [];
    Object.keys(found).forEach(function (k) {
      if (skip && skip[k]) return;
      out.push([found[k].text, found[k].els.some(function (el) { return !hidden(el); }) ? 1 : 0]);
    });
    out.sort(function (a, b) { return b[1] - a[1]; });
    return out;
  }

  window.ltScan = { collect: collect, compare: compare, list: list, load: load, key: key };

  /* ---------- "Highlight untranslated texts" on the page itself (?lt_check=1) ---------- */

  var data = window.ltCheck;
  if (!data || !data.original) return;
  function start() {
    var style = document.createElement('style');
    style.textContent = '.lt-untranslated{outline:2px solid #d63638!important;outline-offset:1px;background-color:rgba(214,54,56,.12)!important}'
      + '#lt-check-bar{position:fixed;z-index:2147483647;left:16px;bottom:16px;max-width:calc(100% - 32px);background:#1d2327;color:#fff;font:14px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;padding:10px 14px;border-radius:6px;box-shadow:0 4px 16px rgba(0,0,0,.3)}'
      + '#lt-check-bar a{color:#72aee6;margin-left:10px}'
      + '#lt-check-detail{position:fixed;z-index:2147483647;right:20px;bottom:24px;width:340px;max-width:calc(100vw - 40px);box-sizing:border-box;background:white;color:#1d2327;border:1px solid #dcdcde;border-radius:14px;padding:22px;box-shadow:0 8px 40px #0003;font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}'
      + '#lt-check-detail[hidden]{display:none}#lt-check-detail p{margin:10px 0}#lt-check-detail .lt-check-open{display:inline-block;background:#1769e0;color:white;border-radius:8px;padding:8px 14px;text-decoration:none}#lt-check-detail button{float:right;border:0;background:none;font-size:20px;cursor:pointer}';
    document.head.appendChild(style);
    var bar = document.createElement('div');
    bar.id = 'lt-check-bar';
    bar.textContent = data.labels.reading;
    document.body.appendChild(bar);
    var frame = document.createElement('iframe');
    frame.setAttribute('aria-hidden', 'true');
    frame.style.cssText = 'position:fixed;left:-10000px;top:0;width:' + Math.max(window.innerWidth, 360) + 'px;height:900px;border:0;visibility:hidden;pointer-events:none';
    frame.tabIndex = -1;
    document.body.appendChild(frame);
    var skip = Object.create(null);
    (data.ignored || []).forEach(function (text) { skip[key(text)] = true; });
    var marked = Object.create(null), entries = new Map(), origins = new Map(), resolving = false;
    load(frame, data.original, 1500).then(function (doc) {
      var original = collect(doc);
      frame.remove();
      var detail = document.createElement('section');
      detail.id = 'lt-check-detail'; detail.hidden = true;
      detail.setAttribute('aria-label', data.labels.translate);
      document.body.appendChild(detail);
      var activeElement = null;
      function direct(text) {
        var url = new URL(data.translate);
        url.searchParams.set('text', text); url.searchParams.set('ref', data.ref); url.searchParams.set('lang', data.language);
        return url.href;
      }
      function show(text, element) {
        detail.replaceChildren(); detail.hidden = false; activeElement = element;
        var close = document.createElement('button'); close.type = 'button'; close.textContent = '×'; close.setAttribute('aria-label', data.labels.close);
        close.onclick = function () { detail.hidden = true; if (activeElement) activeElement.focus(); };
        var wording = document.createElement('p'); wording.textContent = text;
        var origin = document.createElement('p'); var found = origins.get(key(text));
        origin.textContent = data.labels.origin + ': ' + (found ? found.origin : data.labels.unknown);
        var page = document.createElement('p'); page.textContent = data.labels.foundOn + ': ' + document.title;
        var link = document.createElement('a'); link.className = 'lt-check-open'; link.href = found ? found.url : direct(text); link.textContent = data.labels.translate;
        detail.append(close, wording, origin, page, link);
        var choices = entries.get(element) || [];
        if (choices.length > 1) {
          var select = document.createElement('select'); select.setAttribute('aria-label', data.labels.translate);
          choices.forEach(function (choice) { var option = document.createElement('option'); option.textContent = choice; option.value = choice; option.selected = choice === text; select.appendChild(option); });
          select.addEventListener('change', function () { show(select.value, element); }); detail.insertBefore(select, wording);
        }
        link.focus();
      }
      document.addEventListener('click', function (event) {
        var element = event.target.closest('.lt-untranslated');
        if (!element || detail.contains(event.target)) return;
        var texts = entries.get(element); if (!texts || !texts.length) return;
        event.preventDefault(); event.stopPropagation();
        show(texts[0], element);
      }, true);
      document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !detail.hidden) { detail.hidden = true; if (activeElement) activeElement.focus(); return; }
        var element = event.target.closest('.lt-untranslated');
        if (element && entries.has(element) && (event.key === 'Enter' || event.key === ' ')) { event.preventDefault(); show(entries.get(element)[0], element); }
      });
      async function identify(texts) {
        if (resolving || !data.ajax) return;
        texts = texts.filter(function (text) { return !origins.has(key(text)); }).slice(0, 80);
        if (!texts.length) return;
        resolving = true;
        try {
          var body = new FormData(); body.append('action', 'lt_detected_texts'); body.append('nonce', data.nonce);
          body.append('ref', data.ref); body.append('lang', data.language); body.append('texts', JSON.stringify(texts));
          var response = await fetch(data.ajax, { method: 'POST', credentials: 'same-origin', body: body });
          var result = await response.json();
          if (result.success) result.data.forEach(function (item) { origins.set(key(item.text), item); });
        } catch (e) { /* The direct editor still works if provenance could not be fetched. */ }
        finally { resolving = false; }
      }
      function run() {
        var foundTexts = compare(original, collect(document), skip);
        marked = Object.create(null); entries.clear();
        document.querySelectorAll('.lt-untranslated').forEach(function (el) { el.classList.remove('lt-untranslated'); });
        foundTexts.forEach(function (found) {
          found.els.forEach(function (el) {
            el.classList.add('lt-untranslated');
            if (!entries.has(el)) entries.set(el, []);
            entries.get(el).push(found.text);
            if (!el.matches('a,button,input,select,textarea,[tabindex]')) el.setAttribute('tabindex', '0');
          });
          marked[key(found.text)] = found.visible || marked[key(found.text)] || false;
        });
        identify(foundTexts.map(function (item) { return item.text; }));
        var total = Object.keys(marked).length;
        var hiddenCount = Object.keys(marked).filter(function (k) { return !marked[k]; }).length;
        bar.textContent = total ? data.labels.found.replace('%1$d', total).replace('%2$d', hiddenCount) : data.labels.none;
        var link = document.createElement('a'); link.href = data.report; link.textContent = data.labels.report; bar.appendChild(link);
      }
      run();
      // Texts written later (mini-cart, messages, popups, checkout blocks): checked too.
      var pending = null;
      new MutationObserver(function (mutations) {
        if (mutations.every(function (m) { return bar.contains(m.target) || detail.contains(m.target); })) return; // our own counter
        clearTimeout(pending);
        pending = setTimeout(run, 600);
      })
        .observe(document.body, { childList: true, subtree: true, characterData: true });
    }).catch(function () { bar.textContent = data.labels.error; });
  }
  if (document.readyState === 'complete') start(); else window.addEventListener('load', start);
}());
