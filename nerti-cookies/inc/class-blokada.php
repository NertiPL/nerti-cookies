<?php
/**
 * Blokowanie skryptów PRZED zgodą.
 *
 * To jest sedno wtyczki. Większość banerów na rynku odpala skrypty od razu i "wyłącza" je
 * po odmowie — czyli po fakcie, po ustawieniu ciasteczka i po wysłaniu pierwszego żądania
 * do serwera analitycznego. Naruszenie jest już wtedy popełnione i widać je w zakładce
 * Sieć w devtools, mimo że baner zachowuje się poprawnie.
 *
 * Mechanizm: skrypt niedozwolonej kategorii dostaje `type="text/plain"`, przez co
 * przeglądarka go NIE wykonuje, a jego adres wędruje z `src` do `data-nc-src`, przez co
 * NIE zostaje pobrany. Po zgodzie baner podmienia znacznik na wykonywalny.
 *
 * @package NertiCookies
 */

defined( 'ABSPATH' ) || exit;

class Nerti_Cookies_Blokada {

	public static function hooks() {
		add_action( 'wp_head', array( __CLASS__, 'glowa' ), 1 );
		add_filter( 'script_loader_tag', array( __CLASS__, 'filtr_uchwytu' ), 10, 3 );
		add_action( 'template_redirect', array( __CLASS__, 'start_bufora' ), 1 );
	}

	/**
	 * Czy w tym żądaniu w ogóle blokujemy.
	 *
	 * @return bool
	 */
	public static function czy_dziala() {
		if ( is_admin() || wp_doing_ajax() || is_feed() ) {
			return false;
		}
		if ( self::czy_edytor() ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return false;
		}
		if ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) {
			return false;
		}
		return true;
	}

	/**
	 * Podgląd w edytorze wizualnym to nie jest wizyta użytkownika.
	 *
	 * Baner jest przyklejony do okna, więc w edytorze zasłania róg płótna i wchodzi
	 * w każdy zrzut kontrolny — a zgody i tak nie da się tam sensownie podjąć.
	 * Rozpoznajemy budowniczych po ich własnych parametrach żądania; lista jest
	 * jawna, bo cicha heurystyka („jest zalogowany, więc pewnie edytuje") wyłączałaby
	 * baner także wtedy, gdy właściciel po prostu ogląda swoją stronę.
	 *
	 * @return bool
	 */
	public static function czy_edytor() {
		if ( ! current_user_can( 'edit_posts' ) ) { return false; }
		$znaczniki = array(
			'oxygen',
			'oxygen_iframe',
			'breakdance',          // Oxygen 6 / Breakdance — panel budowniczego
			'breakdance_iframe',   // Oxygen 6 / Breakdance — ramka z podglądem
			'elementor-preview',
			'brizy-edit',
			'brizy-edit-iframe',
			'et_fb',               // Divi
			'fl_builder',          // Beaver Builder
			'bricks',              // Bricks
		);
		foreach ( $znaczniki as $z ) {
			if ( isset( $_GET[ $z ] ) && '' !== $_GET[ $z ] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				return true;
			}
		}
		if ( 'breakdance_server_side_render' === filter_input( INPUT_POST, 'action' ) ) {
			return true;
		}
		return (bool) apply_filters( 'nerti_cookies_czy_edytor', false );
	}

	/**
	 * Consent Mode v2 i znacznik stanu — drukowane jako pierwsza rzecz w `<head>`.
	 *
	 * Domyślne `denied` musi paść ZANIM załaduje się kontener Google. Ustawione później
	 * jest tylko deklaracją: kontener zdąży wysłać pierwsze zdarzenie z domyślnymi
	 * ustawieniami Google, czyli ze zgodą.
	 */
	public static function glowa() {
		if ( ! self::czy_dziala() ) {
			return;
		}
		$u = Nerti_Cookies_Ustawienia::pobierz();

		echo "<script id=\"nc-bootstrap\">\n";
		echo "window.ncStan=(function(){try{var m=document.cookie.match(/(?:^|; )" . esc_js( NERTI_COOKIES_CIASTECZKO ) . "=([^;]*)/);";
		echo "return m?JSON.parse(decodeURIComponent(m[1])):null;}catch(e){return null;}})();\n";
		echo "document.documentElement.setAttribute('data-nc',window.ncStan?'decyzja':'pytanie');\n";

		if ( ! empty( $u['consent_mode'] ) ) {
			$an = 'denied';
			$mk = 'denied'; // HTML jest niezależny od cookies odwiedzającego (cache).
			echo "window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}\n";
			echo "gtag('consent','default',{'ad_storage':'" . esc_js( $mk ) . "','ad_user_data':'" . esc_js( $mk ) . "','ad_personalization':'" . esc_js( $mk ) . "','analytics_storage':'" . esc_js( $an ) . "','functionality_storage':'granted','security_storage':'granted','wait_for_update':500});\n";
		}
		echo "</script>\n";
	}

	/**
	 * Odczyt decyzji po stronie serwera. Używany do Consent Mode i do tego, żeby nie
	 * blokować skryptów komuś, kto już się zgodził — bo wtedy strona z cache'a
	 * pokazywałaby mu zablokowane skrypty do czasu, aż zadziała JavaScript.
	 *
	 * @return array Mapa kategoria => bool.
	 */
	public static function zgoda_z_ciasteczka() {
		$puste = array();
		if ( empty( $_COOKIE[ NERTI_COOKIES_CIASTECZKO ] ) ) {
			return $puste;
		}
		$surowe = wp_unslash( $_COOKIE[ NERTI_COOKIES_CIASTECZKO ] );
		$dane   = json_decode( (string) $surowe, true );
		if ( ! is_array( $dane ) || ! isset( $dane['kat'] ) || ! is_array( $dane['kat'] ) ) {
			return $puste;
		}
		$u = Nerti_Cookies_Ustawienia::pobierz();
		if ( ! isset( $dane['w'] ) || (string) $dane['w'] !== (string) $u['wersja_polityki'] ) {
			// Zgoda na starą wersję polityki nie jest zgodą na obecną.
			return $puste;
		}
		$wynik = array();
		foreach ( $dane['kat'] as $id => $stan ) {
			$wynik[ sanitize_key( $id ) ] = (bool) $stan;
		}
		return $wynik;
	}

	/**
	 * Czy kategoria jest już dozwolona w tym żądaniu.
	 *
	 * @param string $kategoria Identyfikator kategorii.
	 * @return bool
	 */
	private static function dozwolona( $kategoria ) {
		$u = Nerti_Cookies_Ustawienia::pobierz();
		if ( isset( $u['kategorie'][ $kategoria ]['wymagana'] ) && $u['kategorie'][ $kategoria ]['wymagana'] ) {
			return true;
		}
		return false; // Opcjonalne skrypty uruchamia wyłącznie JS po odczycie bieżącej decyzji.
	}

	/**
	 * Kategoria dla tekstu (adres, atrybuty, treść skryptu).
	 *
	 * Kolejność ma znaczenie i jest jedna dla wszystkich warstw:
	 *  1. usługi „zawsze" z biblioteki (Turnstile) — nigdy nieblokowane, nawet gdy szerszy
	 *     wzorzec projektu by je złapał, bo formularz bez ochrony to formularz ze spamem;
	 *  2. mapy z ustawień (`wzorce`, `osadzenia`) — decyzja projektu;
	 *  3. biblioteka usług.
	 *
	 * @param string $tekst  Tekst.
	 * @param string $rodzaj skrypty | ramki | piksele | hosty
	 * @return string Identyfikator kategorii albo ''.
	 */
	public static function kategoria( $tekst, $rodzaj = 'skrypty' ) {
		$tekst = (string) $tekst;
		if ( '' === trim( $tekst ) || Nerti_Cookies_Uslugi::zawsze_dozwolone( $tekst ) ) {
			return '';
		}
		$u    = Nerti_Cookies_Ustawienia::pobierz();
		$mapa = 'ramki' === $rodzaj ? $u['osadzenia'] : $u['wzorce'];
		$kat  = self::kategoria_dla_tekstu( $tekst, $mapa );
		if ( '' === $kat ) {
			$kat = Nerti_Cookies_Uslugi::kategoria_dla_tekstu( $tekst, $rodzaj );
			if ( '' === $kat && 'skrypty' !== $rodzaj ) {
				$kat = Nerti_Cookies_Uslugi::kategoria_dla_tekstu( $tekst, 'skrypty' );
			}
		}
		return $kat;
	}

	/**
	 * Warstwa 1 — skrypty dodane przez `wp_enqueue_script`.
	 *
	 * @param string $tag    Pełny znacznik.
	 * @param string $uchwyt Uchwyt skryptu.
	 * @param string $src    Adres.
	 * @return string
	 */
	public static function filtr_uchwytu( $tag, $uchwyt, $src ) {
		if ( ! self::czy_dziala() ) {
			return $tag;
		}
		$u   = Nerti_Cookies_Ustawienia::pobierz();
		$kat = '';

		if ( isset( $u['uchwyty'][ $uchwyt ] ) ) {
			$kat = $u['uchwyty'][ $uchwyt ];
		} else {
			foreach ( Nerti_Cookies_Uslugi::wszystkie() as $usl ) {
				if ( empty( $usl['zawsze'] ) && in_array( $uchwyt, $usl['uchwyty'], true ) ) {
					$kat = $usl['kategoria'];
					break;
				}
			}
			if ( '' === $kat ) {
				$kat = self::kategoria( (string) $src );
			}
		}

		if ( '' === $kat || self::dozwolona( $kat ) ) {
			return $tag;
		}
		return self::zablokuj_znacznik( $tag, $kat );
	}

	/**
	 * Warstwa 2 — bufor wyjścia. Łapie to, co warstwa 1 przepuszcza: piksele wklejone
	 * w `header.php`, w widget HTML, w pole "kod w nagłówku" motywu albo przez wtyczkę,
	 * która drukuje znacznik bezpośrednio.
	 *
	 * W polskich instalacjach to jest większość pikseli, więc bez tej warstwy blokada
	 * wygląda na działającą i nie działa.
	 */
	public static function start_bufora() {
		if ( ! self::czy_dziala() ) {
			return;
		}
		$u = Nerti_Cookies_Ustawienia::pobierz();
		if ( empty( $u['bufor_wyjscia'] ) ) {
			return;
		}
		ob_start( array( __CLASS__, 'przetworz_bufor' ) );
	}

	/**
	 * Typy skryptów, które przeglądarka wykona — wprost albo przez runtime optymalizatora.
	 * `rocketlazyloadscript` (WP Rocket, „opóźnij JS") i `litespeed/javascript` (LiteSpeed,
	 * „opóźnij JS") nie wykonują się od razu, ale wykonają się przy pierwszym ruchu myszy,
	 * czyli dalej przed zgodą.
	 */
	const TYPY_WYKONYWALNE = array( 'module', 'text/javascript', 'application/javascript', 'application/ecmascript', 'text/ecmascript', 'rocketlazyloadscript', 'text/rocketlazyloadscript', 'litespeed/javascript' );

	/** Atrybuty z adresem skryptu: zwykły, WP Rocket, leniwe ładowarki, LiteSpeed. */
	const ATRYBUTY_SRC = array( 'src', 'data-rocket-src', 'data-src', 'data-litespeed-src' );

	/**
	 * @param string $html Cała odpowiedź HTML.
	 * @return string
	 */
	public static function przetworz_bufor( $html ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}
		if ( false === stripos( $html, '</body>' ) ) {
			// Nie wygląda na dokument HTML — nie dotykamy.
			return $html;
		}

		$u = Nerti_Cookies_Ustawienia::pobierz();

		/*
		 * <noscript> najpierw i wyrażeniem regularnym: jego treść to dla parsera tekst,
		 * nie znaczniki. Piksel w <noscript> strzela tylko u kogoś bez JavaScriptu — czyli
		 * u kogoś, komu baner nigdy się nie pokaże i kto nie ma jak się zgodzić. Takiego
		 * piksela nie przepisujemy na zablokowany, tylko wycinamy.
		 */
		$po_noscript = preg_replace_callback(
			'#<noscript\b[^>]*>(.*?)</noscript>#is',
			static function ( $m ) {
				$kat = self::kategoria( $m[1], 'piksele' );
				return ( '' === $kat || self::dozwolona( $kat ) ) ? $m[0] : '<noscript data-nc-cat="' . esc_attr( $kat ) . '"></noscript>';
			},
			$html
		);
		if ( is_string( $po_noscript ) ) {
			$html = $po_noscript;
		}

		$p = new WP_HTML_Tag_Processor( $html );
		while ( $p->next_tag() ) {
			$tag = $p->get_tag();
			if ( 'SCRIPT' === $tag ) {
				self::bufor_skrypt( $p );
			} elseif ( 'IMG' === $tag ) {
				$src = (string) $p->get_attribute( 'src' );
				$kat = self::kategoria( $src, 'piksele' );
				if ( '' !== $kat && ! self::dozwolona( $kat ) && ! $p->get_attribute( 'data-nc-cat' ) ) {
					$p->set_attribute( 'data-nc-src', $src );
					$p->remove_attribute( 'src' );
					$p->remove_attribute( 'srcset' );
					$p->set_attribute( 'data-nc-cat', $kat );
				}
			} elseif ( 'LINK' === $tag ) {
				// preconnect / dns-prefetch to już połączenie z serwerem śledzącym: DNS, TLS i adres IP.
				$rel  = strtolower( (string) $p->get_attribute( 'rel' ) );
				$href = (string) $p->get_attribute( 'href' );
				if ( preg_match( '/\b(preconnect|dns-prefetch|preload|prefetch|modulepreload)\b/', $rel ) ) {
					$kat = self::kategoria( $href, 'hosty' );
					if ( '' !== $kat && ! self::dozwolona( $kat ) ) {
						$p->set_attribute( 'data-nc-href', $href );
						$p->remove_attribute( 'href' );
						$p->set_attribute( 'data-nc-cat', $kat );
					}
				}
			}
		}
		$html         = $p->get_updated_html();
		$po_skryptach = $html;

		$osadzenia_cb = function ( $m ) use ( $u ) {
			$atrybuty = isset( $m[1] ) ? $m[1] : '';
			$kat      = self::kategoria( $atrybuty, 'ramki' );
			if ( '' === $kat || self::dozwolona( $kat ) ) {
				return $m[0];
			}
			return self::zastepnik_osadzenia( $m[0], $atrybuty, $kat, $u['teksty'] );
		};

		$html = preg_replace_callback( '#<iframe\b([^>]*)>.*?</iframe>#is', $osadzenia_cb, $html );
		if ( ! is_string( $html ) ) {
			return $po_skryptach;
		}

		$html = preg_replace_callback( '#<iframe\b([^>]*?)\s*/>#i', $osadzenia_cb, $html );
		if ( ! is_string( $html ) ) {
			return $po_skryptach;
		}

		return $html;
	}

	/**
	 * Jeden znacznik SCRIPT z bufora.
	 *
	 * @param WP_HTML_Tag_Processor $p Procesor ustawiony na znaczniku.
	 */
	private static function bufor_skrypt( $p ) {
		$id = (string) $p->get_attribute( 'id' );
		if ( in_array( $id, array( 'nc-bootstrap', 'nerti-cookies-js', 'nerti-cookies-js-extra' ), true ) || $p->get_attribute( 'data-nc-cat' ) ) {
			return;
		}
		$type = $p->get_attribute( 'type' );
		if ( is_string( $type ) && '' !== $type && ! in_array( strtolower( $type ), self::TYPY_WYKONYWALNE, true ) ) {
			return;
		}

		$tekst   = '';
		$atr_src = '';
		foreach ( self::ATRYBUTY_SRC as $a ) {
			$v = $p->get_attribute( $a );
			if ( ! is_string( $v ) || '' === $v ) {
				continue;
			}
			$tekst  .= ' ' . $v;
			$atr_src = '' === $atr_src ? $a : $atr_src;
			// LiteSpeed zamienia skrypty inline na data: URI w base64 — wzorzec `fbq(` trzeba szukać w środku.
			if ( preg_match( '#^data:[^,]*;base64,(.+)$#is', $v, $m ) ) {
				$tekst .= ' ' . (string) base64_decode( $m[1] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			} elseif ( 0 === stripos( $v, 'data:' ) ) {
				$tekst .= ' ' . rawurldecode( $v );
			}
		}
		$tekst .= ' ' . $p->get_modifiable_text();

		$kat = self::kategoria( $tekst, 'skrypty' );
		if ( '' === $kat || self::dozwolona( $kat ) ) {
			return;
		}
		if ( '' !== $atr_src ) {
			$p->set_attribute( 'data-nc-src', (string) $p->get_attribute( $atr_src ) );
			$p->remove_attribute( $atr_src );
		}
		// Typy optymalizatorów nie wracają po odblokowaniu: skrypt ma się wykonać od razu po zgodzie,
		// a runtime WP Rocket czy LiteSpeed nie wie o znacznikach dodanych po jego starcie.
		if ( is_string( $type ) && 'module' === strtolower( $type ) ) {
			$p->set_attribute( 'data-nc-type', $type );
		}
		$p->set_attribute( 'data-nc-cat', $kat );
		$p->set_attribute( 'type', 'text/plain' );
		self::nie_optymalizuj( $p );
	}

	/**
	 * Znaczniki, których optymalizatory mają nie ruszać: nie łączyć, nie opóźniać,
	 * nie przenosić. Każdy ma swój atrybut i żaden nie czyta cudzego.
	 *
	 * @param WP_HTML_Tag_Processor $p Procesor.
	 */
	public static function nie_optymalizuj( $p ) {
		$p->set_attribute( 'data-no-optimize', '1' ); // LiteSpeed
		$p->set_attribute( 'data-no-defer', '1' );    // LiteSpeed
		$p->set_attribute( 'data-noptimize', '1' );   // Autoptimize
		$p->set_attribute( 'data-cfasync', 'false' ); // Cloudflare Rocket Loader
		$p->set_attribute( 'nowprocket', true );      // WP Rocket
	}

	/**
	 * @param string $tekst   Tekst do przeszukania (atrybuty, treść skryptu).
	 * @param array  $wzorce  Mapa fragment => kategoria.
	 * @return string Identyfikator kategorii albo pusty string.
	 */
	private static function kategoria_dla_tekstu( $tekst, $wzorce ) {
		if ( ! is_array( $wzorce ) || '' === $tekst ) {
			return '';
		}
		foreach ( $wzorce as $fragment => $kategoria ) {
			if ( '' !== $fragment && false !== stripos( $tekst, (string) $fragment ) ) {
				return (string) $kategoria;
			}
		}
		return '';
	}

	/**
	 * Zamienia wykonywalny znacznik skryptu na nieaktywny.
	 *
	 * `type="text/plain"` zatrzymuje wykonanie, przeniesienie `src` do `data-nc-src`
	 * zatrzymuje pobranie. Same `type` bez przeniesienia `src` NIE wystarcza w części
	 * przeglądarek przy `async`/`defer` — a to jest dokładnie ten defekt, którego
	 * nie widać po kliknięciu „Akceptuję".
	 *
	 * @param string $tag       Znacznik.
	 * @param string $kategoria Kategoria.
	 * @return string
	 */
	private static function zablokuj_znacznik( $tag, $kategoria ) {
		$p = new WP_HTML_Tag_Processor( $tag );
		if ( ! $p->next_tag( 'SCRIPT' ) ) { return $tag; }
		$src = $p->get_attribute( 'src' );
		$typ = $p->get_attribute( 'type' );
		if ( is_string( $src ) ) { $p->set_attribute( 'data-nc-src', $src ); $p->remove_attribute( 'src' ); }
		if ( is_string( $typ ) ) { $p->set_attribute( 'data-nc-type', $typ ); }
		$p->set_attribute( 'type', 'text/plain' );
		$p->set_attribute( 'data-nc-cat', $kategoria );
		self::nie_optymalizuj( $p );
		$tag = $p->get_updated_html();
		return $tag;
	}

	/**
	 * Zastępnik osadzenia: prostokąt z wyjaśnieniem i przyciskiem.
	 *
	 * Kliknięcie ładuje TEN materiał, nie włącza całej kategorii — użytkownik zgadza się
	 * na to, co widzi, a nie na wszystko, co wtyczka podpięła pod tę samą etykietę.
	 *
	 * @param string $oryginal  Cały oryginalny znacznik — zwracany, gdy nie umiemy go rozebrać.
	 * @param string $atrybuty  Atrybuty oryginalnego iframe'a.
	 * @param string $kategoria Kategoria.
	 * @param array  $teksty    Teksty interfejsu.
	 * @return string
	 */
	private static function zastepnik_osadzenia( $oryginal, $atrybuty, $kategoria, $teksty ) {
		$p = new WP_HTML_Tag_Processor($oryginal);
		if (!$p->next_tag('IFRAME')) return $oryginal;
		$tytul = (string) $p->get_attribute( 'title' );
		$src   = '';
		// Leniwe ładowarki trzymają adres w data-src / data-lazy-src i przepisują go do src
		// po przewinięciu — bez zgody, więc zastępnik należy się i im.
		foreach ( array( 'src', 'data-src', 'data-lazy-src', 'data-litespeed-src', 'data-rocket-src' ) as $a ) {
			$v = $p->get_attribute( $a );
			if ( is_string( $v ) && '' !== $v && 'about:blank' !== $v ) {
				$src = $v;
				break;
			}
		}
		if ( '' === $src ) {
			return $oryginal;
		}

		return '<div class="nc-osadzenie" data-nc-cat="' . esc_attr( $kategoria ) . '"'
			. ' data-nc-src="' . esc_attr( $src ) . '"'
			. ' data-nc-atrybuty="' . esc_attr( $atrybuty ) . '">'
			. '<p class="nc-osadzenie__opis">' . esc_html( $teksty['osadzenie'] ) . '</p>'
			. '<button type="button" class="nc-btn nc-btn--rowny nc-osadzenie__btn">'
			. esc_html( $teksty['osadzenie_btn'] ) . ( '' !== $tytul ? ' — ' . esc_html( $tytul ) : '' )
			. '</button></div>';
	}
}
