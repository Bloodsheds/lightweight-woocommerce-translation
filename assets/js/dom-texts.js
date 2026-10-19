/** Manual visible-text fallback. Configuration, image/ARIA metadata and customer input are never changed. */
(function () {
  'use strict';
  var SKIP = 'script,style,noscript,template,svg,code,pre,#wpadminbar,#lt-check-bar,#lt-check-detail,.wd-logo,.site-logo,.custom-logo-link,.screen-reader-text,.sr-only,.visually-hidden,.lt-language-switcher-wrap,.lt-language-switcher-list,.lt-menu-switcher-item,.lt-language-switcher-mobile,[data-lt-lang]';
  var NAMES = ['placeholder', 'title', 'value'];
  function allowed(el) { return el && !el.closest(SKIP); }
  // want(value): optional quick test run before the (costlier) position checks, so that only texts that can be
  // replaced are checked against the skipped areas. Without it every text is returned (collect()).
  function attributes(el, callback, want) {
    if (want && !NAMES.some(function (name) { return el.hasAttribute(name) && want(el.getAttribute(name)); })) return;
    if (!allowed(el)) return;
    if (el.matches('input[placeholder],textarea[placeholder]')) callback(el, 'placeholder');
    if (el.matches('a[title],button[title],input[title],abbr[title],.wd-tooltip[title],.has-tooltip[title]')) callback(el, 'title');
    if (el.matches('input[type=submit][value],input[type=button][value],input[type=reset][value]')) callback(el, 'value');
  }
  var CODE = { SCRIPT: 1, STYLE: 1, NOSCRIPT: 1, TEMPLATE: 1 };
  function textNode(node, text, want) {
    if (want && ((node.parentElement && CODE[node.parentElement.tagName]) || !want(node.data))) return; // inline scripts (large JSON) are never page text
    if (allowed(node.parentElement) && !node.parentElement.closest('textarea')) text(node);
  }
  function walk(root, text, attribute, want) {
    if (root.nodeType === 3) { textNode(root, text, want); return; }
    if (root.nodeType !== 1 || !allowed(root)) return;
    var it = document.createTreeWalker(root, NodeFilter.SHOW_TEXT), node;
    while ((node = it.nextNode())) textNode(node, text, want);
    attributes(root, attribute, want);
    root.querySelectorAll('[placeholder],[title],input[value]').forEach(function (el) { attributes(el, attribute, want); });
  }
  window.ltDOMTexts = {
    collect: function () {
      var out = [];
      if (document.body) walk(document.body, function (node) { out.push([node.data, node.parentElement, '']); }, function (el, name) { out.push([el.getAttribute(name), el, name]); });
      return out;
    },
    replace: function (map) {
      // A translation may equal another source. Do not retranslate our own observer notifications.
      var texts = new WeakMap(), attrs = new WeakMap(), has = Object.prototype.hasOwnProperty;
      function key(value) { return String(value || '').replace(/\s+/g, ' ').trim(); }
      function want(value) { var k = key(value); return k !== '' && has.call(map, k); }
      function replacement(value) { var k = key(value); return k && has.call(map, k) ? value.replace(value.trim(), function () { return map[k]; }) : value; }
      function text(node) {
        if (texts.get(node) === node.data) return;
        var out = replacement(node.data); if (out !== node.data) { texts.set(node, out); node.data = out; }
      }
      function attribute(el, name) {
        var previous = attrs.get(el) || {}, value = el.getAttribute(name);
        if (previous[name] === value) return;
        var out = replacement(value); if (out !== value) { previous[name] = out; attrs.set(el, previous); el.setAttribute(name, out); }
      }
      function start() {
        walk(document.body, text, attribute, want);
        new MutationObserver(function (records) {
          records.forEach(function (record) {
            if (record.type === 'characterData') textNode(record.target, text, want);
            else if (record.type === 'attributes') attributes(record.target, attribute, want);
            else record.addedNodes.forEach(function (node) { walk(node, text, attribute, want); });
          });
        }).observe(document.body, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: NAMES });
      }
      if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
    }
  };
}());
