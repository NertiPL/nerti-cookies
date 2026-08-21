# Changelog

Wersjonowanie [semver](https://semver.org/). Dopóki wtyczka jest `0.x`, podniesienie środkowej liczby może zmienić kształt wyjścia ability; ostatnia liczba nigdy tego nie robi.

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
