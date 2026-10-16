# Nerti Cookies

Baner zgód dla WordPressa, który **blokuje skrypty przed zgodą, nie po niej**, i wygląda jak reszta strony, a nie jak wtyczka.

> **To nie jest narzędzie do orzekania o zgodności z prawem.** Wtyczka wykonuje decyzje techniczne: blokuje skrypty, zapisuje decyzje, pokazuje baner. Treść polityki prywatności zatwierdza właściciel strony albo jego prawnik. Bez tego dokumentu wtyczka jest połową dostawy.

## Czym różni się od Cookiebota i CookieYes

**Wygląd.** Gotowe rozwiązania dają baner, który wygląda jak baner — cudza typografia, cudze promienie, cudzy niebieski przycisk, przyklejone do starannie zaprojektowanej strony. Odwiedzający widzi to w pierwszej sekundzie, zanim zobaczy cokolwiek innego. Ta wtyczka bierze tokeny z projektu strony i wystawia je jako zmienne CSS `--nc-*`.

**Odmowa.** Przycisk odmowy jest na pierwszym ekranie i ma tę samą wagę wizualną co przycisk zgody. Klient może przepisać jego treść, ale nie może go schować ani zmniejszyć — wyczyszczone pole wraca do wartości domyślnej. Baner, na którym łatwiej się zgodzić niż odmówić, nie zbiera ważnej zgody.

**Blokada.** Cztery warstwy, bo żadna nie wystarcza sama:

1. uchwyty `wp_enqueue_script`;
2. bufor wyjścia: skrypty wklejone w motyw (także zamienione przez LiteSpeed na `data:` base64 i opóźnione przez WP Rocket/LiteSpeed), piksele `<img>` i `<noscript>`, `preconnect` do trackerów, iframe'y — także leniwe z `data-src` — z zaślepką „Pokaż materiał";
3. **nagłówki `Set-Cookie` z PHP** — ciasteczka ustawiane przez serwer przed zgodą znikają z odpowiedzi (np. `_fbp` z wtyczki Meta Pixel, którego Complianz nie łapie), także w `admin-ajax.php`;
4. integracje z wtyczkami, które same umieją czekać: WP Consent API (Site Kit, WooCommerce i inne), Meta Pixel for WordPress (wstrzymuje też Conversions API wysyłane z serwera), Google Consent Mode v2.

**Biblioteka usług.** GA4, GTM, Meta Pixel (także ślady PixelYourSite: `pys_*`, `last_pys*`, `pysTrafficSource`, `pbid`, `fbqpc_*`), TikTok, Hotjar, Clarity, Google Ads, LinkedIn, YouTube, Vimeo, Mapy, reCAPTCHA, Spotify, atrybucja WooCommerce — z kategorią, celem po polsku, odbiorcą i ciasteczkami. Cloudflare Turnstile jest niezbędny i nigdy nie jest blokowany.

**Cache.** Jeden HTML dla wszystkich: decyzję czyta przeglądarka. Skrypty wtyczki i zablokowane znaczniki są wyłączone z łączenia i opóźniania w LiteSpeed, WP Rocket, Autoptimize i SiteGround Optimizer.

**Sterowanie przez agenta.** Piętnaście abilities dla Agent Connector for WP, plus równoległe trasy REST dla instalacji bez niego.

## Instalacja

Wgraj ZIP przez *Wtyczki → Dodaj wtyczkę → Wyślij wtyczkę na serwer*. Aktualizacje przychodzą same z GitHub Releases — nie trzeba nic konfigurować.

Przy aktywacji powstaje tabela rejestru zgód. **Deaktywacja jej nie kasuje**: rejestr jest jedynym dowodem na to, że zgoda została udzielona i kiedy.

## Skąd bierze się wygląd

Trzy źródła, w tej kolejności — panel pokazuje, które jest aktywne:

1. **`design-system.json` projektu** — pełna mapa tokenów, jeśli strona powstała w tym procesie.
2. **Ustawienia globalne Oxygena** — paleta, kroje, rozmiar bazowy, promienie. Działa na dowolnej stronie na Oxygenie, bez pliku projektu.
3. **Domyślne wtyczki** — neutralne, gdy nie ma ani jednego, ani drugiego.

4. **Ręczne tokeny** (`ustaw-tokeny`, od 0.6.0) — pojedyncze zmienne `--nc-*`, wspólne albo osobno dla telefonu i desktopu. Do oddania banera 1:1 z projektem. Tokeny urządzeń przeżywają ponowny import, wspólne nie.

### Tokeny kształtu (0.6.0)

Wszystkie mają wartość zapasową równą wyglądowi sprzed 0.6.0, więc strona, która ich nie ustawia, nie zmienia się. Wartością tokenu jest liczba albo kolor: `inherit`/`initial`/`unset` odnoszą się do samej zmiennej i dają wartość zapasową. 0.6.2 dodaje `--nc-link-podkreslenie`, `--nc-tekst-lh`, `--nc-tekst-min-h`.

| Token | Co zmienia |
|---|---|
| `--nc-baner-pad` | odstęp panelu od krawędzi ekranu (domyślnie `--nc-gap`) |
| `--nc-border-w`, `--nc-panel-max-h` | grubość obrysu panelu, maksymalna wysokość |
| `--nc-tytul-waga`, `--nc-tresc-fs`, `--nc-tresc-lh` | waga tytułu, rozmiar i interlinia treści |
| `--nc-linki-fs`, `--nc-link-color`, `--nc-link-waga`, `--nc-link-offset`, `--nc-link-display`, `--nc-link-pad` | link do polityki w zdaniu: kolor, grubość, podkreślenie, cel dotyku bez zmiany wiersza |
| `--nc-btn-font`, `--nc-btn-fs`, `--nc-btn-lh`, `--nc-btn-waga`, `--nc-btn-pad`, `--nc-btn-min-h`, `--nc-btn-border-w`, `--nc-btn-border` | przyciski równe: krój, rozmiar, padding, obrys |
| `--nc-btn-bg-hover`, `--nc-btn-ink-hover`, `--nc-btn-border-hover` | jawny hover przycisków równych (z `--nc-btn-hover-filtr: none`) |
| `--nc-btn-basis`, `--nc-btn-basis-mobile` | szerokość bazowa; `0` na telefonie = dwa przyciski obok siebie |
| `--nc-akceptuj-order` | `-1` stawia „Akceptuję" przed „Odrzucam" na ekranie (czytnik dalej czyta odmowę pierwszą) |
| `--nc-tekst-flex`, `--nc-tekst-color`, `--nc-tekst-fs`, `--nc-tekst-waga`, `--nc-tekst-offset`, `--nc-tekst-hover-grubosc` | przycisk tekstowy „Ustawienia" |
| `--nc-zapisz-*` (`bg`, `ink`, `border`, `waga`, `flex`, `podkreslenie`, `bg-hover`, `ink-hover`, `border-hover`) | „Zapisz wybór" jako pełny przycisk |
| `--nc-ustawienia-border`, `--nc-kat-pad`, `--nc-kat-waga`, `--nc-kat-knob-radius`, `--nc-kat-knob-tlo-wl` (0.6.1) | widok „Ustawienia": obrys nad listą, wiersz kategorii, kształt i kolor gałki przełącznika |
| `--nc-wejscie-czas`, `--nc-wejscie-opoznienie`, `--nc-wejscie-y`, `--nc-wejscie-ease` | wejście panelu; przy `prefers-reduced-motion` wyłączone |

Import sprawdza kontrast. Jeśli para tekst/tło albo etykieta/przycisk schodzi poniżej 4,5:1, zgłasza to zamiast po cichu zrobić baner nie do przeczytania.

Baner renderuje wtyczka, nie Oxygen — musi pokazać się przed zgodą, na każdej podstronie, także z cache'a i na stronie błędu. Z Oxygena bierzemy wygląd, nie mechanizm renderowania.

## Co klient ustawia sam

Menu *Ciasteczka*. Przy pierwszym wejściu **kreator w 4 krokach**: dokumenty → skan → wygląd → sprawdzenie.

| Zakładka | Co w niej jest |
|---|---|
| Pulpit | czy ochrona działa (wynik pomiaru, nie ustawień), co jest blokowane, decyzje z 30 dni, CSV, „Zapytaj wszystkich ponownie", ciasteczka zatrzymane na serwerze |
| Skanuj stronę | skan podstron jak nowy gość + w przeglądarce z pełną zgodą; każda pozycja z nazwą, kategorią i statusem „czeka na zgodę / działa zawsze / działa PRZED zgodą / nie rozpoznano"; przypisanie kategorii jednym kliknięciem albo „Pomiń". Pozycje pomijane są zwinięte na dole tabeli (patrz niżej) |
| Ustawienia | adresy polityk, ważność decyzji, Consent Mode, **gdzie jest wejście do wycofania zgody** |
| Wygląd | pozycja, szerokość i wielkość tekstu — **osobno dla telefonu i desktopu** — plus import tokenów i podgląd na żywo |
| Teksty | każdy napis w banerze, z opisem gdzie się pokazuje |
| Kategorie i skrypty | trzy kategorie, przypisane ciasteczka, mapa uchwytów i wzorców |
| Rejestr zgód | eksport CSV i podniesienie wersji polityki |

### Wycofanie zgody

Zgodę trzeba dać się wycofać tak samo łatwo, jak się jej udzieliło — to wymóg, nie udogodnienie. Dlatego wtyczka **domyślnie sama** kończy każdą podstronę pasem z przyciskiem zmiany zgody i nikt nie musi o tym pamiętać. Pas jest zwykłym elementem na końcu strony, nie pływającym kółkiem: wymóg mówi o dostępności wycofania, nie o zasłanianiu treści.

Jeżeli wejście jest już w projekcie strony — dowolny element z atrybutem `data-nc-akcja="otworz"` — przestaw Stan → *Wycofanie zgody* na „wejście jest w projekcie strony”. Wtyczka nie dokłada wtedy nic, a przeniesienie z designu zostaje 1:1. Przestawiaj to dopiero wtedy, gdy takie wejście na stronie naprawdę jest.

Trzecia opcja: **mały przycisk w rogu ekranu**, widoczny dopiero po decyzji.

Na Oxygenie i Breakdanie skrót `[nerti_cookies_ustawienia]` w treści strony **się nie wykona** — builder omija `the_content`. Tam używa się atrybutu albo elementu „Shortcode".

### Pozycje pomijane w skanie (0.7.0)

Skan z panelu działa w sesji zalogowanego administratora, więc widzi też to, czego odwiedzający strony nie ma. Takie pozycje nie są „do przypisania": dostają jeden z trzech statusów, nie liczą się do „nierozpoznane", nie trafiają do `[nerti_cookies_lista]` i leżą zwinięte na dole tabeli.

| Status | Co to jest | Skąd wiadomo |
|---|---|---|
| Tylko panel administratora (pomijane) | wpisy kreatora stron (`breakdance*`), Site Kita (`googlesitekit*`), klienta REST WordPressa (`wp-api-schema-model*`, `wp-settings-*`) | wzorce w bibliotece; albo ciasteczko / wpis pamięci widziany WYŁĄCZNIE w skanie z zalogowanej sesji (ramka skanu zgłasza, że strona wyrenderowała się dla zalogowanego) |
| Pozostałość po usuniętej wtyczce (pomijane) | `cmplz_*` po Complianzie | wzorzec w bibliotece |
| Pominięte przez Ciebie | to, co właściciel oznaczył przyciskiem **Pomiń** | ustawienie `ignoruj`; „Przywróć" w zwiniętej sekcji cofa |

Zasady, które trzymają to w ryzach:

- Pomijanie dotyczy **tylko pozycji nierozpoznanych**. Narzędzie rozpoznane przez bibliotekę albo działające przed zgodą (`BLAD`) nie zniknie z raportu ani przez „Pomiń", ani przez heurystykę „widziane tylko po zalogowaniu". Host (obca domena) nigdy nie jest uznany za ślad panelu.
- Heurystyka dotyczy ciasteczek i pamięci przeglądarki, których serwer nie widział w anonimowym skanie. Jeśli to jednak narzędzie strony, przypisz mu kategorię: ręczna kategoria zawsze wygrywa.
- „Pomiń" nie zmienia blokady skryptów. To porządek w raporcie i w polityce, nie zgoda.
- Wzorce przyjmują gwiazdkę w dowolnym miejscu (`pys_*`, `*_sid`). Baner kasuje po odmowie tylko wzorce z gwiazdką **na końcu**, więc ręcznie zapisywane wzorce kategorii trzymaj w tej postaci.
- Nakładka jest stosowana przy odczycie, nie zapisana w skanie: po aktualizacji wtyczki stary wynik poprawia się bez ponownego skanowania.
- Biblioteka da się rozszerzyć filtrem `nerti_cookies_pomijane`.

### Lista ciasteczek do polityki

`[nerti_cookies_lista]` na podstronie polityki cookies: tabela z ostatniego skanu — narzędzie, po co, ciasteczka z czasem przechowywania, kto otrzymuje dane. Pozycje nierozpoznane nie trafiają do tabeli, dopóki administrator nie przypisze im kategorii.
Podniesienie wersji polityki **unieważnia wszystkie dotychczasowe zgody** i pokazuje baner od nowa. To osobna operacja, a nie efekt uboczny zapisu ustawień: zmiana treści polityki bez odpytania ludzi na nowo znaczy, że rejestr dowodzi zgody na dokument, którego oni nie widzieli.

## Sterowanie przez MCP

Wtyczka rejestruje się jako paczka abilities Agent Connector for WP. Uwierzytelnienia, blokady domeny ani audytu w niej nie ma i nie ma go pisać — Agent Connector wstrzykuje to każdemu ability sam.

| Ability | Do czego |
|---|---|
| `nerti-cookies/pobierz-ustawienia` | odczyt całej konfiguracji |
| `nerti-cookies/zapisz-ustawienia` | zapis częściowy, scalany z bieżącym stanem |
| `nerti-cookies/importuj-tokeny` | wygląd z `design-system.json` |
| `nerti-cookies/importuj-z-oxygena` | wygląd z ustawień globalnych Oxygena |
| `nerti-cookies/ustaw-tokeny` | pojedyncze tokeny `--nc-*`: `tokeny` (wspólne), `mobile`, `desktop`; `null` usuwa |
| `nerti-cookies/podnies-wersje-polityki` | unieważnienie zgód po zmianie polityki |
| `nerti-cookies/statystyki-zgod` | ile decyzji, ile odmów, ostatnia |
| `nerti-cookies/inwentarz-skryptow` | jakie skrypty zewnętrzne faktycznie są na stronie |
| `nerti-cookies/zaproponuj-dokumenty` | komplet dokumentów prawnych dobrany do typu strony |
| `nerti-cookies/skanuj-strone` | skan podstron (serwer) z kategorią i statusem każdej pozycji; `tylko_lista`, `dopisz`, `zapisany` |
| `nerti-cookies/przypisz-kategorie` | hurtowe przypisanie kategorii albo pominięcie pozycji ze skanu, z gwiazdką (0.7.0) |
| `nerti-cookies/zakoncz-kreator` | oznacza kreator „Pierwsze uruchomienie" jako ukończony; `{"otworz": true}` otwiera go ponownie (0.7.0) |
| `nerti-cookies/lista-uslug` | biblioteka rozpoznawanych usług |
| `nerti-cookies/eksport-rejestru` | rejestr zgód jako CSV albo JSON, od daty, z limitem |
| `nerti-cookies/status` | to samo co pulpit: kontrole, werdykt, skan, decyzje z 30 dni, integracje, cache |

`przypisz-kategorie` przyjmuje `{"pozycje":[{"nazwa":"pys_*","kategoria":"marketingowe"}]}`, gdzie `kategoria` to `marketingowe`, `analityczne`, `niezbedne` albo `pomin`. Nazwa dopasowuje się do klucza pozycji i do nazwy widocznej w skanie, gwiazdka obejmuje wiele pozycji. Rusza wyłącznie pozycje nierozpoznane (reszta wraca w `bez_zmian` z powodem), a nazwy bez trafienia w skanie w `brak_dopasowania`. Wzorce z gwiazdką na końcu zapisują się jako jeden wzorzec kategorii, inne rozwijają się do dokładnych kluczy (baner kasuje po odmowie tylko wzorce z gwiazdką na końcu). `pomin` dopisuje wzorzec do ustawienia `ignoruj` (cofnięcie: `zapisz-ustawienia` z nową listą `ignoruj`). Wymaga wcześniejszego skanu; po zmianie kategorii wyczyść cache strony.

`zakoncz-kreator` służy agentowi, który skonfigurował wszystko przez MCP: znika przypomnienie „Skonfiguruj w 4 krokach", a ustawień banera nie rusza. Stan widać w `status` (`kreator_ukonczony`).

`zaproponuj-dokumenty` rozdziela dwa warianty: strona wizytówkowa dostaje **jeden** dokument z ciasteczkami w środku, sklep dostaje osobne polityki, regulamin i pouczenie o odstąpieniu. Przy pytaniu o deklarację dostępności (European Accessibility Act) ability nie zgaduje: bez podanej liczby pracowników i obrotu zwraca `PYTANIE DO KLIENTA`, bo mikroprzedsiębiorca usługowy jest z tego obowiązku zwolniony w całości.

Trasy REST `nerti-cookies/v1/*` robią to samo dla instalacji bez Agent Connectora. Obie warstwy wołają jedną implementację (`katalog()`), więc nie mogą się rozjechać. **W dniu, w którym ktoś doda trasę REST poza `katalog()`, ta gwarancja znika.**

## Testy

Bez frameworka, uruchamiane przez WP-CLI:

```bash
wp eval-file wp-content/plugins/nerti-cookies/testy/sprawdz-dokumenty.php
wp eval-file wp-content/plugins/nerti-cookies/testy/sprawdz-oxygena.php
wp eval-file wp-content/plugins/nerti-cookies/testy/sprawdz-wycofanie.php
wp eval-file wp-content/plugins/nerti-cookies/testy/sprawdz-skaner.php
```

`sprawdz-skaner.php` zapisuje do opcji skanu, ustawień i kreatora, a na końcu je przywraca; puszczaj go na witrynie testowej.

**Test odmowy (bramka Q-COOKIE) jako skrypt** — tylko na lokalnej witrynie testowej:

```bash
node testy/odmowa.mjs http://try.local/nc-test/ http://try.local/
```

Sprawdza nagłówki `Set-Cookie` bez przeglądarki (nowy gość, po odmowie, po zgodzie), potem w czystej sesji Chromium: stan przed decyzją, odmowę jednym kliknięciem, stan po odmowie i po przeładowaniu, wycofanie zgody. Kod wyjścia 1 przy blokerze. Stronę z każdym rodzajem śledzenia daje fikstura `testy/fikstura/nc-fikstura.php` (do `mu-plugins` witryny testowej, nigdy na stronę klienta).

## Licencja

GPL-2.0-or-later.
