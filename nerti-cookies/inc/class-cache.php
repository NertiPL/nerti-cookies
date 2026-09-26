<?php
/**
 * Zgodność z cache'em stron i optymalizatorami JavaScriptu.
 *
 * @package NertiCookies
 */

defined( 'ABSPATH' ) || exit;

/**
 * Dwie zasady, na których stoi zgodność z cache'em — i obie są w innych klasach:
 *  1. HTML nie zależy od gościa. Baner jest drukowany zawsze, Consent Mode startuje od
 *     `denied`, decyzję czyta JavaScript z ciasteczka (Nerti_Cookies_Baner, _Blokada).
 *  2. Nagłówki `Set-Cookie` bez zgody znikają, zanim cache zdąży je zapisać (Nerti_Cookies_Naglowki).
 *
 * Ta klasa pilnuje trzeciej rzeczy: optymalizatory JS nie mogą ruszać skryptów wtyczki.
 * „Opóźnij JavaScript" (WP Rocket, LiteSpeed, Perfmatters) wstrzymuje skrypty do pierwszego
 * ruchu myszy — baner, który czeka na ruch myszy, nie pokazuje się komuś, kto tylko czyta.
 * „Połącz pliki JS" potrafi przenieść skrypt startowy za tag Google, a wtedy domyślne
 * `denied` przychodzi po pierwszym zdarzeniu. Zablokowane skrypty (`type="text/plain"`)
 * też są wyłączone: optymalizator, który je „naprawi", odblokuje je przed zgodą.
 *
 * Mechanizmy są dwa, bo żaden nie wystarcza sam: atrybuty na znacznikach (czytane w czasie
 * przetwarzania HTML-u) i filtry wykluczeń (czytane przy konfiguracji). Atrybuty dokłada
 * Nerti_Cookies_Blokada::nie_optymalizuj(); tu są filtry i znaczniki banera.
 */
class Nerti_Cookies_Cache {

	/** Fragmenty, po których optymalizatory rozpoznają nasze skrypty. */
	const WZORCE = array( 'nerti-cookies', 'nc-bootstrap', 'ncKonfig', 'ncStan', 'data-nc-cat' );

	public static function hooks() {
		add_filter( 'script_loader_tag', array( __CLASS__, 'znacznik_banera' ), 20, 2 );
		add_filter( 'wp_inline_script_attributes', array( __CLASS__, 'atrybuty_inline' ), 20, 2 );

		// WP Rocket.
		foreach ( array( 'rocket_delay_js_exclusions', 'rocket_exclude_js', 'rocket_exclude_defer_js', 'rocket_excluded_inline_js_content' ) as $f ) {
			add_filter( $f, array( __CLASS__, 'dopisz_do_listy' ) );
		}
		// LiteSpeed Cache.
		foreach ( array( 'litespeed_optimize_js_excludes', 'litespeed_optm_js_defer_exc' ) as $f ) {
			add_filter( $f, array( __CLASS__, 'dopisz_do_listy' ) );
		}
		// SiteGround Optimizer.
		foreach ( array( 'sgo_javascript_combine_exclude', 'sgo_js_minify_exclude', 'sgo_js_async_exclude' ) as $f ) {
			add_filter( $f, array( __CLASS__, 'dopisz_do_listy' ) );
		}
		// Autoptimize trzyma listę jako tekst rozdzielony przecinkami.
		add_filter( 'autoptimize_filter_js_exclude', array( __CLASS__, 'dopisz_do_tekstu' ) );
	}

	/**
	 * @param mixed $lista Lista wykluczeń.
	 * @return mixed
	 */
	public static function dopisz_do_listy( $lista ) {
		if ( ! is_array( $lista ) ) {
			return $lista;
		}
		return array_values( array_unique( array_merge( $lista, self::WZORCE, array( 'nerti-cookies/assets/baner.js' ) ) ) );
	}

	/**
	 * @param mixed $tekst Lista wykluczeń jako tekst.
	 * @return mixed
	 */
	public static function dopisz_do_tekstu( $tekst ) {
		if ( ! is_string( $tekst ) ) {
			return $tekst;
		}
		return trim( $tekst . ', ' . implode( ', ', self::WZORCE ), ', ' );
	}

	/**
	 * Skrypt banera: bez opóźniania i łączenia.
	 *
	 * @param string $tag    Znacznik.
	 * @param string $uchwyt Uchwyt.
	 * @return string
	 */
	public static function znacznik_banera( $tag, $uchwyt ) {
		if ( 'nerti-cookies' !== $uchwyt ) {
			return $tag;
		}
		$p = new WP_HTML_Tag_Processor( $tag );
		while ( $p->next_tag( 'SCRIPT' ) ) {
			Nerti_Cookies_Blokada::nie_optymalizuj( $p );
		}
		return $p->get_updated_html();
	}

	/**
	 * Konfiguracja banera (`ncKonfig`, drukowana przez wp_localize_script) — te same atrybuty.
	 *
	 * @param array  $atrybuty Atrybuty.
	 * @param string $js       Treść.
	 * @return array
	 */
	public static function atrybuty_inline( $atrybuty, $js = '' ) {
		$id = isset( $atrybuty['id'] ) ? (string) $atrybuty['id'] : '';
		if ( 0 === strpos( $id, 'nerti-cookies-js' ) || false !== strpos( (string) $js, 'ncKonfig' ) ) {
			$atrybuty['data-no-optimize'] = '1';
			$atrybuty['data-no-defer']    = '1';
			$atrybuty['data-noptimize']   = '1';
			$atrybuty['data-cfasync']     = 'false';
			$atrybuty['nowprocket']       = true;
		}
		return $atrybuty;
	}

	/**
	 * Wykryte wtyczki cache'u i optymalizacji — dla pulpitu.
	 *
	 * @return array<int,string>
	 */
	public static function wykryte() {
		$znane = array(
			'LiteSpeed Cache'     => defined( 'LSCWP_V' ),
			'WP Rocket'           => defined( 'WP_ROCKET_VERSION' ),
			'Autoptimize'         => defined( 'AUTOPTIMIZE_PLUGIN_VERSION' ),
			'SiteGround Optimizer' => defined( '\\SiteGround_Optimizer\\VERSION' ) || class_exists( '\\SiteGround_Optimizer\\Loader\\Loader' ),
			'Cache Enabler'       => class_exists( 'Cache_Enabler' ),
			'W3 Total Cache'      => defined( 'W3TC' ),
			'WP Super Cache'      => function_exists( 'wp_cache_phase2' ),
			'Perfmatters'         => defined( 'PERFMATTERS_VERSION' ),
		);
		return array_keys( array_filter( $znane ) );
	}
}
