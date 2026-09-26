<?php
/**
 * Lista ciasteczek do polityki — shortcode `[nerti_cookies_lista]`.
 *
 * @package NertiCookies
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tabela budowana z WYNIKU SKANU, nie z szablonu: w polityce są tylko narzędzia, które
 * ta strona naprawdę ładuje (inventory.md: „policy before inventory is a template with the
 * company name swapped"). Opis, odbiorca i czas życia pochodzą z biblioteki usług.
 *
 * Czego nie ma w tabeli: pozycji „nieznane". Nieznane ciasteczko w polityce to zdanie
 * „nie wiemy, co to jest" podpisane przez klienta — takie pozycje wiszą na pulpicie jako
 * zadanie, a administrator widzi pod tabelą ostrzeżenie.
 *
 * Użycie: `[nerti_cookies_lista]` na podstronie polityki cookies. W Oxygenie/Breakdance —
 * element „Shortcode". Atrybut `kategorie="analityczne,marketingowe"` zawęża tabelę.
 */
class Nerti_Cookies_Lista {

	public static function hooks() {
		add_shortcode( 'nerti_cookies_lista', array( __CLASS__, 'shortcode' ) );
	}

	/**
	 * Pozycje listy, pogrupowane po kategorii.
	 *
	 * @return array<string,array<string,array>> kategoria => usługa => wiersz
	 */
	public static function dane() {
		$uslugi = Nerti_Cookies_Uslugi::wszystkie();
		$skan   = Nerti_Cookies_Skaner::wynik();
		$u      = Nerti_Cookies_Ustawienia::pobierz();
		$grupy  = array();
		foreach ( array_keys( $u['kategorie'] ) as $k ) {
			$grupy[ $k ] = array();
		}

		$dodaj = static function ( $id_uslugi, $nazwa_ciasteczka = '' ) use ( &$grupy, $uslugi ) {
			if ( ! isset( $uslugi[ $id_uslugi ] ) ) {
				return;
			}
			$usl = $uslugi[ $id_uslugi ];
			$kat = (string) $usl['kategoria'];
			if ( ! isset( $grupy[ $kat ] ) ) {
				return;
			}
			if ( ! isset( $grupy[ $kat ][ $id_uslugi ] ) ) {
				$grupy[ $kat ][ $id_uslugi ] = array(
					'nazwa'      => $usl['nazwa'],
					'cel'        => $usl['cel'],
					'odbiorca'   => $usl['odbiorca'],
					'ciasteczka' => array(),
				);
			}
			// Nazwy zmierzone w skanie mają pierwszeństwo; bez pomiaru — wzorce z biblioteki.
			if ( '' !== $nazwa_ciasteczka ) {
				$info = Nerti_Cookies_Uslugi::ciasteczko( $nazwa_ciasteczka );
				$grupy[ $kat ][ $id_uslugi ]['ciasteczka'][ $nazwa_ciasteczka ] = '' !== $info['czas'] ? $info['czas'] : '—';
			}
		};

		// Zawsze: ciasteczko decyzji. Strona z banerem je ustawia, więc ono JEST w użyciu.
		$dodaj( 'nerti-cookies', NERTI_COOKIES_CIASTECZKO );

		foreach ( (array) ( $skan['pozycje'] ?? array() ) as $poz ) {
			if ( '' === (string) $poz['usluga'] || 'nieznane' === $poz['status'] ) {
				continue;
			}
			$dodaj( (string) $poz['usluga'], 'ciasteczko' === $poz['typ'] ? (string) $poz['klucz'] : '' );
		}

		// Usługa wykryta po znaczniku, bez zmierzonego ciasteczka (np. skan tylko z serwera):
		// pokazujemy ciasteczka z biblioteki, z dopiskiem, że to wartości dostawcy.
		foreach ( $grupy as $kat => &$wiersze ) {
			foreach ( $wiersze as $id => &$w ) {
				if ( ! $w['ciasteczka'] ) {
					$w['ciasteczka'] = $uslugi[ $id ]['ciasteczka'];
					$w['z_biblioteki'] = true;
				}
			}
			unset( $w );
		}
		unset( $wiersze );
		return $grupy;
	}

	/**
	 * @param array|string $atrybuty Atrybuty.
	 * @return string
	 */
	public static function shortcode( $atrybuty ) {
		$a     = shortcode_atts( array( 'kategorie' => '' ), $atrybuty, 'nerti_cookies_lista' );
		$tylko = array_filter( array_map( 'trim', explode( ',', (string) $a['kategorie'] ) ) );
		$u     = Nerti_Cookies_Ustawienia::pobierz();
		$skan  = Nerti_Cookies_Skaner::wynik();
		$out   = '<div class="nc-lista">';

		foreach ( self::dane() as $kat => $wiersze ) {
			if ( ( $tylko && ! in_array( $kat, $tylko, true ) ) || ! $wiersze || ! empty( $u['kategorie'][ $kat ]['ukryta'] ) ) {
				continue;
			}
			$out .= '<h3 class="nc-lista__kategoria">' . esc_html( $u['kategorie'][ $kat ]['nazwa'] ) . '</h3>';
			$out .= '<p class="nc-lista__opis">' . esc_html( $u['kategorie'][ $kat ]['opis'] ) . '</p>';
			$out .= '<table class="nc-lista__tabela"><thead><tr><th scope="col">Narzędzie</th><th scope="col">Po co</th><th scope="col">Ciasteczka i czas przechowywania</th><th scope="col">Kto otrzymuje dane</th></tr></thead><tbody>';
			foreach ( $wiersze as $w ) {
				$c = array();
				foreach ( $w['ciasteczka'] as $nazwa => $czas ) {
					$c[] = '<code>' . esc_html( (string) $nazwa ) . '</code> — ' . esc_html( (string) $czas );
				}
				$out .= '<tr><td>' . esc_html( $w['nazwa'] ) . '</td><td>' . esc_html( $w['cel'] ) . '</td><td>' . ( $c ? implode( '<br>', $c ) : 'nie zapisuje ciasteczek' ) . '</td><td>' . esc_html( $w['odbiorca'] ) . '</td></tr>';
			}
			$out .= '</tbody></table>';
		}

		if ( ! empty( $skan['czas'] ) ) {
			$out .= '<p class="nc-lista__stan">Stan na ' . esc_html( wp_date( 'j.m.Y', (int) $skan['czas'] ) ) . '. Zgodę możesz zmienić w każdej chwili: ' . Nerti_Cookies_Baner::shortcode( array() ) . '</p>';
		}

		// Tylko dla administratora: czego lista NIE zawiera i dlaczego.
		if ( current_user_can( 'manage_options' ) ) {
			$nieznane = array_filter( (array) ( $skan['pozycje'] ?? array() ), static function ( $p ) { return 'nieznane' === $p['status']; } );
			if ( empty( $skan['czas'] ) ) {
				$out .= '<p class="nc-lista__admin"><strong>Widzi to tylko administrator:</strong> strona nie była jeszcze skanowana, więc tabela zawiera tylko ciasteczko zgody. Ciasteczka → Skanuj stronę.</p>';
			} elseif ( $nieznane ) {
				$out .= '<p class="nc-lista__admin"><strong>Widzi to tylko administrator:</strong> skan znalazł ' . count( $nieznane ) . ' pozycji bez rozpoznanego narzędzia. Nie ma ich w tabeli, dopóki nie przypiszesz im kategorii (Ciasteczka → Skanuj stronę).</p>';
			}
		}
		return $out . '</div>';
	}
}
