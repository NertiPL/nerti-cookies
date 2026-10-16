<?php
/**
 * Test rozpoznawania pozycji skanu (0.7.0): ślady PixelYourSite, ślady panelu administratora,
 * pozostałości po Complianz, „Pomiń" od właściciela, hurtowe `przypisz-kategorie`
 * i `zakoncz-kreator`.
 *
 * Uruchomienie: wp eval-file wp-content/plugins/nerti-cookies/testy/sprawdz-skaner.php
 * Wyjście: „OK <n>/<n>" i kod 0, albo lista rozbieżności i kod 1.
 *
 * Test zapisuje do opcji skanu, ustawień i kreatora, a na końcu przywraca ich poprzedni stan.
 * Mimo to uruchamiaj go na witrynie testowej, nie na produkcji klienta.
 *
 * @package NertiCookies
 */

defined( 'ABSPATH' ) || exit;

$bledy = 0;
$razem = 0;

$sprawdz = function ( $opis, $mam, $oczekiwane ) use ( &$bledy, &$razem ) {
	$razem++;
	if ( $mam !== $oczekiwane ) {
		$bledy++;
		echo "BŁĄD: $opis\n  mam:        " . var_export( $mam, true ) . "\n  oczekiwane: " . var_export( $oczekiwane, true ) . "\n";
	}
};

$stare_skan    = get_option( Nerti_Cookies_Skaner::OPCJA, false );
$stare_ust     = get_option( Nerti_Cookies_Ustawienia::OPCJA, false );
$stary_kreator = get_option( Nerti_Cookies_Kreator::OPCJA, false );

$poz = function ( $typ, $klucz, $status = 'nieznane', $slad = array() ) {
	return array_merge(
		array(
			'typ' => $typ, 'nazwa' => $klucz, 'klucz' => $klucz, 'usluga' => '', 'kategoria' => '', 'status' => $status,
			'opis' => 'x', 'strony' => array(), 'serwer' => false, 'przegl_zal' => false, 'przegl_gosc' => false,
		),
		$slad
	);
};
$status_z_wyniku = static function ( $typ, $klucz ) {
	$w = Nerti_Cookies_Skaner::wynik();
	return $w['pozycje'][ $typ . '|' . $klucz ]['status'] ?? 'BRAK';
};

/* ---------- 1. Dopasowanie wzorców ---------- */
$sprawdz( 'prefiks z gwiazdką', Nerti_Cookies_Uslugi::pasuje_nazwa( 'pys_first_visit', 'pys_*' ), true );
$sprawdz( 'prefiks nie pasuje do innej nazwy', Nerti_Cookies_Uslugi::pasuje_nazwa( 'xpys_a', 'pys_*' ), false );
$sprawdz( 'nazwa dokładna', Nerti_Cookies_Uslugi::pasuje_nazwa( 'pbid', 'pbid' ), true );
$sprawdz( 'nazwa dokładna nie jest prefiksem', Nerti_Cookies_Uslugi::pasuje_nazwa( 'pbid2', 'pbid' ), false );
$sprawdz( 'gwiazdka na początku', Nerti_Cookies_Uslugi::pasuje_nazwa( 'abc_sid', '*_sid' ), true );
$sprawdz( 'gwiazdka w środku', Nerti_Cookies_Uslugi::pasuje_nazwa( 'wp-api-schema-model-x', 'wp-*-model-*' ), true );
$sprawdz( 'wielkość liter ma znaczenie', Nerti_Cookies_Uslugi::pasuje_nazwa( '_GA', '_ga' ), false );

/* ---------- 2. PixelYourSite i Meta: marketingowe ---------- */
foreach ( array( 'pys_first_visit', 'pys_landing_page', 'pys_session_limit', 'pys_start_session', 'pys_cd', 'pys_event_referrer', 'pys_session_entry_referrer', 'last_pys_landing_page', 'last_pysTrafficSource', 'pysTrafficSource', 'pbid', '_fbp', '_fbc' ) as $n ) {
	$i = Nerti_Cookies_Uslugi::ciasteczko( $n );
	$sprawdz( "ciasteczko $n = marketingowe / meta-pixel", array( $i['kategoria'], $i['usluga'] ), array( 'marketingowe', 'meta-pixel' ) );
}
foreach ( array( 'pys_advanced_form_data', 'pys_cd', 'last_pysTrafficSource', 'pysTrafficSource', 'pbid', 'fbqpc_abc123' ) as $k ) {
	$i = Nerti_Cookies_Uslugi::pamiec( $k );
	$sprawdz( "storage $k = marketingowe / meta-pixel", array( $i['kategoria'], $i['usluga'] ), array( 'marketingowe', 'meta-pixel' ) );
}

/* ---------- 3. Ślady panelu i pozostałości ---------- */
foreach ( array( 'breakdance-twig-macros', 'breakdance-twig-macros-hash', 'breakdance_clipboard', 'googlesitekit_1_abc', 'googlesitekit_dashboard', 'wp-api-schema-model/wp/v2', 'wp-settings-1' ) as $k ) {
	$r = Nerti_Cookies_Uslugi::pomijanie_dla( 'pamiec', $k );
	$sprawdz( "storage $k = admin", $r ? $r['status'] : null, 'admin' );
}
foreach ( array( 'cmplz_banner-status', 'cmplz_consented_services', 'cmplz_functional', 'cmplz_marketing', 'cmplz_policy_id', 'cmplz_preferences', 'cmplz_statistics' ) as $k ) {
	$r = Nerti_Cookies_Uslugi::pomijanie_dla( 'ciasteczko', $k );
	$sprawdz( "ciasteczko $k = pozostalosc", $r ? $r['status'] : null, 'pozostalosc' );
}
$sprawdz( 'nieznane narzędzie nie jest pomijane', Nerti_Cookies_Uslugi::pomijanie_dla( 'ciasteczko', 'moj_tracker' ), null );
$sprawdz( 'host nie jest pomijany bibliotekowo', Nerti_Cookies_Uslugi::pomijanie_dla( 'host', 'fonts.googleapis.com' ), null );

/* ---------- 4. Nakładka na wynik skanu ---------- */
$nowy_skan = array(
	'czas' => time(), 'wersja' => NERTI_COOKIES_WERSJA, 'adresy' => array(), 'strony' => array(), 'przegladarka' => array(),
	'pozycje' => array(
		'ciasteczko|cmplz_marketing'       => $poz( 'ciasteczko', 'cmplz_marketing' ),
		'pamiec|breakdance_clipboard'      => $poz( 'pamiec', 'breakdance_clipboard' ),
		'pamiec|nieznany_klucz_admina'     => $poz( 'pamiec', 'nieznany_klucz_admina', 'nieznane', array( 'przegl_zal' => true ) ),
		'pamiec|klucz_goscia'              => $poz( 'pamiec', 'klucz_goscia', 'nieznane', array( 'przegl_zal' => true, 'przegl_gosc' => true ) ),
		'ciasteczko|ciastko_z_serwera'     => $poz( 'ciasteczko', 'ciastko_z_serwera', 'nieznane', array( 'serwer' => true, 'przegl_zal' => true ) ),
		'host|obca.example'                => $poz( 'host', 'obca.example', 'nieznane', array( 'przegl_zal' => true ) ),
		'ciasteczko|BLAD_tracker'          => $poz( 'ciasteczko', 'BLAD_tracker', 'BLAD', array( 'przegl_zal' => true ) ),
		'ciasteczko|foo_a'                 => $poz( 'ciasteczko', 'foo_a' ),
		'ciasteczko|foo_b'                 => $poz( 'ciasteczko', 'foo_b' ),
		'ciasteczko|zzz_a'                 => $poz( 'ciasteczko', 'zzz_a' ),
		'pamiec|zzz_b'                     => $poz( 'pamiec', 'zzz_b' ),
	),
);
update_option( Nerti_Cookies_Skaner::OPCJA, $nowy_skan, false );
Nerti_Cookies_Ustawienia::zapisz( array( 'ignoruj' => array() ) );

$sprawdz( 'Complianz = pozostalosc', $status_z_wyniku( 'ciasteczko', 'cmplz_marketing' ), 'pozostalosc' );
$sprawdz( 'builder = admin', $status_z_wyniku( 'pamiec', 'breakdance_clipboard' ), 'admin' );
$sprawdz( 'nieznany klucz widziany tylko po zalogowaniu = admin', $status_z_wyniku( 'pamiec', 'nieznany_klucz_admina' ), 'admin' );
$sprawdz( 'klucz widziany też bez logowania zostaje nieznany', $status_z_wyniku( 'pamiec', 'klucz_goscia' ), 'nieznane' );
$sprawdz( 'ciasteczko widziane przez serwer zostaje nieznane', $status_z_wyniku( 'ciasteczko', 'ciastko_z_serwera' ), 'nieznane' );
$sprawdz( 'host nigdy nie jest uznany za ślad panelu', $status_z_wyniku( 'host', 'obca.example' ), 'nieznane' );
$sprawdz( 'BLAD nie znika', $status_z_wyniku( 'ciasteczko', 'BLAD_tracker' ), 'BLAD' );

$sum = Nerti_Cookies_Skaner::podsumowanie();
$sprawdz( 'podsumowanie: admin', $sum['licz']['admin'], 2 );
$sprawdz( 'podsumowanie: pozostalosc', $sum['licz']['pozostalosc'], 1 );

/* ---------- 5. „Pomiń" i cofnięcie ---------- */
$sprawdz( 'pomin foo_*', Nerti_Cookies_Skaner::pomin( 'foo_*' ), true );
$sprawdz( 'pomin dwa razy nie dubluje', Nerti_Cookies_Skaner::pomin( 'foo_*' ), true );
$sprawdz( 'ignoruj ma jeden wpis', Nerti_Cookies_Ustawienia::pobierz()['ignoruj'], array( 'foo_*' ) );
$sprawdz( 'foo_a pominięte', $status_z_wyniku( 'ciasteczko', 'foo_a' ), 'pominiete' );
$sprawdz( 'foo_b pominięte (wzorzec)', $status_z_wyniku( 'ciasteczko', 'foo_b' ), 'pominiete' );
$w = Nerti_Cookies_Skaner::wynik();
$sprawdz( 'status_pierwotny zachowany', $w['pozycje']['ciasteczko|foo_a']['status_pierwotny'] ?? null, 'nieznane' );
Nerti_Cookies_Ustawienia::zapisz( array( 'ignoruj' => array( 'BLAD_*' ) ) );
$sprawdz( 'Pomiń nie ukrywa BLAD', $status_z_wyniku( 'ciasteczko', 'BLAD_tracker' ), 'BLAD' );
Nerti_Cookies_Ustawienia::zapisz( array( 'ignoruj' => array( 'foo_*' ) ) );
$sprawdz( 'przywroc', Nerti_Cookies_Skaner::przywroc( 'foo_a' ), true );
$sprawdz( 'po przywróceniu foo_a znów nieznane', $status_z_wyniku( 'ciasteczko', 'foo_a' ), 'nieznane' );
$sprawdz( 'ignoruj puste po przywróceniu', Nerti_Cookies_Ustawienia::pobierz()['ignoruj'], array() );
$zle = Nerti_Cookies_Ustawienia::zapisz( array( 'ignoruj' => array( 5 ) ) );
$sprawdz( 'ignoruj: nie-tekst odpada', is_wp_error( $zle ) ? $zle->get_error_code() : 'zapisano', 'nc_config' );

/* ---------- 6. przypisz-kategorie ---------- */
$o = Nerti_Cookies_Abilities::op_przypisz(
	array(
		'pozycje' => array(
			array( 'nazwa' => 'zzz_*', 'kategoria' => 'marketingowe' ),
			array( 'nazwa' => 'foo_*', 'kategoria' => 'pomin' ),
			array( 'nazwa' => 'BLAD_tracker', 'kategoria' => 'pomin' ),
			array( 'nazwa' => 'nie_ma_takiego', 'kategoria' => 'analityczne' ),
		),
	)
);
$sprawdz( 'przypisz: ok', is_wp_error( $o ) ? $o->get_error_message() : $o['ok'], true );
$sprawdz( 'przypisz: zmiany', count( $o['zmiany'] ), 2 );
$sprawdz( 'przypisz: brak dopasowania', $o['brak_dopasowania'], array( 'nie_ma_takiego' ) );
$sprawdz( 'przypisz: BLAD bez zmian', $o['bez_zmian'][0]['status'] ?? null, 'BLAD' );
$u = Nerti_Cookies_Ustawienia::pobierz();
$sprawdz( 'zzz_* w ciasteczkach marketingowych', in_array( 'zzz_*', $u['kategorie']['marketingowe']['ciasteczka'], true ), true );
$sprawdz( 'zzz_* w storage marketingowych', in_array( 'zzz_*', $u['kategorie']['marketingowe']['storage'] ?? array(), true ), true );
$sprawdz( 'foo_* na liście pomijanych', in_array( 'foo_*', $u['ignoruj'], true ), true );
$sprawdz( 'zzz_a ma status blokowane', $status_z_wyniku( 'ciasteczko', 'zzz_a' ), 'blokowane' );
$sprawdz( 'foo_a pominięte po hurtowym pomin', $status_z_wyniku( 'ciasteczko', 'foo_a' ), 'pominiete' );
$ponowne = Nerti_Cookies_Abilities::op_przypisz( array( 'pozycje' => array( array( 'nazwa' => 'zzz_*', 'kategoria' => 'marketingowe' ) ) ) );
$sprawdz( 'przypisz jest idempotentne', count( $ponowne['zmiany'] ), 0 );
$sprawdz( 'zzz_* nie dubluje się w liście', count( array_keys( $u['kategorie']['marketingowe']['ciasteczka'], 'zzz_*', true ) ), 1 );
$zla = Nerti_Cookies_Abilities::op_przypisz( array( 'pozycje' => array( array( 'nazwa' => 'x', 'kategoria' => 'inna' ) ) ) );
$sprawdz( 'zła kategoria odpada', is_wp_error( $zla ) ? $zla->get_error_code() : 'ok', 'nc_przypisz' );

/* ---------- 7. zakoncz-kreator ---------- */
Nerti_Cookies_Abilities::op_kreator( array( 'otworz' => true ) );
$sprawdz( 'kreator otwarty', Nerti_Cookies_Kreator::ukonczony(), false );
$k = Nerti_Cookies_Abilities::op_kreator( array() );
$sprawdz( 'kreator zakończony', array( $k['ok'], $k['kreator_ukonczony'], Nerti_Cookies_Kreator::ukonczony() ), array( true, true, true ) );
$k = Nerti_Cookies_Abilities::op_kreator( array( 'otworz' => true ) );
$sprawdz( 'kreator otwarty ponownie', $k['kreator_ukonczony'], false );

/* ---------- 8. Lista do polityki nie zawiera pomijanych ---------- */
$tabela = Nerti_Cookies_Lista::shortcode( array() );
$sprawdz( 'lista nie pokazuje cmplz_', false !== strpos( $tabela, 'cmplz_' ), false );
$sprawdz( 'lista nie pokazuje breakdance', false !== strpos( $tabela, 'breakdance' ), false );

/* ---------- sprzątanie ---------- */
foreach ( array( Nerti_Cookies_Skaner::OPCJA => $stare_skan, Nerti_Cookies_Ustawienia::OPCJA => $stare_ust, Nerti_Cookies_Kreator::OPCJA => $stary_kreator ) as $opcja => $wartosc ) {
	if ( false === $wartosc ) {
		delete_option( $opcja );
	} else {
		update_option( $opcja, $wartosc, false );
	}
}

if ( $bledy ) {
	echo "BŁĘDY: $bledy z $razem\n";
	exit( 1 );
}
echo "OK $razem/$razem\n";
