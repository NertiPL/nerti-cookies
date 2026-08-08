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

		update_option( self::OPCJA, $wynik, false );

		return array(
			'ok'    => true,
			'ile'   => count( $wynik ),
			'braki' => $braki,
			'blad'  => '',
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
}
