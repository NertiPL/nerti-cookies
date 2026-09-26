<?php
/**
 * Rejestr zgód — dowód, że zgoda była, kiedy była i na jaką wersję polityki.
 *
 * Bez wersji polityki rejestr nie jest dowodem: zapis „zgodził się 12.03" nic nie znaczy,
 * jeśli treść polityki zmieniła się w kwietniu i nikt nie wie, na co ten człowiek się zgodził.
 *
 * @package NertiCookies
 */

defined( 'ABSPATH' ) || exit;

class Nerti_Cookies_Rejestr {

	public static function hooks() {
		add_action( 'rest_api_init', array( __CLASS__, 'trasy' ) );
		add_action( 'admin_post_nerti_cookies_eksport', array( __CLASS__, 'eksport_csv' ) );
	}

	/**
	 * @return string
	 */
	public static function tabela() {
		global $wpdb;
		return $wpdb->prefix . 'nerti_cookies_zgody';
	}

	public static function utworz_tabele() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$tabela   = self::tabela();
		$kolacja  = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$tabela} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			zgoda_id CHAR(32) NOT NULL,
			utworzono DATETIME NOT NULL,
			wersja_polityki VARCHAR(20) NOT NULL,
			wersja_banera VARCHAR(20) NOT NULL,
			akcja VARCHAR(20) NOT NULL,
			kategorie VARCHAR(255) NOT NULL,
			odcisk CHAR(16) NOT NULL,
			przegladarka VARCHAR(255) NOT NULL,
			adres VARCHAR(255) NOT NULL,
			PRIMARY KEY (id),
			KEY zgoda_id (zgoda_id),
			KEY utworzono (utworzono)
		) {$kolacja};";

		dbDelta( $sql );
		update_option( 'nerti_cookies_db', NERTI_COOKIES_DB, false );
	}

	public static function trasy() {
		register_rest_route(
			'nerti-cookies/v1',
			'/zgoda',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_zapisz' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'zgoda_id'  => array( 'type' => 'string', 'required' => true ),
					'akcja'     => array( 'type' => 'string', 'required' => true ),
					'kategorie' => array( 'type' => 'object', 'required' => true ),
				),
			)
		);
	}

	/**
	 * Zapis jest publiczny, bo zgody udziela osoba niezalogowana. Chronimy go tym,
	 * czego naprawdę potrzeba: walidacją wejścia i brakiem jakiegokolwiek odczytu
	 * przez tę trasę. Odczyt rejestru wymaga `manage_options` i idzie inną drogą.
	 *
	 * @param WP_REST_Request $req Żądanie.
	 * @return WP_REST_Response
	 */
	public static function rest_zapisz( $req ) {
		// Limit: 20 zapisów na minutę z jednego adresu. Trasa jest publiczna (zgody udziela
		// niezalogowany gość), więc bez limitu każdy mógłby zasypać rejestr fałszywymi wpisami
		// i zamienić dowód w szum. Liczymy po skrócie IP, nie po samym IP.
		$klucz = 'nc_limit_' . self::odcisk();
		$ile   = (int) get_transient( $klucz );
		if ( $ile >= 20 ) {
			return new WP_REST_Response( array( 'ok' => false, 'blad' => 'limit' ), 429 );
		}
		set_transient( $klucz, $ile + 1, MINUTE_IN_SECONDS );

		$u          = Nerti_Cookies_Ustawienia::pobierz();
		$dozwolone  = array_keys( $u['kategorie'] );
		$kategorie  = (array) $req->get_param( 'kategorie' );
		$wybrane    = array();

		foreach ( $kategorie as $id => $stan ) {
			$id = sanitize_key( $id );
			if ( in_array( $id, $dozwolone, true ) && $stan ) {
				$wybrane[] = $id;
			}
		}

		$akcja = sanitize_key( (string) $req->get_param( 'akcja' ) );
		if ( ! in_array( $akcja, array( 'akceptuj', 'odrzuc', 'wybor', 'wycofaj' ), true ) ) {
			$akcja = 'wybor';
		}

		$id = self::zapisz(
			array(
				'zgoda_id'  => substr( preg_replace( '/[^a-f0-9]/i', '', (string) $req->get_param( 'zgoda_id' ) ), 0, 32 ),
				'akcja'     => $akcja,
				'kategorie' => $wybrane,
				'adres'     => (string) $req->get_param( 'adres' ),
			)
		);

		return new WP_REST_Response( array( 'ok' => (bool) $id ), $id ? 201 : 500 );
	}

	/**
	 * @param array $dane Dane wpisu.
	 * @return int Identyfikator wiersza albo 0.
	 */
	public static function zapisz( array $dane ) {
		global $wpdb;
		$u = Nerti_Cookies_Ustawienia::pobierz();

		$wynik = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::tabela(),
			array(
				'zgoda_id'        => isset( $dane['zgoda_id'] ) && '' !== $dane['zgoda_id'] ? $dane['zgoda_id'] : md5( uniqid( '', true ) ),
				'utworzono'       => current_time( 'mysql', true ),
				'wersja_polityki' => (string) $u['wersja_polityki'],
				'wersja_banera'   => (string) $u['wersja_banera'],
				'akcja'           => (string) $dane['akcja'],
				'kategorie'       => implode( ',', (array) $dane['kategorie'] ),
				'odcisk'          => self::odcisk(),
				'przegladarka'    => self::przegladarka(),
				'adres'           => esc_url_raw( isset( $dane['adres'] ) ? $dane['adres'] : '' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return $wynik ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Skrót adresu IP zamiast adresu IP.
	 *
	 * Rejestr zgód, który przechowuje surowe adresy IP, sam jest zbiorem danych osobowych
	 * gromadzonym bez podstawy — czyli narusza to, czego ma dowodzić. Skrót z solą instalacji
	 * wystarcza do wykazania, że zgody udzieliło osobne urządzenie, i nie da się z niego
	 * odtworzyć adresu.
	 *
	 * @return string
	 */
	private static function odcisk() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return substr( hash( 'sha256', $ip . wp_salt( 'auth' ) ), 0, 16 );
	}

	/**
	 * @return string
	 */
	private static function przegladarka() {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		return substr( $ua, 0, 255 );
	}

	/**
	 * Warunek SQL „to była odmowa": zapisane kategorie to nic albo same niezbędne.
	 * Odmowa to nie tylko przycisk „Odrzucam" — „Zapisz mój wybór" bez zaznaczonej kategorii
	 * też nią jest, a liczona jako „wybór" zaniżała liczbę odmów na pulpicie.
	 */
	const SQL_ODMOWA = "(kategorie = '' OR kategorie = 'niezbedne')";

	/**
	 * @return array {
	 *     @type int $razem
	 *     @type int $odmowy
	 *     @type string $ostatnia
	 * }
	 */
	public static function statystyki() {
		global $wpdb;
		$tabela = self::tabela();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$razem    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tabela}" );
		$odmowy   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tabela} WHERE " . self::SQL_ODMOWA );
		$ostatnia = (string) $wpdb->get_var( "SELECT utworzono FROM {$tabela} ORDER BY id DESC LIMIT 1" );
		// phpcs:enable

		return array(
			'razem'    => $razem,
			'odmowy'   => $odmowy,
			'ostatnia' => $ostatnia ? $ostatnia : '',
		);
	}

	/**
	 * Liczby z ostatnich dni — dla pulpitu. Trzy koszyki, które klient rozumie:
	 * zgoda na wszystko, odmowa, zgoda częściowa.
	 *
	 * @param int $dni Okres.
	 * @return array{dni:int,razem:int,wszystko:int,odmowy:int,czesciowe:int,procent_odmow:int}
	 */
	public static function statystyki_okres( $dni = 30 ) {
		global $wpdb;
		$tabela = self::tabela();
		$od     = gmdate( 'Y-m-d H:i:s', time() - (int) $dni * DAY_IN_SECONDS );
		$u      = Nerti_Cookies_Ustawienia::pobierz();
		$wszyst = array();
		foreach ( $u['kategorie'] as $id => $kat ) {
			if ( empty( $kat['ukryta'] ) ) {
				$wszyst[] = $id;
			}
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$razem    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tabela} WHERE utworzono >= %s", $od ) );
		$odmowy   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tabela} WHERE utworzono >= %s AND " . self::SQL_ODMOWA, $od ) );
		$wszystko = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tabela} WHERE utworzono >= %s AND (akcja = 'akceptuj' OR kategorie = %s)", $od, implode( ',', $wszyst ) ) );
		// phpcs:enable
		return array(
			'dni'           => (int) $dni,
			'razem'         => $razem,
			'wszystko'      => $wszystko,
			'odmowy'        => $odmowy,
			'czesciowe'     => max( 0, $razem - $wszystko - $odmowy ),
			'procent_odmow' => $razem ? (int) round( 100 * $odmowy / $razem ) : 0,
		);
	}

	/**
	 * Wiersze rejestru do eksportu — wspólne dla pliku CSV z panelu i ability `eksport-rejestru`.
	 *
	 * @param string $od    Data „od" (Y-m-d) albo ''.
	 * @param int    $limit Maksymalna liczba wierszy, 0 = wszystkie.
	 * @return array<int,array>
	 */
	public static function wiersze( $od = '', $limit = 0 ) {
		global $wpdb;
		$tabela = self::tabela();
		$sql    = "SELECT id, zgoda_id, utworzono, wersja_polityki, wersja_banera, akcja, kategorie, odcisk, przegladarka, adres FROM {$tabela}";
		if ( '' !== $od ) {
			$sql .= $wpdb->prepare( ' WHERE utworzono >= %s', $od . ' 00:00:00' );
		}
		$sql .= ' ORDER BY id ASC';
		if ( $limit > 0 ) {
			$sql .= $wpdb->prepare( ' LIMIT %d', $limit );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$wiersze = $wpdb->get_results( $sql, ARRAY_A );
		return is_array( $wiersze ) ? $wiersze : array();
	}

	/**
	 * Nagłówek CSV — jedna definicja dla panelu i MCP.
	 */
	const KOLUMNY_CSV = array( 'id', 'zgoda_id', 'utworzono_utc', 'wersja_polityki', 'wersja_banera', 'akcja', 'kategorie', 'odcisk', 'przegladarka', 'adres' );

	/**
	 * @param array $wiersze Wiersze.
	 * @return string CSV z BOM, żeby Excel otworzył polskie znaki bez kreatora importu.
	 */
	public static function do_csv( array $wiersze ) {
		$f = fopen( 'php://temp', 'w+' );
		fwrite( $f, "\xEF\xBB\xBF" );
		fputcsv( $f, self::KOLUMNY_CSV, ';' );
		foreach ( $wiersze as $w ) {
			fputcsv( $f, array_values( $w ), ';' );
		}
		rewind( $f );
		$csv = (string) stream_get_contents( $f );
		fclose( $f );
		return $csv;
	}

	/**
	 * Eksport całego rejestru do CSV. Potrzebny wtedy, gdy ktoś kwestionuje zgodę —
	 * i wtedy nikt nie ma czasu pisać zapytania SQL.
	 */
	public static function eksport_csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Brak uprawnień.' );
		}
		check_admin_referer( 'nerti_cookies_eksport' );

		$csv = self::do_csv( self::wiersze() );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=zgody-' . gmdate( 'Y-m-d' ) . '.csv' );
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}
}
