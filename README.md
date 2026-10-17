<p align="center">
  <img src="docs/lightweight-translation-logo.svg" alt="Lightweight Translation logo" width="128">
</p>

<h1 align="center">Lightweight Translation 🌍</h1>
<p align="center"><strong>Translate freely. Stay lightweight. Pay nothing.</strong><br>
Make your WordPress site multilingual, without making it feel complicated. ✨</p>

I built Lightweight Translation because I wanted a simpler way to translate my own WordPress site. I wanted to choose my languages, keep control of my content, and stop wrestling with a complicated workflow just to make a site multilingual. So I started building the plugin I wished I had—and decided to share it. ❤️

The idea is simple: keep your original pages, products and other content in WordPress, and manage their translations alongside them. Visitors see the language they choose at an address such as `/fr/`. You can translate texts yourself or use the tools you already trust. No translation service is required just to display a page.

Lightweight Translation is still growing. It is designed around a small WordPress core and optional integrations, so you can translate your site without needing every integration on every installation. If you find a bug or have an idea, your feedback can help make it better. 🛠️

## ✨ What you can do

- 🌐 Add the languages your site needs, with language-specific URLs.
- 📝 Translate pages, posts, menus, products, categories, theme and plugin texts, and other content from one organized place.
- 🔎 Check public pages for visible text that still needs translation, then jump from a result to its translation.
- 📦 Move translations in bulk with CSV files. Translate them yourself or with an external AI tool, then import them back.
- 💾 Download and restore a full JSON backup of the plugin's translation data and settings.
- 🎨 Add a language selector to a WordPress menu, a block, or a footer list.
- 🧩 Use optional support for WooCommerce, WoodMart and WooCommerce Product Bundles when those integrations are available.

## 🚀 Get started

### Requirements

- WordPress 6.2 or later
- PHP 7.4 or later
- WooCommerce is optional. It is needed only for shop-specific features.
- Current plugin version: 0.46.0

### Install

1. Download a plugin ZIP from **Releases** when one is available, or package the `lightweight-translation` plugin folder as a ZIP.
2. In WordPress, go to **Plugins → Add New → Upload Plugin**, select the ZIP and activate it.
3. Open **Translations → Settings** and choose the original language and the languages you want to offer.
4. Open **Translations → Translate** and start with the source you want to work on.

## 🧭 Find your way around

- **Overview** shows translation progress and links to the next step.
- **Translate** organizes work by source: **Pages & posts**, **Menus**, **WooCommerce**, **Theme**, **Plugins** and **Other content**. WooCommerce appears when it is active. WooCommerce and Theme have their own left-hand content lists, so you can go straight to products, shop texts, settings, widgets and other relevant content.
- **Check the site** compares public pages in the languages you choose. It reports visible text that remains untranslated and links to the corresponding translation.
- **Import / Export** handles bulk CSV translation and full JSON backups. The plugin provides an AI-ready prompt for CSV work, but it does not connect to an AI provider or require an API key. You choose whether to use an external tool and where to send your file.
- **Settings** manages languages, the language selector and maintenance tools.

The administration interface follows the WordPress user's language. French is included. 🇫🇷

## 🛒 WooCommerce and theme support

When WooCommerce is active, its translation tools organize shop content and interface wording by useful areas such as products, cart, checkout, account, orders, e-mails, settings and categories. A starter catalog of common WooCommerce texts is included, so you can begin before running a scan. The scan can add wording specific to your store and its active extensions.

WoodMart support is enabled when the WoodMart theme is active. It covers relevant theme content such as shared layouts and Gutenberg HTML blocks. Product Bundles support is loaded when WooCommerce Product Bundles is active; its component titles and descriptions are translated with the parent product.

The WordPress translation core remains usable without these optional integrations. Other plugins can use the general text discovery and translation tools; extensions that bypass WordPress's usual content or translation APIs may need additional compatibility work.

## 🌍 Add a language selector

You can add the selector from **Appearance → Menus** or insert its shortcode in a block, widget or supported theme area:

```text
[lt_language_switcher]
```

For a plain dropdown:

```text
[lt_language_switcher style="plain"]
```

For a bulleted list, for example in a footer:

```text
[lt_language_switcher style="list"]
```

When added as a WordPress menu item, the selector uses the theme's normal menu styling and behavior where possible.

## ⚡ How it works

The plugin keeps one original WordPress page, product or term. Translations are stored separately in a dedicated database table and displayed according to the language in the URL. This avoids creating a second WordPress page or product for each translation. Page views do not call an external AI or translation API; bulk AI translation is an optional file-based workflow that you control.

The **Check the site** tool is for reviewing content available on public pages. It compares what a visitor can see across languages; administrative screens are not translation targets.

## 🧹 Your content stays yours

Translations can be exported as a full JSON backup before you reset or remove the plugin. The uninstall routine deletes data owned by Lightweight Translation, while your WordPress pages, products, terms and other site content remain. Keep your backup somewhere outside the site if you may need to restore it later.

## 🤝 Help improve it

This project is a work in progress, and compatibility can vary between WordPress sites, themes and extensions. If you run into a problem, please include your WordPress and PHP versions, the active theme and relevant plugins, and the steps to reproduce it when opening a GitHub issue. Bug fixes, compatibility reports, ideas and pull requests are all welcome.

**Less hassle. More languages. More freedom. 🌍**

*Made out of frustration. Shared with everyone. Built to stay lightweight.*
