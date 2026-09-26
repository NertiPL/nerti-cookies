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
			'consent_mode'     => true,
			'bufor_wyjscia'    => true,
			'link_polityka'    => '',
			'link_cookies'     => '',
			'administrator'    => '',

			/*
			 * Kto wystawia wejście do wycofania zgody. Wycofanie musi być osiągalne z każdej
			 * podstrony — to wymóg, nie udogodnienie — więc domyślnie robi to wtyczka i nikt
			 * nie musi o tym pamiętać. 'strona' oddaje je projektowi: wejściem jest wtedy
			 * element strony z atrybutem data-nc-akcja="otworz", a wtyczka nie dokłada
			 * własnego, żeby nie psuć układu. Na Oxygenie to jedyna droga w treści strony,
			 * bo builder omija the_content i skrót [nerti_cookies_ustawienia] się nie wykona.
			 */
			'wycofanie'        => 'wtyczka',

			/*
			 * Wygląd ustawiany osobno dla telefonu i dla desktopu.
			 *
			 * Jeden breakpoint, 48rem (768px przy domyślnym kroku przeglądarki), ten sam
			 * w ustawieniach i w `assets/baner.css`. Wybrany nie z listy popularnych
			 * breakpointów, tylko z jedynego progu, który dla TEGO elementu coś zmienia:
			 * poniżej 48rem panel o szerokości 30rem zajmuje cały ekran i dwa przyciski
			 * przestają się mieścić obok siebie. Drugi breakpoint dokładałby ustawienie,
			 * którego klient nie umiałby rozstrzygnąć.
			 *
			 * `tekst` => 'auto' znaczy „nie nadpisuj" — wtedy działa rozmiar z importu
			 * tokenów. Trzy nazwane rozmiary zamiast pola z jednostkami, bo klient, który
			 * wpisze tam `20` bez `px`, dostanie baner bez stylu i nie będzie wiedział czemu.
			 */
			'wyglad'           => array(
				'desktop' => array(
					'pozycja'   => 'dol',
					'szerokosc' => 46,
					'tekst'     => 'auto',
				),
				'mobile'  => array(
					'pozycja'   => 'dol',
					'szerokosc' => 30,
					'tekst'     => 'auto',
				),
			),

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
				'zamknij'      => 'Zamknij bez zmiany ustawień',
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
		self::$bufor = self::scal( self::domyslne(), $zapisane );
		self::$bufor['wersja_banera'] = NERTI_COOKIES_WERSJA;
		foreach (self::$bufor['kategorie'] as $id => &$kat) {
			$kat['wymagana'] = 'niezbedne' === $id;
			$kat['domyslnie'] = $kat['wymagana'];
		}
		unset($kat);
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
	public static function scal( array $baza, array $nowe ) {
		foreach ( $nowe as $k => $v ) {
			$baza[$k] = is_array($v) && $v && !is_numeric(key($v)) && isset($baza[$k]) && is_array($baza[$k]) ? self::scal($baza[$k], $v) : $v;
		}
		return $baza;
	}

	public static function zapisz( array $nowe ) {
		// Jedna walidacja dla panelu, REST i MCP; null nie oznacza pominięcia pola.
		foreach ($nowe as $k => $v) {
			if (null === $v) return new WP_Error('nc_config', 'Pole '.$k.' nie może być null.', array('status'=>400));
		}
		foreach (array('wersja_polityki','link_polityka','link_cookies','administrator') as $k) {
			if (isset($nowe[$k]) && !is_string($nowe[$k])) return new WP_Error('nc_config', 'Pole '.$k.' musi być tekstem.', array('status'=>400));
		}
		foreach (array('teksty','kategorie','wzorce','osadzenia','uchwyty','wyglad') as $k) {
			if (isset($nowe[$k]) && !is_array($nowe[$k])) return new WP_Error('nc_config', 'Pole '.$k.' musi być obiektem.', array('status'=>400));
		}
		if (isset($nowe['waznosc_dni']) && (!is_int($nowe['waznosc_dni']) || $nowe['waznosc_dni'] < 1 || $nowe['waznosc_dni'] > 365)) return new WP_Error('nc_config','Ważność: liczba dni 1–365.',array('status'=>400));
		foreach (($nowe['kategorie'] ?? array()) as $id=>$kat) {
			if (!in_array($id,array('niezbedne','analityczne','marketingowe'),true) || !is_array($kat)) return new WP_Error('nc_category','Nieznana kategoria.',array('status'=>400));
			foreach ($kat as $pole=>$v) {
				if (null === $v || (in_array($pole,array('nazwa','opis'),true) && !is_string($v)) || (in_array($pole,array('wymagana','domyslnie','ukryta'),true) && !is_bool($v))) return new WP_Error('nc_category','Nieprawidłowa wartość kategorii.',array('status'=>400));
			}
			foreach (array('ciasteczka','storage') as $pole) {
				if (isset($kat[$pole]) && (!is_array($kat[$pole]) || count(array_filter($kat[$pole], 'is_string')) !== count($kat[$pole]))) return new WP_Error('nc_category','Wzorce muszą być listą tekstów.',array('status'=>400));
			}
			if ($id !== 'niezbedne' && (!empty($kat['wymagana']) || !empty($kat['domyslnie']))) return new WP_Error('nc_consent','Kategorie opcjonalne wymagają aktywnego wyboru.',array('status'=>400));
		}
		foreach (array('wzorce','osadzenia','uchwyty') as $mapa) foreach (($nowe[$mapa] ?? array()) as $w=>$kat) {
			if ('' === (string)$w || !in_array($kat,array('niezbedne','analityczne','marketingowe'),true)) return new WP_Error('nc_pattern','Nieprawidłowy wzorzec lub kategoria.',array('status'=>400));
		}
		foreach (array('consent_mode','bufor_wyjscia') as $k) if (isset($nowe[$k]) && !is_bool($nowe[$k])) return new WP_Error('nc_config','Przełącznik musi być bool.',array('status'=>400));
		if (isset($nowe['wycofanie']) && !in_array($nowe['wycofanie'], array('wtyczka','strona'), true)) return new WP_Error('nc_config','Wycofanie: wtyczka albo strona.',array('status'=>400));
		foreach (($nowe['teksty'] ?? array()) as $v) if (!is_string($v)) return new WP_Error('nc_config','Teksty muszą być ciągami znaków.',array('status'=>400));
		$biezace = get_option( self::OPCJA, array() );
		$biezace = is_array( $biezace ) ? $biezace : array();
		$scalone = self::scal( $biezace, $nowe );
		$scalone = self::pilnuj_odmowy( $scalone );
		$scalone['kategorie']['niezbedne']['wymagana'] = true;
		$scalone['kategorie']['niezbedne']['domyslnie'] = true;
		update_option( self::OPCJA, $scalone, false );
		if (get_option(self::OPCJA) !== $scalone) return new WP_Error('nc_write','Nie potwierdzono zapisu konfiguracji.',array('status'=>500));
		self::$bufor = null;
		return self::pobierz();
	}

	/**
	 * Bramka Q-COOKIE: przycisk odmowy zostaje na pierwszym ekranie i wygląda tak samo
	 * jak przycisk zgody.
	 *
	 * Klasa `nc-btn--rowny` i miejsce w znaczniku są w `class-baner.php` na sztywno —
	 * nie da się ich wyklikać. Wyklikać da się jedno: wyczyścić tekst. Pusty przycisk
	 * jest przyciskiem niewidocznym, czyli odmową schowaną — dokładnie tym defektem,
	 * którego reszta wtyczki pilnuje. Dlatego pusty tekst wraca do domyślnego, a nie
	 * zostaje pusty.
	 *
	 * Stoi w `zapisz()`, a nie w obsłudze formularza, bo ustawienia zmienia też MCP
	 * (`nerti-cookies/zapisz-ustawienia`) i REST. Bramka w jednym z tych trzech miejsc
	 * jest bramką, którą omija się przez dwa pozostałe.
	 *
	 * @param array $u Ustawienia po scaleniu.
	 * @return array
	 */
	private static function pilnuj_odmowy( array $u ) {
		$domyslne = self::domyslne();

		// Bez tekstu nie ma przycisku; te trzy muszą istnieć, żeby pierwszy ekran był
		// kompletny — odmowa, zgoda i wejście w wybór szczegółowy.
		foreach ( array( 'odrzuc', 'akceptuj', 'ustawienia' ) as $klucz ) {
			if ( ! isset( $u['teksty'][ $klucz ] ) || '' === trim( (string) $u['teksty'][ $klucz ] ) ) {
				$u['teksty'][ $klucz ] = $domyslne['teksty'][ $klucz ];
			}
		}
		return $u;
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
		$wynik = self::zapisz( array( 'wersja_polityki' => sanitize_text_field( $wersja ) ) );
		return is_wp_error($wynik) ? $wynik : $wynik['wersja_polityki'];
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
			/*
			 * Kategoria „ukryta" znika z banera, ale ZOSTAJE w mapach skryptów i osadzeń.
			 * Po co: strona, która dziś nie prowadzi marketingu, nie ma o co pytać —
			 * zgoda na coś, czego się nie robi, jest pusta i musiałaby być opisana
			 * w polityce. Gdy marketing wejdzie, wystarczy zdjąć flagę: mapy, wzorce
			 * i blokada są już na miejscu, a do tego czasu te skrypty pozostają
			 * zablokowane, bo zgoda nigdy nie zostaje udzielona.
			 */
			
			$k[] = array(
				'id'         => $id,
				'ukryta'     => !empty($kat['ukryta']),
				// Z biblioteką usług: kasowanie po wycofaniu ma sprzątać także ciasteczka usług,
				// których projekt nie wpisał ręcznie do kategorii.
				'storage'    => Nerti_Cookies_Uslugi::wzorce_kategorii( $id, 'storage' ),
				'nazwa'      => $kat['nazwa'],
				'opis'       => $kat['opis'],
				'wymagana'   => (bool) $kat['wymagana'],
				'domyslnie'  => (bool) $kat['domyslnie'],
				'ciasteczka' => Nerti_Cookies_Uslugi::wzorce_kategorii( $id, 'ciasteczka' ),
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

	/** Zakładki ekranu, w kolejności wyświetlania. */
	public static function zakladki() {
		return array(
			'stan'      => 'Stan ochrony',
			'wyglad'    => 'Wygląd',
			'teksty'    => 'Teksty',
			'kategorie' => 'Kategorie i skrypty',
			'dokumenty' => 'Dokumenty',
			'testy' => 'Inwentarz i testy',
			'rejestr'   => 'Historia zgód',
		);
	}

	/**
	 * Pozycja najwyższego poziomu, nie podstrona Ustawień.
	 *
	 * Powód: to jest ekran, na który klient wchodzi sam — zmienić tekst, sprawdzić
	 * liczbę odmów, podnieść wersję polityki po zmianie treści. Schowany pod
	 * `Ustawienia → Nerti Cookies` bywa nieznajdowany, bo w lewym pasku nie ma po nim
	 * śladu, a `Ustawienia` to worek, do którego wtyczki wrzucają wszystko.
	 * Slug zostaje ten sam (`nerti-cookies`), więc stare zakładki dalej działają.
	 */
	public static function menu() {
		$strona = add_menu_page(
			'Nerti Cookies',
			'Ciasteczka',
			'manage_options',
			'nerti-cookies',
			array( __CLASS__, 'ekran' ),
			'dashicons-privacy',
			76 // Tuż nad Ustawieniami (80), pod Wtyczkami.
		);
		add_action( 'load-' . $strona, array( __CLASS__, 'zasoby_panelu' ) );
	}

	/**
	 * Zasoby panelu wchodzą TYLKO na własnym ekranie — arkusz banera ma reguły na
	 * `.nc-btn` i `position: fixed`, więc wpuszczony na cały panel WordPressa
	 * pomalowałby cudze ekrany.
	 */
	public static function zasoby_panelu() {
		add_action(
			'admin_enqueue_scripts',
			static function () {
				wp_enqueue_style( 'nerti-cookies', NERTI_COOKIES_URL . 'assets/baner.css', array(), NERTI_COOKIES_WERSJA );
				wp_enqueue_style( 'nerti-cookies-panel', NERTI_COOKIES_URL . 'assets/panel.css', array( 'nerti-cookies' ), NERTI_COOKIES_WERSJA );

				$zmienne = Nerti_Cookies_Baner::css_zmiennych();
				if ( '' !== $zmienne ) {
					wp_add_inline_style( 'nerti-cookies-panel', $zmienne );
				}

				wp_enqueue_script( 'nerti-cookies-panel', NERTI_COOKIES_URL . 'assets/panel.js', array(), NERTI_COOKIES_WERSJA, true );
				wp_localize_script(
					'nerti-cookies-panel',
					'ncPanel',
					array(
						'pozycje'  => Nerti_Cookies_Tokeny::pozycje(),
						'rozmiary' => Nerti_Cookies_Tokeny::rozmiary(),
					)
				);
			}
		);
	}

	/**
	 * Opis każdego pola tekstowego: gdzie ten tekst się pokazuje. Bez tego klient
	 * zgaduje, co edytuje, i sprawdza to publikując zmianę na żywej stronie.
	 *
	 * @return array
	 */
	public static function opisy_tekstow() {
		return array(
			'tytul'         => array( 'Nagłówek banera', 'Pierwsza linia banera, dużym krojem.', false ),
			'tresc'         => array( 'Treść banera', 'Akapit pod nagłówkiem. Jedno-dwa zdania — dłuższego nikt nie przeczyta przed kliknięciem.', true ),
			'odrzuc'        => array( 'Przycisk odmowy', 'Pierwszy przycisk na pierwszym ekranie. Musi zostać — pusty wraca do domyślnego.', false ),
			'akceptuj'      => array( 'Przycisk zgody', 'Drugi przycisk na pierwszym ekranie, tej samej wielkości co odmowa.', false ),
			'ustawienia'    => array( 'Wejście w wybór szczegółowy', 'Trzeci element pierwszego ekranu — rozwija listę kategorii.', false ),
			'zapisz'        => array( 'Zapis wyboru szczegółowego', 'Przycisk pod listą kategorii, widoczny po rozwinięciu.', false ),
			'zamknij'       => array( 'Opis krzyżyka', 'Czyta go czytnik ekranu; sam krzyżyk pokazuje się tylko wtedy, gdy projekt go włączy. Zamknięcie zapisuje decyzję zgodną z przełącznikami, nie omija jej.', false ),
			'polityka'      => array( 'Podpis linku do polityki prywatności', 'Widoczny w banerze tylko wtedy, gdy adres polityki jest uzupełniony w zakładce Stan.', false ),
			'cookies'       => array( 'Podpis linku do polityki cookies', 'Jak wyżej — pokazuje się tylko przy uzupełnionym adresie.', false ),
			'wycofaj'       => array( 'Link wycofania zgody', 'Tekst wejścia do zmiany zgody: przycisku wtyczki, skrótu [nerti_cookies_ustawienia] i elementu z data-nc-akcja="otworz".', false ),
			'osadzenie'     => array( 'Zastępnik zablokowanego materiału', 'Pokazuje się w miejscu filmu z YouTube albo mapy, dopóki nie ma zgody marketingowej.', true ),
			'osadzenie_btn' => array( 'Przycisk pod zastępnikiem', 'Klikniecie odblokowuje ten jeden materiał.', false ),
		);
	}

	public static function ekran() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$u      = self::pobierz();
		$zgody  = Nerti_Cookies_Rejestr::statystyki();
		$zrodlo = Nerti_Cookies_Tokeny::zrodlo();
		$oxy    = null !== Nerti_Cookies_Tokeny::ustawienia_oxygena();
		$akcja  = esc_url( admin_url( 'admin-post.php' ) );
		?>
		<div class="wrap nc-panel">
			<h1>Nerti Cookies <span class="nc-panel__wersja"><?php echo esc_html( NERTI_COOKIES_WERSJA ); ?></span></h1>

			<p class="nc-panel__zastrzezenie">
				<strong>To nie jest narzędzie do orzekania o zgodności z prawem.</strong>
				Wtyczka blokuje skrypty przed zgodą i zapisuje decyzje. Treść polityki
				zatwierdza właściciel strony albo jego prawnik.
			</p>

			<?php if ( ! empty( $zrodlo['ostrzezenia'] ) ) : ?>
				<div class="notice notice-warning">
					<p><strong>Import wyglądu zgłosił problem z czytelnością:</strong></p>
					<ul class="nc-panel__lista">
						<?php foreach ( $zrodlo['ostrzezenia'] as $o ) : ?>
							<li><?php echo esc_html( $o ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['nc-info'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['nc-info'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?></p>
				</div>
			<?php endif; ?>

			<h2 class="nav-tab-wrapper nc-taby">
				<?php foreach ( self::zakladki() as $id => $etykieta ) : ?>
					<a href="#nc-<?php echo esc_attr( $id ); ?>" class="nav-tab" data-nc-tab="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $etykieta ); ?></a>
				<?php endforeach; ?>
			</h2>

			<div class="nc-uklad">
				<div class="nc-uklad__tresc">

					<form method="post" action="<?php echo $akcja; ?>">
						<input type="hidden" name="action" value="nerti_cookies_zapisz">
						<?php wp_nonce_field( 'nerti_cookies_zapisz' ); ?>

						<?php self::sekcja_stan( $u, $zgody, $zrodlo ); ?>
						<?php self::sekcja_wyglad( $u, $zrodlo, $oxy ); ?>
						<?php self::sekcja_teksty( $u ); ?>
						<?php self::sekcja_kategorie( $u ); ?>

						<p class="nc-zapis"><?php submit_button( 'Zapisz zmiany', 'primary', 'submit', false ); ?></p>
					</form>

					<?php self::sekcja_rejestr( $zgody, $akcja ); ?>
					<?php self::sekcja_dokumenty_testy( $u ); ?>
				</div>

				<?php self::podglad(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Podgląd banera na żywo. Rysuje go tym samym `render()`, którego używa front,
	 * i tymi samymi zmiennymi — inaczej podgląd zacząłby żyć własnym życiem i
	 * pokazywałby baner, którego na stronie nie ma.
	 */
	private static function sekcja_dokumenty_testy(array $u) {
		$inw = Nerti_Cookies_Abilities::op_inwentarz(); ?>
		<section id="nc-dokumenty" class="nc-sekcja" data-nc-panel="dokumenty">
		<h2>Dokumenty</h2><p>Politykę przygotuj na podstawie rzeczywistych narzędzi, hostingu, formularzy i odbiorców danych. Brak faktów nie oznacza braku przetwarzania.</p>
		<ul><li>Administrator: <?php echo empty($u['administrator']) ? 'DO UZUPEŁNIENIA' : esc_html($u['administrator']); ?></li>
		<li>Polityka prywatności: <?php echo empty($u['link_polityka']) ? 'BRAK ADRESU' : esc_html($u['link_polityka']); ?></li>
		<li>Hosting, czas przechowywania i odbiorcy: potwierdź z właścicielem.</li></ul>
		<p>Adres dokumentu ustaw w zakładce Stan ochrony. Po zmianie treści podnieś wersję w Historii zgód. Wtyczka nie orzeka o zgodności prawnej.</p></section>
		<section id="nc-testy" class="nc-sekcja" data-nc-panel="testy"><h2>Inwentarz i testy</h2>
		<p>Rejestr WP z tego żądania jest niepełny: nie dowodzi, co pobiera przeglądarka na konkretnej stronie. Poniżej zarejestrowane skrypty, nie lista odbiorców danych.</p>
		<details><summary>Odczyt rejestru WordPressa</summary><pre><?php echo esc_html(wp_json_encode($inw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre></details>
		<ol><li>Nowa sesja: przed decyzją zero opcjonalnych żądań i zapisów.</li><li>Odrzuć, odśwież: nadal zero.</li><li>Wybierz jedną kategorię: druga pozostaje zablokowana.</li><li>Zaakceptuj i wróć: respektuj zapisaną decyzję.</li><li>Wycofaj: przeładowanie i usunięcie skonfigurowanych cookies oraz localStorage/sessionStorage.</li><li>Zmień wersję polityki: zapytaj ponownie.</li></ol>
		<p><strong>Te kroki to plan, nie wykonany test.</strong> Zachowaj datę, URL, wersję wtyczki, żądania sieciowe i stan pamięci dla każdego scenariusza. Nie testuj na prawdziwych odbiorcach bez zgody.</p>
		<p>Skrypty wstrzyknięte później przez obcy JS, piksele obrazkowe, HttpOnly i obce domeny wymagają konfiguracji u źródła; skrypt banera nie może ich dowolnie kasować.</p></section>
		<?php
	}

	private static function podglad() {
		?>
		<aside class="nc-uklad__podglad">
			<div class="nc-podglad__pasek">
				<strong>Podgląd</strong>
				<span class="nc-podglad__przelacznik">
					<button type="button" class="button button-small is-active" data-nc-urzadzenie="desktop">Desktop</button>
					<button type="button" class="button button-small" data-nc-urzadzenie="mobile">Telefon</button>
				</span>
			</div>
			<div class="nc-podglad" data-nc-podglad>
				<?php Nerti_Cookies_Baner::render( true ); ?>
			</div>
			<p class="description">Podgląd pokazuje pierwszy ekran banera. Zmiany widać od razu; na stronie pojawiają się po zapisaniu.</p>
		</aside>
		<?php
	}

	private static function sekcja_stan( array $u, array $zgody, array $zrodlo ) {
		?>
		<section id="nc-stan" class="nc-sekcja" data-nc-panel="stan">
			<h2>Stan ochrony</h2>
			<p>Włączona blokada nie jest wynikiem testu. Przejdź kolejno: inwentarz → kategorie → wygląd i teksty → dokumenty → test odmowy.</p>
			<table class="widefat striped nc-tabela">
				<tbody>
				<tr><td>Wersja polityki</td><td><code><?php echo esc_html( $u['wersja_polityki'] ); ?></code></td></tr>
				<tr><td>Zapisanych decyzji</td><td><?php echo esc_html( (string) $zgody['razem'] ); ?></td></tr>
				<tr><td>w tym odmów</td><td><?php echo esc_html( (string) $zgody['odmowy'] ); ?></td></tr>
				<tr><td>Blokada przez bufor wyjścia</td><td><?php echo $u['bufor_wyjscia'] ? 'włączona' : '<strong>wyłączona</strong>'; ?></td></tr>
				<tr><td>Consent Mode v2</td><td><?php echo $u['consent_mode'] ? 'włączony' : 'wyłączony'; ?></td></tr>
				<tr>
					<td>Wygląd banera</td>
					<td><strong><?php echo esc_html( Nerti_Cookies_Tokeny::opis_zrodla() ); ?></strong>
					<?php if ( '' !== $zrodlo['kiedy'] ) : ?>
						<span class="description">(import: <?php echo esc_html( $zrodlo['kiedy'] ); ?>)</span>
					<?php endif; ?>
					</td>
				</tr>
				</tbody>
			</table>

			<table class="form-table">
				<tr>
					<th><label for="nc-polityka">Adres polityki prywatności</label></th>
					<td><input id="nc-polityka" class="regular-text" type="url" name="link_polityka" value="<?php echo esc_attr( $u['link_polityka'] ); ?>">
					<p class="description">Póki jest pusty, baner nie pokazuje linku do polityki.</p></td>
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
					<th>Wycofanie zgody</th>
					<td><label><input type="radio" name="wycofanie" value="wtyczka" <?php checked( 'strona' !== $u['wycofanie'] ); ?>> wtyczka dokłada wejście na dole każdej podstrony</label><br>
					<label><input type="radio" name="wycofanie" value="strona" <?php checked( 'strona' === $u['wycofanie'] ); ?>> wejście jest w projekcie strony (element z <code>data-nc-akcja="otworz"</code>)</label>
					<p class="description">Zgodę trzeba dać się wycofać tak samo łatwo, jak się jej udzieliło. Drugą opcję wybierz dopiero wtedy, gdy takie wejście na stronie NAPRAWDĘ jest — inaczej zostaje baner bez wyjścia.</p></td>
				</tr>
				<tr>
					<th>Blokada przez bufor wyjścia</th>
					<td><label><input type="checkbox" name="bufor_wyjscia" value="1" <?php checked( (bool) $u['bufor_wyjscia'] ); ?>> łap też skrypty wklejone na sztywno w motyw</label></td>
				</tr>
			</table>
		</section>
		<?php
	}

	private static function sekcja_wyglad( array $u, array $zrodlo, $oxy ) {
		$pozycje  = Nerti_Cookies_Tokeny::pozycje();
		$rozmiary = Nerti_Cookies_Tokeny::rozmiary();
		$urzadzenia = array( 'desktop' => 'Desktop (szerokość od 48rem)', 'mobile' => 'Telefon (szerokość poniżej 48rem)' );
		?>
		<section id="nc-wyglad" class="nc-sekcja" data-nc-panel="wyglad">
			<h2>Wygląd</h2>

			<h3>Skąd baner bierze kolory i kroje</h3>
			<p>Wtyczka sprawdza źródła w tej kolejności i bierze pierwsze, które jest dostępne:</p>
			<ol class="nc-zrodla">
				<li class="<?php echo 'design-system' === $zrodlo['zrodlo'] ? 'nc-zrodla__aktywne' : ''; ?>">
					<strong>design-system.json projektu</strong> — pełny zestaw tokenów, wczytywany przez MCP
					(<code>nerti-cookies/importuj-tokeny</code>). Najdokładniejszy, bo pochodzi z projektu strony.
				</li>
				<li class="<?php echo 'oxygen' === $zrodlo['zrodlo'] ? 'nc-zrodla__aktywne' : ''; ?>">
					<strong>Ustawienia globalne Oxygena</strong> — kolory, kroje, rozmiar bazowy, promień i cień
					przycisku. <?php echo $oxy ? 'Na tej instalacji są ustawione.' : '<em>Na tej instalacji są puste.</em>'; ?>
				</li>
				<li class="<?php echo 'domyslne' === $zrodlo['zrodlo'] ? 'nc-zrodla__aktywne' : ''; ?>">
					<strong>Domyślne wtyczki</strong> — neutralna czerń na bieli. Zawsze czytelne, nigdy nie pasuje.
				</li>
			</ol>
			<p>Teraz obowiązuje: <strong><?php echo esc_html( Nerti_Cookies_Tokeny::opis_zrodla() ); ?></strong>.</p>

			<?php if ( ! empty( $zrodlo['pomiary'] ) ) : ?>
				<p class="description">Zmierzony kontrast:
				<?php
				$czesci = array();
				foreach ( $zrodlo['pomiary'] as $opis => $r ) {
					$czesci[] = esc_html( $opis . ' — ' . number_format_i18n( $r, 2 ) . ':1' );
				}
				echo wp_kses_post( implode( ', ', $czesci ) );
				?>
				. Próg to 4,5:1.</p>
			<?php endif; ?>

			<p>
				<button type="submit" class="button" name="importuj_oxygen" value="1" <?php disabled( ! $oxy ); ?>>
					Wczytaj wygląd z Oxygena
				</button>
				<button type="submit" class="button" name="wyczysc_tokeny" value="1" <?php disabled( 'domyslne' === $zrodlo['zrodlo'] ); ?>>
					Wróć do domyślnych
				</button>
			</p>

			<h3>Ustawienie osobno dla telefonu i dla desktopu</h3>
			<p class="description">Granica między nimi to 48rem (przy domyślnych ustawieniach przeglądarki 768&nbsp;px) — jedyny próg, na którym dwa przyciski przestają się mieścić obok siebie.</p>

			<div class="nc-urzadzenia">
				<?php foreach ( $urzadzenia as $klucz => $etykieta ) : ?>
					<?php $w = isset( $u['wyglad'][ $klucz ] ) ? $u['wyglad'][ $klucz ] : array(); ?>
					<fieldset class="nc-urzadzenie">
						<legend><?php echo esc_html( $etykieta ); ?></legend>

						<p>
							<label for="nc-poz-<?php echo esc_attr( $klucz ); ?>">Pozycja na ekranie</label><br>
							<select id="nc-poz-<?php echo esc_attr( $klucz ); ?>" name="wyglad[<?php echo esc_attr( $klucz ); ?>][pozycja]" data-nc-wyglad="pozycja" data-nc-dla="<?php echo esc_attr( $klucz ); ?>">
								<?php foreach ( $pozycje as $id => $p ) : ?>
									<option value="<?php echo esc_attr( $id ); ?>" <?php selected( isset( $w['pozycja'] ) ? $w['pozycja'] : 'dol', $id ); ?>><?php echo esc_html( $p['nazwa'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>

						<p>
							<label for="nc-szer-<?php echo esc_attr( $klucz ); ?>">Szerokość panelu (rem)</label><br>
							<input id="nc-szer-<?php echo esc_attr( $klucz ); ?>" type="number" min="16" max="100" step="1"
								name="wyglad[<?php echo esc_attr( $klucz ); ?>][szerokosc]"
								value="<?php echo esc_attr( (string) ( isset( $w['szerokosc'] ) ? $w['szerokosc'] : 46 ) ); ?>"
								data-nc-wyglad="szerokosc" data-nc-dla="<?php echo esc_attr( $klucz ); ?>">
							<span class="description">Baner nigdy nie przekroczy szerokości ekranu, więc na telefonie duża liczba znaczy „na całą szerokość".</span>
						</p>

						<p>
							<label for="nc-tekst-<?php echo esc_attr( $klucz ); ?>">Wielkość tekstu</label><br>
							<select id="nc-tekst-<?php echo esc_attr( $klucz ); ?>" name="wyglad[<?php echo esc_attr( $klucz ); ?>][tekst]" data-nc-wyglad="tekst" data-nc-dla="<?php echo esc_attr( $klucz ); ?>">
								<?php foreach ( $rozmiary as $id => $r ) : ?>
									<option value="<?php echo esc_attr( $id ); ?>" <?php selected( isset( $w['tekst'] ) ? $w['tekst'] : 'auto', $id ); ?>><?php echo esc_html( $r['nazwa'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>
					</fieldset>
				<?php endforeach; ?>
			</div>
		</section>
		<?php
	}

	private static function sekcja_teksty( array $u ) {
		?>
		<section id="nc-teksty" class="nc-sekcja" data-nc-panel="teksty">
			<h2>Teksty</h2>
			<p>Wszystko, co odwiedzający przeczyta. Zmiany widać od razu w podglądzie obok.</p>

			<div class="notice notice-info inline">
				<p><strong>Przycisku odmowy nie da się ukryć.</strong> Stoi na pierwszym ekranie, ma tę samą
				wielkość i wagę co przycisk zgody, i wymaga jednego kliknięcia — tak samo jak zgoda.
				Wyczyszczenie jego tekstu przywraca tekst domyślny, a nie zostawia pustego przycisku.</p>
			</div>

			<table class="form-table">
				<?php foreach ( self::opisy_tekstow() as $klucz => $meta ) : ?>
					<?php list( $etykieta, $gdzie, $dlugie ) = $meta; ?>
					<tr>
						<th><label for="nc-t-<?php echo esc_attr( $klucz ); ?>"><?php echo esc_html( $etykieta ); ?></label></th>
						<td>
							<?php if ( $dlugie ) : ?>
								<textarea id="nc-t-<?php echo esc_attr( $klucz ); ?>" class="large-text" rows="3"
									name="teksty[<?php echo esc_attr( $klucz ); ?>]"
									data-nc-pole="<?php echo esc_attr( $klucz ); ?>"><?php echo esc_textarea( $u['teksty'][ $klucz ] ); ?></textarea>
							<?php else : ?>
								<input id="nc-t-<?php echo esc_attr( $klucz ); ?>" class="regular-text" type="text"
									name="teksty[<?php echo esc_attr( $klucz ); ?>]"
									value="<?php echo esc_attr( $u['teksty'][ $klucz ] ); ?>"
									data-nc-pole="<?php echo esc_attr( $klucz ); ?>">
							<?php endif; ?>
							<p class="description"><?php echo esc_html( $gdzie ); ?></p>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>
		</section>
		<?php
	}

	private static function sekcja_kategorie( array $u ) {
		?>
		<section id="nc-kategorie" class="nc-sekcja" data-nc-panel="kategorie">
			<h2>Kategorie i skrypty</h2>
			<p>Nazwa i opis każdej kategorii pokazują się na drugim ekranie banera, po kliknięciu
			„<?php echo esc_html( $u['teksty']['ustawienia'] ); ?>". Opis ma mówić, co ta kategoria robi
			odwiedzającemu, a nie jak nazywa się narzędzie.</p>

			<?php foreach ( $u['kategorie'] as $id => $kat ) : ?>
				<fieldset class="nc-kat">
					<legend>
						<?php echo esc_html( $kat['nazwa'] ); ?>
						<?php if ( ! empty( $kat['wymagana'] ) ) : ?>
							<span class="nc-znacznik">zawsze włączona</span>
						<?php endif; ?>
					</legend>
					<p>
						<label for="nc-kn-<?php echo esc_attr( $id ); ?>">Nazwa</label><br>
						<input id="nc-kn-<?php echo esc_attr( $id ); ?>" class="regular-text" type="text"
							name="kategorie[<?php echo esc_attr( $id ); ?>][nazwa]" value="<?php echo esc_attr( $kat['nazwa'] ); ?>">
					</p>
					<p>
						<label for="nc-ko-<?php echo esc_attr( $id ); ?>">Opis</label><br>
						<textarea id="nc-ko-<?php echo esc_attr( $id ); ?>" class="large-text" rows="3"
							name="kategorie[<?php echo esc_attr( $id ); ?>][opis]"><?php echo esc_textarea( $kat['opis'] ); ?></textarea>
					</p>
					<p class="description">Blokowane ciasteczka: <code><?php echo esc_html( implode( ', ', (array) $kat['ciasteczka'] ) ); ?></code></p>
				</fieldset>
			<?php endforeach; ?>

			<h3>Co jest blokowane przed zgodą</h3>
			<p class="description">Tę listę zmienia się przez MCP (<code>nerti-cookies/zapisz-ustawienia</code>)
			albo przez inwentarz skryptów (<code>nerti-cookies/inwentarz-skryptow</code>). Nie ma jej tutaj do
			wyklikania, bo pomyłka w jednym wzorcu odblokowuje skrypt przed zgodą i nikt tego nie zauważy.</p>

			<table class="widefat striped nc-tabela">
				<thead><tr><th>Wzorzec</th><th>Kategoria</th></tr></thead>
				<tbody>
				<?php foreach ( array( 'uchwyty' => 'uchwyt WordPressa', 'wzorce' => 'adres lub treść skryptu', 'osadzenia' => 'osadzenie' ) as $grupa => $co ) : ?>
					<?php foreach ( (array) $u[ $grupa ] as $wzorzec => $kategoria ) : ?>
						<tr>
							<td><code><?php echo esc_html( $wzorzec ); ?></code> <span class="description">(<?php echo esc_html( $co ); ?>)</span></td>
							<td><?php echo esc_html( $kategoria ); ?></td>
						</tr>
					<?php endforeach; ?>
				<?php endforeach; ?>
				</tbody>
			</table>
		</section>
		<?php
	}

	private static function sekcja_rejestr( array $zgody, $akcja ) {
		?>
		<section id="nc-rejestr" class="nc-sekcja" data-nc-panel="rejestr">
			<h2>Rejestr zgód</h2>
			<table class="widefat striped nc-tabela">
				<tbody>
				<tr><td>Zapisanych decyzji</td><td><?php echo esc_html( (string) $zgody['razem'] ); ?></td></tr>
				<tr><td>w tym odmów</td><td><?php echo esc_html( (string) $zgody['odmowy'] ); ?></td></tr>
				</tbody>
			</table>
			<p class="description">Rejestr z zerem odmów przy setkach zgód jest sygnałem defektu, nie sukcesu —
			znaczy zwykle, że przycisk odmowy jest niewidoczny albo nie zapisuje.</p>

			<p>Eksport całego rejestru do pliku CSV. Potrzebny wtedy, gdy ktoś kwestionuje zgodę — a wtedy nikt nie ma czasu na zapytanie SQL.</p>
			<form method="post" action="<?php echo $akcja; ?>">
				<input type="hidden" name="action" value="nerti_cookies_eksport">
				<?php wp_nonce_field( 'nerti_cookies_eksport' ); ?>
				<?php submit_button( 'Pobierz CSV', 'secondary', 'submit', false ); ?>
			</form>

			<h3>Nowa wersja polityki</h3>
			<p>Podniesienie wersji <strong>unieważnia wszystkie dotychczasowe zgody</strong> i pokazuje baner od nowa.
			Robisz to, gdy zmieniła się treść polityki albo lista narzędzi na stronie.</p>
			<form method="post" action="<?php echo $akcja; ?>">
				<input type="hidden" name="action" value="nerti_cookies_zapisz">
				<input type="hidden" name="podnies_wersje" value="1">
				<?php wp_nonce_field( 'nerti_cookies_zapisz' ); ?>
				<?php submit_button( 'Podnieś wersję polityki', 'secondary', 'submit', false ); ?>
			</form>
		</section>
		<?php
	}

	public static function zapisz_z_panelu() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Brak uprawnień.' );
		}
		check_admin_referer( 'nerti_cookies_zapisz' );

		$info = 'Zapisano.';

		if ( isset( $_POST['podnies_wersje'] ) ) {
			$info = 'Wersja polityki podniesiona do ' . self::podnies_wersje_polityki() . '. Wszystkie dotychczasowe zgody zostały unieważnione.';
		} elseif ( isset( $_POST['importuj_oxygen'] ) ) {
			$w    = Nerti_Cookies_Tokeny::importuj_z_oxygena();
			$info = ! empty( $w['ok'] )
				? sprintf( 'Wygląd wczytany z Oxygena: %d zmiennych.', (int) $w['ile'] ) . ( empty( $w['ostrzezenia'] ) ? '' : ' Zgłoszono problem z kontrastem — patrz komunikat wyżej.' )
				: 'Nie udało się wczytać z Oxygena: ' . $w['blad'];
		} elseif ( isset( $_POST['wyczysc_tokeny'] ) ) {
			delete_option( Nerti_Cookies_Tokeny::OPCJA );
			delete_option( Nerti_Cookies_Tokeny::OPCJA_ZRODLO );
			$info = 'Baner wrócił do wyglądu domyślnego.';
		} else {
			$nowe = array(
				'link_polityka' => isset( $_POST['link_polityka'] ) ? esc_url_raw( wp_unslash( $_POST['link_polityka'] ) ) : '',
				'link_cookies'  => isset( $_POST['link_cookies'] ) ? esc_url_raw( wp_unslash( $_POST['link_cookies'] ) ) : '',
				'waznosc_dni'   => isset( $_POST['waznosc_dni'] ) ? max( 1, min( 365, (int) $_POST['waznosc_dni'] ) ) : 182,
				'bufor_wyjscia' => isset( $_POST['bufor_wyjscia'] ),
				'wycofanie'     => isset( $_POST['wycofanie'] ) && 'strona' === $_POST['wycofanie'] ? 'strona' : 'wtyczka',
			);

			// Teksty: przez sanitize_text_field, bo trafiają do znacznika banera.
			// Pusty tekst odmowy naprawia `zapisz()`, nie to miejsce — patrz pilnuj_odmowy().
			if ( isset( $_POST['teksty'] ) && is_array( $_POST['teksty'] ) ) {
				$dozwolone = array_keys( self::opisy_tekstow() );
				$teksty    = array();
				foreach ( wp_unslash( $_POST['teksty'] ) as $klucz => $wartosc ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
					if ( in_array( $klucz, $dozwolone, true ) ) {
						$teksty[ $klucz ] = sanitize_text_field( (string) $wartosc );
					}
				}
				$nowe['teksty'] = $teksty;
			}

			// Kategorie: tylko nazwa i opis. Reszta — `wymagana`, `ciasteczka` — nie jest
			// w formularzu i nie może dać się podstawić przez dopisanie pola do POST-a.
			if ( isset( $_POST['kategorie'] ) && is_array( $_POST['kategorie'] ) ) {
				$biezace = self::pobierz();
				$kat     = array();
				foreach ( wp_unslash( $_POST['kategorie'] ) as $id => $pola ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
					if ( ! isset( $biezace['kategorie'][ $id ] ) || ! is_array( $pola ) ) {
						continue;
					}
					$kat[ $id ] = array(
						'nazwa' => sanitize_text_field( (string) ( $pola['nazwa'] ?? '' ) ),
						'opis'  => sanitize_text_field( (string) ( $pola['opis'] ?? '' ) ),
					);
					if ( '' === $kat[ $id ]['nazwa'] ) {
						$kat[ $id ]['nazwa'] = $biezace['kategorie'][ $id ]['nazwa'];
					}
				}
				$nowe['kategorie'] = $kat;
			}

			if ( isset( $_POST['wyglad'] ) && is_array( $_POST['wyglad'] ) ) {
				$pozycje  = Nerti_Cookies_Tokeny::pozycje();
				$rozmiary = Nerti_Cookies_Tokeny::rozmiary();
				$wyglad   = array();
				foreach ( array( 'desktop', 'mobile' ) as $urzadzenie ) {
					$w = isset( $_POST['wyglad'][ $urzadzenie ] ) && is_array( $_POST['wyglad'][ $urzadzenie ] )
						? wp_unslash( $_POST['wyglad'][ $urzadzenie ] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
						: array();
					$pozycja = isset( $w['pozycja'] ) ? (string) $w['pozycja'] : 'dol';
					$tekst   = isset( $w['tekst'] ) ? (string) $w['tekst'] : 'auto';
					$wyglad[ $urzadzenie ] = array(
						'pozycja'   => isset( $pozycje[ $pozycja ] ) ? $pozycja : 'dol',
						'szerokosc' => max( 16, min( 100, isset( $w['szerokosc'] ) ? (int) $w['szerokosc'] : 46 ) ),
						'tekst'     => isset( $rozmiary[ $tekst ] ) ? $tekst : 'auto',
					);
				}
				$nowe['wyglad'] = $wyglad;
			}

			$zapis = self::zapisz( $nowe );
			if (is_wp_error($zapis)) wp_die(esc_html($zapis->get_error_message()));
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'nerti-cookies',
					'nc-info' => rawurlencode( $info ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
