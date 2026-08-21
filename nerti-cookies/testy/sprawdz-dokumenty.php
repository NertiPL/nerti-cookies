<?php
/**
 * Jedyny uruchamialny test wtyczki. Sprawdza rozgałęzienia `op_dokumenty()` — jedyne miejsce
 * z realną logiką decyzyjną. Reszta klasy to odczyt i zapis opcji.
 *
 * Uruchomienie (laboria):
 *   /c/AI/.narzedzia/wp-laboria.sh eval-file wp-content/plugins/nerti-cookies/testy/sprawdz-dokumenty.php
 *
 * Wyjście: „OK <n>/<n>" i kod 0, albo lista rozbieżności i kod 1.
 *
 * @package NertiCookies
 */

defined( 'ABSPATH' ) || exit;

$przypadki = array(
	// opis, wejście, oczekiwane slugi, oczekiwany stan dostępności (fragment)
	array(
		'wizytówka bez niczego',
		array( 'typ' => 'wizytowka' ),
		array( 'polityka-prywatnosci' ),
		'nie dotyczy',
	),
	array(
		'wizytówka z newsletterem — dochodzi regulamin',
		array( 'typ' => 'wizytowka', 'funkcje' => array( 'newsletter' ) ),
		array( 'polityka-prywatnosci', 'regulamin' ),
		'nie dotyczy',
	),
	array(
		'sklep bez danych o wielkości — dostępność jest pytaniem, nie domysłem',
		array( 'typ' => 'sklep' ),
		array( 'polityka-prywatnosci', 'polityka-cookies', 'regulamin', 'zwroty-i-reklamacje', 'dane-firmowe' ),
		'PYTANIE DO KLIENTA',
	),
	array(
		'sklep mikroprzedsiębiorcy — zwolniony z dostępności',
		array( 'typ' => 'sklep', 'pracownikow' => 4, 'obrot_mln_eur' => 0.6 ),
		array( 'polityka-prywatnosci', 'polityka-cookies', 'regulamin', 'zwroty-i-reklamacje', 'dane-firmowe' ),
		'mikroprzedsi',
	),
	array(
		'sklep powyżej progu — dochodzi informacja o dostępności',
		array( 'typ' => 'sklep', 'pracownikow' => 25, 'obrot_mln_eur' => 12 ),
		array( 'polityka-prywatnosci', 'polityka-cookies', 'regulamin', 'zwroty-i-reklamacje', 'dane-firmowe', 'informacja-o-dostepnosci' ),
		'DOTYCZY',
	),
	array(
		'typ spoza listy jest odrzucany, a nie interpretowany',
		array( 'typ' => 'blog' ),
		null,
		null,
	),
);

$bledy = array();

foreach ( $przypadki as $p ) {
	list( $opis, $wejscie, $slugi, $dostepnosc ) = $p;
	$w = Nerti_Cookies_Abilities::op_dokumenty( $wejscie );

	if ( null === $slugi ) {
		if ( empty( $w['blad'] ) ) {
			$bledy[] = "$opis — oczekiwano błędu, dostano wynik";
		}
		continue;
	}

	$mam = wp_list_pluck( $w['dokumenty'], 'slug' );
	if ( $mam !== $slugi ) {
		$bledy[] = "$opis — dokumenty: " . implode( ',', $mam ) . ' ≠ ' . implode( ',', $slugi );
	}
	if ( false === strpos( $w['dostepnosc']['stan'], $dostepnosc ) ) {
		$bledy[] = "$opis — dostępność: „{$w['dostepnosc']['stan']}" . '" nie zawiera „' . $dostepnosc . '"';
	}
	// Każda pozycja musi mieć uzasadnienie — lista bez „dlaczego" jest listą do zignorowania.
	foreach ( $w['dokumenty'] as $dok ) {
		if ( empty( $dok['powod'] ) ) {
			$bledy[] = "$opis — {$dok['slug']} bez uzasadnienia";
		}
	}
}

if ( $bledy ) {
	echo "BŁĄD\n - " . implode( "\n - ", $bledy ) . "\n";
	exit( 1 );
}

echo 'OK ' . count( $przypadki ) . '/' . count( $przypadki ) . "\n";
