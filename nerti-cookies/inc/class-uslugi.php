<?php
/**
 * Biblioteka znanych usług — co to za narzędzie, do której kategorii należy, po czym je poznać
 * w HTML-u i jakie ciasteczka zostawia.
 *
 * @package NertiCookies
 */

defined( 'ABSPATH' ) || exit;

/**
 * Jedno miejsce, z którego czytają: blokada (wzorce skryptów, ramek i pikseli), blokada
 * nagłówków (nazwy ciasteczek z PHP), skaner (nazwa usługi zamiast adresu), lista ciasteczek
 * do polityki i baner (co skasować po wycofaniu zgody).
 *
 * Dlaczego biblioteka, a nie same wzorce w ustawieniach: wzorzec „googletagmanager.com"
 * wystarcza, żeby zablokować skrypt, ale nie wystarcza, żeby powiedzieć klientowi, co to jest,
 * po co i dokąd idą dane. Polityka cookies bez tych trzech informacji jest listą adresów.
 *
 * Wzorce z ustawień (`wzorce`, `osadzenia`, `uchwyty`) mają PIERWSZEŃSTWO przed biblioteką:
 * to jest miejsce na decyzję projektu („ta mapa u nas jest niezbędna, bo…"), a decyzja
 * człowieka wygrywa z domyślną.
 *
 * Pola `czas` i `odbiorca` to wartości z dokumentacji dostawców, stan na 2026-09. Trafiają do
 * polityki cookies, więc skaner pokazuje je jako „do potwierdzenia" — dostawca może je zmienić
 * bez uprzedzenia, a tabela w polityce ma opisywać to, co strona robi dziś.
 */
class Nerti_Cookies_Uslugi {

	/** @var array|null */
	private static $bufor = null;

	/**
	 * @return array<string,array> id => definicja
	 */
	public static function wszystkie() {
		if ( null !== self::$bufor ) {
			return self::$bufor;
		}
		$g = 'Google Ireland Ltd (Irlandia); możliwe przekazanie do USA na podstawie EU-US Data Privacy Framework';

		$uslugi = array(

			/* ---------- analityczne ---------- */

			'ga4' => array(
				'nazwa'      => 'Google Analytics 4',
				'kategoria'  => 'analityczne',
				'cel'        => 'Liczy odwiedziny i pokazuje, które podstrony są czytane i skąd przychodzą odwiedzający.',
				'odbiorca'   => $g,
				'skrypty'    => array( 'google-analytics.com', 'analytics.google.com', 'googletagmanager.com/gtag/js', "gtag('config'", 'gtag("config"' ),
				'hosty'      => array( 'google-analytics.com', 'analytics.google.com' ),
				'ciasteczka' => array( '_ga' => '2 lata', '_ga_*' => '2 lata', '_gid' => '24 godziny', '_gat*' => '1 minuta' ),
				'uchwyty'    => array( 'google-analytics', 'google_gtagjs', 'googlesitekit-gtag' ),
			),
			'gtm' => array(
				'nazwa'      => 'Google Tag Manager',
				'kategoria'  => 'analityczne',
				'cel'        => 'Wczytuje inne narzędzia pomiarowe skonfigurowane w kontenerze. Sam nie zapisuje ciasteczek, ale uruchamia te, które ma w środku.',
				'odbiorca'   => $g,
				'skrypty'    => array( 'googletagmanager.com/gtm.js', 'googletagmanager.com', 'GTM-' ),
				'ramki'      => array( 'googletagmanager.com/ns.html' ),
				'hosty'      => array( 'googletagmanager.com' ),
				'ciasteczka' => array(),
				'uchwyty'    => array( 'google-tag-manager' ),
			),
			'hotjar' => array(
				'nazwa'      => 'Hotjar',
				'kategoria'  => 'analityczne',
				'cel'        => 'Nagrywa ruchy myszy i przewijanie, żeby pokazać, w których miejscach strony odwiedzający się gubią.',
				'odbiorca'   => 'Hotjar Ltd (Malta)',
				'skrypty'    => array( 'static.hotjar.com', 'script.hotjar.com', 'hotjar.com/c/hotjar', '_hjSettings' ),
				'hosty'      => array( 'hotjar.com', 'hotjar.io' ),
				'ciasteczka' => array( '_hjSessionUser_*' => '1 rok', '_hjSession_*' => '30 minut', '_hjIncludedInSessionSample*' => '30 minut', '_hjAbsoluteSessionInProgress' => '30 minut', '_hjTLDTest' => 'sesja' ),
				'storage'    => array( '_hj*' ),
				'uchwyty'    => array( 'hotjar' ),
			),
			'clarity' => array(
				'nazwa'      => 'Microsoft Clarity',
				'kategoria'  => 'analityczne',
				'cel'        => 'Nagrywa sesje i tworzy mapy kliknięć, żeby pokazać, jak odwiedzający korzystają ze strony.',
				'odbiorca'   => 'Microsoft Ireland Operations Ltd (Irlandia); możliwe przekazanie do USA na podstawie EU-US Data Privacy Framework',
				'skrypty'    => array( 'clarity.ms', '"clarity"', "'clarity'" ),
				'hosty'      => array( 'clarity.ms' ),
				'ciasteczka' => array( '_clck' => '1 rok', '_clsk' => '1 dzień', 'CLID' => '1 rok', 'MUID' => '1 rok' ),
				'uchwyty'    => array( 'clarity' ),
			),

			/* ---------- marketingowe ---------- */

			'meta-pixel' => array(
				'nazwa'      => 'Meta Pixel (Facebook, Instagram)',
				'kategoria'  => 'marketingowe',
				'cel'        => 'Mierzy skuteczność reklam na Facebooku i Instagramie i pozwala pokazywać reklamy osobom, które odwiedziły stronę.',
				'odbiorca'   => 'Meta Platforms Ireland Ltd (Irlandia); możliwe przekazanie do USA na podstawie EU-US Data Privacy Framework',
				'skrypty'    => array( 'connect.facebook.net', 'facebook.com/tr', 'fbq(', 'fbevents.js' ),
				'piksele'    => array( 'facebook.com/tr' ),
				'hosty'      => array( 'facebook.net', 'facebook.com' ),
				'ciasteczka' => array( '_fbp' => '90 dni', '_fbc' => '90 dni', 'fr' => '90 dni' ),
				'uchwyty'    => array( 'facebook-pixel' ),
				'wtyczki'    => array( 'official-facebook-pixel', 'facebook-for-woocommerce', 'pixelyoursite' ),
			),
			'tiktok' => array(
				'nazwa'      => 'TikTok Pixel',
				'kategoria'  => 'marketingowe',
				'cel'        => 'Mierzy skuteczność reklam na TikToku i pozwala pokazywać reklamy osobom, które odwiedziły stronę.',
				'odbiorca'   => 'TikTok Technology Ltd (Irlandia); możliwe przekazanie poza EOG',
				'skrypty'    => array( 'analytics.tiktok.com', 'ttq.load', 'ttq.page' ),
				'hosty'      => array( 'tiktok.com', 'tiktokw.us' ),
				'ciasteczka' => array( '_ttp' => '13 miesięcy', '_tt_enable_cookie' => '13 miesięcy', 'ttcsid*' => '13 miesięcy' ),
				'storage'    => array( 'tt_*' ),
			),
			'google-ads' => array(
				'nazwa'      => 'Google Ads',
				'kategoria'  => 'marketingowe',
				'cel'        => 'Mierzy, które reklamy Google przyprowadziły klientów, i pozwala kierować reklamy do osób, które odwiedziły stronę.',
				'odbiorca'   => $g,
				'skrypty'    => array( 'googleadservices.com', 'doubleclick.net', 'googlesyndication.com', 'gtag/js?id=AW-', "'AW-", '"AW-' ),
				'piksele'    => array( 'googleadservices.com', 'doubleclick.net' ),
				'ramki'      => array( 'doubleclick.net' ),
				'hosty'      => array( 'googleadservices.com', 'doubleclick.net', 'googlesyndication.com' ),
				'ciasteczka' => array( '_gcl_au' => '90 dni', '_gcl_aw' => '90 dni', '_gcl_dc' => '90 dni', 'IDE' => '13 miesięcy', 'test_cookie' => '15 minut' ),
			),
			'linkedin' => array(
				'nazwa'      => 'LinkedIn Insight Tag',
				'kategoria'  => 'marketingowe',
				'cel'        => 'Mierzy skuteczność reklam na LinkedInie i pozwala kierować je do osób, które odwiedziły stronę.',
				'odbiorca'   => 'LinkedIn Ireland Unlimited Company (Irlandia); możliwe przekazanie do USA',
				'skrypty'    => array( 'snap.licdn.com', 'ads.linkedin.com', '_linkedin_partner_id' ),
				'piksele'    => array( 'px.ads.linkedin.com', 'dc.ads.linkedin.com' ),
				'hosty'      => array( 'licdn.com', 'linkedin.com' ),
				'ciasteczka' => array( 'li_sugr' => '90 dni', 'bcookie' => '1 rok', 'lidc' => '1 dzień', 'UserMatchHistory' => '30 dni', 'AnalyticsSyncHistory' => '30 dni' ),
			),
			'youtube' => array(
				'nazwa'      => 'YouTube',
				'kategoria'  => 'marketingowe',
				'cel'        => 'Odtwarza osadzone filmy. YouTube zapisuje przy tym, co oglądasz, i wykorzystuje to do reklam.',
				'odbiorca'   => $g,
				'ramki'      => array( 'youtube.com/embed', 'youtube-nocookie.com', 'youtu.be' ),
				'skrypty'    => array( 'youtube.com/iframe_api', 'youtube.com/player_api' ),
				'hosty'      => array( 'youtube.com', 'youtube-nocookie.com', 'ytimg.com', 'googlevideo.com' ),
				'ciasteczka' => array( 'VISITOR_INFO1_LIVE' => '6 miesięcy', 'YSC' => 'sesja', 'VISITOR_PRIVACY_METADATA' => '6 miesięcy' ),
				'storage'    => array( 'yt-*', 'ytidb::*' ),
			),
			'vimeo' => array(
				'nazwa'      => 'Vimeo',
				'kategoria'  => 'marketingowe',
				'cel'        => 'Odtwarza osadzone filmy i zbiera statystyki ich oglądania.',
				'odbiorca'   => 'Vimeo.com, Inc. (USA)',
				'ramki'      => array( 'player.vimeo.com' ),
				'skrypty'    => array( 'player.vimeo.com/api' ),
				'hosty'      => array( 'vimeo.com', 'vimeocdn.com' ),
				'ciasteczka' => array( 'vuid' => '2 lata', '__cf_bm' => '30 minut' ),
			),
			'google-maps' => array(
				'nazwa'      => 'Mapy Google',
				'kategoria'  => 'marketingowe',
				'cel'        => 'Pokazuje osadzoną mapę. Google zapisuje przy tym dane o urządzeniu i wykorzystuje je we własnych usługach.',
				'odbiorca'   => $g,
				'ramki'      => array( 'google.com/maps/embed', 'maps.google.', 'google.com/maps?', 'google.pl/maps' ),
				'skrypty'    => array( 'maps.googleapis.com/maps/api/js', 'maps.google.com/maps/api' ),
				'hosty'      => array( 'maps.googleapis.com', 'maps.gstatic.com' ),
				'ciasteczka' => array( 'NID' => '6 miesięcy' ),
			),
			'recaptcha' => array(
				'nazwa'      => 'Google reCAPTCHA',
				'kategoria'  => 'marketingowe',
				'cel'        => 'Sprawdza, czy formularz wysyła człowiek. Google analizuje przy tym zachowanie na stronie i dane urządzenia także do własnych celów, dlatego wymaga zgody. Formularze bez zgody chroni Cloudflare Turnstile.',
				'odbiorca'   => $g,
				'skrypty'    => array( 'google.com/recaptcha', 'recaptcha.net', 'gstatic.com/recaptcha', 'grecaptcha' ),
				'ramki'      => array( 'google.com/recaptcha', 'recaptcha.net' ),
				'hosty'      => array( 'recaptcha.net' ),
				'ciasteczka' => array( '_GRECAPTCHA' => '6 miesięcy' ),
			),
			'spotify' => array(
				'nazwa'      => 'Spotify',
				'kategoria'  => 'marketingowe',
				'cel'        => 'Odtwarza osadzone utwory i podcasty.',
				'odbiorca'   => 'Spotify AB (Szwecja)',
				'ramki'      => array( 'open.spotify.com/embed' ),
				'hosty'      => array( 'spotify.com', 'scdn.co' ),
				'ciasteczka' => array( 'sp_t' => '1 rok', 'sp_landing' => '1 dzień' ),
			),
			'woo-atrybucja' => array(
				'nazwa'      => 'WooCommerce: atrybucja zamówień',
				'kategoria'  => 'marketingowe',
				'cel'        => 'Zapamiętuje, z jakiej kampanii albo strony przyszedł klient, i dopisuje to do zamówienia.',
				'odbiorca'   => 'Właściciel sklepu (dane zostają na serwerze strony)',
				'skrypty'    => array( 'sourcebuster', 'order-attribution' ),
				'ciasteczka' => array( 'sbjs_*' => 'sesja lub 31 minut' ),
				'uchwyty'    => array( 'sourcebuster-js', 'wc-order-attribution' ),
			),

			/* ---------- niezbędne: rozpoznawane, NIGDY nieblokowane ---------- */

			'turnstile' => array(
				'nazwa'      => 'Cloudflare Turnstile',
				'kategoria'  => 'niezbedne',
				'zawsze'     => true,
				'cel'        => 'Chroni formularze przed spamem i botami bez zbierania danych do reklam.',
				'odbiorca'   => 'Cloudflare, Inc. (USA); przekazanie na podstawie EU-US Data Privacy Framework',
				'skrypty'    => array( 'challenges.cloudflare.com' ),
				'ramki'      => array( 'challenges.cloudflare.com' ),
				'hosty'      => array( 'challenges.cloudflare.com' ),
				'ciasteczka' => array( 'cf_clearance' => '30 minut', '__cf_bm' => '30 minut', 'cf_chl_*' => 'sesja' ),
			),
			'nerti-cookies' => array(
				'nazwa'      => 'Zapis decyzji o ciasteczkach',
				'kategoria'  => 'niezbedne',
				'zawsze'     => true,
				'cel'        => 'Zapamiętuje, na które ciasteczka się zgodziłeś, żeby nie pytać przy każdej podstronie.',
				'odbiorca'   => 'Nikt. Ciasteczko zostaje w przeglądarce, a decyzja w rejestrze zgód strony',
				'ciasteczka' => array( NERTI_COOKIES_CIASTECZKO => '6 miesięcy', 'wp_consent_*' => '30 dni', 'wc_facebook_signals_state' => '1 rok' ),
			),
			'wordpress' => array(
				'nazwa'      => 'WordPress',
				'kategoria'  => 'niezbedne',
				'zawsze'     => true,
				'cel'        => 'Logowanie do panelu i zapamiętanie ustawień zalogowanego użytkownika.',
				'odbiorca'   => 'Nikt. Dane zostają na serwerze strony',
				'ciasteczka' => array( 'wordpress_*' => 'sesja lub 14 dni', 'wordpress_logged_in_*' => 'sesja lub 14 dni', 'wp-settings-*' => '1 rok', 'wordpress_test_cookie' => 'sesja', 'PHPSESSID' => 'sesja', 'comment_author_*' => '1 rok' ),
				'storage'    => array( 'wpEmojiSettingsSupports', 'wp-autosave-*' ),
			),
			'woocommerce' => array(
				'nazwa'      => 'WooCommerce: koszyk',
				'kategoria'  => 'niezbedne',
				'zawsze'     => true,
				'cel'        => 'Pamięta zawartość koszyka i sesję zakupów.',
				'odbiorca'   => 'Nikt. Dane zostają na serwerze strony',
				'ciasteczka' => array( 'woocommerce_cart_hash' => 'sesja', 'woocommerce_items_in_cart' => 'sesja', 'wp_woocommerce_session_*' => '2 dni', 'woocommerce_recently_viewed' => 'sesja' ),
			),
		);

		/**
		 * Rozszerzenie biblioteki przez projekt. Klucz usługi jest stały — skaner i lista
		 * ciasteczek odwołują się do niego — więc nie zmieniaj istniejących kluczy.
		 */
		$uslugi = apply_filters( 'nerti_cookies_uslugi', $uslugi );

		foreach ( $uslugi as $id => &$u ) {
			$u = wp_parse_args(
				$u,
				array( 'nazwa' => $id, 'kategoria' => '', 'cel' => '', 'odbiorca' => '', 'zawsze' => false, 'skrypty' => array(), 'ramki' => array(), 'piksele' => array(), 'hosty' => array(), 'ciasteczka' => array(), 'storage' => array(), 'uchwyty' => array(), 'wtyczki' => array() )
			);
		}
		unset( $u );
		self::$bufor = $uslugi;
		return $uslugi;
	}

	/**
	 * Usługa, której wzorzec danego rodzaju pasuje do tekstu.
	 *
	 * Kolejność: najpierw usługi „zawsze" (adres Turnstile ma wygrać z każdym szerszym
	 * wzorcem), potem marketingowe, na końcu analityczne. Jeden znacznik `gtag/js?id=AW-…`
	 * albo skrypt inline z `config G-…` i `config AW-…` naraz pasuje do obu kategorii —
	 * wygrywa surowsza, bo zgoda na statystyki nie jest zgodą na reklamy.
	 *
	 * @param string $tekst  Adres, atrybuty albo treść skryptu.
	 * @param string $rodzaj skrypty | ramki | piksele | hosty
	 * @return string Identyfikator usługi albo ''.
	 */
	public static function usluga_dla_tekstu( $tekst, $rodzaj ) {
		if ( '' === (string) $tekst ) {
			return '';
		}
		$uslugi    = self::wszystkie();
		$waga      = static function ( $u ) {
			return ! empty( $u['zawsze'] ) ? 0 : ( 'marketingowe' === $u['kategoria'] ? 1 : 2 );
		};
		$kolejnosc = array_keys( $uslugi );
		usort( $kolejnosc, static function ( $a, $b ) use ( $uslugi, $waga ) { return $waga( $uslugi[ $a ] ) - $waga( $uslugi[ $b ] ); } );
		foreach ( $kolejnosc as $id ) {
			foreach ( (array) $uslugi[ $id ][ $rodzaj ] as $wzorzec ) {
				if ( '' !== $wzorzec && false !== stripos( $tekst, (string) $wzorzec ) ) {
					return $id;
				}
			}
		}
		return '';
	}

	/**
	 * Kategoria z biblioteki. Usługa „zawsze" zwraca `niezbedne`.
	 *
	 * @param string $tekst  Tekst.
	 * @param string $rodzaj Rodzaj wzorca.
	 * @return string
	 */
	public static function kategoria_dla_tekstu( $tekst, $rodzaj ) {
		$id = self::usluga_dla_tekstu( $tekst, $rodzaj );
		return '' === $id ? '' : (string) self::wszystkie()[ $id ]['kategoria'];
	}

	/**
	 * Czy tekst należy do usługi, której NIE WOLNO blokować (Turnstile, zapis decyzji).
	 *
	 * @param string $tekst Tekst.
	 * @return bool
	 */
	public static function zawsze_dozwolone( $tekst ) {
		foreach ( array( 'skrypty', 'ramki', 'piksele' ) as $rodzaj ) {
			$id = self::usluga_dla_tekstu( $tekst, $rodzaj );
			if ( '' !== $id && ! empty( self::wszystkie()[ $id ]['zawsze'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Czy nazwa pasuje do wzorca z gwiazdką na końcu (`_ga_*`).
	 *
	 * @param string $nazwa   Nazwa ciasteczka.
	 * @param string $wzorzec Wzorzec.
	 * @return bool
	 */
	public static function pasuje_nazwa( $nazwa, $wzorzec ) {
		$wzorzec = (string) $wzorzec;
		if ( '*' === substr( $wzorzec, -1 ) ) {
			return 0 === strpos( (string) $nazwa, substr( $wzorzec, 0, -1 ) );
		}
		return (string) $nazwa === $wzorzec;
	}

	/**
	 * Kto zostawia to ciasteczko. Listy z ustawień kategorii mają pierwszeństwo przed
	 * biblioteką — to tam projekt dopisuje ciasteczka, których biblioteka nie zna.
	 *
	 * @param string $nazwa Nazwa ciasteczka.
	 * @return array{kategoria:string,usluga:string,czas:string}
	 */
	public static function ciasteczko( $nazwa ) {
		$u = Nerti_Cookies_Ustawienia::pobierz();
		foreach ( $u['kategorie'] as $kat_id => $kat ) {
			foreach ( (array) ( $kat['ciasteczka'] ?? array() ) as $w ) {
				if ( self::pasuje_nazwa( $nazwa, $w ) ) {
					$z_bib = self::ciasteczko_z_biblioteki( $nazwa );
					return array( 'kategoria' => (string) $kat_id, 'usluga' => $z_bib['usluga'], 'czas' => $z_bib['czas'] );
				}
			}
		}
		return self::ciasteczko_z_biblioteki( $nazwa );
	}

	/**
	 * @param string $nazwa Nazwa ciasteczka.
	 * @return array{kategoria:string,usluga:string,czas:string}
	 */
	private static function ciasteczko_z_biblioteki( $nazwa ) {
		// Najpierw usługi „zawsze": `__cf_bm` stawia i Turnstile, i Vimeo — w razie wątpliwości niezbędne.
		$uslugi = self::wszystkie();
		uasort( $uslugi, static function ( $a, $b ) { return (int) ! empty( $b['zawsze'] ) - (int) ! empty( $a['zawsze'] ); } );
		foreach ( $uslugi as $id => $usl ) {
			foreach ( $usl['ciasteczka'] as $w => $czas ) {
				if ( self::pasuje_nazwa( $nazwa, $w ) ) {
					return array( 'kategoria' => (string) $usl['kategoria'], 'usluga' => (string) $id, 'czas' => (string) $czas );
				}
			}
		}
		return array( 'kategoria' => '', 'usluga' => '', 'czas' => '' );
	}

	/**
	 * Wzorce ciasteczek i storage na kategorię — z ustawień i z biblioteki razem.
	 * Baner kasuje je po odmowie i po wycofaniu zgody.
	 *
	 * @param string $kategoria Kategoria.
	 * @param string $pole      ciasteczka | storage
	 * @return array<int,string>
	 */
	public static function wzorce_kategorii( $kategoria, $pole ) {
		$u     = Nerti_Cookies_Ustawienia::pobierz();
		$lista = (array) ( $u['kategorie'][ $kategoria ][ $pole ] ?? array() );
		foreach ( self::wszystkie() as $usl ) {
			if ( $usl['kategoria'] !== $kategoria || ! empty( $usl['zawsze'] ) ) {
				continue;
			}
			$lista = array_merge( $lista, 'ciasteczka' === $pole ? array_keys( $usl['ciasteczka'] ) : (array) $usl['storage'] );
		}
		return array_values( array_unique( array_map( 'strval', $lista ) ) );
	}

	/**
	 * Wersja do MCP i panelu: bez wzorców wewnętrznych, z tym, co człowiek ma zrozumieć.
	 *
	 * @return array<int,array>
	 */
	public static function lista() {
		$out = array();
		foreach ( self::wszystkie() as $id => $u ) {
			$out[] = array(
				'id'         => $id,
				'nazwa'      => $u['nazwa'],
				'kategoria'  => $u['kategoria'],
				'blokowana'  => empty( $u['zawsze'] ) && 'niezbedne' !== $u['kategoria'],
				'cel'        => $u['cel'],
				'odbiorca'   => $u['odbiorca'],
				'ciasteczka' => $u['ciasteczka'],
				'wzorce'     => array_values( array_unique( array_merge( $u['skrypty'], $u['ramki'], $u['piksele'] ) ) ),
			);
		}
		return $out;
	}
}
