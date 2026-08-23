<?php
/**
 * Czujka dokumentów — pilnuje, czy polityka nie zmieniła się bez podniesienia wersji zgody.
 *
 * @package NertiCookies
 */

defined( 'ABSPATH' ) || exit;

/**
 * Rejestr zgód dowodzi zgody NA KONKRETNĄ WERSJĘ tekstu. Gdy ktoś poprawi politykę
 * prywatności i nie podniesie `wersja_polityki`, rejestr zaczyna kłamać: twierdzi,
 * że ludzie zgodzili się na zdanie, którego nigdy nie widzieli.
 *
 * Dlatego czujka pilnuje odcisku treści dokumentów i mówi o rozjeździe.
 * Świadomie NIE podnosi wersji sama: podniesienie unieważnia wszystkie zgody
 * i pokazuje baner każdemu odwiedzającemu od nowa. Poprawka literówki tego nie
 * uzasadnia, zmiana zakresu przetwarzania — owszem. Tę różnicę widzi człowiek,
 * nie funkcja porównująca sumy kontrolne.
 */
class Nerti_Cookies_Czujka {

	const OPCJA = 'nerti_cookies_odciski';

	public static function hooks() {
		add_action( 'save_post', array( __CLASS__, 'po_zapisie' ), 20, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'komunikat' ) );
		add_action( 'admin_post_nerti_cookies_wersja', array( __CLASS__, 'obsluz_podniesienie' ) );
		add_action( 'admin_post_nerti_cookies_kwituj', array( __CLASS__, 'obsluz_kwitowanie' ) );
	}

	/**
	 * Identyfikatory stron wskazanych w ustawieniach jako polityka i polityka cookies.
	 *
	 * @return array<int,int>
	 */
	public static function strony() {
		$u    = Nerti_Cookies_Ustawienia::pobierz();
		$idy  = array();
		foreach ( array( 'link_polityka', 'link_cookies' ) as $pole ) {
			if ( empty( $u[ $pole ] ) ) {
				continue;
			}
			$id = url_to_postid( $u[ $pole ] );
			if ( $id ) {
				$idy[] = (int) $id;
			}
		}
		return array_values( array_unique( $idy ) );
	}

	public static function odcisk( $post_id ) {
		$p = get_post( $post_id );
		return $p ? md5( wp_strip_all_tags( (string) $p->post_content ) ) : '';
	}

	/**
	 * Zapamiętuje odcisk przy pierwszym spotkaniu, żeby nie krzyczeć od razu po instalacji.
	 */
	public static function po_zapisie( $post_id, $post = null ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! in_array( (int) $post_id, self::strony(), true ) ) {
			return;
		}
		$zapisane = get_option( self::OPCJA, array() );
		if ( ! is_array( $zapisane ) ) {
			$zapisane = array();
		}
		$biezacy = self::odcisk( $post_id );
		if ( ! isset( $zapisane[ $post_id ] ) ) {
			$zapisane[ $post_id ] = array( 'odcisk' => $biezacy, 'zmieniono' => 0 );
		} elseif ( $zapisane[ $post_id ]['odcisk'] !== $biezacy ) {
			$zapisane[ $post_id ] = array( 'odcisk' => $biezacy, 'zmieniono' => time() );
		}
		update_option( self::OPCJA, $zapisane, false );
	}

	/**
	 * @return array<int,int> post_id => znacznik czasu zmiany
	 */
	public static function nieskwitowane() {
		$zapisane = get_option( self::OPCJA, array() );
		$out      = array();
		if ( is_array( $zapisane ) ) {
			foreach ( $zapisane as $id => $d ) {
				if ( ! empty( $d['zmieniono'] ) ) {
					$out[ (int) $id ] = (int) $d['zmieniono'];
				}
			}
		}
		return $out;
	}

	public static function komunikat() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$zmiany = self::nieskwitowane();
		if ( ! $zmiany ) {
			return;
		}
		$u        = Nerti_Cookies_Ustawienia::pobierz();
		$tytuly   = array();
		foreach ( array_keys( $zmiany ) as $id ) {
			$tytuly[] = get_the_title( $id );
		}
		$nowa = self::nastepna_wersja( (string) $u['wersja_polityki'] );
		?>
		<div class="notice notice-warning">
			<p><strong>Zmieniła się treść dokumentu:</strong> <?php echo esc_html( implode( ', ', $tytuly ) ); ?>.</p>
			<p>
				Wersja zgody to nadal <code><?php echo esc_html( $u['wersja_polityki'] ); ?></code>.
				Jeśli zmiana dotyczy tego, <em>co</em> robicie z danymi albo <em>jakie</em> ciasteczka są ustawiane,
				podnieś wersję — baner zapyta wszystkich od nowa, a rejestr będzie dowodził zgody na aktualny tekst.
				Przy poprawce redakcyjnej wystarczy odnotować, że zmiana jest bez znaczenia dla zgody.
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=nerti_cookies_wersja' ), 'nerti_cookies_wersja' ) ); ?>">
					Podnieś wersję do <?php echo esc_html( $nowa ); ?> i zapytaj ponownie
				</a>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=nerti_cookies_kwituj' ), 'nerti_cookies_kwituj' ) ); ?>">
					To poprawka redakcyjna — zostaw zgody
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * 1.0 → 1.1, 1.9 → 1.10, „2026-08" → „2026-08.1”. Kiedy nie ma się czego chwycić, dokleja sufiks.
	 */
	public static function nastepna_wersja( $wersja ) {
		if ( preg_match( '/^(.*?)(\d+)$/', $wersja, $m ) ) {
			return $m[1] . ( (int) $m[2] + 1 );
		}
		return $wersja . '.1';
	}

	public static function obsluz_podniesienie() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'nerti_cookies_wersja' ) ) {
			wp_die( 'Brak uprawnień.' );
		}
		$u = Nerti_Cookies_Ustawienia::pobierz();
		Nerti_Cookies_Ustawienia::podnies_wersje_polityki( self::nastepna_wersja( (string) $u['wersja_polityki'] ) );
		self::kwituj();
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	public static function obsluz_kwitowanie() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'nerti_cookies_kwituj' ) ) {
			wp_die( 'Brak uprawnień.' );
		}
		self::kwituj();
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	protected static function kwituj() {
		$zapisane = get_option( self::OPCJA, array() );
		if ( is_array( $zapisane ) ) {
			foreach ( $zapisane as $id => $d ) {
				$zapisane[ $id ]['zmieniono'] = 0;
			}
			update_option( self::OPCJA, $zapisane, false );
		}
	}
}
