<?php
/**
 * Integracje z wtyczkami, które same umieją czekać na zgodę — jeśli ktoś im powie, że mają.
 *
 * @package NertiCookies
 */

defined( 'ABSPATH' ) || exit;

/**
 * Blokada w buforze i w nagłówkach łapie to, co strona WYSYŁA do przeglądarki. Nie łapie
 * tego, co wtyczka wysyła z serwera wprost do dostawcy: Meta Pixel for WordPress wysyła
 * zdarzenia przez Conversions API (serwer → Meta, z adresem IP i przeglądarką gościa),
 * a tego nie widać w żadnej zakładce Sieć. Takie wtyczki trzeba zatrzymać ich własnym
 * hakiem — i do tego jest ta klasa.
 *
 * Mapowanie naszych trzech kategorii na pięć kategorii WP Consent API:
 *   functional            ← niezbędne (zawsze)
 *   statistics            ← analityczne
 *   statistics-anonymous  ← analityczne (anonimowa analityka nadal zapisuje identyfikator
 *                           na urządzeniu, więc też wymaga zgody — inventory.md §5)
 *   marketing             ← marketingowe
 *   preferences           ← marketingowe (nie mamy czwartej kategorii; surowsza z dwóch)
 */
class Nerti_Cookies_Integracje {

	const MAPA_WP_CONSENT = array(
		'functional'           => 'niezbedne',
		'statistics'           => 'analityczne',
		'statistics-anonymous' => 'analityczne',
		'marketing'            => 'marketingowe',
		'preferences'          => 'marketingowe',
	);

	public static function hooks() {
		/* ---------- WP Consent API ---------- */
		add_filter( 'wp_consent_api_registered_' . plugin_basename( NERTI_COOKIES_PLIK ), '__return_true' );
		add_filter( 'wp_get_consent_type', array( __CLASS__, 'typ_zgody' ), 20 );
		// Źródłem prawdy jest ciasteczko decyzji z wersją polityki, nie ciasteczka wp_consent_*
		// (te mają własną ważność i nie wiedzą o wersji polityki).
		add_filter( 'wp_has_consent', array( __CLASS__, 'ma_zgode' ), 20, 2 );

		/* ---------- Meta Pixel for WordPress ---------- */
		add_filter( 'facebook_signals_held', array( __CLASS__, 'meta_wstrzymaj' ), 20 );

		/*
		 * WooCommerce: celowo BEZ filtra `wc_order_attribution_allow_tracking`. Jego wynik
		 * ląduje w HTML-u (konfiguracja skryptu), więc zależny od ciasteczka gościa trafiłby
		 * do cache'a w stanie pierwszego odwiedzającego. Atrybucję zatrzymuje blokada uchwytów
		 * `wc-order-attribution` i `sourcebuster-js` (biblioteka usług) oraz WP Consent API.
		 */
	}

	/**
	 * @return string
	 */
	public static function typ_zgody() {
		return 'optin';
	}

	/**
	 * @param bool   $ma        Wynik WP Consent API.
	 * @param string $kategoria Kategoria WP Consent API.
	 * @return bool
	 */
	public static function ma_zgode( $ma, $kategoria ) {
		if ( ! isset( self::MAPA_WP_CONSENT[ $kategoria ] ) ) {
			return (bool) $ma;
		}
		return Nerti_Cookies_Naglowki::dozwolona( self::MAPA_WP_CONSENT[ $kategoria ] );
	}

	/**
	 * Meta wstrzymuje wtedy trzy rzeczy naraz: ciasteczka _fbp/_fbc z PHP, inicjalizację
	 * piksela w przeglądarce i wysyłkę Conversions API z serwera (zdarzenia trafiają do
	 * kolejki i przepadają z końcem żądania). Po zgodzie baner woła `FacebookSignal.release()`.
	 *
	 * @param bool $wstrzymane Stan z wtyczki Meta.
	 * @return bool
	 */
	public static function meta_wstrzymaj( $wstrzymane ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return (bool) $wstrzymane;
		}
		return (bool) $wstrzymane || ! Nerti_Cookies_Naglowki::dozwolona( 'marketingowe' );
	}

	/**
	 * Wykryte integracje — dla pulpitu i ability `status`.
	 *
	 * @return array<int,array{nazwa:string,aktywna:bool,jak:string}>
	 */
	public static function wykryte() {
		return array(
			array( 'nazwa' => 'WP Consent API', 'aktywna' => function_exists( 'wp_has_consent' ), 'jak' => 'Decyzja odwiedzającego trafia do wtyczek, które obsługują WP Consent API (np. Site Kit, WooCommerce).' ),
			array( 'nazwa' => 'Meta Pixel for WordPress', 'aktywna' => class_exists( '\\FacebookPixelPlugin\\Core\\FacebookSignalState' ), 'jak' => 'Bez zgody marketingowej wtyczka Meta wstrzymuje ciasteczka, piksel i wysyłkę z serwera.' ),
			array( 'nazwa' => 'Site Kit by Google', 'aktywna' => defined( 'GOOGLESITEKIT_VERSION' ), 'jak' => 'Tag Google blokowany do zgody, decyzja przez WP Consent API i Consent Mode.' ),
			array( 'nazwa' => 'WooCommerce', 'aktywna' => class_exists( 'WooCommerce' ), 'jak' => 'Koszyk działa zawsze; atrybucja zamówień (sbjs_*) dopiero po zgodzie marketingowej.' ),
		);
	}
}
