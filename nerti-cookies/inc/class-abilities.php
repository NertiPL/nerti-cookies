<?php
/**
 * Sterowanie wtyczką z zewnątrz — przez Agent Connector for WP (MCP) i przez REST.
 *
 * PO CO TO ISTNIEJE
 * Zmiana tekstu w banerze, podniesienie wersji polityki albo import tokenów wyglądu wymaga
 * dziś zalogowania się do panelu klienta i wyklikania tego ręcznie. Przy dziesięciu stronach
 * to jest dziesięć logowań, żeby zmienić jedno zdanie. Ten plik wystawia te operacje jako
 * wywołania, które agent może wykonać przez MCP.
 *
 * SYGNATURA ZWERYFIKOWANA NA ŻYWEJ INSTALACJI — 2026-08-21, laboria (Local by Flywheel),
 * Agent Connector for WP. Poprzednia wersja tego pliku była napisana z pamięci i nie
 * rejestrowała niczego. Zmierzone różnice:
 *
 *   funkcja  wp_register_ability()          -> agent_connector_for_wp_register_ability()
 *   hook     abilities_api_init             -> wp_abilities_api_init
 *   kategorie brak                          -> wp_abilities_api_categories_init
 *   auth     permission_callback po naszej  -> BRAK. Agent Connector wstrzykuje bramkę
 *            stronie                           admin/super-admin, blokadę domeny i audyt
 *                                              dla każdego ability zarejestrowanego jego API
 *
 * Wzorzec: `agent-connector-for-wp/examples/acfw-ability-pack-hello/`. Wtyczka deklaruje się
 * jako paczka nagłówkiem `Agent Connector: Ability Pack` w `nerti-cookies.php`.
 *
 * DWIE WARSTWY, JEDNA DEFINICJA
 * `katalog()` jest jedynym miejscem, gdzie operacja jest zdefiniowana. Warstwa abilities
 * i warstwa REST to dwa adaptery po kilkanaście linii nad tym samym katalogiem — nie dwie
 * implementacje, więc nie mają jak się rozjechać. REST zostaje, bo działa na czystym
 * WordPressie bez Agent Connectora, i to jest jedyna droga sterowania banerem na stronie
 * klienta, który nie ma tej wtyczki. Uwierzytelnienie REST: hasło aplikacji + `manage_options`
 * po naszej stronie, bo tam nikt niczego za nas nie wstrzykuje.
 *
 * @package NertiCookies
 */

defined( 'ABSPATH' ) || exit;

class Nerti_Cookies_Abilities {

	/** Slug kategorii w katalogu abilities. */
	const KATEGORIA = 'nerti-cookies';

	public static function hooks() {
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'rejestruj_kategorie' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'rejestruj_abilities' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'trasy_rest' ) );
	}

	/**
	 * @return bool
	 */
	public static function moze() {
		return current_user_can( 'manage_options' );
	}

	/* ---------- Warstwa 1: Agent Connector for WP (Abilities API) ---------- */

	/**
	 * Kategoria musi powstać przed abilities, które się na nią powołują — stąd osobny hook.
	 */
	public static function rejestruj_kategorie() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		wp_register_ability_category(
			self::KATEGORIA,
			array(
				'label'       => 'Nerti Cookies',
				'description' => 'Baner zgód, rejestr zgód, inwentarz skryptów i dobór dokumentów prawnych strony.',
			)
		);
	}

	/**
	 * Bez `permission_callback` — Agent Connector wstrzykuje uwierzytelnienie i audyt sam.
	 * Dopisanie własnego callbacku tutaj nie dokłada bezpieczeństwa, tylko drugą bramkę,
	 * która potrafi się rozjechać z pierwszą.
	 */
	public static function rejestruj_abilities() {
		if ( ! function_exists( 'agent_connector_for_wp_register_ability' ) ) {
			return; // Agent Connector nieaktywny — nie rejestrujemy nic. REST niżej działa dalej.
		}

		foreach ( self::katalog() as $nazwa => $def ) {
			/* `default` musi być, inaczej ability wołana bez parametrów nie przechodzi
			   walidacji. Adapter MCP dowozi `parameters: {}` do WP_Ability::execute()
			   jako NULL; execute() woła najpierw normalize_input() (który podstawia
			   input_schema['default'], gdy wejście jest nullem), a dopiero potem
			   validate_input(). Bez zadeklarowanego default null przechodzi
			   normalizację nietknięty i rest_validate_value_from_schema odrzuca go
			   jako „nie obiekt" — czyli `pobierz-ustawienia`, `wykryj-skrypty` i
			   `statystyki-zgod`, które z definicji nie biorą argumentów, nie dawały
			   się wywołać wcale. Zmierzone 2026-08-23 na WP 7.1,
			   wp-includes/abilities-api/class-wp-ability.php. */
			$wejscie = $def['wejscie'];
			if ( ! array_key_exists( 'default', $wejscie ) ) {
				$wejscie['default'] = array();
			}

			agent_connector_for_wp_register_ability(
				'nerti-cookies/' . $nazwa,
				array(
					'label'            => $def['etykieta'],
					'description'      => $def['opis'] . ' Uprawnienia MCP zapewnia Agent Connector; REST wymaga manage_options. Wejście określa input_schema. Wynik nie dowodzi ochrony sieciowej: sprawdź zapis → odczyt → przeglądarkę. Przed zapisem zachowaj pobierz-ustawienia; odwrócenie przez zapis poprzednich wartości. Nie cofaj wersji polityki w celu przywrócenia wygasłych zgód.',
					'category'         => self::KATEGORIA,
					'input_schema'     => $wejscie,
					'output_schema'    => $def['wyjscie'],
					'annotations'      => $def['adnotacje'],
					'execute_callback' => static function ( array $input ) use ( $def ) {
						return call_user_func( $def['wykonaj'], $input );
					},
				)
			);
		}
	}

	/* ---------- Warstwa 2: REST (bezwarunkowa) ---------- */

	public static function trasy_rest() {
		foreach ( self::katalog() as $nazwa => $def ) {
			register_rest_route(
				'nerti-cookies/v1',
				'/' . $nazwa,
				array(
					'methods'             => 'POST',
					'callback'            => function ( $req ) use ( $def ) {
						$input = $req->get_json_params();
						$input = null === $input ? array() : $input;
						$valid = rest_validate_value_from_schema( $input, $def['wejscie'], 'input' );
						if ( is_wp_error( $valid ) ) { $valid->add_data(array('status' => 400)); return $valid; }
						$wynik = call_user_func( $def['wykonaj'], $input );
						return is_wp_error( $wynik ) ? $wynik : rest_ensure_response( $wynik );
					},
					'permission_callback' => array( __CLASS__, 'moze' ),
				)
			);
		}
	}

	/**
	 * Jedna definicja operacji obsługuje obie warstwy. Rozjazd między abilities a REST
	 * byłby dwiema implementacjami tej samej rzeczy, z których jedna zostaje niepoprawiona.
	 *
	 * @return array
	 */
	private static function katalog() {
		return array(

			'pobierz-ustawienia' => array(
				'etykieta' => 'Pobierz ustawienia banera',
				'opis'     => 'Zwraca pełną konfigurację banera zgód: kategorie, teksty, wersję polityki, listę blokowanych wzorców i stan importu tokenów wyglądu.',
				'wejscie'  => array( 'type' => 'object', 'properties' => new stdClass() ),
				'wyjscie'  => array( 'type' => 'object' ),
				'adnotacje' => array( 'readonly' => true, 'idempotent' => true, 'destructive' => false ),
				'wykonaj'  => array( __CLASS__, 'op_pobierz' ),
			),

			'zapisz-ustawienia' => array(
				'etykieta' => 'Zmień ustawienia banera',
				'opis'     => 'Scala przekazany fragment konfiguracji z bieżącą. Pozwala zmienić teksty, linki do polityk, ważność decyzji i mapy blokowanych skryptów bez wchodzenia do panelu WordPressa.',
				'wejscie'  => array(
					'type'       => 'object',
					'properties' => array(
						'teksty'        => array( 'type' => 'object' ),
						'link_polityka' => array( 'type' => 'string' ),
						'link_cookies'  => array( 'type' => 'string' ),
						'administrator' => array( 'type' => 'string' ),
						'waznosc_dni'   => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 365 ),
						'kategorie'     => array( 'type' => 'object' ),
						'wzorce'        => array( 'type' => 'object' ),
						'osadzenia'     => array( 'type' => 'object' ),
						'uchwyty'       => array( 'type' => 'object' ),
						'bufor_wyjscia' => array( 'type' => 'boolean' ),
						'ignoruj'       => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Wzorce nazw pominiętych w skanie (gwiazdka = dowolny ciąg, np. "pys_*"). ZASTĘPUJE całą listę. Do dopisywania pojedynczych pozycji służy przypisz-kategorie z kategorią "pomin".' ),
						'consent_mode'  => array( 'type' => 'boolean' ),
						'cm_redakcja'   => array( 'type' => 'boolean', 'description' => 'Consent Mode: ads_data_redaction przy braku zgody reklamowej.' ),
						'cm_url_passthrough' => array( 'type' => 'boolean', 'description' => 'Consent Mode: url_passthrough.' ),
						'wycofanie'     => array( 'type' => 'string', 'enum' => array( 'wtyczka', 'plywajacy', 'strona' ) ),
						'wyglad'        => array(
							'type'        => 'object',
							'description' => 'Układ banera, osobno dla telefonu i desktopu: {"desktop":{"pozycja":"dol|gora|srodek|lewy-dol|prawy-dol","szerokosc":46,"tekst":"auto|mniejszy|normalny|wiekszy"},"mobile":{...}}. Szerokość w rem, 16-100. Granica między urządzeniami to 48rem.',
						),
					),
				),
				'wyjscie'  => array( 'type' => 'object' ),
				'adnotacje' => array( 'readonly' => false, 'idempotent' => true, 'destructive' => false ),
				'wykonaj'  => array( __CLASS__, 'op_zapisz' ),
			),

			'importuj-tokeny' => array(
				'etykieta' => 'Wczytaj wygląd z design-system.json',
				'opis'     => 'Przyjmuje treść albo ścieżkę pliku design-system.json projektu i przepisuje kolory, kroje, odstępy, promienie i czasy na zmienne banera. Dzięki temu baner wygląda jak strona, a nie jak wtyczka.',
				'wejscie'  => array(
					'type'       => 'object',
					'properties' => array(
						'zrodlo' => array( 'type' => 'string', 'description' => 'Treść JSON albo ścieżka do pliku na serwerze.' ),
					),
					'required'   => array( 'zrodlo' ),
				),
				'wyjscie'  => array( 'type' => 'object' ),
				'adnotacje' => array( 'readonly' => false, 'idempotent' => true, 'destructive' => false ),
				'wykonaj'  => array( __CLASS__, 'op_tokeny' ),
			),

			'ustaw-tokeny' => array(
				'etykieta' => 'Ustaw pojedyncze tokeny wyglądu',
				'opis'     => 'Scala pojedyncze zmienne --nc-* z bieżącymi: wspólne dla wszystkich ekranów albo osobno dla telefonu (mobile, poniżej 48rem) i desktopu. Null albo pusty string usuwa token. Służy do oddania banera 1:1 z projektem: obrys, kolejność i hover przycisków, link w zdaniu, odstęp od krawędzi, wejście. Lista zmiennych: README, sekcja Tokeny. Tokeny wspólne nadpisze kolejny import; tokeny urządzeń zostają. Kontrast mierzy i ostrzega, ale nie odrzuca.',
				'wejscie'  => array(
					'type'       => 'object',
					'properties' => array(
						'tokeny'  => array( 'type' => 'object', 'description' => 'Mapa "--nc-nazwa": "wartość" dla wszystkich ekranów.' ),
						'mobile'  => array( 'type' => 'object', 'description' => 'Mapa tokenów tylko dla telefonu.' ),
						'desktop' => array( 'type' => 'object', 'description' => 'Mapa tokenów tylko dla desktopu.' ),
					),
				),
				'wyjscie'  => array( 'type' => 'object' ),
				'adnotacje' => array( 'readonly' => false, 'idempotent' => true, 'destructive' => false ),
				'wykonaj'  => array( __CLASS__, 'op_ustaw_tokeny' ),
			),

			'importuj-z-oxygena' => array(
				'etykieta' => 'Wczytaj wygląd z ustawień globalnych Oxygena',
				'opis'     => 'Drugie źródło wyglądu, na wypadek gdy projekt nie ma pliku design-system.json. Czyta ustawienia globalne Oxygena — kolory, kroje, rozmiar bazowy, promień i cień przycisku, czas przejścia — i przepisuje je na te same zmienne banera. Kolejność pierwszeństwa: design-system.json, potem Oxygen, potem wartości domyślne wtyczki. Po imporcie mierzy kontrast tekstu na tle panelu i napisu przycisku na przycisku; para poniżej 4,5:1 NIE zostaje zaimportowana i wraca w polu „ostrzezenia", bo baner, którego nie da się przeczytać, jest gorszy niż baner niepasujący do strony.',
				'wejscie'  => array( 'type' => 'object', 'properties' => new stdClass() ),
				'wyjscie'  => array( 'type' => 'object' ),
				'adnotacje' => array( 'readonly' => false, 'idempotent' => true, 'destructive' => false ),
				'wykonaj'  => array( __CLASS__, 'op_oxygen' ),
			),

			'podnies-wersje-polityki' => array(
				'etykieta' => 'Podnieś wersję polityki',
				'opis'     => 'Unieważnia wszystkie dotychczasowe zgody i pokazuje baner ponownie. Używane po zmianie treści polityki albo po dodaniu narzędzia, które ustawia nowe ciasteczka.',
				'wejscie'  => array(
					'type'       => 'object',
					'properties' => array( 'wersja' => array( 'type' => 'string' ) ),
				),
				'wyjscie'  => array( 'type' => 'object' ),
				'adnotacje' => array( 'readonly' => false, 'idempotent' => false, 'destructive' => true ),
				'wykonaj'  => array( __CLASS__, 'op_wersja' ),
			),

			'statystyki-zgod' => array(
				'etykieta' => 'Statystyki zgód',
				'opis'     => 'Liczba zapisanych decyzji, liczba odmów i data ostatniej. Służy do sprawdzenia, czy baner w ogóle zapisuje odmowy — rejestr z zerem odmów przy tysiącu zgód jest sygnałem defektu, nie sukcesu.',
				'wejscie'  => array( 'type' => 'object', 'properties' => new stdClass() ),
				'wyjscie'  => array( 'type' => 'object' ),
				'adnotacje' => array( 'readonly' => true, 'idempotent' => true, 'destructive' => false ),
				'wykonaj'  => array( __CLASS__, 'op_statystyki' ),
			),

			'zaproponuj-dokumenty' => array(
				'etykieta' => 'Zaproponuj komplet dokumentów prawnych',
				'opis'     => 'Dobiera komplet dokumentów prawnych do typu strony i sprawdza, których brakuje. Strona wizytówkowa albo ofertowa bez sklepu dostaje JEDEN dokument — politykę prywatności z informacją o ciasteczkach w środku. Sklep dostaje osobne: prywatność, cookies, regulamin, zwroty i odstąpienie, dane firmowe. Każda pozycja wraca z uzasadnieniem i ze statusem: czy taka podstrona jest już opublikowana. Nie generuje treści — treść powstaje z faktycznego inwentarza skryptów, nie z szablonu.',
				'wejscie'  => array(
					'type'       => 'object',
					'properties' => array(
						'typ'      => array(
							'type'        => 'string',
							'enum'        => array( 'wizytowka', 'ofertowa', 'sklep' ),
							'description' => 'Typ strony. wizytowka i ofertowa oznaczają brak sprzedaży online.',
						),
						'funkcje'  => array(
							'type'        => 'array',
							'items'       => array(
								'type' => 'string',
								'enum' => array( 'formularz', 'newsletter', 'rezerwacje', 'konta', 'platnosci', 'czat', 'komentarze' ),
							),
							'description' => 'Co strona faktycznie robi. Każda pozycja może dolożyć dokument.',
						),
						'kategorie' => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string', 'enum' => array( 'analityczne', 'marketingowe' ) ),
							'description' => 'Kategorie skryptów nieniezbędnych z inwentarza. Puste — wywołanie odczyta je z rejestru WordPressa i powie o tym w polu dodatkowo.',
						),
						'pracownikow' => array(
							'type'        => 'integer',
							'description' => 'Liczba pracowników. Rozstrzyga zwolnienie mikroprzedsiębiorcy z wymogów dostępności (próg: mniej niż 10). Brak tego pola i pola obrot_mln_eur zwraca pytanie do klienta, nie domysł.',
						),
						'obrot_mln_eur' => array(
							'type'        => 'number',
							'description' => 'Roczny obrót albo suma bilansowa w mln EUR. Drugi warunek zwolnienia mikroprzedsiębiorcy (próg: do 2).',
						),
					),
					'required'   => array( 'typ' ),
				),
				'wyjscie'  => array(
					'type'       => 'object',
					'properties' => array(
						'wariant'      => array( 'type' => 'string' ),
						'dokumenty'    => array( 'type' => 'array' ),
						'brakujace'    => array( 'type' => 'array' ),
						'dodatkowo'    => array( 'type' => 'array' ),
						'dostepnosc'   => array( 'type' => 'object' ),
						'zastrzezenie' => array( 'type' => 'string' ),
						'nota'         => array( 'type' => 'string' ),
					),
				),
				'adnotacje' => array( 'readonly' => true, 'idempotent' => true, 'destructive' => false ),
				'wykonaj'  => array( __CLASS__, 'op_dokumenty' ),
			),

			'skanuj-strone' => array(
				'etykieta' => 'Skanuj stronę',
				'opis'     => 'Pobiera podstrony tak jak nowy odwiedzający (bez ciasteczek, przez cache) i zwraca, co na nich jest: skrypty, osadzenia, piksele i ciasteczka z nagłówków serwera — każda pozycja z nazwą narzędzia, kategorią i statusem: blokowane (czeka na zgodę), zawsze (niezbędne), BLAD (śledzi przed zgodą — bloker Q-COOKIE), nieznane (do rozstrzygnięcia). Trzy statusy pomijane nie wymagają decyzji i nie trafiają do polityki cookies: admin (ślad panelu administratora: kreator stron, Site Kit, klient REST WordPressa), pozostalosc (po usuniętej wtyczce, np. Complianz) i pominiete (właściciel użył „Pomiń"; pole status_pierwotny mówi, czym była). Ciasteczek ustawianych przez JavaScript ta operacja NIE widzi: to robi skan z panelu (Ciasteczka → Skanuj stronę), który dopisuje je do tego samego wyniku. Wynik zapisuje się i zasila shortcode [nerti_cookies_lista].',
				'wejscie'  => array(
					'type'       => 'object',
					'properties' => array(
						'adresy'  => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Podstrony tej witryny. Puste = strona główna, dokumenty, 8 stron, 2 wpisy, sklep.' ),
						'zapisany' => array( 'type' => 'boolean', 'description' => 'true = zwróć ostatni zapisany wynik bez skanowania.' ),
						'tylko_lista' => array( 'type' => 'boolean', 'description' => 'true = zwróć tylko listę podstron, które skaner by sprawdził.' ),
						'dopisz'   => array( 'type' => 'boolean', 'description' => 'true = dopisz podane adresy do świeżego wyniku (skan podstrona po podstronie).' ),
					),
				),
				'wyjscie'  => array( 'type' => 'object' ),
				'adnotacje' => array( 'readonly' => false, 'idempotent' => true, 'destructive' => false ),
				'wykonaj'  => array( __CLASS__, 'op_skanuj' ),
			),

			'przypisz-kategorie' => array(
				'etykieta' => 'Przypisz kategorie pozycjom ze skanu',
				'opis'     => 'Hurtowo przypisuje kategorię (niezbedne, analityczne, marketingowe) albo pomija (pomin) pozycje, które skan zostawił jako nierozpoznane. Nazwa może mieć gwiazdkę: "pys_*" obejmuje wszystkie ciasteczka i wpisy pamięci zaczynające się od pys_. Dopasowanie idzie po kluczu pozycji (nazwa ciasteczka, klucz storage, domena, id usługi) i po nazwie widocznej w skanie. Dotyka tylko pozycji o statusie nieznane: rozpoznanych (blokowane, zawsze) i działających przed zgodą (BLAD) nie ruszy, żeby nie ukryć prawdziwego śledzenia. "pomin" nie zmienia blokady, tylko usuwa pozycję z listy zadań i z polityki cookies; cofa się usunięciem wzorca z ustawienia ignoruj (zapisz-ustawienia). Wymaga wcześniejszego skanu (skanuj-strone, najlepiej pełny z panelu). Wynik: co się zmieniło, co pominięto i dlaczego, czego skan nie zawiera. Po zmianie kategorii wyczyść cache strony.',
				'wejscie'  => array(
					'type'       => 'object',
					'properties' => array(
						'pozycje' => array(
							'type'        => 'array',
							'minItems'    => 1,
							'maxItems'    => 200,
							'description' => 'Lista przypisań.',
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'nazwa'     => array( 'type' => 'string', 'minLength' => 1, 'description' => 'Nazwa pozycji ze skanu albo wzorzec z gwiazdką, np. "pys_*", "cmplz_*", "fbqpc_*".' ),
									'kategoria' => array( 'type' => 'string', 'enum' => array( 'marketingowe', 'analityczne', 'niezbedne', 'pomin' ) ),
								),
								'required'   => array( 'nazwa', 'kategoria' ),
							),
						),
					),
					'required'   => array( 'pozycje' ),
				),
				'wyjscie'  => array( 'type' => 'object' ),
				'adnotacje' => array( 'readonly' => false, 'idempotent' => true, 'destructive' => false ),
				'wykonaj'  => array( __CLASS__, 'op_przypisz' ),
			),

			'zakoncz-kreator' => array(
				'etykieta' => 'Zakończ kreator pierwszego uruchomienia',
				'opis'     => 'Oznacza kreator „Pierwsze uruchomienie" jako ukończony, więc z panelu WordPressa znika przypomnienie „Skonfiguruj w 4 krokach". Służy agentowi, który skonfigurował wtyczkę przez MCP (dokumenty, skan, wygląd) i nie chce, żeby klient dostał przypomnienie o czymś, co już jest zrobione. Z {"otworz": true} otwiera kreator ponownie. Nie zmienia żadnych ustawień banera. Zakończenie nie jest dowodem, że wszystko działa: sprawdza to operacja status.',
				'wejscie'  => array(
					'type'       => 'object',
					'properties' => array(
						'otworz' => array( 'type' => 'boolean', 'description' => 'true = otwórz kreator ponownie zamiast go kończyć.' ),
					),
				),
				'wyjscie'  => array( 'type' => 'object' ),
				'adnotacje' => array( 'readonly' => false, 'idempotent' => true, 'destructive' => false ),
				'wykonaj'  => array( __CLASS__, 'op_kreator' ),
			),

			'lista-uslug' => array(
				'etykieta' => 'Lista znanych usług',
				'opis'     => 'Biblioteka usług, które wtyczka rozpoznaje sama: nazwa, kategoria, czy jest blokowana do zgody, po co jest (po polsku, do polityki), kto otrzymuje dane, ciasteczka z czasem życia i wzorce, po których ją rozpoznaje. Cloudflare Turnstile jest niezbędny i nigdy nie jest blokowany. Czas i odbiorca to wartości z dokumentacji dostawców — do potwierdzenia przed publikacją polityki.',
				'wejscie'  => array( 'type' => 'object', 'properties' => new stdClass() ),
				'wyjscie'  => array( 'type' => 'object' ),
				'adnotacje' => array( 'readonly' => true, 'idempotent' => true, 'destructive' => false ),
				'wykonaj'  => array( __CLASS__, 'op_uslugi' ),
			),

			'eksport-rejestru' => array(
				'etykieta' => 'Eksport rejestru zgód',
				'opis'     => 'Zwraca rejestr zgód jako CSV (średnik, UTF-8 z BOM — otwiera się w Excelu) albo jako wiersze JSON. Rejestr nie zawiera adresów IP, tylko ich skrót z solą instalacji. Używane, gdy ktoś kwestionuje zgodę albo do audytu.',
				'wejscie'  => array(
					'type'       => 'object',
					'properties' => array(
						'od'     => array( 'type' => 'string', 'description' => 'Data RRRR-MM-DD, od której eksportować. Puste = cały rejestr.' ),
						'limit'  => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 50000, 'description' => '0 = bez limitu.' ),
						'format' => array( 'type' => 'string', 'enum' => array( 'csv', 'json' ) ),
					),
				),
				'wyjscie'  => array( 'type' => 'object' ),
				'adnotacje' => array( 'readonly' => true, 'idempotent' => true, 'destructive' => false ),
				'wykonaj'  => array( __CLASS__, 'op_eksport' ),
			),

			'status' => array(
				'etykieta' => 'Stan ochrony',
				'opis'     => 'To samo, co klient widzi na pulpicie: lista kontroli (czy baner jest na każdej podstronie, czy coś śledzi przed zgodą, czy są pozycje nierozpoznane, czy jest wejście do zmiany zgody, adres polityki), podsumowanie ostatniego skanu, decyzje z 30 dni, ciasteczka zatrzymane na serwerze, wykryte integracje i wtyczki cache. Werdykt: ok / uwagi / naprawa.',
				'wejscie'  => array( 'type' => 'object', 'properties' => new stdClass() ),
				'wyjscie'  => array( 'type' => 'object' ),
				'adnotacje' => array( 'readonly' => true, 'idempotent' => true, 'destructive' => false ),
				'wykonaj'  => array( __CLASS__, 'op_status' ),
			),

			'inwentarz-skryptow' => array(
				'etykieta' => 'Inwentarz skryptów zewnętrznych',
				'opis'     => 'Zwraca listę uchwytów skryptów zarejestrowanych w WordPressie wraz z ich adresami i przypisaną kategorią. To jest wejście do polityki cookies generowanej z faktycznego stanu strony, nie z szablonu.',
				'wejscie'  => array( 'type' => 'object', 'properties' => new stdClass() ),
				'wyjscie'  => array( 'type' => 'object' ),
				'adnotacje' => array( 'readonly' => true, 'idempotent' => true, 'destructive' => false ),
				'wykonaj'  => array( __CLASS__, 'op_inwentarz' ),
			),
		);
	}

	/* ---------- Operacje ---------- */

	public static function op_pobierz( $args = array() ) {
		unset( $args );
		$u = Nerti_Cookies_Ustawienia::pobierz();
		return array(
			'ustawienia'       => $u,
			'tokeny'           => Nerti_Cookies_Tokeny::pobierz(),
			'tokeny_urzadzen'  => Nerti_Cookies_Tokeny::pobierz_urzadzenia(),
			'tokeny_z_systemu' => Nerti_Cookies_Tokeny::czy_zaimportowane(),
			// Skąd pochodzi wygląd i co import zgłosił — bez tego agent po drugiej
			// stronie widzi kolory, ale nie wie, czy to projekt, Oxygen, czy nic.
			'wyglad_zrodlo'    => Nerti_Cookies_Tokeny::zrodlo(),
			'wyglad_opis'      => Nerti_Cookies_Tokeny::opis_zrodla(),
			'oxygen_dostepny'  => null !== Nerti_Cookies_Tokeny::ustawienia_oxygena(),
		);
	}

	public static function op_zapisz( $args = array() ) {
		$dozwolone = array( 'teksty', 'link_polityka', 'link_cookies', 'waznosc_dni', 'kategorie', 'wzorce', 'osadzenia', 'uchwyty', 'bufor_wyjscia', 'consent_mode', 'wyglad', 'administrator', 'wycofanie', 'ignoruj' );
		$nowe      = array();
		foreach ( $dozwolone as $klucz ) {
			if ( array_key_exists( $klucz, (array) $args ) ) {
				$nowe[ $klucz ] = $args[ $klucz ];
			}
		}
		if ( empty( $nowe ) ) {
			return array( 'ok' => false, 'blad' => 'Nie przekazano żadnego znanego pola.' );
		}
		$wynik = Nerti_Cookies_Ustawienia::zapisz( $nowe );
		return is_wp_error( $wynik ) ? $wynik : array( 'ok' => true, 'ustawienia' => $wynik );
	}

	public static function op_tokeny( $args = array() ) {
		$zrodlo = isset( $args['zrodlo'] ) ? $args['zrodlo'] : '';
		$wynik  = Nerti_Cookies_Tokeny::importuj( $zrodlo );
		/*
		 * Braki wracają jawnie. Import, który po cichu zostawia połowę zmiennych na
		 * wartościach domyślnych wtyczki, daje baner „prawie" w stylu strony — a to
		 * wygląda gorzej niż baner całkiem obcy, bo wygląda na pomyłkę.
		 */
		return $wynik;
	}

	public static function op_ustaw_tokeny( $args = array() ) {
		$args    = is_array( $args ) ? $args : array();
		$wspolne = isset( $args['tokeny'] ) && is_array( $args['tokeny'] ) ? $args['tokeny'] : array();
		$urz     = array();
		foreach ( array( 'mobile', 'desktop' ) as $u ) {
			if ( isset( $args[ $u ] ) && is_array( $args[ $u ] ) ) {
				$urz[ $u ] = $args[ $u ];
			}
		}
		if ( empty( $wspolne ) && empty( $urz ) ) {
			return array( 'ok' => false, 'blad' => 'Podaj tokeny, mobile albo desktop.' );
		}
		return Nerti_Cookies_Tokeny::ustaw( $wspolne, $urz );
	}

	/**
	 * Wynik niesie `zrodlo`, `pomiary` i `ostrzezenia`. Agent po drugiej stronie ma
	 * zobaczyć, że kontrast został sprawdzony i ile wyszło — „ok: true" bez liczby
	 * nie różni się niczym od importu, który nic nie sprawdził.
	 *
	 * @param array $args Bez parametrów.
	 * @return array
	 */
	public static function op_oxygen( $args = array() ) {
		return Nerti_Cookies_Tokeny::importuj_z_oxygena();
	}

	public static function op_wersja( $args = array() ) {
		$wersja = isset( $args['wersja'] ) ? (string) $args['wersja'] : '';
		$wynik = Nerti_Cookies_Ustawienia::podnies_wersje_polityki( $wersja );
		return is_wp_error($wynik) ? $wynik : array( 'ok' => true, 'wersja_polityki' => $wynik );
	}

	public static function op_statystyki( $args = array() ) {
		unset( $args );
		return Nerti_Cookies_Rejestr::statystyki();
	}

	public static function op_skanuj( $args = array() ) {
		$args = is_array( $args ) ? $args : array();
		if ( ! empty( $args['tylko_lista'] ) ) {
			return array( 'adresy' => Nerti_Cookies_Skaner::adresy() );
		}
		if ( ! empty( $args['zapisany'] ) ) {
			$w = Nerti_Cookies_Skaner::wynik();
		} else {
			$adresy = array_values( array_filter( array_map( 'esc_url_raw', (array) ( $args['adresy'] ?? array() ) ) ) );
			$w      = Nerti_Cookies_Skaner::skanuj( $adresy, ! empty( $args['dopisz'] ) );
		}
		return array(
			'adresy'        => array_values( (array) ( $w['adresy'] ?? array() ) ),
			'podsumowanie'  => Nerti_Cookies_Skaner::podsumowanie(),
			'strony'        => (object) ( $w['strony'] ?? array() ),
			'pozycje'       => array_values( (array) ( $w['pozycje'] ?? array() ) ),
			'nota'          => 'Skan serwerowy nie widzi ciasteczek ustawianych przez JavaScript. Pełny skan: panel → Ciasteczka → Skanuj stronę (dopisuje wynik z przeglądarki). Status BLAD = bloker Q-COOKIE.',
		);
	}

	/**
	 * Hurtowe przypisanie kategorii / pominięcie pozycji ze skanu. Zapis robi
	 * `Nerti_Cookies_Skaner::przypisz()` i `::pomin()`, te same co przyciski w panelu.
	 *
	 * @param array $args {pozycje:[{nazwa,kategoria}]}
	 * @return array|WP_Error
	 */
	public static function op_przypisz( $args = array() ) {
		$wpisy = is_array( $args ) && isset( $args['pozycje'] ) && is_array( $args['pozycje'] ) ? $args['pozycje'] : array();
		if ( ! $wpisy ) {
			return new WP_Error( 'nc_przypisz', 'Podaj pozycje: [{"nazwa":"pys_*","kategoria":"marketingowe"}].', array( 'status' => 400 ) );
		}
		$skan = Nerti_Cookies_Skaner::surowy();
		if ( empty( $skan['pozycje'] ) ) {
			return new WP_Error( 'nc_przypisz', 'Brak wyniku skanu. Najpierw skanuj-strone (najlepiej pełny skan z panelu).', array( 'status' => 409 ) );
		}
		$zmiany    = array();
		$bez_zmian = array();
		$brak      = array();
		foreach ( $wpisy as $wpis ) {
			$nazwa = trim( (string) ( $wpis['nazwa'] ?? '' ) );
			$kat   = (string) ( $wpis['kategoria'] ?? '' );
			if ( '' === $nazwa || ! in_array( $kat, array( 'niezbedne', 'analityczne', 'marketingowe', 'pomin' ), true ) ) {
				return new WP_Error( 'nc_przypisz', 'Pozycja "' . $nazwa . '": nazwa wymagana, kategoria: niezbedne, analityczne, marketingowe albo pomin.', array( 'status' => 400 ) );
			}
			$dop = array();
			foreach ( $skan['pozycje'] as $p ) {
				if ( Nerti_Cookies_Uslugi::pasuje_nazwa( (string) $p['klucz'], $nazwa ) || Nerti_Cookies_Uslugi::pasuje_nazwa( (string) $p['nazwa'], $nazwa ) ) {
					$dop[] = $p;
				}
			}
			if ( ! $dop ) {
				$brak[] = $nazwa;
				continue;
			}
			$do_zmiany = array();
			foreach ( $dop as $p ) {
				if ( 'nieznane' === $p['status'] ) {
					$do_zmiany[] = $p;
				} else {
					$bez_zmian[] = array( 'nazwa' => $nazwa, 'klucz' => $p['klucz'], 'status' => $p['status'], 'powod' => 'Pozycja jest już rozpoznana albo działa przed zgodą; ta operacja zmienia tylko nierozpoznane.' );
				}
			}
			if ( ! $do_zmiany ) {
				continue;
			}
			$klucze = array_map( static function ( $p ) { return (string) $p['klucz']; }, $do_zmiany );
			if ( 'pomin' === $kat ) {
				// Wzorzec idzie do ustawienia w całości: `pys_*` obejmie też przyszłe `pys_*`.
				$w = Nerti_Cookies_Skaner::pomin( $nazwa );
				if ( is_wp_error( $w ) ) {
					return $w;
				}
				$zmiany[] = array( 'nazwa' => $nazwa, 'kategoria' => 'pomin', 'dopasowane' => $klucze, 'zapisany_wzorzec' => $nazwa );
				continue;
			}
			// Do banera (kasowanie po odmowie) nadają się tylko wzorce z gwiazdką na końcu, więc inne
			// rozwijamy do dokładnych kluczy. Każda para (rodzaj, wzorzec) idzie do zapisu raz.
			$gw       = strpos( $nazwa, '*' );
			$na_koncu = false !== $gw && strlen( $nazwa ) - 1 === $gw;
			$zapisane = array();
			foreach ( $do_zmiany as $p ) {
				$wz = ( $na_koncu && Nerti_Cookies_Uslugi::pasuje_nazwa( (string) $p['klucz'], $nazwa ) ) ? $nazwa : (string) $p['klucz'];
				$id = $p['typ'] . '|' . $wz;
				if ( isset( $zapisane[ $id ] ) ) {
					continue;
				}
				$w = Nerti_Cookies_Skaner::przypisz( (string) $p['typ'], $wz, $kat );
				if ( is_wp_error( $w ) ) {
					return $w;
				}
				$zapisane[ $id ] = $wz;
			}
			$zmiany[] = array( 'nazwa' => $nazwa, 'kategoria' => $kat, 'dopasowane' => $klucze, 'zapisane_wzorce' => array_values( $zapisane ) );
		}
		return array(
			'ok'                 => true,
			'zmiany'             => $zmiany,
			'bez_zmian'          => $bez_zmian,
			'brak_dopasowania'   => $brak,
			'ignoruj'            => array_values( (array) Nerti_Cookies_Ustawienia::pole( 'ignoruj', array() ) ),
			'podsumowanie_skanu' => Nerti_Cookies_Skaner::podsumowanie(),
			'nota'               => 'Blokada kategorii działa od następnego wyświetlenia strony: wyczyść cache. Pominięcie ("pomin") nie zmienia blokady skryptów.',
		);
	}

	/**
	 * Kończy (albo otwiera ponownie) kreator pierwszego uruchomienia.
	 *
	 * @param array $args {otworz?:bool}
	 * @return array
	 */
	public static function op_kreator( $args = array() ) {
		$otworz = is_array( $args ) && ! empty( $args['otworz'] );
		if ( $otworz ) {
			Nerti_Cookies_Kreator::otworz();
		} else {
			Nerti_Cookies_Kreator::zakoncz();
		}
		return array( 'ok' => true, 'kreator_ukonczony' => Nerti_Cookies_Kreator::ukonczony() );
	}

	public static function op_uslugi( $args = array() ) {
		unset( $args );
		return array( 'uslugi' => Nerti_Cookies_Uslugi::lista() );
	}

	public static function op_eksport( $args = array() ) {
		$args = is_array( $args ) ? $args : array();
		$od   = isset( $args['od'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $args['od'] ) ? (string) $args['od'] : '';
		$rows = Nerti_Cookies_Rejestr::wiersze( $od, isset( $args['limit'] ) ? (int) $args['limit'] : 0 );
		if ( isset( $args['format'] ) && 'json' === $args['format'] ) {
			return array( 'ile' => count( $rows ), 'kolumny' => Nerti_Cookies_Rejestr::KOLUMNY_CSV, 'wiersze' => $rows );
		}
		return array( 'ile' => count( $rows ), 'plik' => 'zgody-' . gmdate( 'Y-m-d' ) . '.csv', 'csv' => Nerti_Cookies_Rejestr::do_csv( $rows ) );
	}

	public static function op_status( $args = array() ) {
		unset( $args );
		$kontrole = array();
		$bledy    = 0;
		$uwagi    = 0;
		foreach ( Nerti_Cookies_Pulpit::kontrole() as $k ) {
			$kontrole[] = array( 'ok' => (bool) $k[0], 'waga' => $k[1], 'tekst' => $k[2] );
			if ( ! $k[0] ) {
				'blad' === $k[1] ? $bledy++ : $uwagi++;
			}
		}
		$u = Nerti_Cookies_Ustawienia::pobierz();
		return array(
			'werdykt'       => $bledy ? 'naprawa' : ( $uwagi ? 'uwagi' : 'ok' ),
			'kontrole'      => $kontrole,
			'skan'          => Nerti_Cookies_Skaner::podsumowanie(),
			'zgody_30_dni'  => Nerti_Cookies_Rejestr::statystyki_okres( 30 ),
			'zgody_razem'   => Nerti_Cookies_Rejestr::statystyki(),
			'zatrzymane_na_serwerze' => (object) Nerti_Cookies_Naglowki::slad(),
			'integracje'    => Nerti_Cookies_Integracje::wykryte(),
			'cache'         => Nerti_Cookies_Cache::wykryte(),
			'wersja_polityki' => (string) $u['wersja_polityki'],
			'wersja_wtyczki'  => NERTI_COOKIES_WERSJA,
			'kreator_ukonczony' => (bool) get_option( 'nerti_cookies_kreator', false ),
		);
	}

	public static function op_inwentarz( $args = array() ) {
		unset( $args );
		$u     = Nerti_Cookies_Ustawienia::pobierz();
		$lista = array();

		$wp_scripts = wp_scripts();
		if ( $wp_scripts && ! empty( $wp_scripts->registered ) ) {
			foreach ( $wp_scripts->registered as $uchwyt => $obj ) {
				$src = isset( $obj->src ) ? (string) $obj->src : '';
				if ( '' === $src ) {
					continue;
				}
				$zewnetrzny = ( 0 === strpos( $src, 'http' ) && false === strpos( $src, wp_parse_url( home_url(), PHP_URL_HOST ) ) );
				$kat        = isset( $u['uchwyty'][ $uchwyt ] ) ? $u['uchwyty'][ $uchwyt ] : '';
				if ( '' === $kat ) {
					foreach ( $u['wzorce'] as $fragment => $kategoria ) {
						if ( false !== stripos( $src, (string) $fragment ) ) {
							$kat = (string) $kategoria;
							break;
						}
					}
				}
				$lista[] = array(
					'uchwyt'     => $uchwyt,
					'src'        => $src,
					'zewnetrzny' => $zewnetrzny,
					'kategoria'  => '' !== $kat ? $kat : ( $zewnetrzny ? 'DO ROZSTRZYGNIĘCIA' : 'niezbedne' ),
				);
			}
		}

		return array(
			'wtyczki_aktywne' => array_values( (array) get_option( 'active_plugins', array() ) ),
			'skrypty'         => $lista,
			'nota'            => 'Ta lista jest NIEPEŁNA z dwóch powodów i nie zastępuje inwentarza z przeglądarki. Po pierwsze: rejestr WordPressa widzi tylko skrypty dodane przez wp_enqueue_script, a piksele wklejone w motyw albo wstrzykiwane przez menedżera tagów nie przechodzą przez ten rejestr. Po drugie: wywołana przez REST, funkcja widzi wyłącznie skrypty zarejestrowane do momentu init — te z hooka wp_enqueue_scripts jeszcze nie istnieją. Pełny inwentarz robisz procedurą z ../inwentarz.md §2.',
		);
	}

	/**
	 * Dobór kompletu dokumentów prawnych do typu strony.
	 *
	 * Reguła, którą ta funkcja egzekwuje: strona bez sprzedaży online dostaje JEDEN dokument
	 * z ciasteczkami w środku, sklep dostaje osobne. Powód nie jest estetyczny. Wizytówka ma
	 * kilkanaście ciasteczek i jednego odbiorcę danych — rozbicie tego na dwie podstrony daje
	 * dwa miejsca, w których trzeba pamiętać o aktualizacji, i dwie wersje, które mogą się
	 * rozjechać z rejestrem zgód. Sklep ma tabelę na kilkadziesiąt wierszy plus płatności plus
	 * remarketing — wklejona do polityki prywatności robi dokument, którego nikt nie doczyta
	 * do sekcji o prawach osoby.
	 *
	 * Ta funkcja NIE generuje treści. Treść powstaje z faktycznego inwentarza skryptów
	 * (skill cookies-rodo, `inwentarz.md`); szablon jest rusztowaniem, nie treścią.
	 *
	 * @param array $args Wejście.
	 * @return array
	 */
	public static function op_dokumenty( $args = array() ) {
		$typ = isset( $args['typ'] ) ? (string) $args['typ'] : '';
		if ( ! in_array( $typ, array( 'wizytowka', 'ofertowa', 'sklep' ), true ) ) {
			return array( 'ok' => false, 'blad' => 'Pole „typ" musi być jednym z: wizytowka, ofertowa, sklep.' );
		}

		$funkcje = array_map( 'strval', (array) ( isset( $args['funkcje'] ) ? $args['funkcje'] : array() ) );
		$ma      = static function ( $co ) use ( $funkcje ) {
			return in_array( $co, $funkcje, true );
		};

		// Kategorie skryptów nieniezbędnych: z wejścia, a gdy puste — z faktycznego stanu strony.
		$kategorie  = array_map( 'strval', (array) ( isset( $args['kategorie'] ) ? $args['kategorie'] : array() ) );
		$zrodlo_kat = 'z wejścia';
		if ( empty( $kategorie ) ) {
			$zrodlo_kat = 'z rejestru WordPressa — NIEPEŁNY, patrz nota przy inwentarz-skryptow';
			$inw        = self::op_inwentarz();
			foreach ( $inw['skrypty'] as $s ) {
				if ( in_array( $s['kategoria'], array( 'analityczne', 'marketingowe' ), true ) && ! in_array( $s['kategoria'], $kategorie, true ) ) {
					$kategorie[] = $s['kategoria'];
				}
			}
		}
		$sledzi = ! empty( $kategorie );

		$sklep   = ( 'sklep' === $typ );
		$wariant = $sklep ? 'sklepowy' : 'jednodokumentowy';
		$d       = array();

		/* --- Polityka prywatności: zawsze, w obu wariantach --- */
		$sekcje_pp = array(
			'Administrator danych osobowych',
			'Po co przetwarzamy dane i na jakiej podstawie',
			'Jak długo przechowujemy dane',
			'Czy podanie danych jest obowiązkowe',
			'Twoje uprawnienia',
			'Zgoda i skarga do Prezesa UODO',
			'Odbiorcy danych',
			'Przekazywanie danych poza EOG',
			'Bezpieczeństwo danych',
			'Zautomatyzowane decyzje i profilowanie',
			'Data przeglądu i zmiany polityki',
		);
		if ( ! $sklep ) {
			// Ciasteczka wchodzą do środka, tuż przed sekcję o profilowaniu.
			array_splice( $sekcje_pp, 9, 0, array( 'Pliki cookies' ) );
		}
		$d[] = array(
			'slug'     => 'polityka-prywatnosci',
			'tytul'    => 'Polityka prywatności',
			'wymagany' => true,
			'powod'    => $sklep
				? 'RODO art. 13 — obowiązek informacyjny wobec każdej osoby, której dane zbierasz. W sklepie zbierasz je zawsze, przy pierwszym zamówieniu.'
				: 'RODO art. 13 — obowiązek informacyjny. Ciasteczka siedzą W ŚRODKU tego dokumentu jako sekcja: strona bez sprzedaży nie ma na tyle dużej tabeli ciasteczek, żeby rozbicie na dwie podstrony cokolwiek dawało, a daje dwa miejsca do aktualizowania i dwie wersje do rozjechania się z rejestrem zgód.',
			'sekcje'   => $sekcje_pp,
			'szablon'  => $sklep ? 'szablony/komplet-sklepowy.md' : 'szablony/komplet-jednodokumentowy.md',
		);

		/* --- Polityka cookies: osobno tylko w sklepie --- */
		if ( $sklep ) {
			$d[] = array(
				'slug'     => 'polityka-cookies',
				'tytul'    => 'Polityka cookies',
				'wymagany' => true,
				'powod'    => 'OSOBNY dokument, bo w sklepie tabela ciasteczek ma kilkadziesiąt wierszy: koszyk, sesja, operator płatności, remarketing, porzucony koszyk. Wklejona do polityki prywatności zabija czytelność dokumentu, który ma odpowiedzieć na pytanie o dane osobowe.',
				'sekcje'   => array(
					'Czym są pliki cookies i dlaczego pytamy o zgodę na sam zapis',
					'Kategorie: niezbędne, analityczne, marketingowe',
					'Tabela: nazwa, dostawca, cel, czas życia',
					'Jak wycofać albo zmienić zgodę',
					'Ustawienia przeglądarki',
					'Data przeglądu',
				),
				'szablon'  => 'szablony/komplet-sklepowy.md',
			);
		}

		/* --- Regulamin: sprzedaż, konta albo usługa świadczona drogą elektroniczną --- */
		$powod_reg = '';
		if ( $sklep ) {
			$powod_reg = 'Sprzedaż online. Regulamin określa zawarcie umowy, cenę, dostawę i reklamacje.';
		} elseif ( $ma( 'konta' ) ) {
			$powod_reg = 'Strona zakłada konta użytkownikom — to usługa świadczona drogą elektroniczną, więc regulamin jest wymagany mimo braku sklepu.';
		} elseif ( $ma( 'platnosci' ) ) {
			$powod_reg = 'Strona przyjmuje płatności — jest sprzedaż, więc jest umowa, więc jest regulamin.';
		} elseif ( $ma( 'newsletter' ) ) {
			$powod_reg = 'Newsletter jest usługą świadczoną drogą elektroniczną. Regulamin może być krótki i może być sekcją innego dokumentu, ale musi istnieć.';
		}
		if ( '' !== $powod_reg ) {
			$d[] = array(
				'slug'      => 'regulamin',
				'tytul'     => 'Regulamin',
				'wymagany'  => true,
				'powod'     => $powod_reg,
				'sekcje'    => array(
					'Definicje',
					'Dane sprzedawcy i kontakt',
					'Wymagania techniczne',
					'Składanie zamówienia i zawarcie umowy',
					'Ceny i formy płatności',
					'Dostawa i termin realizacji',
					'Odstąpienie od umowy',
					'Reklamacje i niezgodność towaru z umową',
					'Pozasądowe sposoby rozpatrywania sporów',
					'Postanowienia końcowe i zmiany regulaminu',
				),
				'szablon'   => 'szablony/komplet-sklepowy.md',
				'kto_pisze' => 'PRAWNIK KLIENTA. To dokument handlowy, nie techniczny — my dajemy rusztowanie rozdziałów i zgłaszamy, że jest potrzebny (skill cookies-rodo, polityki.md §4).',
			);
		}

		/* --- Zwroty i reklamacje oraz dane firmowe: gdy jest sprzedaż konsumentowi --- */
		if ( $sklep || $ma( 'platnosci' ) ) {
			$d[] = array(
				'slug'      => 'zwroty-i-reklamacje',
				'tytul'     => 'Zwroty, odstąpienie i reklamacje',
				'wymagany'  => true,
				'powod'     => 'Prawo odstąpienia w 14 dni i tryb reklamacyjny muszą być podane konsumentowi przed zawarciem umowy. Osobna podstrona, bo klient szuka tego wtedy, gdy ma problem — a wtedy nie będzie tego szukał w §8 regulaminu.',
				'sekcje'    => array(
					'Termin 14 dni i od kiedy biegnie',
					'Jak złożyć oświadczenie o odstąpieniu — wzór formularza',
					'Wyłączenia prawa odstąpienia',
					'Kto płaci za odesłanie i kiedy wracają pieniądze',
					'Reklamacja: niezgodność towaru z umową',
					'Termin rozpatrzenia i ścieżka odwoławcza',
				),
				'szablon'   => 'szablony/komplet-sklepowy.md',
				'kto_pisze' => 'PRAWNIK KLIENTA — jak wyżej.',
			);

			$d[] = array(
				'slug'     => 'dane-firmowe',
				'tytul'    => 'Dane firmowe',
				'wymagany' => false,
				'powod'    => 'Nazwa, forma prawna, adres, NIP, REGON, KRS i kontakt muszą być dostępne konsumentowi przed zawarciem umowy. Zwykle siedzą tylko w §2 regulaminu — osobna podstrona kosztuje nic i zdejmuje spór o to, czy są „łatwo dostępne".',
				'sekcje'   => array(
					'Pełna nazwa i forma prawna',
					'Adres siedziby i adres do korespondencji',
					'NIP, REGON, KRS albo wpis do CEIDG',
					'Telefon i adres e-mail',
					'Numer rachunku do zwrotów',
				),
				'szablon'  => 'szablony/komplet-sklepowy.md',
			);

			/*
			 * Dostępność (European Accessibility Act) — dyrektywa 2019/882 art. 2 ust. 2 lit. f
			 * obejmuje usługi handlu elektronicznego świadczone konsumentom po 28.06.2025.
			 * Polskie wdrożenie: ustawa z 26.04.2024, Dz.U. 2024 poz. 731, w życie 28.06.2025.
			 *
			 * DWIE rzeczy, które rozstrzygają, czy w ogóle jest o czym mówić:
			 *
			 * 1. Mikroprzedsiębiorca świadczący usługi jest ZWOLNIONY W CAŁOŚCI — dyrektywa
			 *    art. 4 ust. 5, ustawa art. 4. Próg (art. 3 pkt 23): mniej niż 10 osób ORAZ
			 *    obrót albo suma bilansowa do 2 mln EUR. To jest większość portfela małej
			 *    agencji, więc domyślną odpowiedzią jest „nie dotyczy", a nie „dokupcie".
			 * 2. Gdy dotyczy, obowiązek jest PUBLIKACYJNY, ale polska ustawa (art. 32 pkt 1
			 *    lit. c) wskazuje nośnik: „w regulaminie świadczenia usług lub innym
			 *    równoważnym dokumencie". Czyli częściej rozdział regulaminu niż osobna
			 *    podstrona — i dlatego to nie jest automatycznie szósty dokument.
			 *
			 * Bez podanych progów NIE ZGADUJEMY. Brak danych wraca jako pytanie do klienta,
			 * bo obie odpowiedzi w ciemno są kosztowne: „nie dotyczy" ukrywa realny obowiązek,
			 * „dotyczy" sprzedaje mikrofirmie dokument, którego prawo od niej nie wymaga.
			 */
			$prac  = isset( $args['pracownikow'] ) ? (int) $args['pracownikow'] : null;
			$obrot = isset( $args['obrot_mln_eur'] ) ? (float) $args['obrot_mln_eur'] : null;

			if ( null === $prac && null === $obrot ) {
				$dostepnosc = array(
					'stan'  => 'PYTANIE DO KLIENTA',
					'powod' => 'Nie wiadomo, czy klient jest mikroprzedsiębiorcą. Zapytaj o liczbę pracowników i roczny obrót, potem wywołaj to ability ponownie z polami „pracownikow" i „obrot_mln_eur". Zgadywanie kosztuje w obie strony: „nie dotyczy" ukrywa realny obowiązek publikacyjny, „dotyczy" sprzedaje mikrofirmie dokument, którego prawo od niej nie wymaga.',
				);
			} elseif ( ( null !== $prac && $prac < 10 ) && ( null === $obrot || $obrot <= 2.0 ) ) {
				$dostepnosc = array(
					'stan'  => 'nie dotyczy — mikroprzedsiębiorca',
					'powod' => 'Mniej niż 10 osób i obrót do 2 mln EUR. Usługi mikroprzedsiębiorcy są zwolnione w całości — dyrektywa 2019/882 art. 4 ust. 5, ustawa z 26.04.2024 (Dz.U. 2024 poz. 731) art. 4. Zwolnienie dotyczy USŁUG; producent albo importer produktu objętego ustawą się z niego nie wypisuje. Utrata statusu mikro włącza pełne obowiązki — to nie jest zwolnienie na stałe. Dostępność nadal buduj w kodzie: klient mikro traci klientów na kontraście 2:1 i niedziałającej klawiaturze niezależnie od tego, czy ma taki obowiązek.',
				);
			} else {
				$dostepnosc = array(
					'stan'  => 'DOTYCZY — obowiązek publikacyjny',
					'powod' => 'Powyżej progu mikroprzedsiębiorcy. Sklep internetowy jest usługą handlu elektronicznego (dyrektywa 2019/882 art. 2 ust. 2 lit. f, od 28.06.2025). Obowiązkiem jest nie tylko spełnienie wymagań technicznych, ale OSOBNO opublikowanie informacji o tym, w jaki sposób usługa je spełnia — dyrektywa art. 13 ust. 2, ustawa art. 32 pkt 1 lit. c.',
					'gdzie' => 'Polska ustawa wskazuje nośnik: „w regulaminie świadczenia usług lub innym równoważnym dokumencie". Domyślnie ROZDZIAŁ REGULAMINU, nie osobna podstrona. Osobna podstrona tylko wtedy, gdy klient chce ją mieć widoczną — prawo tego nie wymaga.',
					'standard' => 'EN 301 549 V3.2.1 wciąga WCAG 2.1 poziom A i AA. Ani dyrektywa, ani ustawa nie wymieniają WCAG z nazwy — działa domniemanie zgodności z normą zharmonizowaną (ustawa art. 20). EN 301 549 V4.x podnosi bazę do WCAG 2.2 AA; celuj w 2.1 AA jako minimum, projektuj pod 2.2 AA.',
					'rynek' => 'Żaden z trzech przebadanych 2026-08-21 polskich sklepów (2b3sport.pl, resibo.pl, hop-sport.pl) nie ma niczego o dostępności — także te z sześcioma i ośmioma dokumentami. Termin minął ponad rok temu. Dla klienta powyżej progu to jednocześnie realna przewaga i realne ryzyko.',
				);
				$d[] = array(
					'slug'      => 'informacja-o-dostepnosci',
					'tytul'     => 'Informacja o dostępności',
					'wymagany'  => true,
					'powod'     => 'Ustawa z 26.04.2024 (Dz.U. 2024 poz. 731) art. 32 pkt 1 lit. c: usługodawca podaje do publicznej wiadomości informację o tym, w jaki sposób usługa spełnia wymagania dostępności. Klient jest powyżej progu mikroprzedsiębiorcy, więc obowiązek istnieje.',
					'sekcje'    => array(
						'Do jakiej usługi odnosi się ta informacja',
						'Jak sklep spełnia wymagania dostępności — konkretne rozwiązania, nie deklaracja',
						'Standard, wobec którego to mierzono (EN 301 549 / WCAG 2.1 AA)',
						'Elementy, które wymagań jeszcze nie spełniają, i termin naprawy',
						'Dostępne sposoby kontaktu i zgłoszenia problemu z dostępnością',
						'Data ostatniego przeglądu dostępności',
					),
					'szablon'   => 'szablony/komplet-sklepowy.md',
					'kto_pisze' => 'MY — ale dopiero po audycie dostępności, nie przed. Ten dokument opisuje stan faktyczny sklepu, więc bez pomiaru byłby zmyślony dokładnie tak samo jak polityka cookies z szablonu.',
					'uwaga'     => 'Może być ROZDZIAŁEM REGULAMINU zamiast osobnej podstrony — ustawa dopuszcza „inny równoważny dokument". Osobna podstrona jest wyborem, nie wymogiem.',
				);
			}
		} else {
			$dostepnosc = array(
				'stan'  => 'nie dotyczy',
				'powod' => 'European Accessibility Act obejmuje usługi handlu elektronicznego. Strona bez sprzedaży online nie jest usługą handlu elektronicznego w rozumieniu dyrektywy 2019/882 art. 3 pkt 30. Dostępność buduj mimo to — z powodu użytkowników, nie z powodu przepisu.',
			);
		}

		/* --- Status: czy taka podstrona już istnieje --- */
		$u = Nerti_Cookies_Ustawienia::pobierz();
		foreach ( $d as $i => $poz ) {
			// Tylko OPUBLIKOWANE. get_page_by_path() zwraca też szkice, a domyślny WordPress
			// zakłada szkic "polityka-prywatnosci" na każdej instalacji — bez tego warunku
			// ability mówiłoby "jest" o dokumencie, którego nikt nigdy nie zobaczył.
			$strona = get_page_by_path( $poz['slug'] );
			$url    = ( $strona && 'publish' === get_post_status( $strona ) ) ? get_permalink( $strona ) : '';
			if ( '' === $url && 'polityka-prywatnosci' === $poz['slug'] && ! empty( $u['link_polityka'] ) ) {
				$url = (string) $u['link_polityka'];
			}
			if ( '' === $url && 'polityka-cookies' === $poz['slug'] && ! empty( $u['link_cookies'] ) ) {
				$url = (string) $u['link_cookies'];
			}
			$d[ $i ]['url']    = $url;
			$d[ $i ]['status'] = '' !== $url ? 'jest' : 'BRAK';
		}

		$brakujace = array();
		foreach ( $d as $poz ) {
			if ( 'BRAK' === $poz['status'] ) {
				$brakujace[] = $poz['slug'];
			}
		}

		/*
		 * Rzeczy, które nie są dokumentem, a bez których komplet nie działa.
		 * Pierwsza pozycja jest wynikiem pomiaru, nie przeczucia: na trzech zbadanych polskich
		 * stronach usługowych (gabinet, kancelaria, producent) ŻADNA nie miała w stopce linku
		 * ponownie otwierającego baner. Wycofanie zgody było albo pod pływającą ikonką wtyczki,
		 * albo nie istniało wcale.
		 */
		$dodatkowo = array(
			'Link „Ustawienia plików cookies" w stopce KAŻDEJ podstrony, ponownie otwierający baner. Bez niego wycofanie zgody jest nieosiągalne — to jest bloker Q-COOKIE, nie kosmetyka.',
			'Link do polityki prywatności przy KAŻDYM formularzu, nie tylko w stopce.',
			'Data przeglądu w każdym dokumencie plus wersja zgodna z `wersja_polityki` we wtyczce. Zmiana treści = wywołanie podnies-wersje-polityki, inaczej rejestr dowodzi zgody na tekst, którego nikt nie widział.',
		);
		if ( $sledzi ) {
			$dodatkowo[] = 'Na stronie są skrypty nieniezbędne (' . implode( ', ', array_unique( $kategorie ) ) . '; źródło: ' . $zrodlo_kat . '). Każdy musi być wymieniony z nazwy w tabeli ciasteczek i jako odbiorca danych. Zero pozycji z szablonu.';
		} else {
			$dodatkowo[] = 'Nie wykryto skryptów nieniezbędnych (źródło: ' . $zrodlo_kat . '). Zanim uznasz, że ich nie ma, zrób inwentarz w przeglądarce — rejestr WordPressa nie widzi pikseli wklejonych w motyw ani wstrzykiwanych przez menedżera tagów.';
		}

		return array(
			'wariant'      => $wariant,
			'typ'          => $typ,
			'dokumenty'    => $d,
			'brakujace'    => $brakujace,
			'dodatkowo'    => $dodatkowo,
			'dostepnosc'   => $dostepnosc,
			'zastrzezenie' => 'To nie jest porada prawna. To jest dobór kompletu dokumentów do typu strony i lista braków, nie ocena zgodności. Regulamin i dokument o zwrotach pisze prawnik klienta — my dostarczamy rusztowanie i zgłaszamy, że są potrzebne. Pełne brzmienie zastrzeżenia, które idzie do klienta razem z każdym wygenerowanym dokumentem: skill cookies-rodo, polityki.md §5.',
			'nota'         => 'Lista dokumentów pochodzi z typu strony i z pola „funkcje". Ich TREŚĆ pochodzi z inwentarza skryptów, nie z szablonu. Kolejność pozycji w tablicy „dokumenty" jest kolejnością wdrożenia.',
		);
	}
}
