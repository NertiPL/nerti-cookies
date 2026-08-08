<?php
/**
 * Sterowanie wtyczką z zewnątrz — przez WordPress Abilities API (jeśli jest) i przez REST.
 *
 * PO CO TO ISTNIEJE
 * Zmiana tekstu w banerze, podniesienie wersji polityki albo import tokenów wyglądu wymaga
 * dziś zalogowania się do panelu klienta i wyklikania tego ręcznie. Przy dziesięciu stronach
 * to jest dziesięć logowań, żeby zmienić jedno zdanie. Ten plik wystawia te operacje jako
 * wywołania, które agent może wykonać przez MCP.
 *
 * CZEGO NIE WIEM, A CO TRZEBA SPRAWDZIĆ PRZED WDROŻENIEM
 * Sygnatura poniżej (`wp_register_ability`, hook `abilities_api_init`, klucze `label`,
 * `description`, `input_schema`, `output_schema`, `execute_callback`, `permission_callback`)
 * odpowiada temu, co pamiętam z Abilities API. **Nie została zweryfikowana wobec wersji
 * zainstalowanej na maszynie klienta.** Zanim uznasz tę ścieżkę za działającą, sprawdź trzy
 * rzeczy i zapisz wynik — lista jest w ../abilities.md §3:
 *   1. czy funkcja rejestrująca nazywa się `wp_register_ability` i czy hook to
 *      `abilities_api_init`;
 *   2. jakie dokładnie klucze przyjmuje tablica argumentów i które są wymagane;
 *   3. jak agent connector odnajduje zarejestrowane abilities i czym się uwierzytelnia.
 *
 * Dlatego rejestracja jest opakowana w `function_exists()`, a **cała funkcjonalność jest
 * dostępna także przez zwykły REST** (trasy niżej). Ścieżka REST działa na czystym
 * WordPressie z hasłem aplikacji i nie zależy od żadnego założenia o Abilities API.
 * Wtyczka, która działa tylko przez interfejs, którego nie sprawdziłem, jest wtyczką,
 * która nie działa.
 *
 * @package NertiCookies
 */

defined( 'ABSPATH' ) || exit;

class Nerti_Cookies_Abilities {

	public static function hooks() {
		add_action( 'abilities_api_init', array( __CLASS__, 'rejestruj_abilities' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'trasy_rest' ) );
	}

	/**
	 * @return bool
	 */
	public static function moze() {
		return current_user_can( 'manage_options' );
	}

	/* ---------- Warstwa 1: Abilities API (warunkowa) ---------- */

	public static function rejestruj_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		foreach ( self::katalog() as $nazwa => $def ) {
			wp_register_ability(
				'nerti-cookies/' . $nazwa,
				array(
					'label'               => $def['etykieta'],
					'description'         => $def['opis'],
					'input_schema'        => $def['wejscie'],
					'output_schema'       => $def['wyjscie'],
					'execute_callback'    => $def['wykonaj'],
					'permission_callback' => array( __CLASS__, 'moze' ),
				)
			);
		}
	}

	/* ---------- Warstwa 2: REST (bezwarunkowa) ---------- */

	public static function trasy_rest() {
		foreach ( self::katalog() as $nazwa => $def ) {
			register_rest_route(
				'nerti-cookies/v1',
				'/' . $nazwa,
				array(
					'methods'             => 'POST',
					'callback'            => function ( $req ) use ( $def ) {
						$wynik = call_user_func( $def['wykonaj'], (array) $req->get_json_params() );
						return new WP_REST_Response( $wynik, 200 );
					},
					'permission_callback' => array( __CLASS__, 'moze' ),
				)
			);
		}
	}

	/**
	 * Jedna definicja operacji obsługuje obie warstwy. Rozjazd między abilities a REST
	 * byłby dwiema implementacjami tej samej rzeczy, z których jedna zostaje niepoprawiona.
	 *
	 * @return array
	 */
	private static function katalog() {
		return array(

			'pobierz-ustawienia' => array(
				'etykieta' => 'Pobierz ustawienia banera',
				'opis'     => 'Zwraca pełną konfigurację banera zgód: kategorie, teksty, wersję polityki, listę blokowanych wzorców i stan importu tokenów wyglądu.',
				'wejscie'  => array( 'type' => 'object', 'properties' => new stdClass() ),
				'wyjscie'  => array( 'type' => 'object' ),
				'wykonaj'  => array( __CLASS__, 'op_pobierz' ),
			),

			'zapisz-ustawienia' => array(
				'etykieta' => 'Zmień ustawienia banera',
				'opis'     => 'Scala przekazany fragment konfiguracji z bieżącą. Pozwala zmienić teksty, linki do polityk, ważność decyzji i mapy blokowanych skryptów bez wchodzenia do panelu WordPressa.',
				'wejscie'  => array(
					'type'       => 'object',
					'properties' => array(
						'teksty'        => array( 'type' => 'object' ),
						'link_polityka' => array( 'type' => 'string' ),
						'link_cookies'  => array( 'type' => 'string' ),
						'waznosc_dni'   => array( 'type' => 'integer' ),
						'kategorie'     => array( 'type' => 'object' ),
						'wzorce'        => array( 'type' => 'object' ),
						'osadzenia'     => array( 'type' => 'object' ),
						'uchwyty'       => array( 'type' => 'object' ),
						'bufor_wyjscia' => array( 'type' => 'boolean' ),
						'consent_mode'  => array( 'type' => 'boolean' ),
					),
				),
				'wyjscie'  => array( 'type' => 'object' ),
				'wykonaj'  => array( __CLASS__, 'op_zapisz' ),
			),

			'importuj-tokeny' => array(
				'etykieta' => 'Wczytaj wygląd z design-system.json',
				'opis'     => 'Przyjmuje treść albo ścieżkę pliku design-system.json projektu i przepisuje kolory, kroje, odstępy, promienie i czasy na zmienne banera. Dzięki temu baner wygląda jak strona, a nie jak wtyczka.',
				'wejscie'  => array(
					'type'       => 'object',
					'properties' => array(
						'zrodlo' => array( 'type' => 'string', 'description' => 'Treść JSON albo ścieżka do pliku na serwerze.' ),
					),
					'required'   => array( 'zrodlo' ),
				),
				'wyjscie'  => array( 'type' => 'object' ),
				'wykonaj'  => array( __CLASS__, 'op_tokeny' ),
			),

			'podnies-wersje-polityki' => array(
				'etykieta' => 'Podnieś wersję polityki',
				'opis'     => 'Unieważnia wszystkie dotychczasowe zgody i pokazuje baner ponownie. Używane po zmianie treści polityki albo po dodaniu narzędzia, które ustawia nowe ciasteczka.',
				'wejscie'  => array(
					'type'       => 'object',
					'properties' => array( 'wersja' => array( 'type' => 'string' ) ),
				),
				'wyjscie'  => array( 'type' => 'object' ),
				'wykonaj'  => array( __CLASS__, 'op_wersja' ),
			),

			'statystyki-zgod' => array(
				'etykieta' => 'Statystyki zgód',
				'opis'     => 'Liczba zapisanych decyzji, liczba odmów i data ostatniej. Służy do sprawdzenia, czy baner w ogóle zapisuje odmowy — rejestr z zerem odmów przy tysiącu zgód jest sygnałem defektu, nie sukcesu.',
				'wejscie'  => array( 'type' => 'object', 'properties' => new stdClass() ),
				'wyjscie'  => array( 'type' => 'object' ),
				'wykonaj'  => array( __CLASS__, 'op_statystyki' ),
			),

			'inwentarz-skryptow' => array(
				'etykieta' => 'Inwentarz skryptów zewnętrznych',
				'opis'     => 'Zwraca listę uchwytów skryptów zarejestrowanych w WordPressie wraz z ich adresami i przypisaną kategorią. To jest wejście do polityki cookies generowanej z faktycznego stanu strony, nie z szablonu.',
				'wejscie'  => array( 'type' => 'object', 'properties' => new stdClass() ),
				'wyjscie'  => array( 'type' => 'object' ),
				'wykonaj'  => array( __CLASS__, 'op_inwentarz' ),
			),
		);
	}

	/* ---------- Operacje ---------- */

	public static function op_pobierz( $args = array() ) {
		unset( $args );
		$u = Nerti_Cookies_Ustawienia::pobierz();
		return array(
			'ustawienia'       => $u,
			'tokeny'           => Nerti_Cookies_Tokeny::pobierz(),
			'tokeny_z_systemu' => Nerti_Cookies_Tokeny::czy_zaimportowane(),
		);
	}

	public static function op_zapisz( $args = array() ) {
		$dozwolone = array( 'teksty', 'link_polityka', 'link_cookies', 'waznosc_dni', 'kategorie', 'wzorce', 'osadzenia', 'uchwyty', 'bufor_wyjscia', 'consent_mode', 'pozycja', 'administrator' );
		$nowe      = array();
		foreach ( $dozwolone as $klucz ) {
			if ( array_key_exists( $klucz, (array) $args ) ) {
				$nowe[ $klucz ] = $args[ $klucz ];
			}
		}
		if ( empty( $nowe ) ) {
			return array( 'ok' => false, 'blad' => 'Nie przekazano żadnego znanego pola.' );
		}
		return array( 'ok' => true, 'ustawienia' => Nerti_Cookies_Ustawienia::zapisz( $nowe ) );
	}

	public static function op_tokeny( $args = array() ) {
		$zrodlo = isset( $args['zrodlo'] ) ? $args['zrodlo'] : '';
		$wynik  = Nerti_Cookies_Tokeny::importuj( $zrodlo );
		/*
		 * Braki wracają jawnie. Import, który po cichu zostawia połowę zmiennych na
		 * wartościach domyślnych wtyczki, daje baner „prawie" w stylu strony — a to
		 * wygląda gorzej niż baner całkiem obcy, bo wygląda na pomyłkę.
		 */
		return $wynik;
	}

	public static function op_wersja( $args = array() ) {
		$wersja = isset( $args['wersja'] ) ? (string) $args['wersja'] : '';
		return array( 'ok' => true, 'wersja_polityki' => Nerti_Cookies_Ustawienia::podnies_wersje_polityki( $wersja ) );
	}

	public static function op_statystyki( $args = array() ) {
		unset( $args );
		return Nerti_Cookies_Rejestr::statystyki();
	}

	public static function op_inwentarz( $args = array() ) {
		unset( $args );
		$u     = Nerti_Cookies_Ustawienia::pobierz();
		$lista = array();

		$wp_scripts = wp_scripts();
		if ( $wp_scripts && ! empty( $wp_scripts->registered ) ) {
			foreach ( $wp_scripts->registered as $uchwyt => $obj ) {
				$src = isset( $obj->src ) ? (string) $obj->src : '';
				if ( '' === $src ) {
					continue;
				}
				$zewnetrzny = ( 0 === strpos( $src, 'http' ) && false === strpos( $src, wp_parse_url( home_url(), PHP_URL_HOST ) ) );
				$kat        = isset( $u['uchwyty'][ $uchwyt ] ) ? $u['uchwyty'][ $uchwyt ] : '';
				if ( '' === $kat ) {
					foreach ( $u['wzorce'] as $fragment => $kategoria ) {
						if ( false !== stripos( $src, (string) $fragment ) ) {
							$kat = (string) $kategoria;
							break;
						}
					}
				}
				$lista[] = array(
					'uchwyt'     => $uchwyt,
					'src'        => $src,
					'zewnetrzny' => $zewnetrzny,
					'kategoria'  => '' !== $kat ? $kat : ( $zewnetrzny ? 'DO ROZSTRZYGNIĘCIA' : 'niezbedne' ),
				);
			}
		}

		return array(
			'wtyczki_aktywne' => array_values( (array) get_option( 'active_plugins', array() ) ),
			'skrypty'         => $lista,
			'nota'            => 'Ta lista jest NIEPEŁNA z dwóch powodów i nie zastępuje inwentarza z przeglądarki. Po pierwsze: rejestr WordPressa widzi tylko skrypty dodane przez wp_enqueue_script, a piksele wklejone w motyw albo wstrzykiwane przez menedżera tagów nie przechodzą przez ten rejestr. Po drugie: wywołana przez REST, funkcja widzi wyłącznie skrypty zarejestrowane do momentu init — te z hooka wp_enqueue_scripts jeszcze nie istnieją. Pełny inwentarz robisz procedurą z ../inwentarz.md §2.',
		);
	}
}
