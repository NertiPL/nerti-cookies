<?php
/**
 * Pulpit i ekran skanera — to, co klient widzi jako pierwsze po wejściu w „Ciasteczka".
 *
 * @package NertiCookies
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pulpit odpowiada na trzy pytania klienta, w tej kolejności: czy to działa, co jest
 * blokowane, ile osób się zgadza. Bez żargonu: „czeka na zgodę", nie „type=text/plain".
 *
 * Każde „działa" na pulpicie pochodzi z pomiaru (skan, rejestr, ustawienia), nie z tego,
 * że przełącznik jest włączony. Włączona blokada nie jest wynikiem testu.
 */
class Nerti_Cookies_Pulpit {

	const STATUSY = array(
		'blokowane' => array( 'Czeka na zgodę', 'nc-status--ok' ),
		'zawsze'    => array( 'Działa zawsze (niezbędne)', 'nc-status--info' ),
		'BLAD'      => array( 'Działa PRZED zgodą — do naprawy', 'nc-status--blad' ),
		'nieznane'  => array( 'Nie rozpoznano — przypisz kategorię', 'nc-status--uwaga' ),
	);

	const TYPY = array(
		'skrypt'     => 'skrypt',
		'osadzenie'  => 'osadzony materiał',
		'piksel'     => 'piksel',
		'ciasteczko' => 'ciasteczko',
		'pamiec'     => 'pamięć przeglądarki',
		'host'       => 'połączenie z obcą domeną',
	);

	public static function hooks() {
		add_action( 'admin_post_nerti_cookies_przypisz', array( __CLASS__, 'obsluz_przypisanie' ) );
		add_action( 'admin_post_nerti_cookies_skanuj', array( __CLASS__, 'obsluz_skan_bez_js' ) );
	}

	/**
	 * Lista kontroli: [czy_ok, poziom, tekst, gdzie_naprawic].
	 *
	 * @return array<int,array>
	 */
	/**
	 * Adresy jako ścieżki, najwyżej cztery — pełna lista dziesięciu adresów to ściana tekstu.
	 *
	 * @param array $adresy Adresy.
	 * @return string
	 */
	private static function sciezki( array $adresy, $ile = 4 ) {
		$sc  = array_map( static function ( $a ) { return (string) wp_parse_url( $a, PHP_URL_PATH ); }, $adresy );
		$out = implode( ', ', array_slice( $sc, 0, $ile ) );
		return count( $sc ) > $ile ? $out . sprintf( ' i %d innych', count( $sc ) - $ile ) : $out;
	}

	public static function kontrole() {
		$u      = Nerti_Cookies_Ustawienia::pobierz();
		$skan   = Nerti_Cookies_Skaner::podsumowanie();
		$zgody  = Nerti_Cookies_Rejestr::statystyki();
		$k      = array();

		if ( ! $skan['jest'] ) {
			$k[] = array( false, 'blad', 'Strona nie była jeszcze skanowana — nie wiadomo, co ładuje.', 'skan' );
		} else {
			$dni = (int) floor( ( time() - $skan['czas'] ) / DAY_IN_SECONDS );
			$k[] = array( $dni <= 30, 'uwaga', 0 === $dni ? 'Skan z dzisiaj.' : sprintf( 'Ostatni skan %d dni temu.', $dni ) . ( $dni > 30 ? ' Zeskanuj ponownie — nowe wtyczki mogły dołożyć narzędzia.' : '' ), 'skan' );
			$k[] = array( 0 === $skan['licz']['BLAD'], 'blad', 0 === $skan['licz']['BLAD'] ? 'Żadne narzędzie śledzące nie działa przed zgodą.' : sprintf( '%d narzędzi działa PRZED zgodą.', $skan['licz']['BLAD'] ), 'skan' );
			$k[] = array( ! $skan['bez_banera'], 'blad', ! $skan['bez_banera'] ? sprintf( 'Baner jest na każdej sprawdzonej podstronie (%d).', $skan['stron'] ) : 'Brak banera na: ' . self::sciezki( $skan['bez_banera'] ), 'skan' );
			$k[] = array( ! $skan['bez_wycofania'], 'blad', ! $skan['bez_wycofania'] ? 'Wejście do zmiany zgody jest na każdej sprawdzonej podstronie.' : 'Brak wejścia do zmiany zgody na: ' . self::sciezki( $skan['bez_wycofania'] ) . ( 'strona' === $u['wycofanie'] ? ' (ustawienie „wejście w projekcie strony" — strona go nie ma).' : '' ), 'stan' );
			$k[] = array( 0 === $skan['licz']['nieznane'], 'uwaga', 0 === $skan['licz']['nieznane'] ? 'Wszystko, co znalazł skan, jest rozpoznane.' : sprintf( '%d pozycji nierozpoznanych — przypisz im kategorię.', $skan['licz']['nieznane'] ), 'skan' );
			$k[] = array( $skan['przegladarka'] > 0, 'uwaga', $skan['przegladarka'] > 0 ? 'Skan objął też ciasteczka ustawiane w przeglądarce.' : 'Skan był tylko po stronie serwera — ciasteczek ustawianych przez skrypty nie widać. Uruchom skan z panelu.', 'skan' );
		}
		$k[] = array( '' !== $u['link_polityka'], 'blad', '' !== $u['link_polityka'] ? 'Baner linkuje do polityki prywatności.' : 'Brak adresu polityki prywatności — baner nie ma do czego odesłać.', 'stan' );
		$k[] = array( ! empty( $u['bufor_wyjscia'] ), 'blad', ! empty( $u['bufor_wyjscia'] ) ? 'Blokada łapie też skrypty wklejone w motyw.' : 'Blokada skryptów wklejonych w motyw jest WYŁĄCZONA.', 'stan' );
		if ( $zgody['razem'] >= 20 ) {
			$k[] = array( $zgody['odmowy'] > 0, 'blad', $zgody['odmowy'] > 0 ? 'Rejestr zapisuje odmowy.' : 'Rejestr ma zgody, ale zero odmów — to zwykle znaczy, że odmowa się nie zapisuje.', 'rejestr' );
		}
		return $k;
	}

	public static function sekcja_pulpit() {
		$kontrole = self::kontrole();
		$bledy    = count( array_filter( $kontrole, static function ( $x ) { return ! $x[0] && 'blad' === $x[1]; } ) );
		$uwagi    = count( array_filter( $kontrole, static function ( $x ) { return ! $x[0] && 'uwaga' === $x[1]; } ) );
		$okres    = Nerti_Cookies_Rejestr::statystyki_okres( 30 );
		$skan     = Nerti_Cookies_Skaner::wynik();
		$akcja    = esc_url( admin_url( 'admin-post.php' ) );
		$blokowane = array();
		$zawsze    = array();
		foreach ( (array) ( $skan['pozycje'] ?? array() ) as $p ) {
			if ( 'blokowane' === $p['status'] ) {
				$blokowane[ $p['nazwa'] ] = true;
			} elseif ( 'zawsze' === $p['status'] ) {
				$zawsze[ $p['nazwa'] ] = true;
			}
		}
		?>
		<section id="nc-pulpit" class="nc-sekcja" data-nc-panel="pulpit">
			<h2>Pulpit</h2>
			<div class="nc-werdykt <?php echo $bledy ? 'nc-werdykt--blad' : ( $uwagi ? 'nc-werdykt--uwaga' : 'nc-werdykt--ok' ); ?>">
				<strong><?php echo $bledy ? esc_html( sprintf( 'Wymaga naprawy: %d', $bledy ) ) : ( $uwagi ? 'Działa, są uwagi' : 'Ochrona działa' ); ?></strong>
				<span><?php echo $bledy ? 'Poniżej, co jest nie tak i gdzie to poprawić.' : 'Wynik z ostatniego skanu i z rejestru zgód, nie z samych ustawień.'; ?></span>
			</div>

			<ul class="nc-kontrole">
				<?php foreach ( $kontrole as $x ) : ?>
					<li class="<?php echo $x[0] ? 'nc-kontrola--ok' : ( 'blad' === $x[1] ? 'nc-kontrola--blad' : 'nc-kontrola--uwaga' ); ?>">
						<span class="nc-kontrola__znak" aria-hidden="true"><?php echo $x[0] ? '✓' : '!'; ?></span>
						<span><?php echo esc_html( $x[2] ); ?>
						<?php if ( ! $x[0] ) : ?> <a href="#nc-<?php echo esc_attr( $x[3] ); ?>" data-nc-idz="<?php echo esc_attr( $x[3] ); ?>">Przejdź</a><?php endif; ?></span>
					</li>
				<?php endforeach; ?>
			</ul>

			<h3>Co jest blokowane do zgody</h3>
			<p><?php echo $blokowane ? esc_html( implode( ', ', array_keys( $blokowane ) ) ) : '<em>Skan nie znalazł narzędzi, które wymagają zgody.</em>'; // phpcs:ignore ?></p>
			<h3>Co działa zawsze</h3>
			<p><?php echo $zawsze ? esc_html( implode( ', ', array_keys( $zawsze ) ) ) : '—'; ?></p>

			<h3>Decyzje odwiedzających — ostatnie 30 dni</h3>
			<div class="nc-liczby">
				<div><strong><?php echo esc_html( (string) $okres['razem'] ); ?></strong><span>decyzji</span></div>
				<div><strong><?php echo esc_html( (string) $okres['wszystko'] ); ?></strong><span>zgoda na wszystko</span></div>
				<div><strong><?php echo esc_html( (string) $okres['czesciowe'] ); ?></strong><span>zgoda częściowa</span></div>
				<div><strong><?php echo esc_html( (string) $okres['odmowy'] ); ?></strong><span>odmowa (<?php echo esc_html( (string) $okres['procent_odmow'] ); ?>%)</span></div>
			</div>

			<div class="nc-akcje-pulpitu">
				<form method="post" action="<?php echo $akcja; // phpcs:ignore ?>">
					<input type="hidden" name="action" value="nerti_cookies_eksport">
					<?php wp_nonce_field( 'nerti_cookies_eksport' ); ?>
					<?php submit_button( 'Pobierz historię zgód (CSV)', 'secondary', 'submit', false ); ?>
				</form>
				<form method="post" action="<?php echo $akcja; // phpcs:ignore ?>" onsubmit="return confirm('Wszyscy odwiedzający zobaczą baner ponownie, a dotychczasowe zgody przestaną obowiązywać. Kontynuować?');">
					<input type="hidden" name="action" value="nerti_cookies_zapisz">
					<input type="hidden" name="podnies_wersje" value="1">
					<?php wp_nonce_field( 'nerti_cookies_zapisz' ); ?>
					<?php submit_button( 'Zapytaj wszystkich ponownie', 'secondary', 'submit', false ); ?>
				</form>
			</div>
			<p class="description">„Zapytaj wszystkich ponownie" robisz po zmianie polityki albo po dodaniu nowego narzędzia na stronę. Zgoda na starą wersję nie jest zgodą na nową.</p>

			<h3>Współpraca z innymi wtyczkami</h3>
			<ul class="nc-panel__lista">
				<?php foreach ( Nerti_Cookies_Integracje::wykryte() as $i ) : ?>
					<?php if ( $i['aktywna'] ) : ?><li><strong><?php echo esc_html( $i['nazwa'] ); ?></strong> — <?php echo esc_html( $i['jak'] ); ?></li><?php endif; ?>
				<?php endforeach; ?>
				<?php $cache = Nerti_Cookies_Cache::wykryte(); ?>
				<?php if ( $cache ) : ?><li><strong><?php echo esc_html( implode( ', ', $cache ) ); ?></strong> — baner i blokada wyłączone z opóźniania i łączenia skryptów; strona z cache jest ta sama dla każdego.</li><?php endif; ?>
			</ul>

			<?php $slad = Nerti_Cookies_Naglowki::slad(); ?>
			<?php if ( $slad ) : ?>
				<h3>Ciasteczka zatrzymane na serwerze</h3>
				<p class="description">Te ciasteczka próbowała ustawić wtyczka po stronie serwera, zanim ktokolwiek się zgodził. Wtyczka usunęła je z odpowiedzi.</p>
				<table class="widefat striped nc-tabela"><thead><tr><th>Ciasteczko</th><th>Narzędzie</th><th>Ostatnio</th></tr></thead><tbody>
				<?php foreach ( $slad as $nazwa => $s ) : ?>
					<tr><td><code><?php echo esc_html( (string) $nazwa ); ?></code></td><td><?php echo esc_html( '' !== $s['usluga'] && isset( Nerti_Cookies_Uslugi::wszystkie()[ $s['usluga'] ] ) ? Nerti_Cookies_Uslugi::wszystkie()[ $s['usluga'] ]['nazwa'] : '—' ); ?></td><td><?php echo esc_html( wp_date( 'j.m.Y H:i', (int) $s['ostatnio'] ) ); ?></td></tr>
				<?php endforeach; ?>
				</tbody></table>
			<?php endif; ?>
		</section>
		<?php
	}

	public static function sekcja_skan() {
		$skan   = Nerti_Cookies_Skaner::wynik();
		$u      = Nerti_Cookies_Ustawienia::pobierz();
		$akcja  = esc_url( admin_url( 'admin-post.php' ) );
		$pozycje = (array) ( $skan['pozycje'] ?? array() );
		$waga   = array( 'BLAD' => 0, 'nieznane' => 1, 'blokowane' => 2, 'zawsze' => 3 );
		uasort( $pozycje, static function ( $a, $b ) use ( $waga ) { return $waga[ $a['status'] ] - $waga[ $b['status'] ] ?: strcmp( $a['nazwa'], $b['nazwa'] ); } );
		?>
		<section id="nc-skan" class="nc-sekcja" data-nc-panel="skan">
			<h2>Skanuj stronę</h2>
			<p>Skaner otwiera podstrony tak, jak zobaczy je nowy odwiedzający, i sprawdza, co się na nich ładuje. Potem otwiera je jeszcze raz w ukrytej ramce z pełną zgodą, żeby zobaczyć ciasteczka, które zapisują skrypty. Trwa to około 10 sekund na podstronę.</p>
			<p>
				<button type="button" class="button button-primary" id="nc-skaner" data-nc-rest="<?php echo esc_url( rest_url( 'nerti-cookies/v1/' ) ); ?>" data-nc-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>">Skanuj stronę</button>
				<span id="nc-skaner-postep" class="nc-skaner__postep" aria-live="polite"></span>
			</p>
			<noscript>
				<form method="post" action="<?php echo $akcja; // phpcs:ignore ?>"><input type="hidden" name="action" value="nerti_cookies_skanuj"><?php wp_nonce_field( 'nerti_cookies_skanuj' ); ?><?php submit_button( 'Skanuj (tylko serwer)', 'secondary', 'submit', false ); ?></form>
			</noscript>
			<div id="nc-skaner-ramki" class="nc-skaner__ramki" aria-hidden="true"></div>

			<?php if ( ! $pozycje ) : ?>
				<p><em>Nie ma jeszcze wyniku skanu.</em></p>
			<?php else : ?>
				<p class="description">Skan z <?php echo esc_html( wp_date( 'j.m.Y H:i', (int) $skan['czas'] ) ); ?>, podstron: <?php echo esc_html( (string) count( (array) $skan['strony'] ) ); ?>.</p>
				<table class="widefat striped nc-tabela nc-skan">
					<thead><tr><th>Co to jest</th><th>Rodzaj</th><th>Kategoria</th><th>Status</th><th>Gdzie</th></tr></thead>
					<tbody>
					<?php foreach ( $pozycje as $p ) : ?>
						<?php $st = self::STATUSY[ $p['status'] ]; ?>
						<tr>
							<td><strong><?php echo esc_html( $p['nazwa'] ); ?></strong><?php if ( $p['nazwa'] !== $p['klucz'] && 'ciasteczko' === $p['typ'] ) : ?> <code><?php echo esc_html( $p['klucz'] ); ?></code><?php endif; ?><br><span class="description"><?php echo esc_html( $p['opis'] ); ?></span></td>
							<td><?php echo esc_html( self::TYPY[ $p['typ'] ] ?? $p['typ'] ); ?></td>
							<td><?php echo esc_html( '' !== $p['kategoria'] && isset( $u['kategorie'][ $p['kategoria'] ] ) ? $u['kategorie'][ $p['kategoria'] ]['nazwa'] : '—' ); ?>
								<?php if ( 'nieznane' === $p['status'] ) : ?>
									<form method="post" action="<?php echo $akcja; // phpcs:ignore ?>" class="nc-przypisz">
										<input type="hidden" name="action" value="nerti_cookies_przypisz">
										<input type="hidden" name="typ" value="<?php echo esc_attr( $p['typ'] ); ?>">
										<input type="hidden" name="klucz" value="<?php echo esc_attr( $p['klucz'] ); ?>">
										<?php wp_nonce_field( 'nerti_cookies_przypisz' ); ?>
										<label class="screen-reader-text" for="nc-przyp-<?php echo esc_attr( md5( $p['typ'] . $p['klucz'] ) ); ?>">Kategoria</label>
										<select name="kategoria" id="nc-przyp-<?php echo esc_attr( md5( $p['typ'] . $p['klucz'] ) ); ?>">
											<?php foreach ( $u['kategorie'] as $id => $kat ) : ?>
												<option value="<?php echo esc_attr( $id ); ?>" <?php selected( 'marketingowe', $id ); ?>><?php echo esc_html( $kat['nazwa'] ); ?></option>
											<?php endforeach; ?>
										</select>
										<button type="submit" class="button button-small">Przypisz</button>
									</form>
								<?php endif; ?>
							</td>
							<td><span class="nc-status <?php echo esc_attr( $st[1] ); ?>"><?php echo esc_html( $st[0] ); ?></span></td>
							<td class="nc-skan__gdzie"><?php echo esc_html( self::sciezki( (array) $p['strony'], 2 ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description">„Niezbędne" wybieraj tylko wtedy, gdy strona bez tego narzędzia przestaje działać dla kogoś, kto o nic nie prosił. Wygoda właściciela strony to nie jest ten powód.</p>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Przypisanie kategorii nierozpoznanej pozycji. Ciasteczko trafia do listy kategorii,
	 * skrypt/osadzenie/host — do mapy wzorców jako domena. Domena, nie cały adres: adres
	 * z parametrami zmienia się przy każdej wersji skryptu i wzorzec przestałby działać.
	 */
	public static function obsluz_przypisanie() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Brak uprawnień.' );
		}
		check_admin_referer( 'nerti_cookies_przypisz' );
		$u     = Nerti_Cookies_Ustawienia::pobierz();
		$typ   = sanitize_key( wp_unslash( $_POST['typ'] ?? '' ) );
		$klucz = sanitize_text_field( wp_unslash( $_POST['klucz'] ?? '' ) );
		$kat   = sanitize_key( wp_unslash( $_POST['kategoria'] ?? '' ) );
		if ( '' === $klucz || ! isset( $u['kategorie'][ $kat ] ) ) {
			wp_die( 'Nieprawidłowe dane.' );
		}
		if ( in_array( $typ, array( 'ciasteczko', 'pamiec' ), true ) ) {
			$pole  = 'ciasteczko' === $typ ? 'ciasteczka' : 'storage';
			$lista = array_values( array_unique( array_merge( (array) ( $u['kategorie'][ $kat ][ $pole ] ?? array() ), array( $klucz ) ) ) );
			$wynik = Nerti_Cookies_Ustawienia::zapisz( array( 'kategorie' => array( $kat => array( $pole => $lista ) ) ) );
		} else {
			$host  = wp_parse_url( ( false === strpos( $klucz, '://' ) ? 'https://' : '' ) . $klucz, PHP_URL_HOST );
			$wzor  = $host ? (string) $host : $klucz;
			$mapa  = 'osadzenie' === $typ ? 'osadzenia' : 'wzorce';
			$wynik = Nerti_Cookies_Ustawienia::zapisz( array( $mapa => array( $wzor => $kat ) ) );
		}
		if ( is_wp_error( $wynik ) ) {
			wp_die( esc_html( $wynik->get_error_message() ) );
		}
		// Pozycja w zapisanym skanie dostaje nowy status od razu, bez ponownego skanowania.
		$skan = Nerti_Cookies_Skaner::wynik();
		$k    = $typ . '|' . $klucz;
		if ( isset( $skan['pozycje'][ $k ] ) ) {
			$skan['pozycje'][ $k ]['kategoria'] = $kat;
			$skan['pozycje'][ $k ]['status']    = 'niezbedne' === $kat ? 'zawsze' : 'blokowane';
			update_option( Nerti_Cookies_Skaner::OPCJA, $skan, false );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=nerti-cookies&nc-info=' . rawurlencode( 'Przypisano kategorię. Blokada działa od następnego wyświetlenia strony — wyczyść cache strony.' ) . '#nc-skan' ) );
		exit;
	}

	public static function obsluz_skan_bez_js() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Brak uprawnień.' );
		}
		check_admin_referer( 'nerti_cookies_skanuj' );
		Nerti_Cookies_Skaner::skanuj();
		wp_safe_redirect( admin_url( 'admin.php?page=nerti-cookies#nc-skan' ) );
		exit;
	}
}
