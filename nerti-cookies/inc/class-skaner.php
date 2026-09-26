<?php
/**
 * Skaner strony — co ta witryna naprawdę ładuje i ustawia.
 *
 * @package NertiCookies
 */

defined( 'ABSPATH' ) || exit;

/**
 * Dwie połowy, bo żadna nie widzi całości (inventory.md §1):
 *
 *  1. SERWER pobiera podstrony tak, jak pobiera je nowy gość: bez ciasteczek, przez cache.
 *     Widzi znaczniki (czy są zablokowane), osadzenia, piksele i nagłówki Set-Cookie.
 *     Nie widzi ciasteczek, które skrypty ustawiają dopiero w przeglądarce.
 *  2. PRZEGLĄDARKA administratora otwiera te same podstrony w ukrytej ramce, z pełną zgodą
 *     tylko w tej ramce, i odsyła: nazwy ciasteczek, klucze localStorage/sessionStorage i obce
 *     hosty. Tryb skanu włącza się wyłącznie w ramce osadzonej na ekranie skanera w panelu —
 *     odwiedzający nie ma jak go uruchomić (baner.js sprawdza rodzica tej samej domeny).
 *
 * Wynik trafia do jednej opcji. Czyta ją panel (tabela), pulpit (liczby), lista ciasteczek
 * do polityki (shortcode) i ability `skanuj-strone`.
 *
 * Statusy pozycji — jedno słowo, które klient ma zrozumieć:
 *   blokowane  — czeka na zgodę (tak ma być dla analityki i marketingu),
 *   zawsze     — niezbędne, działa bez zgody (tak ma być dla zapisu decyzji, koszyka, Turnstile),
 *   BLAD       — narzędzie śledzące działa przed zgodą (bloker Q-COOKIE),
 *   nieznane   — obca domena albo ciasteczko, którego biblioteka nie zna (do rozstrzygnięcia).
 */
class Nerti_Cookies_Skaner {

	const OPCJA = 'nerti_cookies_skan';

	public static function hooks() {
		add_action( 'rest_api_init', array( __CLASS__, 'trasy' ) );
	}

	public static function trasy() {
		register_rest_route(
			'nerti-cookies/v1',
			'/skan-przegladarka',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_przegladarka' ),
				'permission_callback' => static function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'adres'      => array( 'type' => 'string', 'required' => true ),
					'ciasteczka' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'storage'    => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
					'hosty'      => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
				),
			)
		);
	}

	/**
	 * Które podstrony skanujemy. Strona główna, dokumenty, kilka stron i wpisów, sklep.
	 * Każda podstrona to jedno żądanie, więc lista jest krótka: skaner ma skończyć,
	 * zanim klient pomyśli, że się zawiesił.
	 *
	 * @return array<int,string>
	 */
	public static function adresy() {
		$u      = Nerti_Cookies_Ustawienia::pobierz();
		$adresy = array( home_url( '/' ) );
		foreach ( array( 'link_polityka', 'link_cookies' ) as $pole ) {
			if ( ! empty( $u[ $pole ] ) ) {
				$adresy[] = (string) $u[ $pole ];
			}
		}
		$strony = get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'numberposts' => 8, 'orderby' => 'menu_order date', 'order' => 'ASC', 'fields' => 'ids' ) );
		$wpisy  = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 2, 'fields' => 'ids' ) );
		foreach ( array_merge( $strony, $wpisy ) as $id ) {
			$adresy[] = (string) get_permalink( $id );
		}
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$adresy[] = (string) wc_get_page_permalink( 'shop' );
		}
		$adresy = array_values( array_unique( array_filter( $adresy ) ) );
		$adresy = (array) apply_filters( 'nerti_cookies_skan_adresy', array_slice( $adresy, 0, 12 ) );
		// Tylko własna domena: skaner nie ma chodzić po cudzych stronach.
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		return array_values( array_filter( $adresy, static function ( $a ) use ( $host ) { return wp_parse_url( $a, PHP_URL_HOST ) === $host; } ) );
	}

	/**
	 * Część serwerowa.
	 *
	 * Bez `$dopisz` zaczyna od zera (wynik z przeglądarki zostaje, jeśli jest świeży).
	 * Z `$dopisz` dokłada podane podstrony do bieżącego wyniku — tak skanuje panel: podstrona
	 * po podstronie, żeby klient widział postęp, a nie minutę ciszy.
	 *
	 * @param array $adresy Opcjonalna lista adresów.
	 * @param bool  $dopisz Dopisać do świeżego wyniku zamiast zaczynać od nowa.
	 * @return array Wynik skanu.
	 */
	public static function skanuj( array $adresy = array(), $dopisz = false ) {
		$adresy    = $adresy ? $adresy : self::adresy();
		$u         = Nerti_Cookies_Ustawienia::pobierz();
		$poprzedni = self::wynik();
		$swiezy    = ! empty( $poprzedni['czas'] ) && time() - (int) $poprzedni['czas'] < HOUR_IN_SECONDS;
		if ( $dopisz && $swiezy ) {
			$wynik           = $poprzedni;
			$wynik['adresy'] = array_values( array_unique( array_merge( (array) $wynik['adresy'], $adresy ) ) );
		} else {
			$wynik = array(
				'czas'         => time(),
				'wersja'       => NERTI_COOKIES_WERSJA,
				'adresy'       => $adresy,
				'strony'       => array(),
				'pozycje'      => array(),
				'przegladarka' => array(),
			);
		}

		foreach ( $adresy as $adres ) {
			$odp = wp_remote_get(
				$adres,
				array(
					'timeout'     => 15,
					'redirection' => 3,
					'sslverify'   => false,
					'user-agent'  => 'Mozilla/5.0 (NertiCookies skaner)',
					'headers'     => array( 'Accept' => 'text/html,application/xhtml+xml' ),
				)
			);
			if ( is_wp_error( $odp ) ) {
				$wynik['strony'][ $adres ] = array( 'kod' => 0, 'blad' => $odp->get_error_message() );
				continue;
			}
			$html  = (string) wp_remote_retrieve_body( $odp );
			$strona = array(
				'kod'       => (int) wp_remote_retrieve_response_code( $odp ),
				'baner'     => false !== strpos( $html, 'id="nc-baner"' ),
				'start'     => false !== strpos( $html, 'id="nc-bootstrap"' ),
				'wycofanie' => false !== strpos( $html, 'data-nc-akcja="otworz"' ),
				'cache'     => (string) ( wp_remote_retrieve_header( $odp, 'x-cache-handler' ) ?: wp_remote_retrieve_header( $odp, 'x-litespeed-cache' ) ?: wp_remote_retrieve_header( $odp, 'x-cache' ) ),
			);
			$wynik['strony'][ $adres ] = $strona;

			self::czytaj_html( $html, $adres, $wynik['pozycje'] );

			$naglowki = wp_remote_retrieve_header( $odp, 'set-cookie' );
			foreach ( (array) $naglowki as $linia ) {
				$nazwa = trim( (string) strtok( (string) $linia, '=' ) );
				if ( '' !== $nazwa ) {
					self::dodaj_ciasteczko( $wynik['pozycje'], $nazwa, $adres, 'serwer' );
				}
			}
		}

		// Ciasteczka, które serwer próbował ustawić, a blokada nagłówków je usunęła.
		foreach ( Nerti_Cookies_Naglowki::slad() as $nazwa => $s ) {
			self::dodaj( $wynik['pozycje'], 'ciasteczko', (string) $nazwa, (string) $s['usluga'], (string) $s['kategoria'], 'blokowane', (string) $s['adres'], 'usuwane z nagłówka serwera do czasu zgody' );
		}

		// Wynik z przeglądarki zostaje, jeśli jest świeży (ta sama sesja skanowania z panelu).
		if ( ! ( $dopisz && $swiezy ) && ! empty( $poprzedni['przegladarka'] ) && $swiezy ) {
			foreach ( $poprzedni['przegladarka'] as $adres => $dane ) {
				$wynik['przegladarka'][ $adres ] = $dane;
				self::pozycje_z_przegladarki( $wynik['pozycje'], $adres, $dane );
			}
		}

		unset( $u );
		update_option( self::OPCJA, $wynik, false );
		return $wynik;
	}

	/**
	 * @param string $html   Odpowiedź.
	 * @param string $adres  Adres strony.
	 * @param array  $pozycje Pozycje (przez referencję).
	 */
	private static function czytaj_html( $html, $adres, array &$pozycje ) {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$p    = new WP_HTML_Tag_Processor( $html );
		while ( $p->next_tag() ) {
			$tag = $p->get_tag();
			$zab = (string) $p->get_attribute( 'data-nc-cat' );
			$id  = (string) $p->get_attribute( 'id' );
			if ( in_array( $id, array( 'nc-bootstrap', 'nerti-cookies-js', 'nerti-cookies-js-extra' ), true ) ) {
				continue;
			}
			if ( 'SCRIPT' === $tag ) {
				$typ = strtolower( (string) $p->get_attribute( 'type' ) );
				if ( '' !== $typ && ! in_array( $typ, Nerti_Cookies_Blokada::TYPY_WYKONYWALNE, true ) && 'text/plain' !== $typ ) {
					continue;
				}
				$src = '';
				foreach ( array( 'data-nc-src', 'src', 'data-rocket-src', 'data-src' ) as $a ) {
					$v = $p->get_attribute( $a );
					if ( is_string( $v ) && '' !== $v ) {
						$src = $v;
						break;
					}
				}
				$tekst = $src;
				if ( preg_match( '#^data:[^,]*;base64,(.+)$#is', $src, $m ) ) {
					$tekst = (string) base64_decode( $m[1] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
					$src   = '';
				}
				$tekst .= ' ' . $p->get_modifiable_text();
				self::znacznik( $pozycje, 'skrypt', $src, $tekst, $zab, $adres, $host, 'skrypty' );
			} elseif ( 'IFRAME' === $tag ) {
				$src = (string) ( $p->get_attribute( 'src' ) ?: $p->get_attribute( 'data-src' ) );
				self::znacznik( $pozycje, 'osadzenie', $src, $src, $zab, $adres, $host, 'ramki' );
			} elseif ( 'DIV' === $tag && $zab && $p->get_attribute( 'data-nc-src' ) ) {
				$src = (string) $p->get_attribute( 'data-nc-src' );
				self::znacznik( $pozycje, 'osadzenie', $src, $src, $zab, $adres, $host, 'ramki' );
			} elseif ( 'IMG' === $tag ) {
				$src = (string) ( $p->get_attribute( 'data-nc-src' ) ?: $p->get_attribute( 'src' ) );
				if ( '' !== Nerti_Cookies_Uslugi::usluga_dla_tekstu( $src, 'piksele' ) ) {
					self::znacznik( $pozycje, 'piksel', $src, $src, $zab, $adres, $host, 'piksele' );
				}
			}
		}
	}

	/**
	 * Jeden znacznik: ustalenie usługi, kategorii i statusu.
	 */
	private static function znacznik( array &$pozycje, $typ, $src, $tekst, $zablokowany, $adres, $host, $rodzaj ) {
		$usluga = Nerti_Cookies_Uslugi::usluga_dla_tekstu( $tekst, $rodzaj );
		if ( '' === $usluga && 'skrypty' !== $rodzaj ) {
			$usluga = Nerti_Cookies_Uslugi::usluga_dla_tekstu( $tekst, 'skrypty' );
		}
		$obcy = '' !== $src && 0 !== strpos( $src, 'data:' ) && wp_parse_url( $src, PHP_URL_HOST ) && wp_parse_url( $src, PHP_URL_HOST ) !== $host;
		if ( '' === $usluga && '' === $zablokowany && ! $obcy ) {
			return; // Własny skrypt strony bez śladu śledzenia — nie interesuje polityki.
		}
		$uslugi = Nerti_Cookies_Uslugi::wszystkie();
		$kat    = '' !== $zablokowany ? $zablokowany : ( '' !== $usluga ? (string) $uslugi[ $usluga ]['kategoria'] : '' );
		if ( '' !== $zablokowany ) {
			$status = 'blokowane';
		} elseif ( '' !== $usluga && ( ! empty( $uslugi[ $usluga ]['zawsze'] ) || 'niezbedne' === $kat ) ) {
			$status = 'zawsze';
		} elseif ( '' !== $usluga ) {
			$status = 'BLAD';
		} else {
			$status = 'nieznane';
		}
		$wartosc = '' !== $src ? self::skroc( $src ) : '(skrypt w treści strony)';
		$klucz_w = '' !== $usluga ? $usluga : $wartosc;
		self::dodaj( $pozycje, $typ, $klucz_w, $usluga, $kat, $status, $adres, $wartosc );
	}

	/**
	 * @param array  $pozycje Pozycje.
	 * @param string $nazwa   Nazwa ciasteczka.
	 * @param string $adres   Strona.
	 * @param string $zrodlo  serwer | przegladarka
	 */
	private static function dodaj_ciasteczko( array &$pozycje, $nazwa, $adres, $zrodlo ) {
		$info = Nerti_Cookies_Uslugi::ciasteczko( $nazwa );
		$kat  = $info['kategoria'];
		if ( '' === $kat ) {
			$status = 'nieznane';
		} elseif ( 'niezbedne' === $kat ) {
			$status = 'zawsze';
		} else {
			// Z serwera przed zgodą = błąd. Z przeglądarki = ramka skanu miała pełną zgodę, więc to stan „po zgodzie".
			$status = 'serwer' === $zrodlo ? 'BLAD' : 'blokowane';
		}
		self::dodaj( $pozycje, 'ciasteczko', $nazwa, $info['usluga'], $kat, $status, $adres, 'serwer' === $zrodlo ? 'ustawiane przez serwer' : 'ustawiane w przeglądarce po zgodzie' );
	}

	/**
	 * Dopisanie z deduplikacją po (typ, klucz). Gorszy status wygrywa: jedna podstrona z błędem
	 * to błąd całej pozycji.
	 */
	private static function dodaj( array &$pozycje, $typ, $klucz, $usluga, $kat, $status, $adres, $opis ) {
		$k     = $typ . '|' . $klucz;
		$waga  = array( 'BLAD' => 0, 'nieznane' => 1, 'blokowane' => 2, 'zawsze' => 3 );
		if ( ! isset( $pozycje[ $k ] ) ) {
			$pozycje[ $k ] = array(
				'typ'       => $typ,
				'nazwa'     => '' !== $usluga ? Nerti_Cookies_Uslugi::wszystkie()[ $usluga ]['nazwa'] : $klucz,
				'klucz'     => $klucz,
				'usluga'    => $usluga,
				'kategoria' => $kat,
				'status'    => $status,
				'opis'      => $opis,
				'strony'    => array(),
			);
		} elseif ( $waga[ $status ] < $waga[ $pozycje[ $k ]['status'] ] ) {
			$pozycje[ $k ]['status'] = $status;
			$pozycje[ $k ]['opis']   = $opis;
		}
		if ( '' !== $adres && ! in_array( $adres, $pozycje[ $k ]['strony'], true ) && count( $pozycje[ $k ]['strony'] ) < 5 ) {
			$pozycje[ $k ]['strony'][] = $adres;
		}
	}

	private static function skroc( $adres ) {
		$adres = preg_replace( '#^https?://#', '', (string) $adres );
		return strlen( $adres ) > 90 ? substr( $adres, 0, 87 ) . '…' : $adres;
	}

	/**
	 * Wynik z przeglądarki (ramka skanu).
	 *
	 * @param WP_REST_Request $req Żądanie.
	 * @return WP_REST_Response
	 */
	public static function rest_przegladarka( $req ) {
		$adres = esc_url_raw( (string) $req->get_param( 'adres' ) );
		$dane  = array(
			'ciasteczka' => array_slice( array_map( 'sanitize_text_field', (array) $req->get_param( 'ciasteczka' ) ), 0, 100 ),
			'storage'    => array_slice( array_map( 'sanitize_text_field', (array) $req->get_param( 'storage' ) ), 0, 100 ),
			'hosty'      => array_slice( array_map( 'sanitize_text_field', (array) $req->get_param( 'hosty' ) ), 0, 100 ),
		);
		$wynik = self::wynik();
		if ( ! $wynik ) {
			$wynik = array( 'czas' => time(), 'wersja' => NERTI_COOKIES_WERSJA, 'adresy' => array(), 'strony' => array(), 'pozycje' => array(), 'przegladarka' => array() );
		}
		$wynik['przegladarka'][ $adres ] = $dane;
		self::pozycje_z_przegladarki( $wynik['pozycje'], $adres, $dane );
		update_option( self::OPCJA, $wynik, false );
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	private static function pozycje_z_przegladarki( array &$pozycje, $adres, array $dane ) {
		foreach ( (array) $dane['ciasteczka'] as $nazwa ) {
			// Ramka skanu działa w sesji administratora: jego ciasteczka logowania to nie
			// ciasteczka odwiedzającego i nie należą do polityki.
			if ( 'wordpress' === Nerti_Cookies_Uslugi::ciasteczko( (string) $nazwa )['usluga'] ) {
				continue;
			}
			self::dodaj_ciasteczko( $pozycje, (string) $nazwa, $adres, 'przegladarka' );
		}
		foreach ( (array) $dane['storage'] as $klucz ) {
			$usl = '';
			$kat = '';
			foreach ( Nerti_Cookies_Uslugi::wszystkie() as $id => $u ) {
				foreach ( (array) $u['storage'] as $w ) {
					if ( Nerti_Cookies_Uslugi::pasuje_nazwa( $klucz, $w ) ) {
						$usl = $id;
						$kat = $u['kategoria'];
						break 2;
					}
				}
			}
			$st = '' === $usl ? 'nieznane' : ( 'niezbedne' === $kat ? 'zawsze' : 'blokowane' );
			self::dodaj( $pozycje, 'pamiec', (string) $klucz, $usl, $kat, $st, $adres, 'localStorage / sessionStorage po zgodzie' );
		}
		foreach ( (array) $dane['hosty'] as $h ) {
			$usl = Nerti_Cookies_Uslugi::usluga_dla_tekstu( (string) $h, 'hosty' );
			if ( '' === $usl ) {
				$usl = Nerti_Cookies_Uslugi::usluga_dla_tekstu( (string) $h, 'skrypty' );
			}
			if ( '' !== $usl ) {
				continue; // Host znanej usługi — usługa jest już na liście ze znacznika.
			}
			self::dodaj( $pozycje, 'host', (string) $h, '', '', 'nieznane', $adres, 'obca domena, do której poszło żądanie po zgodzie' );
		}
	}

	/**
	 * @return array
	 */
	public static function wynik() {
		$w = get_option( self::OPCJA, array() );
		return is_array( $w ) ? $w : array();
	}

	/**
	 * Podsumowanie dla pulpitu i ability `status`.
	 *
	 * @return array
	 */
	public static function podsumowanie() {
		$w = self::wynik();
		if ( ! $w ) {
			return array( 'jest' => false );
		}
		$licz = array( 'blokowane' => 0, 'zawsze' => 0, 'BLAD' => 0, 'nieznane' => 0 );
		foreach ( (array) $w['pozycje'] as $poz ) {
			$licz[ $poz['status'] ] = ( $licz[ $poz['status'] ] ?? 0 ) + 1;
		}
		$bez_banera = array();
		$bez_wyc    = array();
		foreach ( (array) $w['strony'] as $adres => $s ) {
			if ( empty( $s['baner'] ) && ! empty( $s['kod'] ) && 200 === (int) $s['kod'] ) {
				$bez_banera[] = $adres;
			}
			if ( empty( $s['wycofanie'] ) && ! empty( $s['kod'] ) && 200 === (int) $s['kod'] ) {
				$bez_wyc[] = $adres;
			}
		}
		return array(
			'jest'              => true,
			'czas'              => (int) $w['czas'],
			'stron'             => count( (array) $w['strony'] ),
			'przegladarka'      => count( (array) $w['przegladarka'] ),
			'licz'              => $licz,
			'bez_banera'        => $bez_banera,
			'bez_wycofania'     => $bez_wyc,
		);
	}
}
