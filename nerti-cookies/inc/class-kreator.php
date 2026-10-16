<?php
/**
 * Kreator pierwszego uruchomienia — cztery kroki, po polsku, bez żargonu.
 *
 * @package NertiCookies
 */

defined( 'ABSPATH' ) || exit;

/**
 * Kolejność kroków jest kolejnością z inventory.md, nie kolejnością „od najłatwiejszego":
 *   1. dokumenty i administrator — bez nich baner nie ma do czego odesłać,
 *   2. skan — co strona naprawdę ładuje (bez tego blokada i polityka to zgadywanie),
 *   3. wygląd i wejście do zmiany zgody,
 *   4. sprawdzenie — kontrole z pulpitu, czyli wynik pomiaru, nie deklaracja.
 *
 * Kreator nie ma własnej logiki zapisu: każdy krok woła Nerti_Cookies_Ustawienia::zapisz()
 * (ta sama walidacja co panel, REST i MCP) albo skaner.
 */
class Nerti_Cookies_Kreator {

	const OPCJA = 'nerti_cookies_kreator';
	const SLUG  = 'nerti-cookies-kreator';

	public static function hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_notices', array( __CLASS__, 'przypomnienie' ) );
		add_action( 'admin_post_nerti_cookies_kreator', array( __CLASS__, 'zapisz' ) );
	}

	public static function ukonczony() {
		return (bool) get_option( self::OPCJA, false );
	}

	/**
	 * Oznacza kreator „Pierwsze uruchomienie" jako ukończony (znika przypomnienie w panelu).
	 * Wspólne dla ostatniego kroku kreatora i ability `zakoncz-kreator`, żeby agent, który
	 * skonfigurował wszystko przez MCP, nie zostawiał klientowi przypomnienia o 4 krokach.
	 */
	public static function zakoncz() {
		update_option( self::OPCJA, time(), false );
	}

	/**
	 * Otwiera kreator ponownie (przypomnienie wraca).
	 */
	public static function otworz() {
		delete_option( self::OPCJA );
	}

	public static function menu() {
		$strona = add_submenu_page( 'nerti-cookies', 'Pierwsze uruchomienie', 'Pierwsze uruchomienie', 'manage_options', self::SLUG, array( __CLASS__, 'ekran' ) );
		add_action( 'load-' . $strona, array( 'Nerti_Cookies_Ustawienia', 'zasoby_panelu' ) );
	}

	public static function przypomnienie() {
		if ( self::ukonczony() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$ekran = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $ekran && false !== strpos( (string) $ekran->id, self::SLUG ) ) {
			return;
		}
		echo '<div class="notice notice-info"><p><strong>Ciasteczka:</strong> baner działa, ale nie jest jeszcze skonfigurowany pod tę stronę. <a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">Skonfiguruj w 4 krokach</a></p></div>';
	}

	/**
	 * @return array<int,string>
	 */
	private static function kroki() {
		return array( 1 => 'Dokumenty', 2 => 'Co jest na stronie', 3 => 'Wygląd', 4 => 'Sprawdzenie' );
	}

	public static function ekran() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$krok = isset( $_GET['krok'] ) ? max( 1, min( 4, (int) $_GET['krok'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$u    = Nerti_Cookies_Ustawienia::pobierz();
		?>
		<div class="wrap nc-panel nc-kreator">
			<h1>Ciasteczka: pierwsze uruchomienie</h1>
			<ol class="nc-kreator__kroki">
				<?php foreach ( self::kroki() as $n => $nazwa ) : ?>
					<li class="<?php echo $n === $krok ? 'is-aktywny' : ( $n < $krok ? 'is-zrobiony' : '' ); ?>" <?php echo $n === $krok ? 'aria-current="step"' : ''; ?>>
						<span><?php echo esc_html( (string) $n ); ?></span> <?php echo esc_html( $nazwa ); ?>
					</li>
				<?php endforeach; ?>
			</ol>

			<div class="nc-sekcja nc-kreator__tresc">
			<?php
			switch ( $krok ) {
				case 1:
					self::krok_dokumenty( $u );
					break;
				case 2:
					self::krok_skan();
					break;
				case 3:
					self::krok_wyglad( $u );
					break;
				default:
					self::krok_sprawdzenie();
			}
			?>
			</div>
		</div>
		<?php
	}

	private static function naglowek_formularza( $krok ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="nerti_cookies_kreator"><input type="hidden" name="krok" value="' . (int) $krok . '">';
		wp_nonce_field( 'nerti_cookies_kreator' );
	}

	private static function stopka_formularza( $krok, $etykieta = 'Dalej' ) {
		echo '<p class="nc-kreator__nawigacja">';
		if ( $krok > 1 ) {
			echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&krok=' . ( $krok - 1 ) ) ) . '">Wstecz</a> ';
		}
		submit_button( $etykieta, 'primary', 'submit', false );
		echo '</p></form>';
	}

	private static function krok_dokumenty( array $u ) {
		$id_pol = $u['link_polityka'] ? url_to_postid( $u['link_polityka'] ) : 0;
		$id_ck  = $u['link_cookies'] ? url_to_postid( $u['link_cookies'] ) : 0;
		self::naglowek_formularza( 1 );
		?>
		<h2>Kto odpowiada za dane i gdzie jest polityka</h2>
		<p>Baner linkuje do polityki prywatności. Bez niej odwiedzający nie ma gdzie przeczytać, na co się zgadza.</p>
		<table class="form-table">
			<tr>
				<th><label for="nc-k-admin">Administrator danych</label></th>
				<td><input id="nc-k-admin" class="regular-text" type="text" name="administrator" value="<?php echo esc_attr( $u['administrator'] ); ?>" placeholder="Nazwa firmy, adres, NIP">
				<p class="description">Firma albo osoba, która decyduje, po co zbierane są dane. Zwykle właściciel strony.</p></td>
			</tr>
			<tr>
				<th><label for="nc-k-pol">Polityka prywatności</label></th>
				<td><?php wp_dropdown_pages( array( 'name' => 'polityka_id', 'id' => 'nc-k-pol', 'selected' => (int) $id_pol, 'show_option_none' => '— nie ma jeszcze —', 'option_none_value' => '0' ) ); ?></td>
			</tr>
			<tr>
				<th><label for="nc-k-ck">Polityka cookies</label></th>
				<td><?php wp_dropdown_pages( array( 'name' => 'cookies_id', 'id' => 'nc-k-ck', 'selected' => (int) $id_ck, 'show_option_none' => '— w polityce prywatności —', 'option_none_value' => '0' ) ); ?>
				<p class="description">Strona bez sklepu zwykle ma jeden dokument: politykę prywatności z informacją o ciasteczkach w środku. Wtedy zostaw tu „w polityce prywatności".</p></td>
			</tr>
		</table>
		<?php
		self::stopka_formularza( 1 );
	}

	private static function krok_skan() {
		?>
		<h2>Co jest na tej stronie</h2>
		<p>Skaner sprawdzi, jakie narzędzia ładuje strona: statystyki, piksele reklamowe, filmy, mapy. Każde dostanie kategorię i status. Na końcu zobaczysz, czy coś działa przed zgodą.</p>
		<?php
		Nerti_Cookies_Pulpit::sekcja_skan();
		self::naglowek_formularza( 2 );
		self::stopka_formularza( 2 );
	}

	private static function krok_wyglad( array $u ) {
		$oxy = null !== Nerti_Cookies_Tokeny::ustawienia_oxygena();
		self::naglowek_formularza( 3 );
		?>
		<h2>Wygląd i przycisk do zmiany zgody</h2>
		<fieldset>
			<legend class="screen-reader-text">Wygląd</legend>
			<p><label><input type="radio" name="wyglad_zrodlo" value="zostaw" checked> zostaw obecny: <strong><?php echo esc_html( Nerti_Cookies_Tokeny::opis_zrodla() ); ?></strong></label></p>
			<p><label><input type="radio" name="wyglad_zrodlo" value="oxygen" <?php disabled( ! $oxy ); ?>> wczytaj kolory i kroje z ustawień Oxygena<?php echo $oxy ? '' : ' (na tej stronie są puste)'; ?></label></p>
		</fieldset>
		<h3>Gdzie odwiedzający zmieni zdanie</h3>
		<p>Zgodę trzeba dać się wycofać tak samo łatwo, jak się ją dało. Wybierz, gdzie będzie wejście:</p>
		<p><label><input type="radio" name="wycofanie" value="wtyczka" <?php checked( 'wtyczka', $u['wycofanie'] ); ?>> link na samym dole każdej podstrony</label></p>
		<p><label><input type="radio" name="wycofanie" value="plywajacy" <?php checked( 'plywajacy', $u['wycofanie'] ); ?>> mały przycisk w rogu ekranu</label></p>
		<p><label><input type="radio" name="wycofanie" value="strona" <?php checked( 'strona', $u['wycofanie'] ); ?>> link jest już w stopce strony (element z <code>data-nc-akcja="otworz"</code> albo <code>[nerti_cookies_ustawienia]</code>)</label></p>
		<div class="nc-kreator__podglad nc-podglad" data-nc-podglad><?php Nerti_Cookies_Baner::render( true ); ?></div>
		<p class="description">Teksty i dokładny układ zmienisz później w zakładkach Teksty i Wygląd.</p>
		<?php
		self::stopka_formularza( 3 );
	}

	private static function krok_sprawdzenie() {
		$kontrole = Nerti_Cookies_Pulpit::kontrole();
		$bledy    = array_filter( $kontrole, static function ( $x ) { return ! $x[0] && 'blad' === $x[1]; } );
		self::naglowek_formularza( 4 );
		?>
		<h2>Sprawdzenie</h2>
		<ul class="nc-kontrole">
			<?php foreach ( $kontrole as $x ) : ?>
				<li class="<?php echo $x[0] ? 'nc-kontrola--ok' : ( 'blad' === $x[1] ? 'nc-kontrola--blad' : 'nc-kontrola--uwaga' ); ?>"><span class="nc-kontrola__znak" aria-hidden="true"><?php echo $x[0] ? '✓' : '!'; ?></span><span><?php echo esc_html( $x[2] ); ?></span></li>
			<?php endforeach; ?>
		</ul>
		<h3>Sprawdź sam, w dwie minuty</h3>
		<ol>
			<li>Otwórz stronę w oknie prywatnym (Ctrl+Shift+N).</li>
			<li>Kliknij „<?php echo esc_html( Nerti_Cookies_Ustawienia::pobierz()['teksty']['odrzuc'] ); ?>".</li>
			<li>Odśwież stronę. Baner nie powinien wrócić, a filmy i mapy mają pokazywać prostokąt „Pokaż materiał" zamiast odtwarzacza.</li>
		</ol>
		<?php if ( $bledy ) : ?>
			<p class="nc-kreator__uwaga"><strong>Są rzeczy do naprawy.</strong> Możesz zakończyć kreator. Lista zostanie na pulpicie, dopóki ich nie poprawisz.</p>
		<?php endif; ?>
		<?php
		self::stopka_formularza( 4, 'Zakończ i przejdź do pulpitu' );
	}

	public static function zapisz() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Brak uprawnień.' );
		}
		check_admin_referer( 'nerti_cookies_kreator' );
		$krok = isset( $_POST['krok'] ) ? (int) $_POST['krok'] : 1;
		$wynik = null;

		if ( 1 === $krok ) {
			$pol   = isset( $_POST['polityka_id'] ) ? (int) $_POST['polityka_id'] : 0;
			$ck    = isset( $_POST['cookies_id'] ) ? (int) $_POST['cookies_id'] : 0;
			$wynik = Nerti_Cookies_Ustawienia::zapisz(
				array(
					'administrator' => sanitize_text_field( wp_unslash( $_POST['administrator'] ?? '' ) ),
					'link_polityka' => $pol ? (string) get_permalink( $pol ) : '',
					'link_cookies'  => $ck ? (string) get_permalink( $ck ) : '',
				)
			);
		} elseif ( 3 === $krok ) {
			$tryb  = sanitize_key( wp_unslash( $_POST['wycofanie'] ?? 'wtyczka' ) );
			$wynik = Nerti_Cookies_Ustawienia::zapisz( array( 'wycofanie' => in_array( $tryb, array( 'wtyczka', 'plywajacy', 'strona' ), true ) ? $tryb : 'wtyczka' ) );
			if ( 'oxygen' === ( $_POST['wyglad_zrodlo'] ?? '' ) ) {
				Nerti_Cookies_Tokeny::importuj_z_oxygena();
			}
		} elseif ( 4 === $krok ) {
			self::zakoncz();
			wp_safe_redirect( admin_url( 'admin.php?page=nerti-cookies#nc-pulpit' ) );
			exit;
		}
		if ( is_wp_error( $wynik ) ) {
			wp_die( esc_html( $wynik->get_error_message() ) );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&krok=' . ( $krok + 1 ) ) );
		exit;
	}
}
