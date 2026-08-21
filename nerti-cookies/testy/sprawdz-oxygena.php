<?php
/**
 * Test mapowania ustawień globalnych Oxygena na zmienne `--nc-*` i sprawdzania kontrastu.
 *
 * Uruchomienie (laboria):
 *   /c/AI/.narzedzia/wp-laboria.sh eval-file wp-content/plugins/nerti-cookies/testy/sprawdz-oxygena.php
 *
 * Wyjście: „OK <n>/<n>" i kod 0, albo lista rozbieżności i kod 1.
 *
 * Kształty danych po lewej stronie asercji są ZMIERZONE na laborii 2026-08-21, nie
 * wypisane z pamięci: ustawienia wstawiono przez `oxygen-set-global-settings`, odczytano
 * `get_option('oxygen_global_settings_json_string')` i wypisano strukturę. Najważniejsze
 * ustalenie: `get_option()` oddaje JSON zakodowany PODWÓJNIE, a
 * `Breakdance\Data\get_global_option()` pojedynczo — pierwszy przypadek niżej pilnuje
 * właśnie tego, bo to on jednym `json_decode` zamienia cały import w zero zmiennych.
 *
 * @package NertiCookies
 */

defined( 'ABSPATH' ) || exit;

$bledy = 0;
$razem = 0;

/*
 * Domknięcie po referencji, a NIE zwykła funkcja z `global`. Powód zmierzony:
 * `wp eval-file` wykonuje plik wewnątrz funkcji, więc `$bledy` i `$razem` z tego
 * pliku są zmiennymi lokalnymi, a `global $bledy` w zwykłej funkcji sięgałoby do
 * zupełnie innej pary. Test kończył się wtedy radosnym „OK 0/0" niezależnie od
 * tego, ile asercji naprawdę padło — czyli był gorszy niż jego brak.
 *
 * @param string $opis       Co sprawdzamy.
 * @param mixed  $mam        Wartość otrzymana.
 * @param mixed  $oczekiwane Wartość oczekiwana.
 */
$sprawdz = function ( $opis, $mam, $oczekiwane ) use ( &$bledy, &$razem ) {
	$razem++;
	if ( $mam !== $oczekiwane ) {
		$bledy++;
		echo "BŁĄD: $opis
";
		echo '   mam:        ' . var_export( $mam, true ) . "
";
		echo '   oczekiwane: ' . var_export( $oczekiwane, true ) . "
";
	}
};

/* ---------- 1. Podwójne kodowanie opcji ---------- */

$zmierzone = array(
	'settings' => array(
		'colors'     => array(
			'brand'      => '#7B4B94',
			'text'       => '#2B2B33',
			'headings'   => '#16161F',
			'links'      => '#7B4B94',
			'background' => '#FBF7F2',
		),
		'typography' => array(
			'heading_font' => 'gfont-fraunces',
			'body_font'    => 'gfont-inter',
			'base_size'    => array(
				'breakpoint_base'           => array( 'number' => 18, 'unit' => 'px', 'style' => null ),
				'breakpoint_phone_portrait' => array( 'number' => 16, 'unit' => 'px', 'style' => null ),
			),
			'ratio'        => 1.25,
		),
		'buttons'    => array(
			'primary' => array(
				'background'    => '#7B4B94',
				'color'         => '#FFFFFF',
				'outline'       => false,
				'corners'       => 'custom',
				'corner_radius' => array( 'breakpoint_base' => array( 'number' => 10, 'unit' => 'px', 'style' => null ) ),
				'shadow'        => array(
					'breakpoint_base' => array(
						'shadows' => array(
							array( 'color' => 'rgba(0,0,0,0.18)', 'x' => '0px', 'y' => '8px', 'blur' => '24px', 'spread' => '0px', 'position' => null ),
						),
					),
				),
			),
		),
		'other'      => array( 'transition_duration' => array( 'number' => 220, 'unit' => 'ms', 'style' => null ) ),
	),
);

$pojedynczo = wp_json_encode( $zmierzone );
$podwojnie  = wp_json_encode( $pojedynczo );

$stara_opcja = get_option( 'oxygen_global_settings_json_string' );
$stare_tokeny = get_option( Nerti_Cookies_Tokeny::OPCJA );
$stare_zrodlo = get_option( Nerti_Cookies_Tokeny::OPCJA_ZRODLO );

// Podwójnie zakodowany kształt to ten, który oddaje get_option(). Jeżeli
// `ustawienia_oxygena()` odda tu null, znaczy że dekoduje tylko raz — a wtedy na
// każdej instalacji czytanej przez get_option() import cicho nie robi nic.
update_option( 'oxygen_global_settings_json_string', $podwojnie, false );
$s = Nerti_Cookies_Tokeny::ustawienia_oxygena();
$sprawdz( 'podwójnie zakodowana opcja daje kolor marki', isset( $s['colors']['brand'] ) ? $s['colors']['brand'] : null, '#7B4B94' );
$sprawdz( 'podwójnie zakodowana opcja gubi opakowanie settings', isset( $s['settings'] ), false );

update_option( 'oxygen_global_settings_json_string', $pojedynczo, false );
$s = Nerti_Cookies_Tokeny::ustawienia_oxygena();
$sprawdz( 'pojedynczo zakodowana opcja daje kolor marki', isset( $s['colors']['brand'] ) ? $s['colors']['brand'] : null, '#7B4B94' );

update_option( 'oxygen_global_settings_json_string', '', false );
$sprawdz( 'puste ustawienia to null, a nie pusta tablica', Nerti_Cookies_Tokeny::ustawienia_oxygena(), null );

update_option( 'oxygen_global_settings_json_string', 'to nie jest JSON', false );
$sprawdz( 'śmieci w opcji to null, a nie wyjątek', Nerti_Cookies_Tokeny::ustawienia_oxygena(), null );

/* ---------- 2. Mapowanie na --nc-* ---------- */

update_option( 'oxygen_global_settings_json_string', $podwojnie, false );
$w = Nerti_Cookies_Tokeny::importuj_z_oxygena();
$t = Nerti_Cookies_Tokeny::pobierz();

$sprawdz( 'import się udał', ! empty( $w['ok'] ), true );
$sprawdz( 'źródło zapisane jako oxygen', Nerti_Cookies_Tokeny::zrodlo()['zrodlo'], 'oxygen' );
$sprawdz( 'opis źródła po polsku', Nerti_Cookies_Tokeny::opis_zrodla(), 'ustawienia globalne Oxygena' );

$sprawdz( 'tło z colors.background', isset( $t['--nc-bg'] ) ? $t['--nc-bg'] : null, '#FBF7F2' );
$sprawdz( 'tekst z colors.text', isset( $t['--nc-text'] ) ? $t['--nc-text'] : null, '#2B2B33' );
// Tło przycisku ma pierwszeństwo przed colors.brand — to nim Oxygen maluje przycisk.
$sprawdz( 'przycisk z buttons.primary.background', isset( $t['--nc-primary'] ) ? $t['--nc-primary'] : null, '#7B4B94' );
$sprawdz( 'napis przycisku z buttons.primary.color', isset( $t['--nc-primary-ink'] ) ? $t['--nc-primary-ink'] : null, '#FFFFFF' );

// {"number":18,"unit":"px"} => "18px". Sklejenie bez jednostki dałoby `font-size: 18`,
// czyli deklarację odrzucaną po cichu przez każdą przeglądarkę.
$sprawdz( 'rozmiar bazowy z liczby i jednostki', isset( $t['--nc-fs'] ) ? $t['--nc-fs'] : null, '18px' );
$sprawdz( 'promień z corner_radius', isset( $t['--nc-radius'] ) ? $t['--nc-radius'] : null, '10px' );
$sprawdz( 'czas przejścia z other.transition_duration', isset( $t['--nc-dur'] ) ? $t['--nc-dur'] : null, '220ms' );
$sprawdz(
	'cień złożony z x, y, blur, spread i koloru',
	isset( $t['--nc-shadow'] ) ? $t['--nc-shadow'] : null,
	'0px 8px 24px 0px rgba(0,0,0,0.18)'
);

// Slug kroju MUSI zostać rozwinięty do rodziny — `font-family: gfont-inter` to krój
// systemowy i baner, który nie pasuje do niczego.
$font = isset( $t['--nc-font'] ) ? $t['--nc-font'] : '';
$sprawdz( 'krój tekstu nie został slugiem', false !== strpos( $font, 'gfont-' ), false );
$sprawdz( 'krój tekstu zawiera nazwę rodziny', false !== stripos( $font, 'Inter' ), true );

$font_head = isset( $t['--nc-font-head'] ) ? $t['--nc-font-head'] : '';
$sprawdz( 'krój nagłówka zawiera nazwę rodziny', false !== stripos( $font_head, 'Fraunces' ), true );

// Braki mają wracać jawnie — import pokrywający połowę banera bez słowa daje wygląd
// „prawie" w stylu strony, co wygląda na pomyłkę.
$sprawdz( 'obramowanie zgłoszone jako brak', in_array( '--nc-border', $w['braki'], true ), true );

/* ---------- 3. Kontrast ---------- */

// Wartości kontrolne z WCAG: czarny na białym to dokładnie 21:1.
$r = Nerti_Cookies_Tokeny::kontrast( '#ffffff', '#000000' );
$sprawdz( 'czarny na białym daje 21:1', round( $r, 2 ), 21.0 );
$sprawdz( 'ten sam kolor daje 1:1', round( Nerti_Cookies_Tokeny::kontrast( '#7B4B94', '#7B4B94' ), 2 ), 1.0 );
$sprawdz( 'skrócony zapis #fff działa', round( Nerti_Cookies_Tokeny::kontrast( '#fff', '#000' ), 2 ), 21.0 );
$sprawdz( 'rgb() działa', round( Nerti_Cookies_Tokeny::kontrast( 'rgb(255,255,255)', 'rgb(0,0,0)' ), 2 ), 21.0 );
$sprawdz( 'var() jest niemierzalny, a nie zgadywany', Nerti_Cookies_Tokeny::kontrast( 'var(--x)', '#000' ), null );
$sprawdz( 'nazwa koloru jest niemierzalna', Nerti_Cookies_Tokeny::rgb( 'rebeccapurple' ), null );

// Para poniżej progu ma zostać ODRZUCONA, nie zapisana z ostrzeżeniem.
// #cccccc na #ffffff to ok. 1,6:1 — tekst nieczytelny.
$k = Nerti_Cookies_Tokeny::sprawdz_kontrast(
	array( '--nc-bg' => '#ffffff', '--nc-text' => '#cccccc' )
);
$sprawdz( 'jasnoszary na białym jest odrzucony', in_array( '--nc-text', $k['odrzucone'], true ), true );
$sprawdz( 'odrzucenie ma swoje ostrzeżenie', count( $k['ostrzezenia'] ), 1 );

$k = Nerti_Cookies_Tokeny::sprawdz_kontrast(
	array( '--nc-bg' => '#ffffff', '--nc-text' => '#16161f' )
);
$sprawdz( 'czytelna para przechodzi', $k['odrzucone'], array() );

// Jedna połowa pary z importu, druga z wartości zapasowej arkusza — mierzy się to,
// co zobaczy odwiedzający, a nie to, co przyszło w danych.
$k = Nerti_Cookies_Tokeny::sprawdz_kontrast( array( '--nc-text' => '#f0f0f0' ) );
$sprawdz( 'tekst mierzony wobec zapasowego tła', in_array( '--nc-text', $k['odrzucone'], true ), true );

// Nietknięta para nie jest oceniana — nie ma czego oceniać.
$k = Nerti_Cookies_Tokeny::sprawdz_kontrast( array( '--nc-radius' => '10px' ) );
$sprawdz( 'brak kolorów to brak pomiaru', $k['pomiary'], array() );

// Pełny przebieg: nieczytelny zestaw NIE trafia do zapisanych zmiennych.
$zly = $zmierzone;
$zly['settings']['colors']['background'] = '#FFFFFF';
$zly['settings']['colors']['text']       = '#DDDDDD';
update_option( 'oxygen_global_settings_json_string', wp_json_encode( wp_json_encode( $zly ) ), false );

$w = Nerti_Cookies_Tokeny::importuj_z_oxygena();
$t = Nerti_Cookies_Tokeny::pobierz();
$sprawdz( 'nieczytelny tekst nie został zapisany', isset( $t['--nc-text'] ), false );
$sprawdz( 'nieczytelne tło nie zostało zapisane', isset( $t['--nc-bg'] ), false );
$sprawdz( 'import mimo to się udał', ! empty( $w['ok'] ), true );
$sprawdz( 'ostrzeżenie wróciło w wyniku', count( $w['ostrzezenia'] ) > 0, true );
// Reszta importu przeżywa — odrzucamy parę, nie cały wygląd.
$sprawdz( 'promień przeżył odrzucenie pary', isset( $t['--nc-radius'] ) ? $t['--nc-radius'] : null, '10px' );

/* ---------- 4. CSS układu: telefon i desktop ---------- */

$css = Nerti_Cookies_Tokeny::css_wygladu(
	array(
		'desktop' => array( 'pozycja' => 'prawy-dol', 'szerokosc' => 40, 'tekst' => 'auto' ),
		'mobile'  => array( 'pozycja' => 'gora', 'szerokosc' => 100, 'tekst' => 'wiekszy' ),
	)
);

$sprawdz( 'blok telefonu kończy się przed progiem', false !== strpos( $css, '@media (max-width:47.9375rem)' ), true );
$sprawdz( 'blok desktopu zaczyna się na progu', false !== strpos( $css, '@media (min-width:48rem)' ), true );
$sprawdz( 'prawy dolny róg to flex-end/flex-end', false !== strpos( $css, '--nc-align:flex-end;--nc-just:flex-end;--nc-szer:40rem' ), true );
$sprawdz( 'góra na telefonie to flex-start', false !== strpos( $css, '--nc-align:flex-start;--nc-just:center;--nc-szer:100rem' ), true );
// 'auto' nie nadpisuje rozmiaru z importu, nazwany rozmiar nadpisuje.
$sprawdz( 'większy tekst na telefonie nadpisuje rozmiar', false !== strpos( $css, '--nc-fs:1.125rem' ), true );
$sprawdz( 'auto na desktopie niczego nie nadpisuje', substr_count( $css, '--nc-fs:' ), 1 );

// Wartości spoza listy nie mogą wejść do arkusza.
$css = Nerti_Cookies_Tokeny::css_wygladu(
	array( 'desktop' => array( 'pozycja' => 'ukos}#a{color:red', 'szerokosc' => 9999, 'tekst' => 'x' ) )
);
$sprawdz( 'nieznana pozycja wraca do domyślnej', false === strpos( $css, 'color:red' ), true );
$sprawdz( 'szerokość ponad zakresem jest przycięta do 100rem', false !== strpos( $css, '--nc-szer:100rem' ), true );

/* ---------- 5. Bramka Q-COOKIE: przycisk odmowy ---------- */

$stare_ust = get_option( Nerti_Cookies_Ustawienia::OPCJA );

Nerti_Cookies_Ustawienia::zapisz( array( 'teksty' => array( 'odrzuc' => '' ) ) );
$u = Nerti_Cookies_Ustawienia::pobierz();
$sprawdz( 'pusty tekst odmowy wraca do domyślnego', $u['teksty']['odrzuc'], 'Odrzucam wszystkie' );

Nerti_Cookies_Ustawienia::zapisz( array( 'teksty' => array( 'odrzuc' => '   ' ) ) );
$u = Nerti_Cookies_Ustawienia::pobierz();
$sprawdz( 'same spacje też wracają do domyślnego', $u['teksty']['odrzuc'], 'Odrzucam wszystkie' );

Nerti_Cookies_Ustawienia::zapisz( array( 'teksty' => array( 'odrzuc' => 'Nie zgadzam się' ) ) );
$u = Nerti_Cookies_Ustawienia::pobierz();
$sprawdz( 'własny tekst odmowy zostaje', $u['teksty']['odrzuc'], 'Nie zgadzam się' );

/* ---------- Sprzątanie ---------- */

foreach ( array(
	'oxygen_global_settings_json_string' => $stara_opcja,
	Nerti_Cookies_Tokeny::OPCJA          => $stare_tokeny,
	Nerti_Cookies_Tokeny::OPCJA_ZRODLO   => $stare_zrodlo,
	Nerti_Cookies_Ustawienia::OPCJA      => $stare_ust,
) as $opcja => $wartosc ) {
	if ( false === $wartosc ) {
		delete_option( $opcja );
	} else {
		update_option( $opcja, $wartosc, false );
	}
}

if ( $bledy ) {
	echo "NIE PRZESZŁO: $bledy z $razem\n";
	exit( 1 );
}

echo "OK $razem/$razem\n";
