# lightweight-woocommerce-translation

= Lightweight Translation for WooCommerce =

This MVP keeps one WordPress object per product, page, post and term. Translations live in the `wp_light_translations` table and are selected by a language URL prefix.

== Setup ==
1. Upload the `lightweight-translation` folder to `wp-content/plugins/`.
2. Activate it and visit WooCommerce > Light translations.
3. Set the default language and comma-separated secondary languages.
4. Edit products/pages/posts and complete the Translations box.
5. Add `[lt_language_switcher]` to a menu block or widget.

After changing URL settings, visit Settings > Permalinks once to refresh rewrite rules.
