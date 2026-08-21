<?php
/**
 * Aktualizacje z GitHub Releases.
 *
 * WordPress 5.8+ ma hook zrobiony dokładnie do tego: wtyczka deklaruje nagłówek
 * `Update URI`, a WP odpala `update_plugins_<host>` wyłącznie dla niej. Żadnej
 * biblioteki update-checkera, żadnego crona, żadnego ekranu ustawień i — co
 * najważniejsze — żadnego filtrowania globalnego `site_transient_update_plugins`,
 * czyli zwykłego sposobu, w jaki własne updatery psują cudze wtyczki.
 *
 * Wzorzec przeniesiony z `unofficial-abilities-for-breakdance-elements/src/updates.php`,
 * gdzie działa. Wszystkie nazwy są na prefiksie `nerti_cookies_` / `NERTI_COOKIES_`,
 * żeby obie paczki mogły stać na jednej instalacji — a stoją, na laborii.
 *
 * @package NertiCookies
 */

defined( 'ABSPATH' ) || exit;

/** Repozytorium wydań. Ta sama wartość co w nagłówkach `Plugin URI` i `Update URI`. */
const NERTI_COOKIES_REPO = 'NertiPL/nerti-cookies';

/** Znacznik nieudanego odpytania API. */
const NERTI_COOKIES_BLAD_ODPYTANIA = 'nerti_cookies_release_lookup_failed';

/** Jak długo trzymamy znacznik nieudanego odpytania. */
const NERTI_COOKIES_BLAD_TTL = 15 * MINUTE_IN_SECONDS;

/**
 * Najnowsze wydanie z API GitHuba.
 *
 * Udane odpytanie celowo NIE jest buforowane. WordPress i tak trzyma odpowiedź we
 * własnym transiencie `update_plugins` i woła ten kod tylko wtedy, gdy sam uzna, że
 * czas ją odświeżyć. Drugi bufor na tym nie dawał nic i kosztował jedyne, co się tu
 * liczy: wydanie opublikowane przed chwilą zostawało niewidoczne przez pół dnia na
 * stronie, która już o nie zapytała.
 *
 * Odpytanie NIEUDANE jest buforowane, krótko. Awaria to jednocześnie przypadek
 * powolny — nieosiągalne repo, wyczerpany limit, DNS — i bez znacznika każde
 * odświeżenie blokowałoby ładowanie strony na tym samym timeoucie.
 *
 * @param bool $wymus Zignoruj znacznik awarii i spróbuj teraz.
 * @return array{version:string,package:string,url:string,published:string,notes:string}|null
 */
function nerti_cookies_ostatnie_wydanie( $wymus = false ) {
	if ( ! $wymus && get_site_transient( NERTI_COOKIES_BLAD_ODPYTANIA ) ) {
		return null;
	}

	$odpowiedz = wp_remote_get(
		'https://api.github.com/repos/' . NERTI_COOKIES_REPO . '/releases/latest',
		array(
			'timeout' => 10,
			'headers' => array(
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'nerti-cookies/' . NERTI_COOKIES_WERSJA,
			),
		)
	);

	$wydanie = null;

	if ( ! is_wp_error( $odpowiedz ) && 200 === wp_remote_retrieve_response_code( $odpowiedz ) ) {
		$body = json_decode( wp_remote_retrieve_body( $odpowiedz ), true );

		if ( is_array( $body ) && ! empty( $body['tag_name'] ) && empty( $body['draft'] ) ) {
			// Wolimy wgrany plik .zip od źródłowego tarballa GitHuba: zip jest zbudowaną
			// wtyczką, a tarball niesie rusztowanie repozytorium (.git, testy, README).
			$paczka = '';
			foreach ( (array) ( isset( $body['assets'] ) ? $body['assets'] : array() ) as $zasob ) {
				if ( isset( $zasob['browser_download_url'], $zasob['name'] ) && '.zip' === substr( (string) $zasob['name'], -4 ) ) {
					$paczka = (string) $zasob['browser_download_url'];
					break;
				}
			}

			if ( '' === $paczka ) {
				$paczka = isset( $body['zipball_url'] ) ? (string) $body['zipball_url'] : '';
			}

			if ( '' !== $paczka ) {
				$wydanie = array(
					'version'   => ltrim( (string) $body['tag_name'], 'vV' ),
					'package'   => $paczka,
					'url'       => isset( $body['html_url'] ) ? (string) $body['html_url'] : '',
					'published' => isset( $body['published_at'] ) ? (string) $body['published_at'] : '',
					'notes'     => isset( $body['body'] ) ? (string) $body['body'] : '',
				);
			}
		}
	}

	if ( null === $wydanie ) {
		set_site_transient( NERTI_COOKIES_BLAD_ODPYTANIA, 1, NERTI_COOKIES_BLAD_TTL );
	} else {
		delete_site_transient( NERTI_COOKIES_BLAD_ODPYTANIA );
	}

	return $wydanie;
}

/**
 * Odpowiada na pytanie WordPressa o aktualizację — tylko dla tej wtyczki.
 *
 * @param array|false $aktualizacja Dotychczasowa odpowiedź.
 * @param array       $dane         Nagłówki wtyczki.
 * @param string      $plik         Ścieżka wtyczki względem katalogu wtyczek.
 * @return array|false
 */
function nerti_cookies_sprawdz_aktualizacje( $aktualizacja, $dane, $plik ) {
	// Cudzy `Update URI` może wskazywać ten sam host — odpowiadamy tylko za siebie.
	$uri = isset( $dane['UpdateURI'] ) ? (string) $dane['UpdateURI'] : '';
	if ( false === strpos( $uri, NERTI_COOKIES_REPO ) ) {
		return $aktualizacja;
	}

	// „Sprawdź ponownie" na ekranie aktualizacji ma znaczyć to, co mówi: próbujemy
	// nawet wewnątrz 15-minutowego okna po nieudanym odpytaniu — a to jest dokładnie
	// ten moment, w którym ktoś sięga po ten przycisk.
	$wymus = isset( $_GET['force-check'] ) && '' !== $_GET['force-check']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	$wydanie = nerti_cookies_ostatnie_wydanie( $wymus );
	if ( null === $wydanie ) {
		return $aktualizacja;
	}

	// Odpowiadamy także wtedy, gdy jesteśmy aktualni. To WordPress decyduje, do
	// którego kubełka to trafi: nowsza wersja idzie do `response`, każda inna do
	// `no_update`. Zwrócenie `false` przy aktualnej wersji trzymałoby wtyczkę poza
	// OBOMA — a wtyczka w żadnym z nich nie dostaje przełącznika „Włącz
	// automatyczne aktualizacje", co czyta się jako „aktualizacje nie działają".
	return array(
		'id'           => 'github.com/' . NERTI_COOKIES_REPO,
		'slug'         => dirname( $plik ),
		'plugin'       => $plik,
		'version'      => $wydanie['version'],
		'url'          => $wydanie['url'],
		'package'      => $wydanie['package'],
		'requires_php' => isset( $dane['RequiresPHP'] ) ? (string) $dane['RequiresPHP'] : '7.4',
	);
}
add_filter( 'update_plugins_github.com', 'nerti_cookies_sprawdz_aktualizacje', 10, 3 );

/**
 * Przywraca rozpakowanemu katalogowi nazwę katalogu wtyczki.
 *
 * ZIP z GitHuba rozpakowuje się do tego, co akurat niesie archiwum — katalogu z
 * wersją przy wgranym pliku, `repo-<sha>` przy tarballu. Bez tego WordPress
 * instaluje aktualizację jako OSOBNĄ wtyczkę i deaktywuje tę aktualizowaną, co
 * użytkownik czyta jako „aktualizacja zepsuła stronę".
 *
 * @param string       $zrodlo       Katalog, do którego rozpakowano archiwum.
 * @param string       $zrodlo_zdalne Katalog nadrzędny.
 * @param \WP_Upgrader $upgrader     Instancja upgradera.
 * @param array        $extra        Argumenty hooka; przy aktualizacji niosą plik wtyczki.
 * @return string|\WP_Error
 */
function nerti_cookies_napraw_katalog( $zrodlo, $zrodlo_zdalne, $upgrader, $extra = array() ) {
	// Dokładne dopasowanie do własnej nazwy, nie fragment: ten filtr chodzi przy
	// KAŻDEJ aktualizacji wtyczki i motywu na stronie, a test na fragment zabrałby
	// też aktualizację wtyczki, której slug zawiera nasz.
	$plik = isset( $extra['plugin'] ) ? (string) $extra['plugin'] : '';
	if ( plugin_basename( NERTI_COOKIES_PLIK ) !== $plik ) {
		return $zrodlo;
	}

	$docelowy = trailingslashit( $zrodlo_zdalne ) . dirname( $plik );

	if ( untrailingslashit( $zrodlo ) === untrailingslashit( $docelowy ) ) {
		return $zrodlo;
	}

	global $wp_filesystem;
	if ( ! $wp_filesystem || ! $wp_filesystem->move( $zrodlo, $docelowy, true ) ) {
		return new WP_Error(
			'nerti_cookies_zmiana_nazwy',
			'Nie udało się poprawić nazwy rozpakowanego katalogu wtyczki. Wgraj ZIP ręcznie ze strony wydań.'
		);
	}

	return trailingslashit( $docelowy );
}
add_filter( 'upgrader_source_selection', 'nerti_cookies_napraw_katalog', 10, 4 );

/**
 * Obsługuje okienko „Wyświetl szczegóły".
 *
 * WordPress kieruje to pytanie do API wordpress.org, które o tej wtyczce nigdy nie
 * słyszało — więc bez tego filtra okienko otwiera się na „Wystąpił nieoczekiwany
 * błąd", tuż obok linku aktualizacji, dokładnie w chwili, gdy ktoś decyduje, czy
 * tej aktualizacji zaufać. Opis wydania jest już w pobranej odpowiedzi, więc nie
 * kosztuje to dodatkowego zapytania.
 *
 * @param false|object|array $wynik  Dotychczasowa odpowiedź.
 * @param string             $akcja  Żądana akcja API.
 * @param object             $args   Argumenty żądania; niosą slug wtyczki.
 * @return false|object|array
 */
function nerti_cookies_informacje( $wynik, $akcja, $args ) {
	$slug = isset( $args->slug ) ? $args->slug : '';
	if ( 'plugin_information' !== $akcja || $slug !== dirname( plugin_basename( NERTI_COOKIES_PLIK ) ) ) {
		return $wynik;
	}

	$wydanie = nerti_cookies_ostatnie_wydanie();
	if ( null === $wydanie ) {
		return $wynik;
	}

	$dane = get_file_data(
		NERTI_COOKIES_PLIK,
		array(
			'name'         => 'Plugin Name',
			'description'  => 'Description',
			'author'       => 'Author',
			'requires_php' => 'Requires PHP',
		)
	);

	return (object) array(
		'name'          => $dane['name'],
		'slug'          => $slug,
		'version'       => $wydanie['version'],
		'author'        => $dane['author'],
		'homepage'      => $wydanie['url'],
		'requires_php'  => $dane['requires_php'],
		'download_link' => $wydanie['package'],
		'last_updated'  => $wydanie['published'],
		'sections'      => array(
			'description' => wpautop( esc_html( $dane['description'] ) ),
			'changelog'   => nerti_cookies_opis_wydania( (string) $wydanie['notes'] ),
		),
	);
}
add_filter( 'plugins_api', 'nerti_cookies_informacje', 10, 3 );

/**
 * Cztery kawałki Markdowna, których opis wydania na GitHubie faktycznie używa, jako HTML.
 *
 * To nie jest parser Markdowna i nie próbuje nim być: najpierw wszystko jest
 * eskejpowane, potem te cztery wzorce wracają jako znaczniki. Cokolwiek innego
 * zostaje widoczne tak, jak zostało napisane — a to jest bezpieczna awaria dla
 * tekstu ściągniętego ze zdalnego API.
 *
 * @param string $opis Surowy opis wydania.
 * @return string
 */
function nerti_cookies_opis_wydania( $opis ) {
	if ( '' === trim( $opis ) ) {
		return '';
	}

	$html = esc_html( $opis );
	$html = preg_replace( '/^#{1,6}\s*(.+)$/m', '<h4>$1</h4>', $html );
	$html = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html );
	$html = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $html );
	$html = preg_replace( '/^[-*]\s+(.+)$/m', '<li>$1</li>', $html );

	return wpautop( $html );
}

/**
 * Wymusza jedno świeże sprawdzenie po zmianie własnej wersji.
 *
 * Aktualizacja instalująca nową wersję przebiega w całości w procesie STAREJ, więc
 * transient `update_plugins`, który po sobie zostawia, zapisał kod właśnie
 * podmieniany — a WordPress dławi kolejne sprawdzenie przez godzinę. Zmierzone na
 * paczce bdox przy 0.6.0 → 0.6.1: wtyczka lądowała potem poza kubełkiem `response`
 * i poza `no_update`, więc ekran wtyczek twierdził, że automatyczne aktualizacje są
 * niedostępne, mimo że poprawka na dokładnie to była już zainstalowana. Porównanie
 * zapisanej wersji z działającą kosztuje jeden odczyt opcji i odpala się raz na wersję.
 */
function nerti_cookies_zresetuj_bufor_aktualizacji() {
	if ( get_site_option( 'nerti_cookies_zainstalowana_wersja' ) === NERTI_COOKIES_WERSJA ) {
		return;
	}

	update_site_option( 'nerti_cookies_zainstalowana_wersja', NERTI_COOKIES_WERSJA );
	delete_site_transient( NERTI_COOKIES_BLAD_ODPYTANIA );
	delete_site_transient( 'update_plugins' );
}
add_action( 'init', 'nerti_cookies_zresetuj_bufor_aktualizacji' );
