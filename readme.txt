=== Lightweight Translation ===
Requires at least: 6.2
Requires PHP: 7.4
Stable tag: 1.3.0

Simple translations for WordPress, with optional WooCommerce, WoodMart and Product Bundles integrations.

== How it works ==
One original WordPress object is kept per page, product or term. Translations are stored in a dedicated table and selected with a language URL prefix. No remote translation service is called when a customer visits a translated page.

== Setup ==
1. Install the ZIP in Plugins > Add New, or upload lightweight-translation to wp-content/plugins.
2. Open Translations > Settings and choose the original and secondary languages.
3. Open Translations > Translate. One tab per source: Pages & posts, Menus, WooCommerce, Theme, Plugins, Other content. WooCommerce and the theme each have one left column with their content (products, categories, attributes, layouts) and their texts (buttons and messages, settings, widgets, e-mails). Gutenberg includes a translation panel; the classic editor includes translation fields.
4. WooCommerce's standard catalog is available without a first scan. Its "Buttons & messages" section can be narrowed to products, cart, checkout, account, orders, customer e-mails, JavaScript/blocks or country lists. Texts that a plugin reuses from WooCommerce or the theme are translated once, in WooCommerce or the theme.
5. Check the site to compare selected public pages with their translations. Choose page types, review a resumable report, and open any result's translation directly. Matching wording alone is not proof that a translation is missing.
6. Import / Export supports manual CSV translation and a prompt to use with an external AI. It does not automatically contact an AI provider.
7. Add [lt_language_switcher] in a block or widget.

== Full backup, reset and uninstall ==
Settings > Maintenance can download a full JSON backup containing translation rows, plugin settings and scan data, language preferences, and language selectors created by the plugin. Restore the JSON file from the same screen; content rows whose WordPress objects no longer exist are skipped.
To clear plugin data, open Settings > Maintenance > Danger zone, download the full backup, then confirm the reset. This clears the plugin's translation table, settings, scan data, user language preferences and its menu selector items, then deactivates the plugin. Pages, products, terms and other menu items remain. The backup must be kept outside the site to restore later.
Deleting the plugin from the WordPress Plugins screen also removes its owned data. Export a full backup before deleting if you may want to restore it.

The administration follows the WordPress user's language; French translations are included.

== Upgrade ==
Existing translation keys, source content and saved translations are retained. The WooCommerce catalog adds missing entries without overwriting translations or review status. Existing ignore flags are retained. As with any plugin update, keep a database backup and verify the update on a staging copy first.

== Optional integrations ==
WooCommerce loads product, attribute, shop setting and e-mail support only when WooCommerce is active.
WoodMart loads its adapter only for WoodMart or WoodMart Core; Gutenberg HTML blocks, shared layouts and additional product/category fields are supported.
Product Bundles loads when the extension is active; custom component titles and descriptions are stored under the parent product.
Other public WordPress content and gettext domains work through the generic core. Extensions that bypass these APIs may need an adapter.

== Limits ==
The bundled WooCommerce catalog is based on the supplied 11.1.0 archive. It lists possible wording, including conditional errors and customer e-mails; it does not prove a string is used on your site. A scan and actual shop visits complete the catalog.
Customer-side JavaScript fallbacks and translated search remain areas to measure on your own hosting. Translation progress is counted once per content type, then updated item by item when a translation or an original is saved. Functional tests are not a production load benchmark.
