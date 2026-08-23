<?php
/**
 * Blokowanie skryptów PRZED zgodą.
 *
 * To jest sedno wtyczki. Większość banerów na rynku odpala skrypty od razu i "wyłącza" je
 * po odmowie — czyli po fakcie, po ustawieniu ciasteczka i po wysłaniu pierwszego żądania
 * do serwera analitycznego. Naruszenie jest już wtedy popełnione i widać je w zakładce
 * Sieć w devtools, mimo że baner zachowuje się poprawnie.
 *
 * Mechanizm: skrypt niedozwolonej kategorii dostaje `type="text/plain"`, przez co
 * przeglądarka go NIE wykonuje, a jego adres wędruje z `src` do `data-nc-src`, przez co
 * NIE zostaje pobrany. Po zgodzie baner podmienia znacznik na wykonywalny.
 *
 * @package NertiCookies
 */

defined( 'ABSPATH' ) || exit;

class Nerti_Cookies_Blokada {

	public static function hooks() {
		add_action( 'wp_head', array( __CLASS__, 'glowa' ), 1 );
		add_filter( 'script_loader_tag', array( __CLASS__, 'filtr_uchwytu' ), 10, 3 );
		add_action( 'template_redirect', array( __CLASS__, 'start_bufora' ), 1 );
	}

	/**
	 * Czy w tym żądaniu w ogóle blokujemy.
	 *
	 * @return bool
	 */
	public static function czy_dziala() {
		if ( is_admin() || wp_doing_ajax() || is_feed() ) {
			return false;
		}
		if ( self::czy_edytor() ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return false;
		}
		if ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) {
			return false;
		}
		return true;
	}

	/**
	 * Podgląd w edytorze wizualnym to nie jest wizyta użytkownika.
	 *
	 * Baner jest przyklejony do okna, więc w edytorze zasłania róg płótna i wchodzi
	 * w każdy zrzut kontrolny — a zgody i tak nie da się tam sensownie podjąć.
	 * Rozpoznajemy budowniczych po ich własnych parametrach żądania; lista jest
	 * jawna, bo cicha heurystyka („jest zalogowany, więc pewnie edytuje") wyłączałaby
	 * baner także wtedy, gdy właściciel po prostu ogląda swoją stronę.
	 *
	 * @return bool
	 */
	public static function czy_edytor() {
		$znaczniki = array(
			'breakdance',          // Oxygen 6 / Breakdance — panel budowniczego
			'breakdance_iframe',   // Oxygen 6 / Breakdance — ramka z podglądem
			'elementor-preview',
			'brizy-edit',
			'brizy-edit-iframe',
			'et_fb',               // Divi
			'fl_builder',          // Beaver Builder
			'bricks',              // Bricks
		);
		foreach ( $znaczniki as $z ) {
			if ( isset( $_GET[ $z ] ) && '' !== $_GET[ $z ] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return true;
			}
		}
		if ( 'breakdance_server_side_render' === filter_input( INPUT_POST, 'action' ) ) {
			return true;
		}
		return (bool) apply_filters( 'nerti_cookies_czy_edytor', false );
	}

	/**
	 * Consent Mode v2 i znacznik stanu — drukowane jako pierwsza rzecz w `<head>`.
	 *
	 * Domyślne `denied` musi paść ZANIM załaduje się kontener Google. Ustawione później
	 * jest tylko deklaracją: kontener zdąży wysłać pierwsze zdarzenie z domyślnymi
	 * ustawieniami Google, czyli ze zgodą.
	 */
	public static function glowa() {
		if ( ! self::czy_dziala() ) {
			return;
		}
		$u = Nerti_Cookies_Ustawienia::pobierz();

		echo "<script id=\"nc-bootstrap\">\n";
		echo "window.ncStan=(function(){try{var m=document.cookie.match(/(?:^|; )" . esc_js( NERTI_COOKIES_CIASTECZKO ) . "=([^;]*)/);";
		echo "return m?JSON.parse(decodeURIComponent(m[1])):null;}catch(e){return null;}})();\n";
		echo "document.documentElement.setAttribute('data-nc',window.ncStan?'decyzja':'pytanie');\n";

		if ( ! empty( $u['consent_mode'] ) ) {
			$zgoda = self::zgoda_z_ciasteczka();
			$an    = ( isset( $zgoda['analityczne'] ) && $zgoda['analityczne'] ) ? 'granted' : 'denied';
			$mk    = ( isset( $zgoda['marketingowe'] ) && $zgoda['marketingowe'] ) ? 'granted' : 'denied';
			echo "window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}\n";
			echo "gtag('consent','default',{'ad_storage':'" . esc_js( $mk ) . "','ad_user_data':'" . esc_js( $mk ) . "','ad_personalization':'" . esc_js( $mk ) . "','analytics_storage':'" . esc_js( $an ) . "','functionality_storage':'granted','security_storage':'granted','wait_for_update':500});\n";
		}
		echo "</script>\n";
	}

	/**
	 * Odczyt decyzji po stronie serwera. Używany do Consent Mode i do tego, żeby nie
	 * blokować skryptów komuś, kto już się zgodził — bo wtedy strona z cache'a
	 * pokazywałaby mu zablokowane skrypty do czasu, aż zadziała JavaScript.
	 *
	 * @return array Mapa kategoria => bool.
	 */
	public static function zgoda_z_ciasteczka() {
		$puste = array();
		if ( empty( $_COOKIE[ NERTI_COOKIES_CIASTECZKO ] ) ) {
			return $puste;
		}
		$surowe = wp_unslash( $_COOKIE[ NERTI_COOKIES_CIASTECZKO ] );
		$dane   = json_decode( (string) $surowe, true );
		if ( ! is_array( $dane ) || ! isset( $dane['kat'] ) || ! is_array( $dane['kat'] ) ) {
			return $puste;
		}
		$u = Nerti_Cookies_Ustawienia::pobierz();
		if ( ! isset( $dane['w'] ) || (string) $dane['w'] !== (string) $u['wersja_polityki'] ) {
			// Zgoda na starą wersję polityki nie jest zgodą na obecną.
			return $puste;
		}
		$wynik = array();
		foreach ( $dane['kat'] as $id => $stan ) {
			$wynik[ sanitize_key( $id ) ] = (bool) $stan;
		}
		return $wynik;
	}

	/**
	 * Czy kategoria jest już dozwolona w tym żądaniu.
	 *
	 * @param string $kategoria Identyfikator kategorii.
	 * @return bool
	 */
	private static function dozwolona( $kategoria ) {
		$u = Nerti_Cookies_Ustawienia::pobierz();
		if ( isset( $u['kategorie'][ $kategoria ]['wymagana'] ) && $u['kategorie'][ $kategoria ]['wymagana'] ) {
			return true;
		}
		$zgoda = self::zgoda_z_ciasteczka();
		return ! empty( $zgoda[ $kategoria ] );
	}

	/**
	 * Warstwa 1 — skrypty dodane przez `wp_enqueue_script`.
	 *
	 * @param string $tag    Pełny znacznik.
	 * @param string $uchwyt Uchwyt skryptu.
	 * @param string $src    Adres.
	 * @return string
	 */
	public static function filtr_uchwytu( $tag, $uchwyt, $src ) {
		if ( ! self::czy_dziala() ) {
			return $tag;
		}
		$u   = Nerti_Cookies_Ustawienia::pobierz();
		$kat = '';

		if ( isset( $u['uchwyty'][ $uchwyt ] ) ) {
			$kat = $u['uchwyty'][ $uchwyt ];
		} else {
			$kat = self::kategoria_dla_tekstu( (string) $src, $u['wzorce'] );
		}

		if ( '' === $kat || self::dozwolona( $kat ) ) {
			return $tag;
		}
		return self::zablokuj_znacznik( $tag, $kat );
	}

	/**
	 * Warstwa 2 — bufor wyjścia. Łapie to, co warstwa 1 przepuszcza: piksele wklejone
	 * w `header.php`, w widget HTML, w pole "kod w nagłówku" motywu albo przez wtyczkę,
	 * która drukuje znacznik bezpośrednio.
	 *
	 * W polskich instalacjach to jest większość pikseli, więc bez tej warstwy blokada
	 * wygląda na działającą i nie działa.
	 */
	public static function start_bufora() {
		if ( ! self::czy_dziala() ) {
			return;
		}
		$u = Nerti_Cookies_Ustawienia::pobierz();
		if ( empty( $u['bufor_wyjscia'] ) ) {
			return;
		}
		ob_start( array( __CLASS__, 'przetworz_bufor' ) );
	}

	/**
	 * @param string $html Cała odpowiedź HTML.
	 * @return string
	 */
	public static function przetworz_bufor( $html ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}
		if ( false === stripos( $html, '</body>' ) ) {
			// Nie wygląda na dokument HTML — nie dotykamy.
			return $html;
		}

		$u         = Nerti_Cookies_Ustawienia::pobierz();
		$oryginal  = $html;

		$html = preg_replace_callback(
			'#<script\b([^>]*)>(.*?)</script>#is',
			function ( $m ) use ( $u ) {
				$atrybuty = $m[1];
				$tresc    = $m[2];

				// Własnych skryptów nie ruszamy — inaczej baner zablokowałby sam siebie.
				if ( false !== stripos( $atrybuty, 'nc-bootstrap' ) || false !== stripos( $atrybuty, 'nerti-cookies' ) ) {
					return $m[0];
				}
				// Już zablokowany przez warstwę 1.
				if ( false !== stripos( $atrybuty, 'data-nc-cat' ) ) {
					return $m[0];
				}
				// Dane strukturalne i szablony to nie są skrypty wykonywalne.
				if ( preg_match( '#type\s*=\s*["\'](application/ld\+json|text/template|text/html)["\']#i', $atrybuty ) ) {
					return $m[0];
				}

				$kat = self::kategoria_dla_tekstu( $atrybuty . ' ' . $tresc, $u['wzorce'] );
				if ( '' === $kat || self::dozwolona( $kat ) ) {
					return $m[0];
				}
				return self::zablokuj_znacznik( $m[0], $kat );
			},
			$html
		);

		/*
		 * `preg_replace_callback` zwraca null przy przekroczeniu limitu nawrotów — na długiej
		 * stronie to jest realne. Cicha zamiana całej odpowiedzi na null wyświetliłaby klientowi
		 * pustą stronę, więc przy błędzie wracamy do wersji sprzed przetwarzania.
		 */
		if ( ! is_string( $html ) ) {
			return $oryginal;
		}
		$po_skryptach = $html;

		$osadzenia_cb = function ( $m ) use ( $u ) {
			$atrybuty = isset( $m[1] ) ? $m[1] : '';
			$kat      = self::kategoria_dla_tekstu( $atrybuty, $u['osadzenia'] );
			if ( '' === $kat || self::dozwolona( $kat ) ) {
				return $m[0];
			}
			return self::zastepnik_osadzenia( $m[0], $atrybuty, $kat, $u['teksty'] );
		};

		$html = preg_replace_callback( '#<iframe\b([^>]*)>.*?</iframe>#is', $osadzenia_cb, $html );
		if ( ! is_string( $html ) ) {
			return $po_skryptach;
		}

		$html = preg_replace_callback( '#<iframe\b([^>]*?)\s*/>#i', $osadzenia_cb, $html );
		if ( ! is_string( $html ) ) {
			return $po_skryptach;
		}

		return $html;
	}

	/**
	 * @param string $tekst   Tekst do przeszukania (atrybuty, treść skryptu).
	 * @param array  $wzorce  Mapa fragment => kategoria.
	 * @return string Identyfikator kategorii albo pusty string.
	 */
	private static function kategoria_dla_tekstu( $tekst, $wzorce ) {
		if ( ! is_array( $wzorce ) || '' === $tekst ) {
			return '';
		}
		foreach ( $wzorce as $fragment => $kategoria ) {
			if ( '' !== $fragment && false !== stripos( $tekst, (string) $fragment ) ) {
				return (string) $kategoria;
			}
		}
		return '';
	}

	/**
	 * Zamienia wykonywalny znacznik skryptu na nieaktywny.
	 *
	 * `type="text/plain"` zatrzymuje wykonanie, przeniesienie `src` do `data-nc-src`
	 * zatrzymuje pobranie. Same `type` bez przeniesienia `src` NIE wystarcza w części
	 * przeglądarek przy `async`/`defer` — a to jest dokładnie ten defekt, którego
	 * nie widać po kliknięciu „Akceptuję".
	 *
	 * @param string $tag       Znacznik.
	 * @param string $kategoria Kategoria.
	 * @return string
	 */
	private static function zablokuj_znacznik( $tag, $kategoria ) {
		$tag = preg_replace( '#\stype\s*=\s*(["\'])[^"\']*\1#i', '', $tag );
		$tag = preg_replace( '#\ssrc\s*=\s*(["\'])([^"\']*)\1#i', ' data-nc-src=$1$2$1', $tag, 1 );
		$tag = preg_replace(
			'#<script\b#i',
			'<script type="text/plain" data-nc-cat="' . esc_attr( $kategoria ) . '"',
			$tag,
			1
		);
		return $tag;
	}

	/**
	 * Zastępnik osadzenia: prostokąt z wyjaśnieniem i przyciskiem.
	 *
	 * Kliknięcie ładuje TEN materiał, nie włącza całej kategorii — użytkownik zgadza się
	 * na to, co widzi, a nie na wszystko, co wtyczka podpięła pod tę samą etykietę.
	 *
	 * @param string $oryginal  Cały oryginalny znacznik — zwracany, gdy nie umiemy go rozebrać.
	 * @param string $atrybuty  Atrybuty oryginalnego iframe'a.
	 * @param string $kategoria Kategoria.
	 * @param array  $teksty    Teksty interfejsu.
	 * @return string
	 */
	private static function zastepnik_osadzenia( $oryginal, $atrybuty, $kategoria, $teksty ) {
		$src   = '';
		$tytul = '';
		if ( preg_match( '#\ssrc\s*=\s*(["\'])([^"\']*)\1#i', $atrybuty, $m ) ) {
			$src = $m[2];
		}
		if ( preg_match( '#\stitle\s*=\s*(["\'])([^"\']*)\1#i', $atrybuty, $m ) ) {
			$tytul = $m[2];
		}
		if ( '' === $src ) {
			// Osadzenie z adresem w `data-src` (leniwe ładowanie). Nie ruszamy go zastępnikiem,
			// bo skasowalibyśmy element; zamiast tego zostawiamy i odnotowujemy w inwentarzu.
			return $oryginal;
		}

		return '<div class="nc-osadzenie" data-nc-cat="' . esc_attr( $kategoria ) . '"'
			. ' data-nc-src="' . esc_attr( $src ) . '"'
			. ' data-nc-atrybuty="' . esc_attr( $atrybuty ) . '">'
			. '<p class="nc-osadzenie__opis">' . esc_html( $teksty['osadzenie'] ) . '</p>'
			. '<button type="button" class="nc-btn nc-btn--rowny nc-osadzenie__btn">'
			. esc_html( $teksty['osadzenie_btn'] ) . ( '' !== $tytul ? ' — ' . esc_html( $tytul ) : '' )
			. '</button></div>';
	}
}
