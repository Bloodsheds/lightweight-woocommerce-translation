(function () {
  'use strict';
  function closeAll(except) {
    document.querySelectorAll('.lt-language-switcher-wrap.is-open, .lt-menu-switcher-item.wd-opened').forEach(function (wrap) {
      if (wrap === except) return;
      wrap.classList.remove('is-open', 'wd-opened');
      var button = wrap.querySelector('.lt-language-switcher-trigger');
      var menu = wrap.querySelector('.lt-language-switcher-menu');
      if (button) button.setAttribute('aria-expanded', 'false');
      if (menu) menu.setAttribute('aria-hidden', 'true');
    });
  }
  document.addEventListener('click', function (event) {
    // The language shown in a theme menu is the active one: it opens the list, it never reloads the page.
    var active = event.target.closest('.lt-language-switcher-trigger--theme');
    if (active) {
      event.preventDefault();
      var parent = active.closest('.lt-menu-switcher-item');
      if (parent) parent.classList.toggle('wd-opened');
      return;
    }
    var button = event.target.closest('.lt-language-switcher-trigger:not(.lt-language-switcher-trigger--theme)');
    // Arrow added by the theme next to the item (phone menus): same as tapping the language.
    if (!button) {
      var arrowItem = event.target.closest('.lt-menu-switcher-item');
      if (arrowItem && !arrowItem.classList.contains('lt-menu-switcher--theme') && !event.target.closest('a') && arrowItem.querySelector(':scope > .lt-language-switcher-menu')) button = arrowItem.querySelector(':scope > .lt-language-switcher-trigger');
    }
    if (button) {
      var wrap = button.closest('.lt-language-switcher-wrap, .lt-menu-switcher-item');
      var menu = wrap.querySelector('.lt-language-switcher-menu');
      var open = !wrap.classList.contains('is-open');
      closeAll(wrap);
      if (button.tagName === 'A') event.preventDefault();
      wrap.classList.toggle('is-open', open);
      wrap.classList.toggle('wd-opened', open);
      button.setAttribute('aria-expanded', open ? 'true' : 'false');
      menu.setAttribute('aria-hidden', open ? 'false' : 'true');
      return;
    }
    if (!event.target.closest('.lt-language-switcher-wrap, .lt-menu-switcher-item')) closeAll();
  });
  // Phone menus. The theme opens sub-menus with its own arrow (accordion, sliding "drill-down" panel…): let it
  // do its job, and only open the list ourselves when the theme does nothing at all.
  function toggleList(item, list, open) {
    item.classList.toggle('lt-mobile-open', open);
    list.style.display = open ? 'block' : 'none';
  }
  document.addEventListener('click', function (event) {
    var item = event.target.closest('.lt-menu-switcher-item');
    if (!item || event.target.closest('.lt-language-switcher-option')) return;
    var list = item.querySelector(':scope > .lt-language-switcher-mobile');
    if (!list) return;
    var opener = item.querySelector(':scope > .wd-nav-opener, :scope > [class*="opener"], :scope > [class*="toggle"], :scope > [class*="arrow"]');
    if (event.target.closest('.lt-language-switcher-mobile-trigger')) {
      // Tapping the current language = tapping the theme's arrow (never treated as a page link: no page loader,
      // no closed menu).
      event.preventDefault();
      event.stopPropagation();
      if (opener) { opener.click(); return; }
      toggleList(item, list, list.offsetHeight === 0);
      return;
    }
    if (item.classList.contains('lt-mobile-open')) { toggleList(item, list, false); return; } // we opened it: we close it
    // Watch whether the theme reacts to its arrow; if nothing changes, open the list ourselves.
    var reacted = false;
    var watcher = new MutationObserver(function () { reacted = true; });
    watcher.observe(item.closest('ul, nav, div') || item, { attributes: true, subtree: true, attributeFilter: ['class', 'style'] });
    setTimeout(function () {
      watcher.disconnect();
      if (!reacted && list.offsetHeight === 0) toggleList(item, list, true);
    }, 400);
  }, true);
  // Sliding ("drill-down") phone menus show a sub-menu as a new panel: it needs the theme's "Back" line.
  function addBackLines() {
    document.querySelectorAll('[class*="drilldown"] .lt-menu-switcher-item > .lt-language-switcher-mobile').forEach(function (list) {
      if (list.querySelector(':scope > .wd-drilldown-back')) return;
      var model = document.querySelector('.wd-drilldown-back a');
      var back = document.createElement('li');
      back.className = 'wd-drilldown-back';
      back.innerHTML = '<span class="wd-nav-opener"></span><a href="#"></a>';
      back.querySelector('a').textContent = model ? model.textContent.trim() : '←';
      list.insertBefore(back, list.firstChild);
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', addBackLines); else addBackLines();
  // The visitor's choice (a[data-lt-lang] clicks) is remembered by the head script of every page (redirect_script).
  document.addEventListener('keydown', function (event) { if (event.key === 'Escape') closeAll(); });
}());
