<?php
/**
 * Wygląd banera brany z design-system.json projektu.
 *
 * To jest jedyny powód, dla którego ta wtyczka istnieje zamiast Cookiebota albo CookieYes.
 * Gotowe rozwiązania dają baner, który wygląda jak baner — obcy prostokąt z cudzą typografią,
 * cudzymi promieniami i cudzym niebieskim przyciskiem, przyklejony do starannie zaprojektowanej
 * strony. Odwiedzający widzi to w pierwszej sekundzie, zanim zobaczy cokolwiek innego.
 *
 * @package NertiCookies
 */

defined( 'ABSPATH' ) || exit;

/**
 * Importuje tokeny z `design-system.json` i wystawia je jako zmienne CSS z prefiksem `--nc-`.
 *
 * Prefiks jest osobny celowo: baner nie dziedziczy zmiennych strony, bo wtedy zmiana tokenu
 * na stronie po cichu zmieniałaby kontrast w banerze i nikt by tego nie sprawdził.
 * Import jest jawnym krokiem z jawnym wynikiem.
 */
class Nerti_Cookies_Tokeny {

	const OPCJA = 'nerti_cookies_tokeny';

	/**
	 * Skąd pochodzi bieżący zestaw zmiennych i co przy imporcie poszło nie tak.
	 * Osobna opcja, żeby `pobierz()` dalej zwracało czystą mapę zmienna => wartość —
	 * dopisanie metadanych do tej samej tablicy skończyłoby się wstrzyknięciem
	 * `_zrodlo: design-system` do arkusza stylów.
	 */
	const OPCJA_ZRODLO = 'nerti_cookies_tokeny_zrodlo';

	/**
	 * Tokeny tylko dla telefonu albo tylko dla desktopu: {"mobile":{...},"desktop":{...}}.
	 * Osobna opcja, bo import z design-system.json albo z Oxygena nadpisuje CAŁĄ opcję
	 * OPCJA. Ręczne poprawki per urządzenie nie mają znikać przy ponownym imporcie.
	 */
	const OPCJA_URZADZENIA = 'nerti_cookies_tokeny_urzadzenia';

	/** Minimalny stosunek kontrastu dla tekstu podstawowego (WCAG 2.1 AA). */
	const KONTRAST_MIN = 4.5;

	/**
	 * Mapa: zmienna banera => lista kandydatów w `design-system.json`, w kolejności preferencji.
	 * Nazwy po prawej pochodzą z zamkniętego zestawu web-core/reguly/tokeny.md.
	 *
	 * @return array
	 */
	public static function mapa() {
		return array(
			'--nc-bg'          => array( 'kolor.c-surface', 'kolor.c-bg' ),
			'--nc-bg-2'        => array( 'kolor.c-surface-2', 'kolor.c-surface' ),
			'--nc-text'        => array( 'kolor.c-text' ),
			'--nc-muted'       => array( 'kolor.c-muted', 'kolor.c-text' ),
			'--nc-border'      => array( 'kolor.c-border' ),
			'--nc-primary'     => array( 'kolor.c-primary' ),
			'--nc-primary-ink' => array( 'kolor.c-primary-ink' ),
			'--nc-focus'       => array( 'kolor.c-focus' ),
			'--nc-font'        => array( 'typografia.f-body' ),
			'--nc-font-head'   => array( 'typografia.f-head', 'typografia.f-body' ),
			'--nc-fs'          => array( 'typografia.t-base' ),
			'--nc-fs-sm'       => array( 'typografia.t-sm', 'typografia.t-base' ),
			'--nc-fs-head'     => array( 'typografia.t-lg', 'typografia.t-base' ),
			'--nc-lh'          => array( 'typografia.lh-body' ),
			'--nc-gap'         => array( 'przestrzen.s-2' ),
			'--nc-pad'         => array( 'przestrzen.s-3', 'przestrzen.s-2' ),
			'--nc-radius'      => array( 'ksztalt.r-md' ),
			'--nc-radius-lg'   => array( 'ksztalt.r-lg', 'ksztalt.r-md' ),
			'--nc-shadow'      => array( 'ksztalt.sh-2', 'ksztalt.sh-1' ),
			'--nc-dur'         => array( 'ruch.d-base' ),
			'--nc-ease'        => array( 'ruch.e-out' ),
			'--nc-z'           => array( 'warstwy.z-modal', 'warstwy.z-overlay' ),
		);
	}

	/**
	 * @param string $zrodlo Ścieżka do pliku albo treść JSON.
	 * @return array {
	 *     @type bool   $ok
	 *     @type int    $ile      Liczba zaimportowanych zmiennych.
	 *     @type array  $braki    Zmienne, dla których nie było wartości w źródle.
	 *     @type string $blad
	 * }
	 */
	public static function importuj( $zrodlo ) {
		$json = null;

		if ( is_array( $zrodlo ) ) {
			$json = $zrodlo;
		} elseif ( is_string( $zrodlo ) && '' !== trim( $zrodlo ) ) {
			$tekst = $zrodlo;
			if ( '{' !== substr( ltrim( $zrodlo ), 0, 1 ) ) {
				if ( ! file_exists( $zrodlo ) || ! is_readable( $zrodlo ) ) {
					return array(
						'ok'    => false,
						'ile'   => 0,
						'braki' => array(),
						'blad'  => 'Nie ma takiego pliku albo brak prawa odczytu: ' . $zrodlo,
					);
				}
				$tekst = file_get_contents( $zrodlo ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
			$json = json_decode( (string) $tekst, true );
		}

		if ( ! is_array( $json ) ) {
			return array(
				'ok'    => false,
				'ile'   => 0,
				'braki' => array(),
				'blad'  => 'Źródło nie jest poprawnym JSON-em.',
			);
		}

		$wynik = array();
		$braki = array();

		foreach ( self::mapa() as $zmienna => $kandydaci ) {
			$wartosc = null;
			foreach ( $kandydaci as $sciezka ) {
				$wartosc = self::wyjmij( $json, $sciezka );
				if ( null !== $wartosc ) {
					break;
				}
			}
			if ( null === $wartosc ) {
				$braki[] = $zmienna;
				continue;
			}
			$wynik[ $zmienna ] = self::oczysc( (string) $wartosc );
		}

		return self::zapisz_import( $wynik, $braki, 'design-system' );
	}

	/**
	 * Zapisuje zestaw zmiennych po sprawdzeniu kontrastu — wspólny finał obu importów.
	 *
	 * Para, która nie przechodzi progu, jest USUWANA z zestawu, a nie zapisywana z
	 * ostrzeżeniem. Baner z wartościami zapasowymi wygląda obco; baner z jasnoszarym
	 * tekstem na białym tle jest nieczytelny. Z tych dwóch tylko jedno jest defektem
	 * dostępności, więc przy konflikcie wygrywa czytelność, a nie podobieństwo.
	 *
	 * @param array  $zmienne Mapa `--nc-*` => wartość.
	 * @param array  $braki   Zmienne bez pokrycia w źródle.
	 * @param string $zrodlo  'design-system' albo 'oxygen'.
	 * @return array
	 */
	private static function zapisz_import( array $zmienne, array $braki, $zrodlo ) {
		$kontrast = self::sprawdz_kontrast( $zmienne );

		foreach ( $kontrast['odrzucone'] as $zmienna ) {
			unset( $zmienne[ $zmienna ] );
			$braki[] = $zmienna;
		}

		update_option( self::OPCJA, $zmienne, false );
		update_option(
			self::OPCJA_ZRODLO,
			array(
				'zrodlo'      => $zrodlo,
				'kiedy'       => current_time( 'mysql' ),
				'ostrzezenia' => $kontrast['ostrzezenia'],
				'pomiary'     => $kontrast['pomiary'],
			),
			false
		);

		return array(
			'ok'          => true,
			'zrodlo'      => $zrodlo,
			'ile'         => count( $zmienne ),
			'braki'       => array_values( array_unique( $braki ) ),
			'ostrzezenia' => $kontrast['ostrzezenia'],
			'pomiary'     => $kontrast['pomiary'],
			'blad'        => '',
		);
	}

	/**
	 * Wyciąga wartość spod ścieżki `grupa.klucz`.
	 *
	 * Obsługuje oba kształty z kontraktu design-system.json: wpisy kolorów i rodzin krojów
	 * są obiektami z polem `wartosc`, a odstępy, promienie i czasy są wartościami płaskimi.
	 * Pominięcie tej różnicy jest najprostszym sposobem na import, który po cichu wstawia
	 * "Array" zamiast koloru.
	 *
	 * @param array  $json    Zdekodowany design-system.json.
	 * @param string $sciezka Ścieżka `grupa.klucz`.
	 * @return string|null
	 */
	private static function wyjmij( array $json, $sciezka ) {
		$czesci = explode( '.', $sciezka );
		$grupa  = $czesci[0];
		$klucz  = isset( $czesci[1] ) ? $czesci[1] : '';

		if ( ! isset( $json[ $grupa ][ $klucz ] ) ) {
			return null;
		}
		$w = $json[ $grupa ][ $klucz ];

		if ( is_array( $w ) ) {
			$w = isset( $w['wartosc'] ) ? $w['wartosc'] : null;
		}
		if ( null === $w || is_array( $w ) ) {
			return null;
		}
		if ( is_bool( $w ) ) {
			return null;
		}
		return (string) $w;
	}

	/**
	 * Wartość tokenu trafia do atrybutu `style`, więc musi być pozbawiona znaków,
	 * które pozwoliłyby z niej wyjść. Zawartość `design-system.json` jest nasza,
	 * ale plik da się podmienić przez MCP, więc traktujemy go jak wejście.
	 *
	 * @param string $w Wartość.
	 * @return string
	 */
	private static function oczysc( $w ) {
		$w = str_replace( array( '<', '>', '{', '}', ';', '\\' ), '', $w );
		$w = preg_replace( '/(url|expression|@import)\s*\(/i', '', $w );
		return trim( (string) $w );
	}

	/* ---------- Drugie źródło: ustawienia globalne Oxygena ---------- */

	/**
	 * Surowe ustawienia globalne Oxygena jako tablica, albo null.
	 *
	 * ZMIERZONE NA LABORII 2026-08-21, nie zgadywane — te dwie drogi dają RÓŻNE dane:
	 *
	 *   Breakdance\Data\get_global_option('global_settings_json_string')
	 *       -> '{"settings":{...}}'          — JSON pojedynczo zakodowany
	 *   get_option('oxygen_global_settings_json_string')
	 *       -> '"{\"settings\":{...}}"'      — JSON zakodowany PODWÓJNIE
	 *
	 * Dekodowanie raz i uznanie wyniku za tablicę działa tylko na pierwszej drodze.
	 * Na drugiej pierwszy `json_decode` zwraca string, nie tablicę — i import po cichu
	 * kończy się zerem zmiennych. Stąd pętla: dekoduj, dopóki wychodzi string.
	 *
	 * @return array|null
	 */
	public static function ustawienia_oxygena() {
		$surowe = null;

		if ( function_exists( '\\Breakdance\\Data\\get_global_option' ) ) {
			$surowe = \Breakdance\Data\get_global_option( 'global_settings_json_string' );
		}
		if ( ! is_string( $surowe ) || '' === trim( $surowe ) ) {
			$surowe = get_option( 'oxygen_global_settings_json_string', '' );
		}
		if ( ! is_string( $surowe ) || '' === trim( $surowe ) ) {
			return null;
		}

		$dane = $surowe;
		for ( $i = 0; $i < 3 && is_string( $dane ); $i++ ) {
			$dane = json_decode( $dane, true );
		}
		if ( ! is_array( $dane ) ) {
			return null;
		}

		// Kształt zmierzony: jeden klucz najwyższego poziomu `settings`.
		if ( isset( $dane['settings'] ) && is_array( $dane['settings'] ) ) {
			$dane = $dane['settings'];
		}
		return empty( $dane ) ? null : $dane;
	}

	/**
	 * Mapuje ustawienia globalne Oxygena na te same zmienne `--nc-*`.
	 *
	 * Świadomie NIE mapujemy: koloru obramowania (Oxygen nie ma takiego ustawienia
	 * globalnego), drugiego tła i odstępów (`containers.*` opisuje sekcje strony o
	 * szerokości 1120px — przełożenie tego na padding banera dałoby absurd).
	 * Dla nich zostają wartości zapasowe z `assets/baner.css`.
	 *
	 * @return array
	 */
	public static function importuj_z_oxygena() {
		$s = self::ustawienia_oxygena();
		if ( null === $s ) {
			return array(
				'ok'    => false,
				'ile'   => 0,
				'braki' => array(),
				'blad'  => 'Oxygen nie ma zapisanych ustawień globalnych na tej instalacji.',
			);
		}

		$kolory = isset( $s['colors'] ) && is_array( $s['colors'] ) ? $s['colors'] : array();
		$typo   = isset( $s['typography'] ) && is_array( $s['typography'] ) ? $s['typography'] : array();
		$btn    = isset( $s['buttons']['primary'] ) && is_array( $s['buttons']['primary'] ) ? $s['buttons']['primary'] : array();
		$inne   = isset( $s['other'] ) && is_array( $s['other'] ) ? $s['other'] : array();

		$w = array(
			'--nc-bg'          => self::tekst( $kolory, 'background' ),
			'--nc-text'        => self::tekst( $kolory, 'text' ),
			'--nc-muted'       => self::tekst( $kolory, 'text' ),
			// Tło przycisku jest bliżej prawdy niż kolor marki: to nim Oxygen faktycznie
			// maluje przycisk na stronie, a baner ma mieć TEN przycisk, nie kolor z palety.
			'--nc-primary'     => self::tekst( $btn, 'background' ) !== null ? self::tekst( $btn, 'background' ) : self::tekst( $kolory, 'brand' ),
			'--nc-primary-ink' => self::tekst( $btn, 'color' ),
			'--nc-focus'       => self::tekst( $kolory, 'links' ) !== null ? self::tekst( $kolory, 'links' ) : self::tekst( $kolory, 'brand' ),
			'--nc-font'        => self::kroj( $typo, 'body_font' ),
			'--nc-font-head'   => self::kroj( $typo, 'heading_font' ),
			'--nc-fs'          => self::miara( isset( $typo['base_size']['breakpoint_base'] ) ? $typo['base_size']['breakpoint_base'] : null ),
			'--nc-radius'      => self::miara( isset( $btn['corner_radius']['breakpoint_base'] ) ? $btn['corner_radius']['breakpoint_base'] : null ),
			'--nc-radius-lg'   => self::miara( isset( $btn['corner_radius']['breakpoint_base'] ) ? $btn['corner_radius']['breakpoint_base'] : null ),
			'--nc-shadow'      => self::cien( isset( $btn['shadow']['breakpoint_base'] ) ? $btn['shadow']['breakpoint_base'] : null ),
			'--nc-dur'         => self::miara( isset( $inne['transition_duration'] ) ? $inne['transition_duration'] : null ),
		);

		$zmienne = array();
		$braki   = array();
		foreach ( $w as $zmienna => $wartosc ) {
			if ( null === $wartosc || '' === $wartosc ) {
				$braki[] = $zmienna;
				continue;
			}
			$zmienne[ $zmienna ] = self::oczysc( (string) $wartosc );
		}

		// Zmienne, których Oxygen w ogóle nie ma — meldujemy je jako braki, żeby panel
		// nie sugerował, że import pokrył cały baner.
		foreach ( array( '--nc-bg-2', '--nc-border', '--nc-fs-sm', '--nc-fs-head', '--nc-lh', '--nc-gap', '--nc-pad', '--nc-ease', '--nc-z' ) as $poza ) {
			$braki[] = $poza;
		}

		return self::zapisz_import( $zmienne, $braki, 'oxygen' );
	}

	/**
	 * @param array  $tablica Fragment ustawień.
	 * @param string $klucz   Klucz.
	 * @return string|null
	 */
	private static function tekst( array $tablica, $klucz ) {
		if ( ! isset( $tablica[ $klucz ] ) || ! is_string( $tablica[ $klucz ] ) ) {
			return null;
		}
		$w = trim( $tablica[ $klucz ] );
		return '' === $w ? null : $w;
	}

	/**
	 * Slug kroju na deklarację `font-family`.
	 *
	 * Oxygen zapisuje SLUG (`gfont-inter`), nie nazwę rodziny — wstawienie slugu wprost
	 * do CSS daje `font-family: gfont-inter`, czyli krój systemowy i baner, który nie
	 * pasuje do niczego. Zmierzone: `Breakdance\Fonts\process_font('gfont-inter')`
	 * zwraca `"Inter",sans-serif`, a dla nieznanego slugu oddaje go bez zmian.
	 * Gdy Oxygena nie ma, składamy nazwę z slugu sami — bez rodziny zapasowej, bo
	 * tej ze slugu nie da się odczytać (`gfont-fraunces` to serif, `gfont-inter` nie).
	 *
	 * @param array  $typo  Sekcja `typography`.
	 * @param string $klucz `body_font` albo `heading_font`.
	 * @return string|null
	 */
	private static function kroj( array $typo, $klucz ) {
		$slug = self::tekst( $typo, $klucz );
		if ( null === $slug ) {
			return null;
		}
		if ( function_exists( '\\Breakdance\\Fonts\\process_font' ) ) {
			$rodzina = \Breakdance\Fonts\process_font( $slug );
			if ( is_string( $rodzina ) && '' !== trim( $rodzina ) ) {
				return trim( $rodzina );
			}
		}
		if ( 0 === strpos( $slug, 'gfont-' ) ) {
			return '"' . ucfirst( substr( $slug, 6 ) ) . '"';
		}
		return $slug;
	}

	/**
	 * Wartość wymiarowa Oxygena na CSS.
	 *
	 * Kształt zmierzony: `{"number":18,"unit":"px","style":null}`. Pole `style` niesie
	 * gotową wartość dla trybów, w których liczba nic nie znaczy (np. `auto`), więc ma
	 * pierwszeństwo. Brak `number` to nie zero — to ustawienie niewypełnione.
	 *
	 * @param mixed $w Wpis wymiarowy.
	 * @return string|null
	 */
	private static function miara( $w ) {
		if ( ! is_array( $w ) ) {
			return null;
		}
		if ( isset( $w['style'] ) && is_string( $w['style'] ) && '' !== trim( $w['style'] ) ) {
			return trim( $w['style'] );
		}
		if ( ! isset( $w['number'] ) || '' === $w['number'] || null === $w['number'] ) {
			return null;
		}
		if ( ! is_numeric( $w['number'] ) ) {
			return null;
		}
		$jednostka = isset( $w['unit'] ) && is_string( $w['unit'] ) ? trim( $w['unit'] ) : 'px';
		return rtrim( rtrim( sprintf( '%.4F', (float) $w['number'] ), '0' ), '.' ) . $jednostka;
	}

	/**
	 * Lista cieni Oxygena na `box-shadow`.
	 *
	 * Kształt zmierzony: `{"shadows":[{"color":..,"x":"0px","y":"8px","blur":"24px",
	 * "spread":"0px","position":null}]}`.
	 *
	 * @param mixed $w Wpis cienia dla breakpointu.
	 * @return string|null
	 */
	private static function cien( $w ) {
		if ( ! is_array( $w ) || empty( $w['shadows'] ) || ! is_array( $w['shadows'] ) ) {
			return null;
		}
		$czesci = array();
		foreach ( $w['shadows'] as $c ) {
			if ( ! is_array( $c ) ) {
				continue;
			}
			$pola = array();
			if ( ! empty( $c['position'] ) && 'inset' === strtolower( (string) $c['position'] ) ) {
				$pola[] = 'inset';
			}
			foreach ( array( 'x', 'y', 'blur', 'spread' ) as $k ) {
				$pola[] = ( isset( $c[ $k ] ) && '' !== $c[ $k ] && null !== $c[ $k ] ) ? (string) $c[ $k ] : '0';
			}
			if ( ! empty( $c['color'] ) && is_string( $c['color'] ) ) {
				$pola[] = trim( $c['color'] );
			}
			$czesci[] = implode( ' ', $pola );
		}
		return empty( $czesci ) ? null : implode( ', ', $czesci );
	}

	/* ---------- Kontrast ---------- */

	/**
	 * Kolor CSS na trójkę RGB 0-255. Rozumie `#rgb`, `#rrggbb`, `rgb()` i `rgba()`.
	 * Wszystko inne — `var(--x)`, `currentColor`, nazwy — zwraca null, bo bez znajomości
	 * reszty arkusza nie da się tego policzyć, a zgadnięty pomiar jest gorszy niż brak.
	 *
	 * @param string $kolor Wartość CSS.
	 * @return array|null
	 */
	public static function rgb( $kolor ) {
		$k = strtolower( trim( (string) $kolor ) );

		if ( preg_match( '/^#([0-9a-f]{3})$/', $k, $m ) ) {
			return array(
				hexdec( str_repeat( $m[1][0], 2 ) ),
				hexdec( str_repeat( $m[1][1], 2 ) ),
				hexdec( str_repeat( $m[1][2], 2 ) ),
			);
		}
		if ( preg_match( '/^#([0-9a-f]{6})$/', $k, $m ) ) {
			return array(
				hexdec( substr( $m[1], 0, 2 ) ),
				hexdec( substr( $m[1], 2, 2 ) ),
				hexdec( substr( $m[1], 4, 2 ) ),
			);
		}
		if ( preg_match( '/^rgba?\(\s*([0-9.]+)[\s,]+([0-9.]+)[\s,]+([0-9.]+)/', $k, $m ) ) {
			return array( (float) $m[1], (float) $m[2], (float) $m[3] );
		}
		return null;
	}

	/**
	 * Stosunek kontrastu dwóch kolorów wg WCAG 2.1. Null, gdy któregoś nie da się zmierzyć.
	 *
	 * @param string $a Pierwszy kolor.
	 * @param string $b Drugi kolor.
	 * @return float|null
	 */
	public static function kontrast( $a, $b ) {
		$ra = self::rgb( $a );
		$rb = self::rgb( $b );
		if ( null === $ra || null === $rb ) {
			return null;
		}
		$la = self::luminancja( $ra );
		$lb = self::luminancja( $rb );
		$j  = max( $la, $lb );
		$c  = min( $la, $lb );
		return ( $j + 0.05 ) / ( $c + 0.05 );
	}

	/**
	 * @param array $rgb Trójka 0-255.
	 * @return float
	 */
	private static function luminancja( array $rgb ) {
		$k = array();
		foreach ( $rgb as $c ) {
			$c   = max( 0.0, min( 1.0, (float) $c / 255 ) );
			$k[] = ( $c <= 0.04045 ) ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
		}
		return 0.2126 * $k[0] + 0.7152 * $k[1] + 0.0722 * $k[2];
	}

	/**
	 * Sprawdza dwie pary, które decydują o tym, czy baner da się przeczytać:
	 * tekst na tle panelu i napis przycisku na przycisku.
	 *
	 * Para mierzalna i poniżej progu wraca w `odrzucone` — obie jej zmienne zostają
	 * wtedy wyrzucone z importu. Para niemierzalna wraca tylko jako ostrzeżenie:
	 * odrzucenie wartości, której nie umiemy policzyć, kasowałoby poprawny import
	 * za to, że użyto w nim `var()`.
	 *
	 * @param array $z Mapa `--nc-*` => wartość.
	 * @return array{ostrzezenia:array,odrzucone:array,pomiary:array}
	 */
	public static function sprawdz_kontrast( array $z ) {
		$pary = array(
			array(
				'opis' => 'tekst banera na tle panelu',
				'tlo'  => '--nc-bg',
				'ink'  => '--nc-text',
				'zap'  => array( '#ffffff', '#16161f' ),
			),
			array(
				'opis' => 'napis przycisku na przycisku',
				'tlo'  => '--nc-primary',
				'ink'  => '--nc-primary-ink',
				'zap'  => array( '#16161f', '#ffffff' ),
			),
		);

		$ostrzezenia = array();
		$odrzucone   = array();
		$pomiary     = array();

		foreach ( $pary as $p ) {
			// Gdy import daje tylko jedną połowę pary, druga zostaje na wartości
			// zapasowej z arkusza — i to ją trzeba zmierzyć, bo to ona się wyświetli.
			$tlo = isset( $z[ $p['tlo'] ] ) ? $z[ $p['tlo'] ] : $p['zap'][0];
			$ink = isset( $z[ $p['ink'] ] ) ? $z[ $p['ink'] ] : $p['zap'][1];

			if ( ! isset( $z[ $p['tlo'] ] ) && ! isset( $z[ $p['ink'] ] ) ) {
				continue; // Nic z tej pary nie pochodzi z importu — nie ma czego oceniać.
			}

			$r = self::kontrast( $tlo, $ink );

			if ( null === $r ) {
				$ostrzezenia[] = sprintf(
					'Nie dało się zmierzyć kontrastu (%s): %s na %s. Sprawdź baner okiem.',
					$p['opis'],
					$ink,
					$tlo
				);
				continue;
			}

			$pomiary[ $p['opis'] ] = round( $r, 2 );

			if ( $r < self::KONTRAST_MIN ) {
				$ostrzezenia[] = sprintf(
					'Za niski kontrast (%s): %s na %s daje %s:1, a potrzeba %s:1. Te dwa kolory NIE zostały zaimportowane — baner używa dla nich wartości domyślnych, żeby dało się go przeczytać.',
					$p['opis'],
					$ink,
					$tlo,
					number_format_i18n( round( $r, 2 ), 2 ),
					number_format_i18n( self::KONTRAST_MIN, 1 )
				);
				$odrzucone[] = $p['tlo'];
				$odrzucone[] = $p['ink'];
			}
		}

		return array(
			'ostrzezenia' => $ostrzezenia,
			'odrzucone'   => array_values( array_unique( $odrzucone ) ),
			'pomiary'     => $pomiary,
		);
	}

	/* ---------- Skąd pochodzi wygląd ---------- */

	/**
	 * @return array{zrodlo:string,kiedy:string,ostrzezenia:array,pomiary:array}
	 */
	public static function zrodlo() {
		$z = get_option( self::OPCJA_ZRODLO, array() );
		$z = is_array( $z ) ? $z : array();
		return array(
			'zrodlo'      => isset( $z['zrodlo'] ) && self::czy_zaimportowane() ? (string) $z['zrodlo'] : 'domyslne',
			'kiedy'       => isset( $z['kiedy'] ) ? (string) $z['kiedy'] : '',
			'ostrzezenia' => isset( $z['ostrzezenia'] ) && is_array( $z['ostrzezenia'] ) ? $z['ostrzezenia'] : array(),
			'pomiary'     => isset( $z['pomiary'] ) && is_array( $z['pomiary'] ) ? $z['pomiary'] : array(),
		);
	}

	/**
	 * Kolejność źródeł, jawnie, w kolejności pierwszeństwa. Klient ma widzieć, skąd
	 * bierze się jego wygląd — „skądś" jest tu gorsze niż „nie wiem".
	 *
	 * @return string
	 */
	public static function opis_zrodla() {
		$z = self::zrodlo();
		switch ( $z['zrodlo'] ) {
			case 'design-system':
				return 'design-system.json projektu';
			case 'oxygen':
				return 'ustawienia globalne Oxygena';
			default:
				return 'domyślne wtyczki';
		}
	}

	/**
	 * @return bool
	 */
	public static function czy_zaimportowane() {
		$t = get_option( self::OPCJA, array() );
		return is_array( $t ) && ! empty( $t );
	}

	/**
	 * @return array
	 */
	public static function pobierz() {
		$t = get_option( self::OPCJA, array() );
		return is_array( $t ) ? $t : array();
	}

	/**
	 * Blok `:root` z zaimportowanymi zmiennymi. Pusty string, gdy importu nie było —
	 * wtedy działają wartości domyślne z arkusza banera.
	 *
	 * @return string
	 */
	public static function css() {
		$t = self::pobierz();
		if ( empty( $t ) ) {
			return '';
		}
		$linie = array();
		foreach ( $t as $zmienna => $wartosc ) {
			if ( ! preg_match( '/^--nc-[a-z0-9-]+$/', $zmienna ) ) {
				continue;
			}
			$linie[] = $zmienna . ':' . $wartosc;
		}
		if ( empty( $linie ) ) {
			return '';
		}
		return ':root{' . implode( ';', $linie ) . '}';
	}

	/* ---------- Ręczne tokeny (ability ustaw-tokeny) ---------- */

	/**
	 * Scala pojedyncze tokeny z bieżącymi, zamiast podmieniać cały zestaw jak import.
	 *
	 * Po co: import z design-system.json zna kilkanaście zmiennych (kolory, kroje, odstępy).
	 * Projekt oddawany 1:1 potrzebuje też kształtu: obrysu przycisków, ich kolejności,
	 * linku w zdaniu, odstępu od krawędzi ekranu. Te wartości nie mają źródła w żadnym
	 * pliku tokenów, więc przychodzą tędy, pojedynczo.
	 *
	 * Wartość null albo pusty string usuwa token (wraca wartość zapasowa z arkusza).
	 *
	 * @param array $wspolne    Mapa `--nc-*` => wartość dla wszystkich ekranów.
	 * @param array $urzadzenia {"mobile":{...},"desktop":{...}}.
	 * @return array
	 */
	public static function ustaw( array $wspolne, array $urzadzenia = array() ) {
		$odrzucone = array();

		$scal = static function ( array $obecne, array $zmiany ) use ( &$odrzucone ) {
			foreach ( $zmiany as $zmienna => $wartosc ) {
				if ( ! is_string( $zmienna ) || ! preg_match( '/^--nc-[a-z0-9-]+$/', $zmienna ) ) {
					$odrzucone[] = (string) $zmienna;
					continue;
				}
				if ( null === $wartosc || ( is_string( $wartosc ) && '' === trim( $wartosc ) ) ) {
					unset( $obecne[ $zmienna ] );
					continue;
				}
				if ( is_array( $wartosc ) || is_bool( $wartosc ) ) {
					$odrzucone[] = $zmienna;
					continue;
				}
				$czysta = self::oczysc( (string) $wartosc );
				if ( '' === $czysta ) {
					$odrzucone[] = $zmienna;
					continue;
				}
				$obecne[ $zmienna ] = $czysta;
			}
			return $obecne;
		};

		$tokeny = $scal( self::pobierz(), $wspolne );

		$per = self::pobierz_urzadzenia();
		foreach ( array( 'mobile', 'desktop' ) as $u ) {
			if ( isset( $urzadzenia[ $u ] ) && is_array( $urzadzenia[ $u ] ) ) {
				$per[ $u ] = $scal( isset( $per[ $u ] ) ? $per[ $u ] : array(), $urzadzenia[ $u ] );
			}
		}

		/*
		 * Kontrast mierzymy na tym, co się wyświetli na danym ekranie: wspólne plus nadpisania
		 * urządzenia. Tu tylko ostrzegamy, nie odrzucamy: to ręczna, świadoma zmiana,
		 * a odrzucenie po cichu wyglądałoby jak zapis, który nie zadziałał.
		 */
		$ostrzezenia = array();
		$pomiary     = array();
		foreach ( array( 'mobile', 'desktop' ) as $u ) {
			$k = self::sprawdz_kontrast( array_merge( $tokeny, isset( $per[ $u ] ) ? $per[ $u ] : array() ) );
			foreach ( $k['ostrzezenia'] as $o ) {
				$ostrzezenia[] = $u . ': ' . $o;
			}
			$pomiary[ $u ] = $k['pomiary'];
		}

		update_option( self::OPCJA, $tokeny, false );
		update_option( self::OPCJA_URZADZENIA, $per, false );

		return array(
			'ok'          => true,
			'tokeny'      => $tokeny,
			'urzadzenia'  => $per,
			'odrzucone'   => array_values( array_unique( $odrzucone ) ),
			'ostrzezenia' => array_values( array_unique( $ostrzezenia ) ),
			'pomiary'     => $pomiary,
			'uwaga'       => 'Tokeny wspólne nadpisze kolejny import z design-system.json albo z Oxygena; tokeny urządzeń zostają.',
		);
	}

	/**
	 * @return array
	 */
	public static function pobierz_urzadzenia() {
		$t = get_option( self::OPCJA_URZADZENIA, array() );
		return is_array( $t ) ? $t : array();
	}

	/**
	 * Bloki @media z tokenami urządzeń. Drukowane PO css_wygladu(), więc wygrywają
	 * z układem z panelu, tak jak ręczna poprawka wygrywa z ustawieniem domyślnym.
	 *
	 * @return string
	 */
	public static function css_urzadzen() {
		$per       = self::pobierz_urzadzenia();
		$css       = '';
		$zapytania = array(
			'mobile'  => '@media (max-width:' . ( self::BREAKPOINT - 0.0625 ) . 'rem)',
			'desktop' => '@media (min-width:' . self::BREAKPOINT . 'rem)',
		);
		foreach ( $zapytania as $u => $zapytanie ) {
			if ( empty( $per[ $u ] ) || ! is_array( $per[ $u ] ) ) {
				continue;
			}
			$linie = array();
			foreach ( $per[ $u ] as $zmienna => $wartosc ) {
				if ( preg_match( '/^--nc-[a-z0-9-]+$/', $zmienna ) ) {
					$linie[] = $zmienna . ':' . $wartosc;
				}
			}
			if ( $linie ) {
				$css .= $zapytanie . '{:root{' . implode( ';', $linie ) . '}}';
			}
		}
		return $css;
	}

	/* ---------- Układ: osobno telefon, osobno desktop ---------- */

	/** Jedyny breakpoint banera. Ta sama liczba stoi w `assets/baner.css`. */
	const BREAKPOINT = 48;

	/**
	 * Pozycja na ekranie => para właściwości flexboxa kontenera banera.
	 * Kontener rozciąga się na cały ekran (`inset:0`) i nie łapie kliknięć, więc
	 * o miejscu panelu decydują wyłącznie te dwie wartości. Dzięki temu pięć pozycji
	 * kosztuje dwie zmienne, a nie pięć zestawów reguł.
	 *
	 * @return array
	 */
	public static function pozycje() {
		return array(
			'dol'       => array( 'nazwa' => 'Na dole ekranu', 'align' => 'flex-end', 'just' => 'center' ),
			'gora'      => array( 'nazwa' => 'Na górze ekranu', 'align' => 'flex-start', 'just' => 'center' ),
			'srodek'    => array( 'nazwa' => 'Na środku ekranu', 'align' => 'center', 'just' => 'center' ),
			'lewy-dol'  => array( 'nazwa' => 'W lewym dolnym rogu', 'align' => 'flex-end', 'just' => 'flex-start' ),
			'prawy-dol' => array( 'nazwa' => 'W prawym dolnym rogu', 'align' => 'flex-end', 'just' => 'flex-end' ),
		);
	}

	/**
	 * Nazwane rozmiary tekstu. `auto` celowo nie ma wartości — nie nadpisuje importu.
	 *
	 * @return array
	 */
	public static function rozmiary() {
		return array(
			'auto'     => array( 'nazwa' => 'Jak w projekcie (z importu)', 'fs' => '' ),
			'mniejszy' => array( 'nazwa' => 'Mniejszy', 'fs' => '0.9375rem' ),
			'normalny' => array( 'nazwa' => 'Normalny', 'fs' => '1rem' ),
			'wiekszy'  => array( 'nazwa' => 'Większy', 'fs' => '1.125rem' ),
		);
	}

	/**
	 * Dwa bloki `@media` ze zmiennymi układu — telefon i desktop, rozdzielone jednym
	 * breakpointem. Blok telefonu kończy się 0.0625rem przed progiem, żeby przy
	 * szerokości dokładnie równej progowi obowiązywał dokładnie jeden z nich.
	 *
	 * @param array $wyglad Sekcja `wyglad` z ustawień.
	 * @return string
	 */
	public static function css_wygladu( $wyglad ) {
		$wyglad = is_array( $wyglad ) ? $wyglad : array();
		$css    = '';

		$zapytania = array(
			'mobile'  => '@media (max-width:' . ( self::BREAKPOINT - 0.0625 ) . 'rem)',
			'desktop' => '@media (min-width:' . self::BREAKPOINT . 'rem)',
		);

		foreach ( $zapytania as $urzadzenie => $zapytanie ) {
			$u = isset( $wyglad[ $urzadzenie ] ) && is_array( $wyglad[ $urzadzenie ] ) ? $wyglad[ $urzadzenie ] : array();

			$pozycje = self::pozycje();
			$klucz   = isset( $u['pozycja'] ) && isset( $pozycje[ $u['pozycja'] ] ) ? $u['pozycja'] : 'dol';
			$p       = $pozycje[ $klucz ];

			// Szerokość jest liczbą rem, nie tekstem CSS — klient nie wpisze tu `calc()`.
			$szerokosc = isset( $u['szerokosc'] ) ? (float) $u['szerokosc'] : 46;
			$szerokosc = max( 16, min( 100, $szerokosc ) );

			$linie = array(
				'--nc-align:' . $p['align'],
				'--nc-just:' . $p['just'],
				'--nc-szer:' . rtrim( rtrim( sprintf( '%.4F', $szerokosc ), '0' ), '.' ) . 'rem',
			);

			$rozmiary = self::rozmiary();
			$r        = isset( $u['tekst'] ) && isset( $rozmiary[ $u['tekst'] ] ) ? $rozmiary[ $u['tekst'] ] : $rozmiary['auto'];
			if ( '' !== $r['fs'] ) {
				$linie[] = '--nc-fs:' . $r['fs'];
			}

			$css .= $zapytanie . '{:root{' . implode( ';', $linie ) . '}}';
		}

		return $css;
	}
}
