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
 *
 * Trzy statusy „pomijane" (od 0.7.0) — pozycje, które NIE są śledzeniem odwiedzającego:
 *   admin       — ślad panelu administratora (kreator stron, Site Kit, klient REST WordPressa),
 *   pozostalosc — wpis usuniętej wtyczki (np. Complianz) w przeglądarce admina,
 *   pominiete   — właściciel kliknął „Pomiń" (ustawienie `ignoruj`, odwracalne).
 * Nie liczą się do „wymaga kategorii" i nie trafiają na listę ciasteczek w polityce.
 * Są nakładką stosowaną przy ODCZYCIE (`wynik()`), a nie zapisem w skanie: dzięki temu stary
 * skan poprawia się po aktualizacji biblioteki bez ponownego skanowania, a „Pomiń" da się cofnąć.
 */
class Nerti_Cookies_Skaner {

	const OPCJA = 'nerti_cookies_skan';

	/** Statusy pozycji, które nie wymagają decyzji ani nie trafiają do polityki. */
	const POMIJANE = array( 'admin', 'pozostalosc', 'pominiete' );

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
					'zalogowany' => array( 'type' => 'boolean' ),
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
		$poprzedni = self::surowy();
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
	 * @param bool   $zalogowany Skan z przeglądarki w sesji zalogowanego użytkownika.
	 */
	private static function dodaj_ciasteczko( array &$pozycje, $nazwa, $adres, $zrodlo, $zalogowany = false ) {
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
		self::dodaj( $pozycje, 'ciasteczko', $nazwa, $info['usluga'], $kat, $status, $adres, 'serwer' === $zrodlo ? 'ustawiane przez serwer' : 'ustawiane w przeglądarce po zgodzie', self::slad( $zrodlo, $zalogowany ) );
	}

	/**
	 * Skąd widać pozycję: anonimowy serwer, zalogowana przeglądarka (skan z panelu) albo
	 * przeglądarka bez rozpoznanego logowania. Tylko pozycja widoczna WYŁĄCZNIE w sesji
	 * administratora może być uznana za ślad panelu.
	 *
	 * @param string $zrodlo     serwer | przegladarka
	 * @param bool   $zalogowany Czy skan z przeglądarki szedł w sesji zalogowanego użytkownika.
	 * @return array<string,bool>
	 */
	private static function slad( $zrodlo, $zalogowany ) {
		return array(
			'serwer'      => 'serwer' === $zrodlo,
			'przegl_zal'  => 'przegladarka' === $zrodlo && $zalogowany,
			'przegl_gosc' => 'przegladarka' === $zrodlo && ! $zalogowany,
		);
	}

	/**
	 * Dopisanie z deduplikacją po (typ, klucz). Gorszy status wygrywa: jedna podstrona z błędem
	 * to błąd całej pozycji.
	 */
	private static function dodaj( array &$pozycje, $typ, $klucz, $usluga, $kat, $status, $adres, $opis, array $slad = array() ) {
		$k     = $typ . '|' . $klucz;
		$waga  = array( 'BLAD' => 0, 'nieznane' => 1, 'blokowane' => 2, 'zawsze' => 3 );
		$slad  = $slad ? $slad : array( 'serwer' => true, 'przegl_zal' => false, 'przegl_gosc' => false );
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
				'serwer'      => false,
				'przegl_zal'  => false,
				'przegl_gosc' => false,
			);
		} elseif ( $waga[ $status ] < $waga[ $pozycje[ $k ]['status'] ] ) {
			$pozycje[ $k ]['status'] = $status;
			$pozycje[ $k ]['opis']   = $opis;
		}
		foreach ( array( 'serwer', 'przegl_zal', 'przegl_gosc' ) as $f ) {
			$pozycje[ $k ][ $f ] = ! empty( $pozycje[ $k ][ $f ] ) || ! empty( $slad[ $f ] );
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
			// Ramka skanu mówi, czy strona wyrenderowała się dla zalogowanego (pasek admina, klasa body).
			'zalogowany' => (bool) $req->get_param( 'zalogowany' ),
		);
		$wynik = self::surowy();
		if ( ! $wynik ) {
			$wynik = array( 'czas' => time(), 'wersja' => NERTI_COOKIES_WERSJA, 'adresy' => array(), 'strony' => array(), 'pozycje' => array(), 'przegladarka' => array() );
		}
		$wynik['przegladarka'][ $adres ] = $dane;
		self::pozycje_z_przegladarki( $wynik['pozycje'], $adres, $dane );
		update_option( self::OPCJA, $wynik, false );
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	private static function pozycje_z_przegladarki( array &$pozycje, $adres, array $dane ) {
		$zal = ! empty( $dane['zalogowany'] );
		foreach ( (array) $dane['ciasteczka'] as $nazwa ) {
			// Ramka skanu działa w sesji administratora: jego ciasteczka logowania to nie
			// ciasteczka odwiedzającego i nie należą do polityki.
			if ( 'wordpress' === Nerti_Cookies_Uslugi::ciasteczko( (string) $nazwa )['usluga'] ) {
				continue;
			}
			self::dodaj_ciasteczko( $pozycje, (string) $nazwa, $adres, 'przegladarka', $zal );
		}
		foreach ( (array) $dane['storage'] as $klucz ) {
			// Listy `storage` z ustawień kategorii wygrywają z biblioteką (jak przy ciasteczkach).
			$info = Nerti_Cookies_Uslugi::pamiec( (string) $klucz );
			$usl  = $info['usluga'];
			$kat  = $info['kategoria'];
			$st   = '' === $kat ? 'nieznane' : ( 'niezbedne' === $kat ? 'zawsze' : 'blokowane' );
			self::dodaj( $pozycje, 'pamiec', (string) $klucz, $usl, $kat, $st, $adres, 'localStorage / sessionStorage po zgodzie', self::slad( 'przegladarka', $zal ) );
		}
		foreach ( (array) $dane['hosty'] as $h ) {
			$usl = Nerti_Cookies_Uslugi::usluga_dla_tekstu( (string) $h, 'hosty' );
			if ( '' === $usl ) {
				$usl = Nerti_Cookies_Uslugi::usluga_dla_tekstu( (string) $h, 'skrypty' );
			}
			if ( '' !== $usl ) {
				continue; // Host znanej usługi — usługa jest już na liście ze znacznika.
			}
			self::dodaj( $pozycje, 'host', (string) $h, '', '', 'nieznane', $adres, 'obca domena, do której poszło żądanie po zgodzie', self::slad( 'przegladarka', $zal ) );
		}
	}

	/**
	 * Wynik tak, jak leży w bazie: bez nakładki „pomijane". Do zapisu z powrotem.
	 *
	 * @return array
	 */
	public static function surowy() {
		$w = get_option( self::OPCJA, array() );
		return is_array( $w ) ? $w : array();
	}

	/**
	 * Wynik do odczytu: z naniesionymi statusami admin / pozostalosc / pominiete. Pozycja
	 * zmieniona przez nakładkę ma `status_pierwotny` (do cofnięcia „Pomiń").
	 *
	 * @return array
	 */
	public static function wynik() {
		$w = self::surowy();
		if ( ! empty( $w['pozycje'] ) && is_array( $w['pozycje'] ) ) {
			$ignoruj = array_values( array_filter( (array) Nerti_Cookies_Ustawienia::pole( 'ignoruj', array() ), 'is_string' ) );
			foreach ( $w['pozycje'] as $k => $p ) {
				if ( is_array( $p ) ) {
					$w['pozycje'][ $k ] = self::nalozenie( $p, $ignoruj );
				}
			}
		}
		return $w;
	}

	/**
	 * Wzorzec z listy „Pomiń", do którego pasuje klucz pozycji.
	 *
	 * @param string            $klucz   Klucz pozycji.
	 * @param array<int,string> $ignoruj Wzorce.
	 * @return string Pasujący wzorzec albo ''.
	 */
	public static function wzorzec_pomijania( $klucz, array $ignoruj ) {
		foreach ( $ignoruj as $w ) {
			if ( Nerti_Cookies_Uslugi::pasuje_nazwa( (string) $klucz, (string) $w ) ) {
				return (string) $w;
			}
		}
		return '';
	}

	/**
	 * Przypisanie kategorii nierozpoznanej pozycji — wspólne dla przycisku w panelu i dla
	 * ability `przypisz-kategorie`, żeby obie drogi zapisywały dokładnie to samo.
	 *
	 * Ciasteczko i pamięć trafiają do listy kategorii (wzorzec albo nazwa), skrypt, osadzenie
	 * i host — do mapy wzorców jako domena. Domena, nie cały adres: adres z parametrami
	 * zmienia się przy każdej wersji skryptu i wzorzec przestałby działać.
	 *
	 * @param string $typ     ciasteczko | pamiec | skrypt | osadzenie | piksel | host
	 * @param string $klucz   Nazwa, wzorzec (`pys_*`) albo adres.
	 * @param string $kat     Id kategorii.
	 * @return true|WP_Error
	 */
	public static function przypisz( $typ, $klucz, $kat ) {
		$u = Nerti_Cookies_Ustawienia::pobierz();
		if ( '' === (string) $klucz || ! isset( $u['kategorie'][ $kat ] ) ) {
			return new WP_Error( 'nc_przypisz', 'Nieprawidłowe dane: brak nazwy albo nieznana kategoria.', array( 'status' => 400 ) );
		}
		$zmiana = array();
		if ( in_array( $typ, array( 'ciasteczko', 'pamiec' ), true ) ) {
			$pole = 'ciasteczko' === $typ ? 'ciasteczka' : 'storage';
			// Wzorzec ląduje tylko w jednej kategorii: z pozostałych znika, inaczej wygrywałaby pierwsza z listy.
			foreach ( array_keys( $u['kategorie'] ) as $id ) {
				$lista = array_values( array_diff( array_map( 'strval', (array) ( $u['kategorie'][ $id ][ $pole ] ?? array() ) ), array( (string) $klucz ) ) );
				if ( $id === $kat ) {
					$lista[] = (string) $klucz;
				}
				if ( $id === $kat || count( $lista ) !== count( (array) ( $u['kategorie'][ $id ][ $pole ] ?? array() ) ) ) {
					$zmiana['kategorie'][ $id ][ $pole ] = array_values( array_unique( $lista ) );
				}
			}
		} else {
			$host = wp_parse_url( ( false === strpos( $klucz, '://' ) ? 'https://' : '' ) . $klucz, PHP_URL_HOST );
			$wzor = $host ? (string) $host : (string) $klucz;
			$mapa = 'osadzenie' === $typ ? 'osadzenia' : 'wzorce';
			$zmiana[ $mapa ] = array( $wzor => $kat );
		}
		// Przypisanie kategorii kasuje ewentualne wcześniejsze „Pomiń" tego samego wzorca.
		$ign = array_values( array_diff( array_map( 'strval', (array) $u['ignoruj'] ), array( (string) $klucz ) ) );
		if ( count( $ign ) !== count( (array) $u['ignoruj'] ) ) {
			$zmiana['ignoruj'] = $ign;
		}
		$wynik = Nerti_Cookies_Ustawienia::zapisz( $zmiana );
		if ( is_wp_error( $wynik ) ) {
			return $wynik;
		}
		// Pozycje w zapisanym skanie dostają nowy status od razu, bez ponownego skanowania.
		$skan  = self::surowy();
		$zmien = false;
		foreach ( (array) ( $skan['pozycje'] ?? array() ) as $k => $p ) {
			$pasuje = $typ === $p['typ'] && ( in_array( $typ, array( 'ciasteczko', 'pamiec' ), true ) ? Nerti_Cookies_Uslugi::pasuje_nazwa( $p['klucz'], $klucz ) : (string) $p['klucz'] === (string) $klucz );
			if ( $pasuje && 'nieznane' === $p['status'] ) {
				$skan['pozycje'][ $k ]['kategoria'] = $kat;
				$skan['pozycje'][ $k ]['status']    = 'niezbedne' === $kat ? 'zawsze' : 'blokowane';
				$zmien = true;
			}
		}
		if ( $zmien ) {
			update_option( self::OPCJA, $skan, false );
		}
		return true;
	}

	/**
	 * „Pomiń": dopisuje wzorzec do ustawienia `ignoruj`. Działa na raportowanie (patrz
	 * `nalozenie()`), nie zmienia blokady.
	 *
	 * @param string $wzorzec Nazwa albo wzorzec z gwiazdką.
	 * @return true|WP_Error
	 */
	public static function pomin( $wzorzec ) {
		$wzorzec = trim( (string) $wzorzec );
		if ( '' === $wzorzec ) {
			return new WP_Error( 'nc_pomin', 'Pusta nazwa.', array( 'status' => 400 ) );
		}
		$lista = array_map( 'strval', (array) Nerti_Cookies_Ustawienia::pole( 'ignoruj', array() ) );
		if ( in_array( $wzorzec, $lista, true ) ) {
			return true;
		}
		$lista[] = $wzorzec;
		$wynik   = Nerti_Cookies_Ustawienia::zapisz( array( 'ignoruj' => array_values( $lista ) ) );
		return is_wp_error( $wynik ) ? $wynik : true;
	}

	/**
	 * Cofnięcie „Pomiń": usuwa z listy wszystkie wzorce, które obejmują ten klucz.
	 *
	 * @param string $klucz Klucz pozycji.
	 * @return true|WP_Error
	 */
	public static function przywroc( $klucz ) {
		$lista = array_map( 'strval', (array) Nerti_Cookies_Ustawienia::pole( 'ignoruj', array() ) );
		$nowa  = array();
		foreach ( $lista as $w ) {
			if ( ! Nerti_Cookies_Uslugi::pasuje_nazwa( (string) $klucz, $w ) ) {
				$nowa[] = $w;
			}
		}
		if ( count( $nowa ) === count( $lista ) ) {
			return true;
		}
		$wynik = Nerti_Cookies_Ustawienia::zapisz( array( 'ignoruj' => $nowa ) );
		return is_wp_error( $wynik ) ? $wynik : true;
	}

	/**
	 * Nakładka „pomijane" na jedną pozycję. Dotyka wyłącznie pozycji `nieznane`: pozycja
	 * rozpoznana (kategoria, usługa) albo działająca przed zgodą (BLAD) nie może zniknąć
	 * z raportu przez „Pomiń" ani przez heurystykę, bo to ukryłoby prawdziwe śledzenie.
	 *
	 * Kolejność: decyzja właściciela, potem znany wzorzec z biblioteki, na końcu heurystyka
	 * „widziane tylko w sesji administratora" (ciasteczko albo pamięć, nigdy host).
	 *
	 * @param array             $p       Pozycja ze skanu.
	 * @param array<int,string> $ignoruj Wzorce z ustawienia `ignoruj`.
	 * @return array
	 */
	private static function nalozenie( array $p, array $ignoruj ) {
		unset( $p['status_pierwotny'] );
		if ( 'nieznane' !== ( $p['status'] ?? '' ) ) {
			return $p;
		}
		$typ   = (string) ( $p['typ'] ?? '' );
		$klucz = (string) ( $p['klucz'] ?? '' );
		$lib   = Nerti_Cookies_Uslugi::pomijanie_dla( $typ, $klucz );
		$wz    = self::wzorzec_pomijania( $klucz, $ignoruj );
		$nowy  = '';
		$opis  = '';
		if ( '' !== $wz ) {
			$nowy = 'pominiete';
			$opis = 'Pominięte przez administratora (wzorzec: ' . $wz . ').';
		} elseif ( $lib ) {
			$nowy = $lib['status'];
			$opis = 'admin' === $lib['status']
				? $lib['nazwa'] . ': zapisuje to panel administratora, odwiedzający strony tego nie ma.'
				: $lib['nazwa'] . ': pozostałość w przeglądarce administratora, do skasowania w ustawieniach przeglądarki.';
		} elseif ( in_array( $typ, array( 'ciasteczko', 'pamiec' ), true ) && empty( $p['serwer'] ) && ! empty( $p['przegl_zal'] ) && empty( $p['przegl_gosc'] ) ) {
			$nowy = 'admin';
			$opis = 'Widziane tylko w skanie z sesji zalogowanego administratora, więc to prawdopodobnie ślad panelu. Jeśli to narzędzie strony, przypisz mu kategorię.';
		}
		if ( '' === $nowy ) {
			return $p;
		}
		$p['status_pierwotny'] = 'nieznane';
		$p['status']           = $nowy;
		$p['opis']             = $opis;
		return $p;
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
		$licz = array( 'blokowane' => 0, 'zawsze' => 0, 'BLAD' => 0, 'nieznane' => 0, 'admin' => 0, 'pozostalosc' => 0, 'pominiete' => 0 );
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
