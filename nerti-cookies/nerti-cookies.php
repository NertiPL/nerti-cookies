<?php
/**
 * Plugin Name:       Nerti Cookies
 * Plugin URI:        https://github.com/NertiPL/nerti-cookies
 * Description:       Baner zgód, który blokuje skrypty PRZED zgodą, nie po niej. Kategorie, rejestr zgód z wersją polityki, odmowa tak samo łatwa jak zgoda na pierwszym ekranie, wygląd brany z design-system.json projektu albo z ustawień globalnych Oxygena.
 * Version:           0.2.3
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Nerti
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       nerti-cookies
 * Update URI:        https://github.com/NertiPL/nerti-cookies
 *
 * Agent Connector: Ability Pack
 *
 * Ten naglowek jest jedynym, po ktorym Agent Connector for WP rozpoznaje wtyczke jako paczke
 * abilities (`PackUpdater::installed_packs()` czyta wylacznie klucz `Agent Connector`).
 * Naglowka `Agent Connector Target:` tu NIE MA celowo: sluzy on do sparowania paczki z wtyczka
 * hosta w zdalnym katalogu Soflyy przy aktualizacjach. Nerti Cookies nie rozszerza zadnej innej
 * wtyczki i nie jest w tym katalogu, wiec Target wskazywalby na nic - dokladnie tak, jak robi
 * to wzorcowy przyklad `agent-connector-for-wp/examples/acfw-ability-pack-hello/`.
 *
 * Uwierzytelnienia, blokady domeny ani audytu w tej wtyczce NIE MA i nie ma go pisac: Agent
 * Connector wstrzykuje to sam kazdemu ability zarejestrowanemu przez jego API. Dotyczy to
 * wylacznie sciezki abilities - trasy REST `nerti-cookies/v1/*` maja wlasna bramke
 * `manage_options`, bo tam nikt niczego za nas nie wstrzykuje.
 *
 * @package NertiCookies
 *
 * Uwaga o zakresie: ta wtyczka wykonuje decyzje techniczne. Nie orzeka o zgodności
 * z prawem. Dokument dla klienta i zastrzeżenie, które musi mu towarzyszyć, opisuje
 * ../polityki.md — bez niego wtyczka jest połową dostawy.
 */

defined( 'ABSPATH' ) || exit;

define( 'NERTI_COOKIES_WERSJA', '0.2.2' );
define( 'NERTI_COOKIES_PLIK', __FILE__ );
define( 'NERTI_COOKIES_KAT', plugin_dir_path( __FILE__ ) );
define( 'NERTI_COOKIES_URL', plugin_dir_url( __FILE__ ) );

/** Nazwa ciasteczka z decyzją. Zmiana wymaga migracji — stare decyzje przestaną być widoczne. */
define( 'NERTI_COOKIES_CIASTECZKO', 'nc_zgoda' );

/** Wersja schematu tabeli rejestru. Podnoszona przy każdej zmianie kolumn. */
define( 'NERTI_COOKIES_DB', '1' );

require_once NERTI_COOKIES_KAT . 'inc/class-ustawienia.php';
require_once NERTI_COOKIES_KAT . 'inc/class-tokeny.php';
require_once NERTI_COOKIES_KAT . 'inc/class-blokada.php';
require_once NERTI_COOKIES_KAT . 'inc/class-baner.php';
require_once NERTI_COOKIES_KAT . 'inc/class-rejestr.php';
require_once NERTI_COOKIES_KAT . 'inc/class-abilities.php';
require_once NERTI_COOKIES_KAT . 'inc/class-czujka.php';

/*
 * Aktualizacje z GitHub Releases. Wczytywane BEZWARUNKOWO: plik tylko dokłada filtry,
 * a wcześniejsza bramka na `is_admin()` w paczce wzorcowej znaczyła, że WP-CLI
 * (`wp plugin list`, `wp plugin update`) i updatery hostingu odpalały
 * wp_update_plugins() z niezarejestrowanym filtrem — wtyczka wypadała wtedy z OBU
 * kubełków aktualizacji, a WordPress raportował, że automatyczne aktualizacje są
 * dla niej niedostępne.
 */
require_once NERTI_COOKIES_KAT . 'inc/updates.php';

/**
 * Punkt wejścia. Kolejność ma znaczenie: blokada podpina się na `wp_head` z priorytetem 1,
 * więc musi być zarejestrowana przed czymkolwiek, co drukuje skrypty.
 */
function nerti_cookies_start() {
	Nerti_Cookies_Ustawienia::hooks();
	Nerti_Cookies_Blokada::hooks();
	Nerti_Cookies_Baner::hooks();
	Nerti_Cookies_Rejestr::hooks();
	Nerti_Cookies_Abilities::hooks();
	Nerti_Cookies_Czujka::hooks();
}
add_action( 'plugins_loaded', 'nerti_cookies_start', 1 );

/**
 * Aktywacja: tabela rejestru zgód i ustawienia domyślne.
 * Rejestr nie jest opcją — dowód zgody musi przeżyć czyszczenie cache i przenosiny motywu.
 */
function nerti_cookies_aktywacja() {
	Nerti_Cookies_Rejestr::utworz_tabele();
	Nerti_Cookies_Ustawienia::zasiej_domyslne();
}
register_activation_hook( __FILE__, 'nerti_cookies_aktywacja' );

/**
 * Deaktywacja NIE kasuje rejestru zgód ani ustawień.
 *
 * Powód: rejestr jest dowodem na to, że zgoda została udzielona i kiedy. Skasowanie go
 * przy przypadkowej deaktywacji wtyczki zabiera klientowi jedyny dowód, jaki ma.
 * Usunięcie danych jest świadomą operacją — patrz uninstall.php, którego celowo tu nie ma.
 */
