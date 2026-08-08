<?php
/**
 * Ustawienia wtyczki — jedno źródło prawdy dla banera, blokady i rejestru.
 *
 * @package NertiCookies
 */

defined( 'ABSPATH' ) || exit;

/**
 * Przechowuje konfigurację w jednej opcji. Jedna opcja zamiast dwudziestu, bo zmiana
 * ustawień przez MCP musi być jedną operacją atomową, nie serią zapisów, z których
 * połowa może się nie udać.
 */
class Nerti_Cookies_Ustawienia {

	const OPCJA = 'nerti_cookies_ustawienia';

	/** @var array|null Bufor w pamięci na czas żądania. */
	private static $bufor = null;

	public static function hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_nerti_cookies_zapisz', array( __CLASS__, 'zapisz_z_panelu' ) );
	}

	/**
	 * Domyślne. Kategorie są zamknięte i celowo trzy — każda kolejna kategoria to kolejny
	 * przełącznik, którego użytkownik nie przeczyta, a od czterech w górę baner zaczyna
	 * wyglądać na sposób zniechęcenia do odmowy.
	 *
	 * @return array
	 */
	public static function domyslne() {
		return array(
			'wersja_polityki'  => '1.0',
			'wersja_banera'    => NERTI_COOKIES_WERSJA,
			'waznosc_dni'      => 182,
			'pozycja'          => 'dol',
			'consent_mode'     => true,
			'bufor_wyjscia'    => true,
			'link_polityka'    => '',
			'link_cookies'     => '',
			'administrator'    => '',

			'kategorie'        => array(
				'niezbedne'   => array(
					'nazwa'      => 'Niezbędne',
					'opis'       => 'Potrzebne, żeby strona działała: sesja, koszyk, zapamiętanie Twojej decyzji o ciasteczkach. Bez nich strona nie zadziała, więc nie da się ich wyłączyć.',
					'wymagana'   => true,
					'domyslnie'  => true,
					'ciasteczka' => array( NERTI_COOKIES_CIASTECZKO, 'PHPSESSID', 'wordpress_*', 'wp-settings-*' ),
				),
				'analityczne' => array(
					'nazwa'      => 'Analityczne',
					'opis'       => 'Liczą odwiedziny i pokazują, które podstrony są czytane. Pomagają nam poprawiać stronę. Nie wykorzystujemy ich do wyświetlania Ci reklam.',
					'wymagana'   => false,
					'domyslnie'  => false,
					'ciasteczka' => array( '_ga', '_ga_*', '_gid', '_gat*', '_clck', '_clsk', '_hjSession*', '_hjIncludedInSessionSample*' ),
				),
				'marketingowe' => array(
					'nazwa'      => 'Marketingowe',
					'opis'       => 'Pozwalają pokazywać Ci nasze reklamy poza tą stroną i mierzyć ich skuteczność. Dane trafiają do dostawców reklam.',
					'wymagana'   => false,
					'domyslnie'  => false,
					'ciasteczka' => array( '_fbp', '_fbc', '_gcl_au', '_gcl_aw', 'IDE', 'test_cookie' ),
				),
			),

			/*
			 * Mapa uchwytów WordPressa na kategorie. Działa na skryptach dodanych przez
			 * wp_enqueue_script — to jest tania i pewna warstwa blokady.
			 */
			'uchwyty'          => array(
				'google-analytics'         => 'analityczne',
				'google-tag-manager'       => 'analityczne',
				'googlesitekit-consent-mode' => 'analityczne',
				'facebook-pixel'           => 'marketingowe',
				'hotjar'                   => 'analityczne',
				'clarity'                  => 'analityczne',
			),

			/*
			 * Wzorce adresów i treści skryptów. Druga warstwa blokady, dla znaczników
			 * wklejonych na sztywno w motyw, w widget albo przez wtyczkę, która nie używa
			 * wp_enqueue_script. To jest warstwa, która realnie ratuje wdrożenie —
			 * większość pikseli w polskich instalacjach jest wklejona ręcznie.
			 */
			'wzorce'           => array(
				'googletagmanager.com'  => 'analityczne',
				'google-analytics.com'  => 'analityczne',
				'analytics.google.com'  => 'analityczne',
				'static.hotjar.com'     => 'analityczne',
				'clarity.ms'            => 'analityczne',
				'connect.facebook.net'  => 'marketingowe',
				'facebook.com/tr'       => 'marketingowe',
				'doubleclick.net'       => 'marketingowe',
				'googleadservices.com'  => 'marketingowe',
				'ads.linkedin.com'      => 'marketingowe',
				'snap.licdn.com'        => 'marketingowe',
				'analytics.tiktok.com'  => 'marketingowe',
				'gtag('                 => 'analityczne',
				'fbq('                  => 'marketingowe',
			),

			/* Osadzenia zamieniane na zastępnik z przyciskiem. */
			'osadzenia'        => array(
				'youtube.com/embed'      => 'marketingowe',
				'youtube-nocookie.com'   => 'marketingowe',
				'player.vimeo.com'       => 'marketingowe',
				'google.com/maps/embed'  => 'marketingowe',
				'open.spotify.com/embed' => 'marketingowe',
			),

			'teksty'           => array(
				'tytul'        => 'Ciasteczka',
				'tresc'        => 'Używamy ciasteczek niezbędnych do działania strony. Na pozostałe — analityczne i marketingowe — potrzebujemy Twojej zgody. Możesz ją wycofać w każdej chwili.',
				'akceptuj'     => 'Akceptuję wszystkie',
				'odrzuc'       => 'Odrzucam wszystkie',
				'ustawienia'   => 'Wybieram sam',
				'zapisz'       => 'Zapisz mój wybór',
				'polityka'     => 'Polityka prywatności',
				'cookies'      => 'Polityka cookies',
				'wycofaj'      => 'Zmień zgodę na ciasteczka',
				'osadzenie'    => 'Ten materiał pochodzi z serwisu zewnętrznego i ustawia własne ciasteczka. Pokaż go, jeśli się na to zgadzasz.',
				'osadzenie_btn' => 'Pokaż materiał',
			),
		);
	}

	/**
	 * @return array
	 */
	public static function pobierz() {
		if ( null !== self::$bufor ) {
			return self::$bufor;
		}
		$zapisane   = get_option( self::OPCJA, array() );
		$zapisane   = is_array( $zapisane ) ? $zapisane : array();
		self::$bufor = array_replace_recursive( self::domyslne(), $zapisane );
		return self::$bufor;
	}

	/**
	 * @param string $klucz   Klucz najwyższego poziomu.
	 * @param mixed  $domyslna Wartość zwracana, gdy klucza nie ma.
	 * @return mixed
	 */
	public static function pole( $klucz, $domyslna = null ) {
		$u = self::pobierz();
		return array_key_exists( $klucz, $u ) ? $u[ $klucz ] : $domyslna;
	}

	/**
	 * Zapis częściowy. Scala z bieżącym stanem, żeby MCP mógł zmienić jedno pole
	 * bez znajomości całej reszty.
	 *
	 * @param array $nowe Fragment konfiguracji.
	 * @return array Stan po zapisie.
	 */
	public static function zapisz( array $nowe ) {
		$biezace = get_option( self::OPCJA, array() );
		$biezace = is_array( $biezace ) ? $biezace : array();
		$scalone = array_replace_recursive( $biezace, $nowe );
		update_option( self::OPCJA, $scalone, false );
		self::$bufor = null;
		return self::pobierz();
	}

	public static function zasiej_domyslne() {
		if ( false === get_option( self::OPCJA, false ) ) {
			update_option( self::OPCJA, self::domyslne(), false );
		}
	}

	/**
	 * Podniesienie wersji polityki unieważnia WSZYSTKIE dotychczasowe zgody i pokazuje
	 * baner ponownie.
	 *
	 * Powód, dla którego to jest osobna operacja, a nie efekt uboczny zapisu ustawień:
	 * zmiana treści polityki bez odpytania ludzi o zgodę na nowo znaczy, że rejestr
	 * dowodzi zgody na dokument, którego oni nie widzieli.
	 *
	 * @param string $wersja Nowa wersja, np. "1.1".
	 * @return string
	 */
	public static function podnies_wersje_polityki( $wersja = '' ) {
		$u      = self::pobierz();
		$biezaca = (string) $u['wersja_polityki'];
		if ( '' === $wersja ) {
			$czesci = explode( '.', $biezaca );
			$ostatni = (int) array_pop( $czesci );
			$czesci[] = (string) ( $ostatni + 1 );
			$wersja  = implode( '.', $czesci );
		}
		self::zapisz( array( 'wersja_polityki' => sanitize_text_field( $wersja ) ) );
		return $wersja;
	}

	/**
	 * Konfiguracja przekazywana do przeglądarki. Świadomie wąska — nie wysyłamy stąd
	 * niczego, czego skrypt banera nie potrzebuje.
	 *
	 * @return array
	 */
	public static function dla_przegladarki() {
		$u = self::pobierz();
		$k = array();
		foreach ( $u['kategorie'] as $id => $kat ) {
			$k[] = array(
				'id'         => $id,
				'nazwa'      => $kat['nazwa'],
				'opis'       => $kat['opis'],
				'wymagana'   => (bool) $kat['wymagana'],
				'domyslnie'  => (bool) $kat['domyslnie'],
				'ciasteczka' => array_values( (array) $kat['ciasteczka'] ),
			);
		}
		return array(
			'ciasteczko'      => NERTI_COOKIES_CIASTECZKO,
			'wersja_polityki' => (string) $u['wersja_polityki'],
			'waznosc_dni'     => (int) $u['waznosc_dni'],
			'kategorie'       => $k,
			'consentMode'     => (bool) $u['consent_mode'],
			'rest'            => esc_url_raw( rest_url( 'nerti-cookies/v1/zgoda' ) ),
			'nonce'           => wp_create_nonce( 'wp_rest' ),
			'teksty'          => $u['teksty'],
		);
	}

	/* ---------- Panel administracyjny ---------- */

	public static function menu() {
		add_options_page(
			'Nerti Cookies',
			'Nerti Cookies',
			'manage_options',
			'nerti-cookies',
			array( __CLASS__, 'ekran' )
		);
	}

	public static function ekran() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$u     = self::pobierz();
		$zgody = Nerti_Cookies_Rejestr::statystyki();
		?>
		<div class="wrap">
			<h1>Nerti Cookies</h1>
			<p><strong>To nie jest narzędzie do orzekania o zgodności z prawem.</strong>
			Wtyczka blokuje skrypty przed zgodą i zapisuje decyzje. Treść polityki
			zatwierdza właściciel strony albo jego prawnik.</p>

			<h2>Stan</h2>
			<table class="widefat striped" style="max-width:52rem">
				<tbody>
				<tr><td>Wersja polityki</td><td><code><?php echo esc_html( $u['wersja_polityki'] ); ?></code></td></tr>
				<tr><td>Zapisanych decyzji</td><td><?php echo esc_html( (string) $zgody['razem'] ); ?></td></tr>
				<tr><td>w tym odmów</td><td><?php echo esc_html( (string) $zgody['odmowy'] ); ?></td></tr>
				<tr><td>Blokada przez bufor wyjścia</td><td><?php echo $u['bufor_wyjscia'] ? 'włączona' : '<strong>wyłączona</strong>'; ?></td></tr>
				<tr><td>Consent Mode v2</td><td><?php echo $u['consent_mode'] ? 'włączony' : 'wyłączony'; ?></td></tr>
				<tr><td>Tokeny wyglądu</td><td><?php echo Nerti_Cookies_Tokeny::czy_zaimportowane() ? 'z design-system.json' : 'domyślne wtyczki'; ?></td></tr>
				</tbody>
			</table>

			<h2>Zmiana</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="nerti_cookies_zapisz">
				<?php wp_nonce_field( 'nerti_cookies_zapisz' ); ?>
				<table class="form-table">
					<tr>
						<th><label for="nc-polityka">Adres polityki prywatności</label></th>
						<td><input id="nc-polityka" class="regular-text" type="url" name="link_polityka" value="<?php echo esc_attr( $u['link_polityka'] ); ?>"></td>
					</tr>
					<tr>
						<th><label for="nc-cookies">Adres polityki cookies</label></th>
						<td><input id="nc-cookies" class="regular-text" type="url" name="link_cookies" value="<?php echo esc_attr( $u['link_cookies'] ); ?>"></td>
					</tr>
					<tr>
						<th><label for="nc-waznosc">Ważność decyzji (dni)</label></th>
						<td><input id="nc-waznosc" type="number" min="1" max="365" name="waznosc_dni" value="<?php echo esc_attr( (string) $u['waznosc_dni'] ); ?>">
						<p class="description">Po tym czasie pytamy ponownie. Powyżej roku zgoda przestaje być odświeżana i to jest problem, nie oszczędność.</p></td>
					</tr>
					<tr>
						<th>Blokada przez bufor wyjścia</th>
						<td><label><input type="checkbox" name="bufor_wyjscia" value="1" <?php checked( (bool) $u['bufor_wyjscia'] ); ?>> łap też skrypty wklejone na sztywno w motyw</label></td>
					</tr>
				</table>
				<?php submit_button( 'Zapisz' ); ?>
			</form>

			<h2>Rejestr zgód</h2>
			<p>Eksport całego rejestru do pliku CSV. Potrzebny wtedy, gdy ktoś kwestionuje zgodę — a wtedy nikt nie ma czasu na zapytanie SQL.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="nerti_cookies_eksport">
				<?php wp_nonce_field( 'nerti_cookies_eksport' ); ?>
				<?php submit_button( 'Pobierz CSV', 'secondary', 'submit', false ); ?>
			</form>

			<h2>Nowa wersja polityki</h2>
			<p>Podniesienie wersji <strong>unieważnia wszystkie dotychczasowe zgody</strong> i pokazuje baner od nowa. Robisz to, gdy zmieniła się treść polityki albo lista narzędzi na stronie.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="nerti_cookies_zapisz">
				<input type="hidden" name="podnies_wersje" value="1">
				<?php wp_nonce_field( 'nerti_cookies_zapisz' ); ?>
				<?php submit_button( 'Podnieś wersję polityki', 'secondary' ); ?>
			</form>
		</div>
		<?php
	}

	public static function zapisz_z_panelu() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Brak uprawnień.' );
		}
		check_admin_referer( 'nerti_cookies_zapisz' );

		if ( isset( $_POST['podnies_wersje'] ) ) {
			self::podnies_wersje_polityki();
		} else {
			$nowe = array(
				'link_polityka' => isset( $_POST['link_polityka'] ) ? esc_url_raw( wp_unslash( $_POST['link_polityka'] ) ) : '',
				'link_cookies'  => isset( $_POST['link_cookies'] ) ? esc_url_raw( wp_unslash( $_POST['link_cookies'] ) ) : '',
				'waznosc_dni'   => isset( $_POST['waznosc_dni'] ) ? max( 1, min( 365, (int) $_POST['waznosc_dni'] ) ) : 182,
				'bufor_wyjscia' => isset( $_POST['bufor_wyjscia'] ),
			);
			self::zapisz( $nowe );
		}

		wp_safe_redirect( admin_url( 'options-general.php?page=nerti-cookies' ) );
		exit;
	}
}
