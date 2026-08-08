<?php
/**
 * Plugin Name:       Nerti Cookies
 * Description:       Baner zgód, który blokuje skrypty PRZED zgodą, nie po niej. Kategorie, rejestr zgód z wersją polityki, odmowa tak samo łatwa jak zgoda na pierwszym ekranie, wygląd brany z design-system.json projektu.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Nerti
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       nerti-cookies
 *
 * @package NertiCookies
 *
 * Uwaga o zakresie: ta wtyczka wykonuje decyzje techniczne. Nie orzeka o zgodności
 * z prawem. Dokument dla klienta i zastrzeżenie, które musi mu towarzyszyć, opisuje
 * ../polityki.md — bez niego wtyczka jest połową dostawy.
 */

defined( 'ABSPATH' ) || exit;

define( 'NERTI_COOKIES_WERSJA', '0.1.0' );
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
