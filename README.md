# nerti-cookies

Wtyczka WordPress: zgody na ciasteczka, blokada skryptów **przed** zgodą, rejestr zgód
z wersją polityki, baner ubrany w tokeny projektu, sterowanie przez abilities MCP.

Wydzielona z repozytorium `webdev` 2026-08-08. Powód: to jest **produkt**, który trafia
na serwer klienta i ma własny cykl wydań. Wiedza o tym, jak go wdrażać i jak testować,
została w skillu `cookies-rodo` — kod nie powinien mieszkać w repozytorium z instrukcjami.

```
nerti-cookies/
├─ nerti-cookies.php     nagłówek wtyczki, ładowanie klas
├─ inc/
│  ├─ class-blokada.php     blokada skryptów przed zgodą  ← rdzeń
│  ├─ class-baner.php       baner i jego stany
│  ├─ class-rejestr.php     rejestr zgód z wersją polityki
│  ├─ class-tokeny.php      wygląd z design-system.json
│  ├─ class-ustawienia.php  panel w kokpicie
│  └─ class-abilities.php   sterowanie przez MCP
└─ assets/  baner.css · baner.js
```

## Stan

**Kod napisany, nieuruchomiony na żywym WordPressie.** Sprawdzone: balans składniowy PHP,
`node --check` na `baner.js`. Niesprawdzone: cokolwiek, co wymaga działającej instalacji.

Zanim uznasz wtyczkę za gotową, wykonaj **test przez odmowę** — procedura w
`C:\AI\webdev\plugins\nerti-web\skills\cookies-rodo\test-odmowy.md`. Klikasz „odrzuć"
i sprawdzasz w zakładce Sieć, czy naprawdę nic nie poszło. Zgoda, która nic nie blokuje,
jest gorsza niż brak banera — bo wygląda na zgodność.

## Dokumentacja

| Czego szukasz | Gdzie |
|---|---|
| jak wdrożyć, jak testować | `webdev\plugins\nerti-web\skills\cookies-rodo\SKILL.md` |
| inwentarz skryptów strony | `...\cookies-rodo\inwentarz.md` |
| generowanie polityk z inwentarza | `...\cookies-rodo\polityki.md` |
| abilities dla agent-connectora | `...\cookies-rodo\abilities.md` |
| test przez odmowę | `...\cookies-rodo\test-odmowy.md` |
