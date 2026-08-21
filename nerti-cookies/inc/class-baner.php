<?php
/**
 * Baner — znacznik, style, skrypt, link do wycofania zgody.
 *
 * @package NertiCookies
 */

defined( 'ABSPATH' ) || exit;

class Nerti_Cookies_Baner {

	public static function hooks() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'zasoby' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render' ), 5 );
		add_shortcode( 'nerti_cookies_ustawienia', array( __CLASS__, 'shortcode' ) );
	}

	public static function zasoby() {
		if ( ! Nerti_Cookies_Blokada::czy_dziala() ) {
			return;
		}

		wp_enqueue_style(
			'nerti-cookies',
			NERTI_COOKIES_URL . 'assets/baner.css',
			array(),
			NERTI_COOKIES_WERSJA
		);

		$tokeny = self::css_zmiennych();
		if ( '' !== $tokeny ) {
			wp_add_inline_style( 'nerti-cookies', $tokeny );
		}

		wp_enqueue_script(
			'nerti-cookies',
			NERTI_COOKIES_URL . 'assets/baner.js',
			array(),
			NERTI_COOKIES_WERSJA,
			true
		);

		wp_localize_script( 'nerti-cookies', 'ncKonfig', Nerti_Cookies_Ustawienia::dla_przegladarki() );
	}

	/**
	 * Wszystkie zmienne `--nc-*`: zaimportowane tokeny plus układ dla telefonu i desktopu.
	 *
	 * Jedna metoda, bo podgląd w panelu musi dostać DOKŁADNIE ten sam zestaw co front.
	 * Gdyby panel składał go sobie sam, podgląd potrafiłby pokazywać baner, którego
	 * na stronie nie ma — a wtedy jest gorszy niż brak podglądu.
	 *
	 * @return string
	 */
	public static function css_zmiennych() {
		return Nerti_Cookies_Tokeny::css()
			. Nerti_Cookies_Tokeny::css_wygladu( Nerti_Cookies_Ustawienia::pole( 'wyglad', array() ) );
	}

	/**
	 * Znacznik jest drukowany ZAWSZE, także komuś, kto już podjął decyzję, i chowany
	 * przez CSS na podstawie atrybutu `data-nc` ustawionego w `<head>`.
	 *
	 * Powód: strona wychodzi z cache'a (LiteSpeed, WP Rocket, Cloudflare). Gdyby baner
	 * był drukowany warunkowo po stronie serwera, pierwszy odwiedzający decydowałby
	 * o tym, czy kolejni w ogóle zobaczą baner. To jest błąd, który ujawnia się dopiero
	 * na produkcji z włączonym cache'em, czyli po odbiorze.
	 */
	public static function render( $podglad = false ) {
		if ( ! $podglad && ! Nerti_Cookies_Blokada::czy_dziala() ) {
			return;
		}
		$u  = Nerti_Cookies_Ustawienia::pobierz();
		$t  = $u['teksty'];
		$id = $podglad ? 'nc-podglad-baner' : 'nc-baner';
		?>
		<div id="<?php echo esc_attr( $id ); ?>" class="nc-baner" role="dialog" aria-modal="false"
			aria-labelledby="nc-tytul" aria-describedby="nc-tresc" <?php echo $podglad ? '' : 'hidden'; ?>>
			<div class="nc-baner__panel">

				<div class="nc-baner__glowny">
					<h2 id="nc-tytul" class="nc-baner__tytul" data-nc-tekst="tytul"><?php echo esc_html( $t['tytul'] ); ?></h2>
					<p id="nc-tresc" class="nc-baner__tresc" data-nc-tekst="tresc"><?php echo esc_html( $t['tresc'] ); ?></p>

					<?php if ( ! empty( $u['link_polityka'] ) || ! empty( $u['link_cookies'] ) ) : ?>
						<p class="nc-baner__linki">
							<?php if ( ! empty( $u['link_polityka'] ) ) : ?>
								<a href="<?php echo esc_url( $u['link_polityka'] ); ?>"><?php echo esc_html( $t['polityka'] ); ?></a>
							<?php endif; ?>
							<?php if ( ! empty( $u['link_cookies'] ) ) : ?>
								<a href="<?php echo esc_url( $u['link_cookies'] ); ?>"><?php echo esc_html( $t['cookies'] ); ?></a>
							<?php endif; ?>
						</p>
					<?php endif; ?>

					<?php
					/*
					 * Kolejność i klasy przycisków są częścią zgodności, nie estetyki.
					 * „Odrzucam wszystkie" ma tę samą klasę wagi co „Akceptuję wszystkie",
					 * stoi na PIERWSZYM ekranie i wymaga tej samej liczby kliknięć.
					 * Zamiana tego na link w szarym tekście jest defektem prawnym,
					 * nie decyzją projektową — i jest najczęstszym defektem na rynku.
					 */
					?>
					<div class="nc-baner__akcje">
						<button type="button" class="nc-btn nc-btn--rowny" data-nc-akcja="odrzuc" data-nc-tekst="odrzuc">
							<?php echo esc_html( $t['odrzuc'] ); ?>
						</button>
						<button type="button" class="nc-btn nc-btn--rowny" data-nc-akcja="akceptuj" data-nc-tekst="akceptuj">
							<?php echo esc_html( $t['akceptuj'] ); ?>
						</button>
						<button type="button" class="nc-btn nc-btn--tekst" data-nc-akcja="pokaz-ustawienia" data-nc-tekst="ustawienia"
							aria-expanded="false" aria-controls="nc-ustawienia">
							<?php echo esc_html( $t['ustawienia'] ); ?>
						</button>
					</div>
				</div>

				<div id="nc-ustawienia" class="nc-baner__ustawienia" hidden>
					<ul class="nc-kategorie">
						<?php foreach ( $u['kategorie'] as $id_kat => $kat ) : ?>
							<li class="nc-kategoria">
								<label class="nc-kategoria__etykieta">
									<input type="checkbox" class="nc-kategoria__pole"
										value="<?php echo esc_attr( $id_kat ); ?>"
										<?php checked( (bool) $kat['domyslnie'] ); ?>
										<?php disabled( (bool) $kat['wymagana'] ); ?>>
									<span class="nc-kategoria__nazwa"><?php echo esc_html( $kat['nazwa'] ); ?></span>
								</label>
								<p class="nc-kategoria__opis"><?php echo esc_html( $kat['opis'] ); ?></p>
							</li>
						<?php endforeach; ?>
					</ul>
					<div class="nc-baner__akcje">
						<button type="button" class="nc-btn nc-btn--rowny" data-nc-akcja="zapisz" data-nc-tekst="zapisz">
							<?php echo esc_html( $t['zapisz'] ); ?>
						</button>
					</div>
				</div>

			</div>
		</div>
		<?php
	}

	/**
	 * Wycofanie zgody. Musi być osiągalne z każdej podstrony — bez tego zgody nie da się
	 * wycofać „tak samo łatwo, jak się jej udzieliło", a to jest wymóg, nie udogodnienie.
	 *
	 * Użycie: `[nerti_cookies_ustawienia]` w stopce albo w polityce cookies.
	 *
	 * @param array $atrybuty Atrybuty shortcode'a.
	 * @return string
	 */
	public static function shortcode( $atrybuty ) {
		$u = Nerti_Cookies_Ustawienia::pobierz();
		$a = shortcode_atts(
			array(
				'tekst' => $u['teksty']['wycofaj'],
				'klasa' => 'nc-wycofaj',
			),
			$atrybuty,
			'nerti_cookies_ustawienia'
		);
		return '<button type="button" class="' . esc_attr( $a['klasa'] ) . '" data-nc-akcja="otworz">'
			. esc_html( $a['tekst'] ) . '</button>';
	}
}
