<?php
/**
 * Test ustawienia `wycofanie` (0.4.0): domyślnie wtyczka drukuje wejście do zmiany zgody,
 * `strona` oddaje je projektowi, wszystko inne odpada w walidacji.
 *
 * Uruchomienie: wp eval-file wp-content/plugins/nerti-cookies/testy/sprawdz-wycofanie.php
 * Wyjście: „OK <n>/<n>" i kod 0, albo lista rozbieżności i kod 1.
 *
 * Kształty zmierzone 16.09.2026 na try.local (Oxygen 6.2.0-beta.8): pas `.nc-wycofaj-pas` z przyciskiem
 * `data-nc-akcja="otworz"` przy `wtyczka`, pusty wp_footer przy `strona`.
 *
 * @package NertiCookies
 */

defined( 'ABSPATH' ) || exit;

$bledy = 0;
$razem = 0;

// Domknięcie po referencji, bo wp eval-file wykonuje plik wewnątrz funkcji (patrz sprawdz-oxygena.php).
$sprawdz = function ( $opis, $mam, $oczekiwane ) use ( &$bledy, &$razem ) {
	$razem++;
	if ( $mam !== $oczekiwane ) {
		$bledy++;
		echo "BŁĄD: $opis\n  mam:        " . var_export( $mam, true ) . "\n  oczekiwane: " . var_export( $oczekiwane, true ) . "\n";
	}
};

$stare_ust = get_option( Nerti_Cookies_Ustawienia::OPCJA, false );

/* ---------- 1. Domyślne ---------- */
$domyslne = Nerti_Cookies_Ustawienia::domyslne();
$sprawdz( 'domyślne wycofanie = wtyczka', $domyslne['wycofanie'] ?? null, 'wtyczka' );

/* ---------- 2. Walidacja ---------- */
$zle = Nerti_Cookies_Ustawienia::zapisz( array( 'wycofanie' => 'stopka' ) );
$sprawdz( 'nieznana wartość odpada', is_wp_error( $zle ) ? $zle->get_error_code() : 'zapisano', 'nc_config' );
$ok = Nerti_Cookies_Ustawienia::zapisz( array( 'wycofanie' => 'strona' ) );
$sprawdz( '„strona" przechodzi', is_wp_error( $ok ) ? $ok->get_error_message() : $ok['wycofanie'], 'strona' );

/* ---------- 3. Druk ---------- */
$drukuj = static function () {
	ob_start();
	Nerti_Cookies_Baner::wycofanie();
	return (string) ob_get_clean();
};
$blokada_dziala = Nerti_Cookies_Blokada::czy_dziala();
if ( ! $blokada_dziala ) {
	echo "UWAGA: blokada nieaktywna w tym kontekście, test druku sprawdza tylko gałąź „nic nie drukuj”.\n";
}
$sprawdz( 'przy „strona" wtyczka nie drukuje nic', $drukuj(), '' );

Nerti_Cookies_Ustawienia::zapisz( array( 'wycofanie' => 'wtyczka' ) );
$html = $drukuj();
if ( $blokada_dziala ) {
	$sprawdz( 'przy „wtyczka" jest pas', false !== strpos( $html, 'class="nc-wycofaj-pas"' ), true );
	$sprawdz( 'pas ma przycisk otwierający baner', false !== strpos( $html, 'data-nc-akcja="otworz"' ), true );
	$sprawdz( 'przycisk ma tekst z ustawień', false !== strpos( $html, esc_html( Nerti_Cookies_Ustawienia::pobierz()['teksty']['wycofaj'] ) ), true );
}

/* ---------- Sprzątanie ---------- */
if ( false === $stare_ust ) {
	delete_option( Nerti_Cookies_Ustawienia::OPCJA );
} else {
	update_option( Nerti_Cookies_Ustawienia::OPCJA, $stare_ust, false );
}

if ( $bledy ) {
	echo "NIE PRZESZŁO: $bledy z $razem\n";
	exit( 1 );
}
echo "OK $razem/$razem\n";
