# Nerti Cookies

Baner zgód dla WordPressa, który **blokuje skrypty przed zgodą, nie po niej**, i wygląda jak reszta strony, a nie jak wtyczka.

> **To nie jest narzędzie do orzekania o zgodności z prawem.** Wtyczka wykonuje decyzje techniczne: blokuje skrypty, zapisuje decyzje, pokazuje baner. Treść polityki prywatności zatwierdza właściciel strony albo jego prawnik. Bez tego dokumentu wtyczka jest połową dostawy.

## Czym różni się od Cookiebota i CookieYes

**Wygląd.** Gotowe rozwiązania dają baner, który wygląda jak baner — cudza typografia, cudze promienie, cudzy niebieski przycisk, przyklejone do starannie zaprojektowanej strony. Odwiedzający widzi to w pierwszej sekundzie, zanim zobaczy cokolwiek innego. Ta wtyczka bierze tokeny z projektu strony i wystawia je jako zmienne CSS `--nc-*`.

**Odmowa.** Przycisk odmowy jest na pierwszym ekranie i ma tę samą wagę wizualną co przycisk zgody. Klient może przepisać jego treść, ale nie może go schować ani zmniejszyć — wyczyszczone pole wraca do wartości domyślnej. Baner, na którym łatwiej się zgodzić niż odmówić, nie zbiera ważnej zgody.

**Blokada.** Trzy warstwy, bo jedna nie wystarcza w polskich instalacjach: mapa uchwytów `wp_enqueue_script`, wzorce adresów i treści skryptów, oraz bufor wyjścia łapiący piksele wklejone na sztywno w motyw. Większość pikseli w realnych wdrożeniach jest wklejona ręcznie.

**Sterowanie przez agenta.** Osiem abilities dla Agent Connector for WP, plus równoległe trasy REST dla instalacji bez niego.

## Instalacja

Wgraj ZIP przez *Wtyczki → Dodaj wtyczkę → Wyślij wtyczkę na serwer*. Aktualizacje przychodzą same z GitHub Releases — nie trzeba nic konfigurować.

Przy aktywacji powstaje tabela rejestru zgód. **Deaktywacja jej nie kasuje**: rejestr jest jedynym dowodem na to, że zgoda została udzielona i kiedy.

## Skąd bierze się wygląd

Trzy źródła, w tej kolejności — panel pokazuje, które jest aktywne:

1. **`design-system.json` projektu** — pełna mapa tokenów, jeśli strona powstała w tym procesie.
2. **Ustawienia globalne Oxygena** — paleta, kroje, rozmiar bazowy, promienie. Działa na dowolnej stronie na Oxygenie, bez pliku projektu.
3. **Domyślne wtyczki** — neutralne, gdy nie ma ani jednego, ani drugiego.

Import sprawdza kontrast. Jeśli para tekst/tło albo etykieta/przycisk schodzi poniżej 4,5:1, zgłasza to zamiast po cichu zrobić baner nie do przeczytania.

Baner renderuje wtyczka, nie Oxygen — musi pokazać się przed zgodą, na każdej podstronie, także z cache'a i na stronie błędu. Z Oxygena bierzemy wygląd, nie mechanizm renderowania.

## Co klient ustawia sam

*Ustawienia → Nerti Cookies*, pięć zakładek:

| Zakładka | Co w niej jest |
|---|---|
| Stan | wersja polityki, liczba decyzji i odmów, źródło tokenów, stan blokady |
| Wygląd | pozycja, szerokość i wielkość tekstu — **osobno dla telefonu i desktopu** — plus import tokenów i podgląd na żywo |
| Teksty | każdy napis w banerze, z opisem gdzie się pokazuje |
| Kategorie i skrypty | trzy kategorie, przypisane ciasteczka, mapa uchwytów i wzorców |
| Rejestr zgód | eksport CSV i podniesienie wersji polityki |

Podniesienie wersji polityki **unieważnia wszystkie dotychczasowe zgody** i pokazuje baner od nowa. To osobna operacja, a nie efekt uboczny zapisu ustawień: zmiana treści polityki bez odpytania ludzi na nowo znaczy, że rejestr dowodzi zgody na dokument, którego oni nie widzieli.

## Sterowanie przez MCP

Wtyczka rejestruje się jako paczka abilities Agent Connector for WP. Uwierzytelnienia, blokady domeny ani audytu w niej nie ma i nie ma go pisać — Agent Connector wstrzykuje to każdemu ability sam.

| Ability | Do czego |
|---|---|
| `nerti-cookies/pobierz-ustawienia` | odczyt całej konfiguracji |
| `nerti-cookies/zapisz-ustawienia` | zapis częściowy, scalany z bieżącym stanem |
| `nerti-cookies/importuj-tokeny` | wygląd z `design-system.json` |
| `nerti-cookies/importuj-z-oxygena` | wygląd z ustawień globalnych Oxygena |
| `nerti-cookies/podnies-wersje-polityki` | unieważnienie zgód po zmianie polityki |
| `nerti-cookies/statystyki-zgod` | ile decyzji, ile odmów, ostatnia |
| `nerti-cookies/inwentarz-skryptow` | jakie skrypty zewnętrzne faktycznie są na stronie |
| `nerti-cookies/zaproponuj-dokumenty` | komplet dokumentów prawnych dobrany do typu strony |

`zaproponuj-dokumenty` rozdziela dwa warianty: strona wizytówkowa dostaje **jeden** dokument z ciasteczkami w środku, sklep dostaje osobne polityki, regulamin i pouczenie o odstąpieniu. Przy pytaniu o deklarację dostępności (European Accessibility Act) ability nie zgaduje: bez podanej liczby pracowników i obrotu zwraca `PYTANIE DO KLIENTA`, bo mikroprzedsiębiorca usługowy jest z tego obowiązku zwolniony w całości.

Trasy REST `nerti-cookies/v1/*` robią to samo dla instalacji bez Agent Connectora. Obie warstwy wołają jedną implementację (`katalog()`), więc nie mogą się rozjechać. **W dniu, w którym ktoś doda trasę REST poza `katalog()`, ta gwarancja znika.**

## Testy

Bez frameworka, uruchamiane przez WP-CLI:

```bash
wp eval-file wp-content/plugins/nerti-cookies/testy/sprawdz-dokumenty.php
wp eval-file wp-content/plugins/nerti-cookies/testy/sprawdz-oxygena.php
```

## Licencja

GPL-2.0-or-later.
