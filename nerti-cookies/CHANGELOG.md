# Changelog

Wersjonowanie [semver](https://semver.org/). Dopóki wtyczka jest `0.x`, podniesienie środkowej liczby może zmienić kształt wyjścia ability; ostatnia liczba nigdy tego nie robi.

## 0.7.1 — 2026-10-17

### Naprawione

- **Skrypty wypisane w stopce PO `baner.js` zostawały zablokowane mimo zapisanej zgody.** Przy kolejnej wizycie decyzja była stosowana od razu, gdy przeglądarka doszła do `baner.js`, a np. `pixelyoursite/public.js` i `googlesitekit-consent-mode` stoją niżej. Meta Pixel nie startował dla nikogo, kto zgodził się wcześniej. Teraz `odblokuj()` przechodzi drugi raz na `DOMContentLoaded`.

## 0.7.0 — 2026-10-17

### Dodane

- **Ślady PixelYourSite w bibliotece (marketingowe, usługa Meta Pixel).** Ciasteczka i localStorage: `pys_*` (m.in. `pys_first_visit`, `pys_landing_page`, `pys_session_limit`, `pys_start_session`, `pys_advanced_form_data`, `pys_cd`, `pys_event_referrer`, `pys_session_entry_referrer`), `last_pys*` (`last_pys_landing_page`, `last_pysTrafficSource`), `pysTrafficSource`, `pbid` oraz `fbqpc_*` (localStorage Meta Pixela). Baner kasuje je po odmowie i wycofaniu zgody tak samo jak `_fbp` i `_fbc`.
- **Trzy nowe statusy „pomijane" w skanie:** „Tylko panel administratora", „Pozostałość po usuniętej wtyczce" i „Pominięte przez Ciebie". Nie liczą się do „nierozpoznane", nie trafiają do `[nerti_cookies_lista]`, w panelu leżą zwinięte na dole tabeli.
  - Ślady panelu: `breakdance*` (kreator stron), `googlesitekit*` (Site Kit), `wp-api-schema-model*` i `wp-settings-*` (WordPress). Pozostałość po Complianzie: `cmplz_*`.
  - Ramka skanu zgłasza, czy strona wyrenderowała się dla zalogowanego (`zalogowany`). Ciasteczko albo wpis pamięci widziany wyłącznie w takim skanie, którego serwer nie widział w anonimowym, dostaje status panelu administratora.
- **„Pomiń" przy każdej nierozpoznanej pozycji** (obok „Przypisz") i „Przywróć" w zwiniętej sekcji. Nowe ustawienie `ignoruj` (lista wzorców), dostępne też w `zapisz-ustawienia`.
- **Dwa nowe abilities (MCP i REST, w `katalog()`):**
  - `nerti-cookies/przypisz-kategorie`: hurtowe przypisanie kategorii albo `pomin` pozycjom ze skanu, nazwy z gwiazdką (`pys_*`); zwraca, co się zmieniło, co pominięto i czego nie znaleziono.
  - `nerti-cookies/zakoncz-kreator`: oznacza kreator „Pierwsze uruchomienie" jako ukończony; `{"otworz": true}` otwiera go ponownie.
- Wzorce nazw przyjmują gwiazdkę w dowolnym miejscu (wcześniej tylko na końcu). Filtr `nerti_cookies_pomijane` rozszerza listę śladów pomijanych.
- Test `testy/sprawdz-skaner.php`.

### Naprawione

- Skan nie czytał list `storage` z ustawień kategorii: po przypisaniu klucza localStorage przyciskiem „Przypisz" wracał jako „nie rozpoznano" przy następnym skanie. Teraz ustawienia wygrywają z biblioteką, tak jak przy ciasteczkach.

### Dlaczego

2026-10-17 na żywej stronie klienta zakładka „Skanuj stronę" pokazywała kilkadziesiąt wierszy „Nie rozpoznano" z powodu śladów panelu administratora (kreator stron, Site Kit, klient REST), resztek usuniętej wtyczki Complianz i niewpisanych w bibliotekę cookies PixelYourSite. Żadne z nich nie było śledzeniem odwiedzającego poza PixelYourSite, który jest marketingowy i ma być blokowany do zgody.

### Zgodność

- Pomijanie dotyczy wyłącznie pozycji nierozpoznanych. Narzędzie rozpoznane przez bibliotekę albo działające przed zgodą (`BLAD`) zostaje w raporcie, a host (obca domena) nigdy nie jest uznawany za ślad panelu. „Pomiń" nie zmienia blokady skryptów.
- Status jest nakładką stosowaną przy odczycie, nie zapisem w skanie, więc stary wynik skanu poprawia się po aktualizacji bez ponownego skanowania. Wyjście `skanuj-strone` dostało pole `status_pierwotny` przy pozycjach zmienionych przez nakładkę i nowe klucze w `podsumowanie.licz` (`admin`, `pozostalosc`, `pominiete`).
- Wiersz „fonts.googleapis.com — połączenie z obcą domeną" nie jest rozpoznawany jako usługa: pochodził z ustawienia LiteSpeed Cache (DNS preconnect) i znika po ponownym skanie.

## 0.6.2 — 2026-10-16

### Dodane

- `--nc-link-podkreslenie` (domyślnie `underline`): link do polityki jest podkreślony z samego banera, a nie dopiero wtedy, gdy motyw nie zeruje `a { text-decoration }`.
- `--nc-tekst-lh`, `--nc-tekst-min-h`: przycisk „Ustawienia" może mieć inną wysokość i interlinię niż przyciski równe.

### Uwaga dla tokenów

- Słowa `inherit`, `initial`, `unset` jako WARTOŚĆ tokenu nie działają: odnoszą się do samej zmiennej, nie do właściwości, więc wraca wartość zapasowa. Zmierzone na biznesoweadhd.pl: `--nc-linki-fs: inherit` dało 14 px zamiast 15 px. Wpisuj liczbę.

## 0.6.1 — 2026-10-16

### Dodane

- Tokeny widoku „Ustawienia": `--nc-ustawienia-border` (cała reguła obrysu nad listą, `0` ją usuwa), `--nc-kat-pad`, `--nc-kat-waga`, `--nc-kat-knob-radius` (kwadratowa gałka przełącznika), `--nc-kat-knob-tlo-wl` (kolor gałki po włączeniu). Gałka zmienia kolor płynnie.
- Wartości zapasowe = wygląd 0.6.0.

## 0.6.0 — 2026-10-16

### Dodane

- **Ability `nerti-cookies/ustaw-tokeny`.** Scala pojedyncze zmienne `--nc-*` z bieżącymi, wspólne albo osobno dla telefonu i desktopu (`tokeny`, `mobile`, `desktop`; `null` usuwa). Tokeny urządzeń siedzą w osobnej opcji `nerti_cookies_tokeny_urzadzenia` i przeżywają ponowny import. Kontrast jest mierzony per urządzenie i zgłaszany, nie odrzucany.
- **Tokeny kształtu** w `baner.css` (lista w README): odstęp od krawędzi, obrys panelu, waga tytułu, treść, link w zdaniu, krój, padding, obrys i hover przycisków równych, kolejność „Akceptuję", przycisk „Ustawienia", „Zapisz wybór" jako pełny przycisk, wejście panelu z opóźnieniem.
- `pobierz-ustawienia` zwraca też `tokeny_urzadzen`.

### Dlaczego

2026-10-16 na biznesoweadhd.pl baner miał wyglądać jak karta cookies z projektu. Bez tych tokenów jedyną drogą były selektory `#nc-baner …` w arkuszu strony, które przy aktualizacji wtyczki rozjeżdżają się bez ostrzeżenia, a klient nie zmieni ich z panelu. Odstęp od krawędzi był dodatkowo podwójny (padding kontenera wtyczki plus własny `inset` strony): panel na telefonie wychodził o 32 px węższy niż w projekcie.

### Zgodność

- Każdy nowy token ma wartość zapasową równą dotychczasowemu wyglądowi. Strony bez tokenów renderują się tak samo jak na 0.5.2, z dwoma wyjątkami, które mogą wyjść tylko przy agresywnym motywie:
  - tytuł banera ma teraz jawnie `font-weight: bold` (wcześniej brał wagę `h2` z motywu),
  - treść i link dziedziczą rozmiar i grubość z panelu (wcześniej motyw mógł je nadpisać regułą `p` albo `a`).

## 0.5.2 — 2026-10-16

### Naprawione

- **PixelYourSite jest blokowany bez ustawień per strona.** 2026-10-16 na biznesoweadhd.pl (PixelYourSite 11.4.2.1, Nerti Cookies 0.5.1) piksel pobierał `fbevents.js` i ustawiał `_fbp`, zanim gość cokolwiek kliknął. PixelYourSite wstawia piksel dopiero w przeglądarce, z własnego `public.js`, więc w HTML-u nie było nic, co biblioteka by rozpoznała. Usługa `meta-pixel` ma teraz:
  - wzorce `pixelyoursite/dist/scripts/public.` i `pixelyoursite-pro/dist/scripts/public.` (łapią `public.js`, `public.min.js` przy włączonej kompresji i wersję Pro),
  - uchwyty `pys` (tak PixelYourSite rejestruje skrypt) i `pys-js` (id znacznika, który z niego robi WordPress).
- Biblioteki PixelYourSite (`js-cookie-pys`, `jquery-bind-first`, `js-sha256`) i dane `pys-js-extra` dalej się ładują. Same niczego nie wysyłają ani nie zapisują, a bez nich `public.js` po zgodzie by nie ruszył.
- Strony, które mają obejście w ustawieniach (`uchwyty` `pys-js`, `wzorce` `pixelyoursite/dist/scripts/public.js`), mogą je zostawić. Ustawienia mają pierwszeństwo i wskazują tę samą kategorię.

### Sprawdzone

- Test w PHP na samym `filtr_uchwytu` i buforze, z pustymi ustawieniami: 0.5.1 przepuszcza `public.js`, `public.min.js` i `public.js` z Pro; 0.5.2 blokuje wszystkie trzy jako marketingowe. Biblioteki PixelYourSite i jQuery przechodzą w obu wersjach.
- Na biznesoweadhd.pl to samo obejście w ustawieniach przeszło test Playwrightem: przed zgodą i po odmowie zero zapytań do Facebooka i brak `_fbp`, po zgodzie piksel i zdarzenie CAPI.

## 0.5.1 — 2026-09-26

### Zmienione

- **Teksty po polsku przeszły przez deslop.** Baner, opisy kategorii, cele usług do polityki, pulpit, skaner i kreator. Bramka `qa-static`: przed zmianą 26 pauz w środku zdania (AS-01), po zmianie 0; wzmacniaczy 0 na 2021 słów. Domyślna treść banera to teraz „Na pozostałe, analityczne i marketingowe, potrzebujemy Twojej zgody.” Strony z własnym tekstem banera nie zmieniają się, bo zapisany tekst ma pierwszeństwo przed domyślnym.

### Sprawdzone

- WooCommerce 11.1 na try.local: przed decyzją i po odmowie żadnych ciasteczek `sbjs_*` ani śledzących, koszyk bez zmian. Atrybucji zamówień po zgodzie nie zmierzono, bo szablon strony testowej nie ładuje jej skryptu.

## 0.5.0 — 2026-09-26

Poziom Complianza, a w trzech miejscach wyżej. Powód tej wersji: 2026-09-26 na biznesoweadhd.pl wtyczka „Meta Pixel for WordPress” ustawiała `_fbp` w nagłówku `Set-Cookie` pierwszej odpowiedzi HTML, zanim ktokolwiek się zgodził. Complianz blokował skrypt `fbq`, ale ciasteczko i tak powstawało. Nerti Cookies 0.4.1 miał ten sam defekt.

Zmierzone na try.local skryptem `testy/odmowa.mjs` z fiksturą, która ma każdy rodzaj śledzenia. Przed zmianą: 12 blokerów, NO GO. Po: GO, także ze stroną z cache (Cache Enabler) i z optymalizacjami JS LiteSpeed (łączenie, opóźnianie, preconnect).

### Dodane

- **Blokada `Set-Cookie` z PHP** (`inc/class-naglowki.php`). Ciasteczka kategorii bez zgody znikają z nagłówków każdej odpowiedzi PHP (strony, `admin-ajax.php`, REST). Ciasteczka, które gość już ma bez zgody (także HttpOnly), są kasowane nagłówkiem na każdym wariancie domeny. Ślad zatrzymanych nazw widać na pulpicie.
- **Biblioteka usług** (`inc/class-uslugi.php`). 18 usług z kategorią, celem po polsku, odbiorcą i ciasteczkami. Cloudflare Turnstile jest niezbędny i nigdy nie jest blokowany. Kolejność dopasowania: zawsze → marketingowe → analityczne, więc `gtag/js?id=AW-…` to reklama, nie statystyka. Rozszerzenie filtrem `nerti_cookies_uslugi`.
- **Szersza blokada w buforze**: piksele `<img>`, piksele w `<noscript>` (wycinane), `preconnect`/`dns-prefetch` do trackerów, leniwe iframe'y z `data-src`, skrypty `data:` base64 (LiteSpeed), typy `rocketlazyloadscript` i `litespeed/javascript`.
- **Integracje** (`inc/class-integracje.php`):
  - WP Consent API: typ `optin`, `wp_has_consent` z ciasteczka decyzji, `wp_set_consent` w banerze.
  - Meta Pixel for WordPress: hak `facebook_signals_held` wstrzymuje ciasteczka, piksel i Conversions API wysyłane z serwera; po zgodzie `fbwcsignal.release()`.
- **Consent Mode v2**: `personalization_storage`, `ads_data_redaction` (domyślnie włączone), `url_passthrough` (opcja). Powracający gość dostaje `update` już w `<head>`.
- **Zgodność z cache** (`inc/class-cache.php`): wykluczenia dla WP Rocket, LiteSpeed, SiteGround Optimizer i Autoptimize oraz atrybuty „nie optymalizuj” na skryptach wtyczki i zablokowanych znacznikach.
- **Skaner** (`inc/class-skaner.php`). Serwer pobiera podstrony jak nowy gość, a ramka w przeglądarce admina uruchamia je z pełną zgodą. Każda pozycja ma status: czeka na zgodę / działa zawsze / działa PRZED zgodą / nie rozpoznano. Nierozpoznanym przypisujesz kategorię jednym kliknięciem.
- **Pulpit.** Pokazuje wynik pomiaru, nie samych ustawień. Kontrole: baner i wejście do wycofania na każdej podstronie, nic nie śledzi przed zgodą, adres polityki, odmowy w rejestrze. Do tego decyzje z 30 dni, CSV, „Zapytaj wszystkich ponownie” i integracje.
- **`[nerti_cookies_lista]`**: tabela ciasteczek do polityki z ostatniego skanu.
- **Pływający przycisk** zmiany zgody: `wycofanie = plywajacy`, widoczny po decyzji.
- **Kreator pierwszego uruchomienia** w 4 krokach.
- **Abilities**: `skanuj-strone`, `lista-uslug`, `eksport-rejestru`, `status` (razem 12, także jako REST).
- **Testy**: `testy/odmowa.mjs` (refusal-test.md kroki 0–5 plus nagłówki `Set-Cookie`), fikstura `testy/fikstura/nc-fikstura.php`.

### Zmienione

- Odmowa w statystykach to brak kategorii opcjonalnych, także przez „Zapisz mój wybór”. Wcześniej liczyło się tylko kliknięcie „Odrzucam”.
- CSV rejestru: średnik i BOM, więc Excel otwiera polskie znaki bez kreatora.
- Publiczna trasa `POST /zgoda`: limit 20 zapisów na minutę z jednego (zahashowanego) adresu.
- Zakładki panelu: Pulpit, Skanuj stronę, Ustawienia (dawniej Stan ochrony), …; „Zapisz zmiany” tylko przy zakładkach formularza.

### Niesprawdzone

- WP Rocket (brak licencji) i cache serwera LiteSpeed (Local ma nginx). Wykluczenia są zrobione według nazw filtrów i atrybutów, ale nie zostały zmierzone.
- Wysyłka Conversions API przy braku zgody. Kod Meta kolejkuje zdarzenia przy `is_held()`, ale bez tokenu dostępu na try.local nie ma czego wysłać, więc widać tylko, że stan `held` jest ustawiony.
- Czas życia ciasteczek i odbiorcy w bibliotece pochodzą z dokumentacji dostawców (stan 2026-09). Przed publikacją polityki trzeba je potwierdzić.

## 0.4.1 — 2026-09-16

### Dodane

- **`testy/sprawdz-wycofanie.php`**: domyślne `wycofanie = wtyczka`, walidacja odrzuca obce wartości, przy `strona` wtyczka nie drukuje nic, przy `wtyczka` pas `.nc-wycofaj-pas` z przyciskiem `data-nc-akcja="otworz"` i tekstem z ustawień. Bez zmian w kodzie produkcyjnym.
## 0.4.0 — 2026-09-16

### Dodane

- **Wejście do wycofania zgody drukuje wtyczka, domyślnie.** Dotąd istniało tylko wtedy, gdy ktoś pamiętał o skrócie `[nerti_cookies_ustawienia]`; zapomniany skrót zostawiał baner bez wyjścia, czyli defekt prawny (zgodę trzeba dać się wycofać tak samo łatwo, jak się jej udzieliło). Teraz `wp_footer` zawsze kończy stronę pasem `.nc-wycofaj-pas` z tym samym przyciskiem. Bez pozycji `fixed`: pływające kółko na każdej podstronie to cudzy element w cudzym projekcie, a wymóg mówi o dostępności wycofania, nie o zasłanianiu treści.
- **Ustawienie `wycofanie`** (zakładka Stan, dwa pola): `wtyczka` (domyślne, jak wyżej) albo `strona` — wejście jest elementem projektu z atrybutem `data-nc-akcja="otworz"`, a wtyczka nie dokłada własnego. Druga opcja jest po to, żeby przeniesienie 1:1 z designu nie miało doklejonego bloku, którego projektant nie narysował: na demie „Po godzinach” pas wtyczki dokładał 70 px do każdej podstrony. Ustawienie przechodzi przez `zapisz-ustawienia` (MCP, REST, panel) z walidacją na dwie dozwolone wartości.

### Zmienione

- Skrót działa jak dotąd, ale dokumentacja mówi wprost, że na builderach omijających `the_content` (Oxygen, Breakdance) jedyną drogą w treści strony jest własny element z `data-nc-akcja="otworz"` — skrót się tam nie wykona.
## 0.3.2 — 2026-09-15

### Naprawione

- **„Wybieram sam” był naraz linkiem i przyciskiem.** `.nc-btn--tekst` miał podkreślenie i jednocześnie dziedziczył obrys 1 px z `.nc-btn`, a po najechaniu dostawał rozjaśnienie `filter` przeznaczone dla przycisków z tłem. Teraz to przycisk tekstowy: obrys przezroczysty (wysokość bez zmian), podkreślenie 1 px w normal i 2 px po najechaniu oraz przy wciśnięciu, `filter: none`, przesunięcie 1 px w `:active`. Każdy stan wpisany jawnie (wp-oxygen build-standard §12a). Focus-visible bez zmian: obrys 3 px w `--nc-focus`. Zmierzone na try.local przez `front-kontrola.mjs`: 4 naruszenia na dwóch stronach demo przed poprawką.

## 0.3.1 — 2026-09-14

Wydane na try.local 14.09, zacommitowane 15.09 (`6496e13`). Pośrednie 0.3.0 nie ma osobnego commita i mieści się w tym wpisie.

### Naprawione

- **Blokada niezależna od cache strony.** HTML nie zależy już od ciasteczka odwiedzającego: Consent Mode startuje zawsze od `denied`, a opcjonalne skrypty uruchamia wyłącznie JS po odczycie bieżącej decyzji. Wcześniej strona z cache mogła podać wersję wygenerowaną dla kogoś, kto się zgodził.
- **Parser znaczników przez `WP_HTML_Tag_Processor`** zamiast wyrażeń regularnych. Zachowuje `type="module"` (`data-nc-type`) po odblokowaniu i nie gubi atrybutów.
- **Wycofanie zgody sprząta.** Kategorie mają listę `storage`; po wycofaniu kasowane są pasujące wpisy `localStorage` i `sessionStorage`, a gdy wycofana kategoria już działała, strona się przeładowuje. Ciasteczka są kasowane na każdym poziomie ścieżki, nie tylko `/` i bieżącej.
- **REST waliduje wejście** schematem (400 zamiast cichego przyjęcia), `waznosc_dni` 1–365, błędy zapisu wracają jako `WP_Error`. Rejestr zgód niedostępny nie blokuje decyzji.
- Rozpoznanie edytora zna parametry Oxygena 6 (`oxygen`, `oxygen_iframe`) i działa tylko dla zalogowanego z `edit_posts`. Wcześniej anonimowy gość mógł zdjąć baner, dopisując parametr budowniczego do adresu.

### Zmienione

- **Rozwinięcie „Wybieram sam” nie zaznacza już wszystkich kategorii.** Zgoda musi być czynna (TSUE C-673/17); zachowanie z 0.2.2 zostało usunięte. Fokus trafia na pierwszą kategorię, „Zapisz mój wybór” zastępuje przycisk rozwinięcia.
- **Zamknięcie banera nie zmienia decyzji ani przełączników** i wraca fokusem tam, skąd baner otwarto.
- Kategorie mogą być ukryte (`ukryta`), panel ma polskie sekcje, pole `administrator` w ustawieniach.

## 0.2.3 — 2026-08-23

### Naprawione

- **Ability wołana bez parametrów nie przechodziła walidacji.** Dotyczyło każdej ability wtyczki, a najbardziej tych, które z definicji nie biorą argumentów: `pobierz-ustawienia`, `wykryj-skrypty`, `statystyki-zgod`. Adapter MCP dowozi `parameters: {}` do `WP_Ability::execute()` jako **null**, nie jako pusty obiekt. `execute()` woła najpierw `normalize_input()` (podstawia `input_schema['default']`, gdy wejście jest nullem), a dopiero potem `validate_input()` — bez zadeklarowanego `default` null przechodzi normalizację nietknięty i `rest_validate_value_from_schema` odrzuca go komunikatem „input nie jest typu object". Agent widział błąd walidacji i najczęściej wnioskował, że ability nie istnieje. Zmierzone 2026-08-23 na WP 7.1. Poprawka w pętli rejestrującej, więc obejmuje też każdą ability dopisaną później.

## 0.2.2 — 2026-08-23

Wydane dopiero razem z 0.2.3 — wpis dopisany wstecz, żeby historia nie miała dziury.

### Naprawione

- **Baner nie znikał po kliknięciu „Akceptuj", a przy kolejnej wizycie wstawał mimo zapisanej decyzji.** Klasa `.nc-baner` ustawia `display: flex`, a wbudowana reguła przeglądarki dla `[hidden]` to zwykły selektor atrybutu — przegrywała na wadze. Wyglądało to na martwe przyciski i szukało się błędu w JS, który działał poprawnie. Jedna reguła `[hidden]` o wyższej wadze załatwia to naraz dla banera, panelu, przycisków i zastępnika osadzenia.

### Zmienione

- Rozwinięcie „Dostosuj ustawienia" zaznacza wszystkie kategorie. **Na wyraźne życzenie właściciela strony i niezgodnie z RODO** — zgoda musi być czynna (TSUE C-673/17). Blok jest opisany w kodzie i da się go wyciąć bez śladu.

## 0.2.1 — 2026-08-21

### Naprawione

- **Ekran wtyczki przeniesiony z `Ustawienia → Nerti Cookies` na własną pozycję w lewym pasku** („Ciasteczka", ikona kłódki, nad Ustawieniami). Był zarejestrowany przez `add_options_page()`, więc w lewym pasku nie zostawiał po sobie żadnego śladu — a `Ustawienia` to worek, do którego wtyczki wrzucają wszystko. To jest ekran, na który klient wchodzi sam: zmienić tekst, sprawdzić liczbę odmów, podnieść wersję polityki po zmianie treści. Slug (`nerti-cookies`) się nie zmienił, więc stare zakładki dalej działają; przekierowanie po zapisie idzie teraz na `admin.php`.

## 0.2.0 — 2026-08-21

Wersja, od której wtyczkę można oddać klientowi: wygląd bierze się z projektu strony, klient sam zmienia teksty, a aktualizacje przychodzą same.

### Dodane

- **Drugie źródło wyglądu: ustawienia globalne Oxygena.** Do tej pory baner umiał wziąć tokeny wyłącznie z `design-system.json`, więc na stronie zbudowanej bez tego pliku wyglądał jak obcy prostokąt. Teraz czyta paletę, kroje, rozmiar bazowy i promienie prosto z Oxygena i mapuje je na te same zmienne `--nc-*`. Nowa ability `nerti-cookies/importuj-z-oxygena` i przycisk w panelu.
- **Kolejność źródeł jest jawna i widoczna w panelu**: `design-system.json` > ustawienia globalne Oxygena > domyślne wtyczki. Klient widzi, skąd bierze się jego wygląd, zamiast zgadywać.
- **Sprawdzanie kontrastu przy imporcie.** Jeśli zaimportowana para tekst/tło albo etykieta/przycisk schodzi poniżej 4,5:1, import to zgłasza zamiast po cichu zrobić baner nie do przeczytania. Niepasujący baner jest problemem estetycznym; nieczytelny jest problemem dostępności.
- **Ustawienia osobno dla telefonu i dla desktopu** — pozycja, szerokość i wielkość tekstu. Trzymane jako `wyglad.desktop` i `wyglad.mobile`, wypuszczane jako dwa bloki `@media`.
- **Panel do edycji tekstów.** Tablica `teksty` była w kodzie od pierwszej wersji, ale ekran ustawień nie miał na nią ani jednego pola — klient nie mógł zmienić ani jednego słowa bez wchodzenia w kod. Teraz każdy tekst ma pole i opis, gdzie się pokazuje.
- **Autoaktualizacja z GitHub Releases.** Ten sam sprawdzony mechanizm, co w paczce `unofficial-abilities-for-breakdance-elements`, z prefiksem `nerti_cookies_`, więc obie wtyczki mogą stać na jednej instalacji. Wczytywany bezwarunkowo: bramka na `is_admin()` znaczyłaby, że WP-CLI i updatery hostingu wypadają z obu kubełków aktualizacji.
- `testy/sprawdz-oxygena.php` — 47 asercji na mapowanie ustawień Oxygena i liczenie kontrastu.
- `README.md`, `CHANGELOG.md`, `LICENSE`, `.gitignore`.

### Zmienione

- **Panel przepisany na pięć zakładek** — Stan, Wygląd, Teksty, Kategorie i skrypty, Rejestr zgód — z podglądem banera na żywo. Wcześniej była to jedna goła tabela `form-table` z czterema polami. Bez frameworka, bez kroku budowania: `assets/panel.css` i `assets/panel.js`.
- **Pusty tekst odmowy wraca do domyślnego, nie do pustego.** Klient może przepisać każdy napis, ale nie może usunąć przycisku odmowy z pierwszego ekranu ani zmniejszyć go względem przycisku zgody. To bramka `Q-COOKIE`, nie preferencja — baner, na którym łatwiej się zgodzić niż odmówić, nie zbiera ważnej zgody.

## 0.1.0

Pierwsza wersja: blokada skryptów przed zgodą (uchwyty, wzorce adresów, bufor wyjścia), trzy kategorie, rejestr zgód z wersją polityki, zastępniki osadzeń, Consent Mode v2, sterowanie przez Agent Connector i REST.
