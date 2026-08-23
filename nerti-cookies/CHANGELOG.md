# Changelog

Wersjonowanie [semver](https://semver.org/). Dopóki wtyczka jest `0.x`, podniesienie środkowej liczby może zmienić kształt wyjścia ability; ostatnia liczba nigdy tego nie robi.

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
