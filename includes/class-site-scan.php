<?php
defined( 'ABSPATH' ) || exit;

/**
 * "Scan the site": every public page (pages, products, categories, blog posts, My account) is opened in the
 * background, in the original language and in every translation, as the browser shows it. Every text a visitor
 * can read in a translation that is still the original text is listed, page by page, whatever wrote it (page
 * content, theme template, WooCommerce, a plugin, JavaScript…). Nothing to browse by hand.
 *
 * "Highlight untranslated texts" (admin bar, ?lt_check=1) outlines those texts in red on the page itself.
 * The comparison is done in the browser: assets/site-scan.js.
 */
class LT_Site_Scan {
	const RESULTS   = 'lt_site_scan';    // [ time, end, texts => [ id => text ], pages => [ ref => [ l => label, o => url, r => [ lang => [ u => url, t => visible ids, h => hidden ids, e => error ] ] ] ] ]
	const LOGIC_VERSION = 3;
	const IGNORED   = 'lt_scan_ignored'; // lowercase text => text: same in every language (brand, name…), not reported
	const BATCH     = 100;               // pages listed per AJAX call
	const MAX_TEXTS = 80;                // texts kept per page and language

	private static $instance;
	public static function instance() { return self::$instance ?: ( self::$instance = new self() ); }

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ), 10 );
		foreach ( array( 'list', 'save', 'ignore' ) as $action ) add_action( 'wp_ajax_lt_site_scan_' . $action, array( $this, 'ajax_' . $action ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'check_mode' ), 30 );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 86 );
	}

	public function menu() { add_submenu_page( 'lt-settings', __( 'Check the site', 'lightweight-translation' ), '🔴 ' . __( 'Check the site', 'lightweight-translation' ), lt_capability(), 'lt-scan', array( $this, 'page' ) ); }

	private static function languages() { $router = LT_Language_Router::instance(); return array_values( array_diff( $router->languages(), array( $router->default_language() ) ) ); }

	private static function ignored() { return (array) get_option( self::IGNORED, array() ); }

	/* ---------- What is scanned ---------- */

	private static function kinds() {
		$kinds = array( 'pages' => function_exists( 'WC' ) ? __( 'Pages (home, shop, cart, checkout, My account…)', 'lightweight-translation' ) : __( 'Pages', 'lightweight-translation' ) );
		if ( post_type_exists( 'product' ) ) $kinds['products'] = __( 'Products', 'lightweight-translation' );
		if ( taxonomy_exists( 'product_cat' ) ) $kinds['categories'] = __( 'Product categories & tags', 'lightweight-translation' );
		$kinds['posts'] = __( 'Blog posts', 'lightweight-translation' );
		return $kinds;
	}

	/** Every page of a kind, as references: "post:12", "term:7", "home", "account:orders". */
	private static function refs( $kind ) {
		$posts = function( $type ) { return array_map( function( $id ) { return 'post:' . $id; }, get_posts( array( 'post_type' => $type, 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids', 'orderby' => 'menu_order title', 'order' => 'ASC', 'suppress_filters' => true ) ) ); };
		switch ( $kind ) {
			case 'pages':
				$refs = 'page' === get_option( 'show_on_front' ) && get_option( 'page_on_front' ) ? array( 'post:' . (int) get_option( 'page_on_front' ) ) : array( 'home' );
				$refs = array_merge( $refs, $posts( 'page' ) );
				if ( function_exists( 'wc_get_account_menu_items' ) && function_exists( 'wc_get_account_endpoint_url' ) ) {
					foreach ( array_keys( (array) wc_get_account_menu_items() ) as $endpoint ) if ( ! in_array( $endpoint, array( 'dashboard', 'customer-logout' ), true ) ) $refs[] = 'account:' . $endpoint;
				}
				return array_values( array_unique( $refs ) );
			case 'products':
				return $posts( 'product' );
			case 'categories':
				$refs = array();
				foreach ( array( 'product_cat', 'product_tag' ) as $taxonomy ) {
					if ( ! taxonomy_exists( $taxonomy ) ) continue;
					$ids = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => true, 'fields' => 'ids' ) );
					if ( ! is_wp_error( $ids ) ) foreach ( $ids as $id ) $refs[] = 'term:' . (int) $id;
				}
				return $refs;
			case 'posts':
				return $posts( 'post' );
		}
		return array();
	}

	private static function count( $kind ) {
		switch ( $kind ) {
			case 'pages':      return (int) ( wp_count_posts( 'page' )->publish ?? 0 ) + ( 'page' === get_option( 'show_on_front' ) && get_option( 'page_on_front' ) ? 0 : 1 ) + ( function_exists( 'wc_get_account_menu_items' ) ? max( 0, count( (array) wc_get_account_menu_items() ) - 2 ) : 0 );
			case 'products':   return (int) ( wp_count_posts( 'product' )->publish ?? 0 );
			case 'posts':      return (int) ( wp_count_posts( 'post' )->publish ?? 0 );
			case 'categories':
				$n = 0;
				foreach ( array( 'product_cat', 'product_tag' ) as $taxonomy ) if ( taxonomy_exists( $taxonomy ) ) { $count = wp_count_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => true ) ); $n += is_wp_error( $count ) ? 0 : (int) $count; }
				return $n;
		}
		return 0;
	}

	/** One page: its name and its address in every language. */
	private static function item( $ref ) {
		$router = LT_Language_Router::instance();
		list( $type, $id ) = array_pad( explode( ':', $ref, 2 ), 2, '' );
		if ( 'post' === $type ) {
			$post = get_post( (int) $id );
			if ( ! $post || 'publish' !== $post->post_status ) return null;
			$label = get_the_title( $post );
			$link  = function() use ( $post ) { return get_permalink( $post ); };
		} elseif ( 'term' === $type ) {
			$term = get_term( (int) $id );
			if ( ! $term || is_wp_error( $term ) ) return null;
			$label = $term->name;
			$link  = function() use ( $term ) { $url = get_term_link( $term ); return is_wp_error( $url ) ? '' : $url; };
		} elseif ( 'home' === $type ) {
			$label = __( 'Home page', 'lightweight-translation' );
			$link  = function() use ( $router ) { return trailingslashit( home_url( $router->is_secondary() ? $router->current_language() : '' ) ); };
		} elseif ( 'account' === $type && function_exists( 'wc_get_account_endpoint_url' ) ) {
			$label = LT_Page_Texts::label( $ref );
			$link  = function() use ( $id ) { return wc_get_account_endpoint_url( $id ); };
		} else {
			return null;
		}
		$urls = array();
		foreach ( $router->languages() as $language ) $urls[ $language ] = (string) $router->with_language( $language, $link );
		$original = $urls[ $router->default_language() ] ?? '';
		if ( '' === $original ) return null;
		unset( $urls[ $router->default_language() ] );
		$label = html_entity_decode( wp_strip_all_tags( (string) $label ), ENT_QUOTES, 'UTF-8' );
		return array( 'key' => $ref, 'label' => '' !== $label ? $label : '#' . $id, 'original' => $original, 'urls' => $urls );
	}

	/* ---------- AJAX (the scan itself runs in the browser) ---------- */

	private static function check() {
		check_ajax_referer( 'lt_site_scan', 'nonce' );
		if ( ! current_user_can( lt_capability() ) ) wp_send_json_error( null, 403 );
	}

	private static function results() { return (array) get_option( self::RESULTS, array() ) + array( 'time' => 0, 'end' => 0, 'texts' => array(), 'pages' => array(), 'logic' => 0 ); }

	/** Pages to read, by batches. With "reset", the previous results are cleared; otherwise pages already read are skipped. */
	public function ajax_list() {
		self::check();
		$kind   = sanitize_key( wp_unslash( $_POST['kind'] ?? '' ) );
		$offset = absint( $_POST['offset'] ?? 0 );
		if ( ! isset( self::kinds()[ $kind ] ) ) wp_send_json_error();
		if ( ! empty( $_POST['reset'] ) ) update_option( self::RESULTS, array( 'time' => time(), 'end' => 0, 'texts' => array(), 'pages' => array(), 'logic' => self::LOGIC_VERSION ), false );
		$results = self::results();
		$refs    = self::refs( $kind );
		$items   = array();
		foreach ( array_slice( $refs, $offset, self::BATCH ) as $ref ) {
			if ( isset( $results['pages'][ $ref ] ) ) continue;
			$item = self::item( $ref );
			if ( $item ) $items[] = $item;
		}
		wp_send_json_success( array( 'items' => $items, 'next' => $offset + self::BATCH < count( $refs ) ? $offset + self::BATCH : 0 ) );
	}

	/** Texts found on one page: { lang: { u: url, t: [ [ text, visible ] ], e: 1 when the page could not be read } }. */
	public function ajax_save() {
		self::check();
		$ref   = sanitize_text_field( wp_unslash( $_POST['key'] ?? '' ) );
		$found = json_decode( wp_unslash( (string) ( $_POST['found'] ?? '' ) ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( '' === $ref || ! is_array( $found ) ) wp_send_json_error();
		$results   = self::results();
		$ids       = array_flip( $results['texts'] );
		$languages = self::languages();
		$page_item = self::item( $ref );
		if ( ! $page_item ) wp_send_json_error( null, 400 );
		$entry = array( 'l' => $page_item['label'], 'o' => $page_item['original'], 'r' => array() );
		foreach ( $found as $language => $data ) {
			if ( ! in_array( $language, $languages, true ) || ! is_array( $data ) ) continue;
			$row = array( 'u' => $page_item['urls'][ $language ] ?? '', 't' => array(), 'h' => array() );
			if ( ! empty( $data['e'] ) ) $row['e'] = 1;
			foreach ( array_slice( (array) ( $data['t'] ?? array() ), 0, self::MAX_TEXTS ) as $text ) {
				$string = mb_substr( sanitize_text_field( (string) ( $text[0] ?? '' ) ), 0, 300 );
				if ( '' === $string ) continue;
				if ( ! isset( $ids[ $string ] ) ) { $ids[ $string ] = count( $results['texts'] ); $results['texts'][] = $string; }
				$row[ empty( $text[1] ) ? 'h' : 't' ][] = $ids[ $string ];
			}
			$entry['r'][ $language ] = $row;
		}
		$results['pages'][ $ref ] = $entry;
		$results['end']           = time();
		update_option( self::RESULTS, $results, false );
		wp_send_json_success();
	}

	/** "Same in every language" (a brand, a name…): no longer reported. undo=1 reports it again. */
	public function ajax_ignore() {
		self::check();
		$text = sanitize_text_field( wp_unslash( $_POST['text'] ?? '' ) );
		if ( '' === $text ) wp_send_json_error();
		$ignored = self::ignored();
		if ( empty( $_POST['undo'] ) ) $ignored[ mb_strtolower( $text ) ] = $text;
		else unset( $ignored[ mb_strtolower( $text ) ] );
		update_option( self::IGNORED, $ignored, false );
		wp_send_json_success();
	}

	/* ---------- Page ---------- */

	public function page() {
		if ( ! current_user_can( lt_capability() ) ) return;
		$languages = self::languages();
		$results   = self::results();
		echo '<div class="wrap lt-scan"><h1>' . esc_html__( 'Check the site', 'lightweight-translation' ) . '</h1>';
		echo '<p class="lt-scan-intro">' . esc_html__( 'Compare each public page in the original language and your translations. See which visible texts still need translation, then open the right translation directly.', 'lightweight-translation' ) . '</p>';
		if ( ! $languages ) { echo '<div class="notice notice-info inline"><p>' . wp_kses_post( sprintf( __( 'Choose at least one translation language in <a href="%s">Settings</a> before checking the site.', 'lightweight-translation' ), esc_url( LT_Admin_Navigation::url( 'lt-preferences' ) . '#languages' ) ) ) . '</p></div></div>'; return; }
		$cart_url = LT_Page_Texts::sample_cart_url();
		if ( ! get_option( 'permalink_structure' ) ) echo '<div class="notice notice-warning inline"><p>' . wp_kses_post( sprintf( __( 'The check needs translated page addresses. Enable pretty permalinks in <a href="%s">WordPress permalink settings</a>.', 'lightweight-translation' ), esc_url( admin_url( 'options-permalink.php' ) ) ) ) . '</p></div>';

		$kinds = self::kinds();
		$details = array( 'pages' => __( 'Home, shop, cart, checkout and account pages', 'lightweight-translation' ), 'products' => __( 'Published products and their public product pages', 'lightweight-translation' ), 'categories' => __( 'Public product categories and tags', 'lightweight-translation' ), 'posts' => __( 'Published blog posts', 'lightweight-translation' ) );
		$stale = $results['pages'] && self::LOGIC_VERSION !== (int) ( $results['logic'] ?? 0 );
		$partial = $results['pages'] && ! empty( $results['time'] ) && ! $stale;
		if ( $stale ) echo '<div class="notice notice-info inline"><p>' . esc_html__( 'The site check rules have changed. Start a new check to refresh these results.', 'lightweight-translation' ) . '</p></div>';
		echo '<section class="lt-scan-card"><h2>' . esc_html__( 'Choose what to check', 'lightweight-translation' ) . '</h2><p class="description">' . esc_html__( 'Published pages are checked in the background. This does not edit your content. You can stop and continue later.', 'lightweight-translation' ) . '</p><div class="lt-scan-kinds">';
		foreach ( $kinds as $kind => $label ) {
			$count = self::count( $kind );
			echo '<label class="lt-scan-kind-card"><input type="checkbox" class="lt-scan-kind" value="' . esc_attr( $kind ) . '" data-count="' . esc_attr( $count ) . '"' . checked( $count > 0, true, false ) . disabled( 0, $count, false ) . '><span><strong>' . esc_html( $label ) . '</strong><small>' . esc_html( $details[ $kind ] ?? '' ) . '</small></span><span class="lt-scan-count">' . esc_html( number_format_i18n( $count ) ) . '</span></label>';
		}
		echo '</div>';
		if ( $cart_url ) echo '<label class="lt-scan-cart"><input type="checkbox" id="lt-scan-cart"> <span><strong>' . esc_html__( 'Test cart and checkout with a sample product', 'lightweight-translation' ) . '</strong><small>' . esc_html__( 'Adds one product to this browser’s shop cart so checkout texts can appear. Remove it from the cart after the check if you do not need it.', 'lightweight-translation' ) . '</small></span></label>';
		echo '<p class="lt-scan-estimate" id="lt-scan-estimate" aria-live="polite"></p><div class="lt-scan-actions">';
		if ( $partial ) echo '<button type="button" class="button button-primary lt-scan-start" data-reset="0">' . esc_html__( 'Continue check', 'lightweight-translation' ) . '</button> ';
		echo '<button type="button" class="button ' . ( $partial ? '' : 'button-primary ' ) . 'lt-scan-start" data-reset="1">' . esc_html( $partial ? __( 'Start a new check', 'lightweight-translation' ) : __( 'Start check', 'lightweight-translation' ) ) . '</button> ';
		if ( $partial ) echo '<span class="description">' . esc_html( sprintf( __( '%1$d pages already checked · last check %2$s ago', 'lightweight-translation' ), count( $results['pages'] ), human_time_diff( (int) $results['end'] ?: (int) $results['time'] ) ) ) . '</span>';
		echo '<button type="button" class="button" id="lt-scan-stop" style="display:none">' . esc_html__( 'Stop and save progress', 'lightweight-translation' ) . '</button></div>';
		echo '<div class="lt-progress" id="lt-scan-progress"><span></span></div><p id="lt-scan-status" aria-live="polite"></p></section>';
		echo '<iframe id="lt-scan-frame" title="" aria-hidden="true" style="position:fixed;left:-10000px;top:0;width:1280px;height:900px;border:0"></iframe>';

		if ( $results['pages'] && ! $stale ) {
			echo '<div class="lt-scan-results"><div class="lt-scan-results-head"><div><h2>' . esc_html__( 'Check results', 'lightweight-translation' ) . '</h2><p class="description">' . esc_html__( 'Search a page or a detected text. Choose “Translate this text” to fix it, or mark wording that is intentionally the same in every language.', 'lightweight-translation' ) . '</p></div><label class="screen-reader-text" for="lt-scan-filter">' . esc_html__( 'Filter results', 'lightweight-translation' ) . '</label><input type="search" id="lt-scan-filter" placeholder="' . esc_attr__( 'Filter pages and texts…', 'lightweight-translation' ) . '"></div><div id="lt-scan-report">';
			$this->report( $languages, $results );
			echo '</div></div>';
		}
		echo '</div>';

		wp_enqueue_script( 'lt-site-scan', plugins_url( 'assets/site-scan.js', LT_FILE ), array(), LT_VERSION, true );
		$config = array(
			'ajax'      => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'lt_site_scan' ),
			'languages' => $languages,
			'ignored'   => array_values( self::ignored() ),
			'cart'      => $cart_url,
			'labels'    => array(
				/* translators: %d: minutes */
				'estimate' => __( '%pages pages selected · about %min min', 'lightweight-translation' ),
				'listing'  => __( 'Listing the pages…', 'lightweight-translation' ),
				'cart'     => __( 'Putting a product in the cart (so the cart and checkout show their texts)…', 'lightweight-translation' ),
				'reading'  => __( 'Reading', 'lightweight-translation' ),
				'done'     => __( 'Done. Showing the result…', 'lightweight-translation' ),
				'stopped'  => __( 'Stopped. “Continue” starts again where it stopped.', 'lightweight-translation' ),
				'nothing'  => __( 'Everything selected is already checked. Start a new check to replace these results.', 'lightweight-translation' ),
				'noSelection' => __( 'Select at least one type of page to check.', 'lightweight-translation' ),
				'error'    => __( 'The check stopped because of an error. Reload this page and choose “Continue check”.', 'lightweight-translation' ),
			),
		);
		wp_add_inline_script( 'lt-site-scan', 'window.ltScanConfig=' . wp_json_encode( $config ) . ';' . self::script() );
	}

	/** The scan: list the pages, open each one in the original language then in every translation, compare, save. */
	private static function script() {
		return <<<'JS'
(function (cfg) {
  var starts = document.querySelectorAll('.lt-scan-start'), stopButton = document.getElementById('lt-scan-stop');
  var status = document.getElementById('lt-scan-status'), progress = document.getElementById('lt-scan-progress'), frame = document.getElementById('lt-scan-frame');
  var stopped = false, skip = {}, WORKERS = 4, WAIT = 2000, QUIET = 400;
  cfg.ignored.forEach(function (text) { skip[ltScan.key(text)] = true; });
  function kinds() { return Array.prototype.filter.call(document.querySelectorAll('.lt-scan-kind'), function (box) { return box.checked; }); }
  function estimate() {
    var pages = kinds().reduce(function (n, box) { return n + Number(box.getAttribute('data-count')); }, 0);
    document.getElementById('lt-scan-estimate').textContent = pages ? cfg.labels.estimate.replace('%pages', pages.toLocaleString()).replace('%min', Math.max(1, Math.round(pages * (cfg.languages.length + 1) * 1.5 / WORKERS / 60))) : cfg.labels.noSelection;
  }
  document.querySelectorAll('.lt-scan-kind').forEach(function (box) { box.addEventListener('change', estimate); });
  estimate();
  var filter = document.getElementById('lt-scan-filter');
  if (filter) filter.addEventListener('input', function () {
    var query = filter.value.trim().toLocaleLowerCase();
    document.querySelectorAll('#lt-scan-report details').forEach(function (section) {
      section.hidden = !!query && section.textContent.toLocaleLowerCase().indexOf(query) === -1;
    });
  });
  function post(action, data) {
    var body = new FormData();
    body.append('action', 'lt_site_scan_' + action);
    body.append('nonce', cfg.nonce);
    Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
    return fetch(cfg.ajax, { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (r) { if (!r || !r.success) throw new Error('ajax'); return r.data; });
  }
  // Several pages are read at the same time, each in its own hidden frame; results are saved one by one.
  var frames = [frame], saving = Promise.resolve();
  function frameFor(n) {
    while (frames.length <= n) { var copy = frame.cloneNode(false); copy.removeAttribute('id'); frame.parentNode.appendChild(copy); frames.push(copy); }
    return frames[n];
  }
  async function read(view, url) {
    var doc;
    try { doc = await ltScan.load(view, url, WAIT, QUIET); }
    catch (e) { doc = await ltScan.load(view, url, WAIT, QUIET); } // a slow server under load: one more try
    return ltScan.collect(doc);
  }
  function save(data) { saving = saving.then(function () { return post('save', data); }); return saving; }
  async function scan(item, view) {
    var found = {}, original = null;
    try { original = await read(view, item.original); } catch (e) { original = null; }
    for (var i = 0; i < cfg.languages.length; i++) {
      var language = cfg.languages[i], url = item.urls[language];
      if (!url) continue;
      if (!original) { found[language] = { u: url, e: 1 }; continue; }
      try {
        var texts = ltScan.compare(original, await read(view, url), skip);
        found[language] = { u: url, t: texts.map(function (t) { return [t.text, t.visible ? 1 : 0]; }) };
      } catch (e) { found[language] = { u: url, e: 1 }; }
    }
    await save({ key: item.key, label: item.label, original: item.original, found: JSON.stringify(found) });
  }
  async function run(reset) {
    stopped = false;
    starts.forEach(function (b) { b.disabled = true; });
    stopButton.style.display = '';
    progress.style.display = 'block';
    var bar = progress.firstChild;
    try {
      if (!kinds().length) { status.textContent = cfg.labels.noSelection; return; }
      status.textContent = cfg.labels.listing;
      var items = [], selected = kinds();
      for (var k = 0; k < selected.length; k++) {
        var offset = 0;
        do {
          var page = await post('list', { kind: selected[k].value, offset: offset, reset: reset && !k && !offset ? 1 : 0 });
          items = items.concat(page.items);
          offset = page.next;
        } while (offset);
      }
      if (!items.length) { status.textContent = cfg.labels.nothing; return; }
      var testCart = document.getElementById('lt-scan-cart');
      if (cfg.cart && testCart && testCart.checked) { status.textContent = cfg.labels.cart; try { await ltScan.load(frame, cfg.cart, 500); } catch (e) {} }
      var next = 0, finished = 0;
      async function worker(n) {
        var view = frameFor(n);
        while (!stopped && next < items.length) {
          var item = items[next++];
          status.textContent = cfg.labels.reading + ' ' + Math.min(finished + 1, items.length) + ' / ' + items.length + ' · ' + item.label;
          await scan(item, view);
          finished++;
          bar.style.width = Math.round(finished * 100 / items.length) + '%';
        }
      }
      var workers = [];
      for (var w = 0; w < Math.min(WORKERS, items.length); w++) workers.push(worker(w));
      await Promise.all(workers);
      await saving;
      if (stopped) { status.textContent = cfg.labels.stopped; location.reload(); return; }
      bar.style.width = '100%';
      status.textContent = cfg.labels.done;
      location.reload();
    } catch (e) {
      status.textContent = cfg.labels.error;
    } finally {
      starts.forEach(function (b) { b.disabled = false; });
      stopButton.style.display = 'none';
    }
  }
  starts.forEach(function (b) { b.addEventListener('click', function () { run(b.getAttribute('data-reset') === '1'); }); });
  stopButton.addEventListener('click', function () { stopped = true; stopButton.disabled = true; });
  // "Same in every language": no longer reported.
  document.addEventListener('click', function (event) {
    var button = event.target.closest('.lt-ignore');
    if (!button) return;
    var undo = button.getAttribute('data-undo') === '1';
    button.disabled = true;
    post('ignore', { text: button.getAttribute('data-text'), undo: undo ? 1 : 0 }).then(function () {
      document.querySelectorAll('.lt-ignore').forEach(function (other) {
        if (other.getAttribute('data-text') === button.getAttribute('data-text')) other.closest('li').classList.toggle('is-ignored', !undo);
      });
    }).catch(function () { button.disabled = false; });
  });
}(window.ltScanConfig));
JS;
	}

	/** Result: per language, the texts shown almost everywhere (header, footer, menus) once, then each page. */
	private function report( $languages, $results ) {
		if ( ! $results['pages'] ) return;
		$ignored = self::ignored();
		$texts   = $results['texts'];
		$keep    = function( $ids ) use ( $texts, $ignored ) { return array_values( array_unique( array_filter( (array) $ids, function( $id ) use ( $texts, $ignored ) { return isset( $texts[ $id ] ) && ! isset( $ignored[ mb_strtolower( $texts[ $id ] ) ] ); } ) ) ); };
		LT_Detected_Text::prime( $texts );
		$item = function( $id, $hidden, $ref, $language ) use ( $texts, $results ) {
			$text = $texts[ $id ];
			$origin = LT_Detected_Text::resolve( $text, $ref );
			echo '<li>' . esc_html( $text );
			if ( $hidden ) echo ' <span class="lt-muted">' . esc_html__( '(hidden until opened: menu, tab, accordion or popup)', 'lightweight-translation' ) . '</span>';
			echo '<span class="lt-detected-origin"><strong>' . esc_html__( 'Content type', 'lightweight-translation' ) . ':</strong> ' . esc_html( $origin['origin'] ) . ' <span aria-hidden="true">·</span> <strong>' . esc_html__( 'Found on', 'lightweight-translation' ) . ':</strong> ' . esc_html( $results['pages'][ $ref ]['l'] ?? $ref ) . '</span>';
			$target = 1 === count( $origin['candidates'] ) ? $origin['candidates'][0]['key'] : '';
			echo '<a class="button button-small" href="' . esc_url( LT_Detected_Text::url( $text, $ref, $language, $target ) ) . '">' . esc_html__( 'Translate this text', 'lightweight-translation' ) . '</a>';
			echo '<button type="button" class="button-link lt-ignore" data-text="' . esc_attr( $text ) . '">' . esc_html__( 'Same in every language', 'lightweight-translation' ) . '</button></li>';
		};
		$check = function( $url ) { return add_query_arg( 'lt_check', 1, $url ); };

		/* translators: 1: number of pages, 2: time ago */
		echo '<h2>' . esc_html( sprintf( __( 'Result: %1$d pages read, %2$s ago', 'lightweight-translation' ), count( $results['pages'] ), human_time_diff( (int) ( $results['end'] ?: $results['time'] ) ) ) ) . '</h2>';
		foreach ( $languages as $language ) {
			$pages  = array(); // ref => [ visible ids, hidden ids ]
			$seen   = array(); // id => number of pages
			$shown  = array(); // id => visible on at least one page
			$errors = array();
			foreach ( $results['pages'] as $ref => $page ) {
				$row = $page['r'][ $language ] ?? null;
				if ( ! $row ) continue;
				if ( ! empty( $row['e'] ) ) { $errors[ $ref ] = $page; continue; }
				$visible = $keep( $row['t'] ?? array() );
				$hidden  = array_values( array_diff( $keep( $row['h'] ?? array() ), $visible ) );
				$pages[ $ref ] = array( $visible, $hidden );
				foreach ( $visible as $id ) $shown[ $id ] = true;
				foreach ( array_merge( $visible, $hidden ) as $id ) $seen[ $id ] = ( $seen[ $id ] ?? 0 ) + 1;
			}
			// Texts on most pages (header, footer, menus, mini-cart): listed once.
			$limit  = max( 3, (int) ceil( count( $pages ) * 0.5 ) );
			$common = count( $pages ) >= 4 ? array_keys( array_filter( $seen, function( $n ) use ( $limit ) { return $n >= $limit; } ) ) : array();
			$todo   = array();
			foreach ( $pages as $ref => $ids ) {
				$visible = array_values( array_diff( $ids[0], $common ) );
				$hidden  = array_values( array_diff( $ids[1], $common ) );
				if ( $visible || $hidden ) $todo[ $ref ] = array( $visible, $hidden );
			}
			uasort( $todo, function( $a, $b ) { return array( count( $b[0] ), count( $b[1] ) ) <=> array( count( $a[0] ), count( $a[1] ) ); } );

			/* translators: 1: flag and language, 2: pages with texts to translate, 3: pages fully translated */
			echo '<h3>' . esc_html( sprintf( __( '%1$s: %2$d pages to review, %3$d without identical texts', 'lightweight-translation' ), LT_Language_Router::flag( $language ) . ' ' . strtoupper( $language ), count( $todo ), count( $pages ) - count( $todo ) ) ) . '</h3>';
			if ( $common ) {
				$common_visible = array_values( array_filter( $common, function( $id ) use ( $shown ) { return isset( $shown[ $id ] ); } ) );
				echo '<details open><summary><strong>' . esc_html__( 'On almost every page (header, footer, menus, mini-cart)', 'lightweight-translation' ) . '</strong>';
				if ( $common_visible ) echo '<span class="lt-badge">' . esc_html( sprintf( _n( '%d visible', '%d visible', count( $common_visible ), 'lightweight-translation' ), count( $common_visible ) ) ) . '</span>';
				if ( count( $common ) > count( $common_visible ) ) echo '<span class="lt-badge hidden">' . esc_html( sprintf( __( '%d hidden', 'lightweight-translation' ), count( $common ) - count( $common_visible ) ) ) . '</span>';
				echo '</summary><ul>';
				foreach ( $common as $id ) { $ref = ''; foreach ( $pages as $page_ref => $page_ids ) if ( in_array( $id, array_merge( $page_ids[0], $page_ids[1] ), true ) ) { $ref = $page_ref; break; } $item( $id, ! isset( $shown[ $id ] ), $ref, $language ); }
				echo '</ul></details>';
			}
			foreach ( $todo as $ref => $ids ) {
				$page = $results['pages'][ $ref ];
				$url  = $page['r'][ $language ]['u'] ?? '';
				echo '<details><summary><strong>' . esc_html( $page['l'] ) . '</strong>';
				if ( $ids[0] ) echo '<span class="lt-badge">' . esc_html( sprintf( _n( '%d visible', '%d visible', count( $ids[0] ), 'lightweight-translation' ), count( $ids[0] ) ) ) . '</span>';
				if ( $ids[1] ) echo '<span class="lt-badge hidden">' . esc_html( sprintf( __( '%d hidden', 'lightweight-translation' ), count( $ids[1] ) ) ) . '</span>';
				if ( $url ) echo '<a href="' . esc_url( $check( $url ) ) . '" target="_blank" class="lt-muted">' . esc_html__( 'Show on the page', 'lightweight-translation' ) . ' ↗</a>';
				echo '</summary><ul>';
				foreach ( $ids[0] as $id ) $item( $id, false, $ref, $language );
				foreach ( $ids[1] as $id ) $item( $id, true, $ref, $language );
				echo '</ul></details>';
			}
			if ( ! $todo && ! $common && $pages ) echo '<p class="lt-ok">✓ ' . esc_html__( 'No identical text was detected on the pages checked.', 'lightweight-translation' ) . '</p>';
			if ( $errors ) {
				echo '<details><summary><strong>' . esc_html( sprintf( _n( '%d page could not be read', '%d pages could not be read', count( $errors ), 'lightweight-translation' ), count( $errors ) ) ) . '</strong></summary><ul>';
				foreach ( $errors as $page ) echo '<li><a href="' . esc_url( $page['o'] ) . '" target="_blank">' . esc_html( $page['l'] ) . ' ↗</a></li>';
				echo '</ul><p class="lt-muted" style="padding:0 14px 10px">' . esc_html__( 'The page took too long, or the site forbids showing its pages in a frame (security plugin).', 'lightweight-translation' ) . '</p></details>';
			}
		}
		if ( $ignored ) {
			echo '<details><summary><strong>' . esc_html( sprintf( __( 'Same in every language (%d texts not reported)', 'lightweight-translation' ), count( $ignored ) ) ) . '</strong></summary><ul>';
			foreach ( $ignored as $text ) echo '<li class="is-ignored-list">' . esc_html( $text ) . '<button type="button" class="button-link lt-ignore" data-undo="1" data-text="' . esc_attr( $text ) . '">' . esc_html__( 'Report it again', 'lightweight-translation' ) . '</button></li>';
			echo '</ul></details>';
		}
	}

	/* ---------- On the site: "Highlight untranslated texts" ---------- */

	public static function current_ref() {
		$object = get_queried_object();
		if ( $object instanceof WP_Post ) return 'post:' . $object->ID;
		if ( $object instanceof WP_Term ) return 'term:' . $object->term_id;
		return LT_Page_Texts::page_type();
	}

	public function check_mode() {
		if ( empty( $_GET['lt_check'] ) || ! is_user_logged_in() || ! current_user_can( lt_capability() ) ) return; // phpcs:ignore WordPress.Security.NonceVerification
		$router = LT_Language_Router::instance();
		if ( ! $router->is_secondary() ) return;
		wp_enqueue_script( 'lt-site-scan', plugins_url( 'assets/site-scan.js', LT_FILE ), array(), LT_VERSION, true );
		$switched = function_exists( 'switch_to_locale' ) && switch_to_locale( get_user_locale() );
		wp_localize_script( 'lt-site-scan', 'ltCheck', array(
			'original' => LT_Language_Router::url( $router->default_language() ),
			'ajax' => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'lt_detected_texts' ),
			'language' => $router->current_language(),
			'ref' => self::current_ref(),
			'translate' => LT_Admin_Navigation::url( 'lt-translate' ),
			'ignored'  => array_values( self::ignored() ),
			'report'   => admin_url( 'admin.php?page=lt-scan' ),
			'labels'   => array(
				'reading' => __( 'Comparing with the original page…', 'lightweight-translation' ),
				/* translators: 1: number of texts, 2: how many are hidden (menus, popups) */
				'found'   => __( '%1$d texts to review on this page, outlined in red (%2$d hidden in menus, tabs or popups).', 'lightweight-translation' ),
				'none'    => __( 'No identical text detected on this page.', 'lightweight-translation' ),
				'tooltip' => __( 'Click to review and translate', 'lightweight-translation' ),
				'origin' => __( 'Content type', 'lightweight-translation' ),
				'foundOn' => __( 'Found on', 'lightweight-translation' ),
				'unknown' => __( 'Content type not identified', 'lightweight-translation' ),
				'translate' => __( 'Translate this text', 'lightweight-translation' ),
				'close' => __( 'Close', 'lightweight-translation' ),
				'report'  => __( 'Scan the whole site', 'lightweight-translation' ),
				'error'   => __( 'The original page could not be read.', 'lightweight-translation' ),
			),
		) );
		if ( $switched ) restore_previous_locale();
	}

	public function admin_bar( $bar ) {
		if ( is_admin() || ! is_user_logged_in() || ! current_user_can( lt_capability() ) || ! $bar->get_node( 'lt-translate' ) ) return;
		$router = LT_Language_Router::instance();
		if ( $router->is_secondary() ) $url = add_query_arg( 'lt_check', 1 );
		else {
			$languages = self::languages();
			if ( ! $languages ) return;
			$url = add_query_arg( 'lt_check', 1, LT_Language_Router::url( $languages[0] ) );
		}
		$bar->add_node( array( 'parent' => 'lt-translate', 'id' => 'lt-translate-check', 'title' => esc_html( '🔴 ' . __( 'Highlight untranslated texts', 'lightweight-translation' ) ), 'href' => $url ) );
		$bar->add_node( array( 'parent' => 'lt-translate', 'id' => 'lt-translate-scan', 'title' => esc_html__( 'Scan the whole site…', 'lightweight-translation' ), 'href' => admin_url( 'admin.php?page=lt-scan' ) ) );
	}
}
