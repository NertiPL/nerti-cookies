<?php
/**
 * Blokada ciasteczek ustawianych po stronie serwera — nagłówek `Set-Cookie`.
 *
 * @package NertiCookies
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tu siedzi defekt, którego nie łapie żaden baner blokujący tylko `<script>`: wtyczka
 * „Meta Pixel for WordPress" woła `setcookie( '_fbp', … )` w PHP, zanim wyjdzie pierwszy bajt
 * HTML-u. Ciasteczko przychodzi w nagłówku odpowiedzi, więc skrypt `fbq` może być zablokowany
 * idealnie, a `_fbp` i tak ląduje w przeglądarce nowego gościa. Zmierzone 2026-09-26 na
 * produkcji z Complianzem: `set-cookie: _fbp=fb.1…; Max-Age=7776000` w pierwszej odpowiedzi.
 *
 * Mechanizm: `header_register_callback()` — PHP woła go tuż przed wysłaniem nagłówków,
 * po tym, jak cały kod strony już się wykonał. Czytamy `headers_list()`, wyrzucamy
 * `Set-Cookie` kategorii bez zgody, resztę oddajemy bez zmian. Działa dla każdej odpowiedzi
 * PHP: stron, `admin-ajax.php` (sygnały Meta idą właśnie tamtędy) i REST.
 *
 * Zgodę czytamy z ciasteczka decyzji w żądaniu. Strona podana z cache'a nie przechodzi
 * przez PHP, więc nie ma tu czego filtrować — a cache nigdy nie zapisuje odpowiedzi
 * z nagłówkiem, który właśnie usunęliśmy.
 *
 * Czego NIE robi: ciasteczek ustawianych przez JavaScript — te pilnuje blokada skryptów
 * i kasowanie w banerze. Ciasteczek nieznanych ani bibliotece, ani ustawieniom — te
 * przechodzą, a skaner pokazuje je jako „do rozstrzygnięcia". Blokowanie nieznanego
 * rozbiłoby logowanie albo koszyk, których nazw nie przewidzieliśmy.
 */
class Nerti_Cookies_Naglowki {

	const OPCJA = 'nerti_cookies_naglowki';

	/** @var array<string,string> nazwa => kategoria, usunięte w tym żądaniu */
	private static $usuniete = array();

	/** @var bool */
	private static $przefiltrowane = false;

	public static function hooks() {
		if ( ! apply_filters( 'nerti_cookies_filtruj_naglowki', true ) ) {
			return;
		}
		if ( function_exists( 'header_register_callback' ) ) {
			header_register_callback( array( __CLASS__, 'filtruj' ) );
		}
		/*
		 * Zapasowo: PHP trzyma tylko JEDEN callback nagłówków. Jeśli inna wtyczka zarejestruje
		 * swój po nas, nasz przepada bez śladu. Dlatego filtrujemy też w ostatniej chwili,
		 * w której nagłówki na pewno jeszcze nie wyszły dla stron z bufora blokady.
		 */
		add_action( 'nerti_cookies_przed_wyslaniem', array( __CLASS__, 'filtruj' ) );
		add_action( 'shutdown', array( __CLASS__, 'zapisz_slad' ), 0 );
	}

	/**
	 * Czy kategoria jest dozwolona dla tego żądania — po decyzji zapisanej w ciasteczku.
	 *
	 * @param string $kategoria Kategoria.
	 * @return bool
	 */
	public static function dozwolona( $kategoria ) {
		$u = Nerti_Cookies_Ustawienia::pobierz();
		if ( '' === $kategoria || ! isset( $u['kategorie'][ $kategoria ] ) || ! empty( $u['kategorie'][ $kategoria ]['wymagana'] ) ) {
			return true;
		}
		$zgoda = Nerti_Cookies_Blokada::zgoda_z_ciasteczka();
		return ! empty( $zgoda[ $kategoria ] );
	}

	/**
	 * Callback nagłówków.
	 */
	public static function filtruj() {
		if ( self::$przefiltrowane || headers_sent() ) {
			return;
		}
		self::$przefiltrowane = true;

		$zostaja = array();
		$zmiana  = false;
		foreach ( headers_list() as $linia ) {
			if ( 0 !== stripos( $linia, 'Set-Cookie:' ) ) {
				continue;
			}
			$wartosc = ltrim( substr( $linia, strlen( 'Set-Cookie:' ) ) );
			$nazwa   = trim( (string) strtok( $wartosc, '=' ) );
			$info    = Nerti_Cookies_Uslugi::ciasteczko( $nazwa );
			// Ciasteczko kasujące (wygasłe) zawsze przepuszczamy — to sprzątanie, nie śledzenie.
			$kasujace = (bool) preg_match( '/;\s*(max-age=0|expires=Thu, 01[ -]Jan[ -]1970)/i', $wartosc ) || (bool) preg_match( '/^[^=]+=(deleted)?;/i', $wartosc );
			if ( $kasujace || self::dozwolona( $info['kategoria'] ) ) {
				$zostaja[] = $linia;
				continue;
			}
			self::$usuniete[ $nazwa ] = $info['kategoria'];
			$zmiana                   = true;
		}

		// Ciasteczka, które gość już ma, a na które nie ma zgody (np. sprzed instalacji wtyczki
		// albo HttpOnly, których JavaScript banera nie widzi) — kasujemy je nagłówkiem.
		$kasuj = self::do_skasowania();
		if ( ! $zmiana && ! $kasuj ) {
			return;
		}
		header_remove( 'Set-Cookie' );
		foreach ( $zostaja as $linia ) {
			header( $linia, false );
		}
		foreach ( $kasuj as $nazwa ) {
			foreach ( self::domeny() as $domena ) {
				header( 'Set-Cookie: ' . rawurlencode( $nazwa ) . '=deleted; Max-Age=0; Path=/' . ( '' !== $domena ? '; Domain=' . $domena : '' ), false );
			}
		}
	}

	/**
	 * @return array<int,string>
	 */
	private static function do_skasowania() {
		$out = array();
		foreach ( array_keys( (array) $_COOKIE ) as $nazwa ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$nazwa = (string) $nazwa;
			$info  = Nerti_Cookies_Uslugi::ciasteczko( $nazwa );
			if ( '' !== $info['kategoria'] && ! self::dozwolona( $info['kategoria'] ) ) {
				$out[] = $nazwa;
			}
		}
		return array_slice( $out, 0, 20 );
	}

	/**
	 * Warianty domeny, na których mogło zostać ustawione ciasteczko: bez atrybutu,
	 * dokładny host i każda domena nadrzędna (`.przyklad.pl`). Ciasteczka z `domain=`
	 * nie da się skasować bez podania tej samej domeny.
	 *
	 * @return array<int,string>
	 */
	private static function domeny() {
		$host   = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$czesci = explode( '.', $host );
		$lista  = array( '', $host );
		while ( count( $czesci ) > 1 ) {
			$lista[] = '.' . implode( '.', $czesci );
			array_shift( $czesci );
		}
		return array_values( array_unique( $lista ) );
	}

	/**
	 * Ślad dla pulpitu i skanera: jakie ciasteczka serwer próbował ustawić bez zgody.
	 * Zapis tylko przy nowej nazwie albo raz na godzinę — nie przy każdym żądaniu.
	 */
	public static function zapisz_slad() {
		if ( ! self::$usuniete ) {
			return;
		}
		$slad   = get_option( self::OPCJA, array() );
		$slad   = is_array( $slad ) ? $slad : array();
		$teraz  = time();
		$zmiana = false;
		foreach ( self::$usuniete as $nazwa => $kat ) {
			$byl = isset( $slad[ $nazwa ] ) ? $slad[ $nazwa ] : null;
			if ( ! $byl || $teraz - (int) $byl['ostatnio'] > HOUR_IN_SECONDS ) {
				$slad[ $nazwa ] = array(
					'kategoria' => $kat,
					'usluga'    => Nerti_Cookies_Uslugi::ciasteczko( $nazwa )['usluga'],
					'ostatnio'  => $teraz,
					'pierwszy'  => $byl ? (int) $byl['pierwszy'] : $teraz,
					'adres'     => isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '', // phpcs:ignore
				);
				$zmiana = true;
			}
		}
		if ( $zmiana ) {
			update_option( self::OPCJA, array_slice( $slad, -50, null, true ), false );
		}
	}

	/**
	 * @return array<string,array>
	 */
	public static function slad() {
		$slad = get_option( self::OPCJA, array() );
		return is_array( $slad ) ? $slad : array();
	}
}
