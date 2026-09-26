#!/usr/bin/env node
/**
 * Test odmowy (bramka Q-COOKIE) — kroki 0–5 z cookie-consent/refusal-test.md jako skrypt.
 *
 *   node testy/odmowa.mjs http://try.local/nc-test/ [kolejny-adres ...]
 *
 * Co sprawdza, w tej kolejności:
 *  A. nagłówki bez przeglądarki (to, czego nie widać w devtools po odświeżeniu):
 *     Set-Cookie w odpowiedzi HTML dla nowego gościa, dla gościa po odmowie i po zgodzie;
 *  B. przeglądarka, czysta sesja: stan przed decyzją, odmowa jednym kliknięciem,
 *     stan po odmowie, po przeładowaniu, czy baner nie wraca;
 *  C. zgoda → wycofanie: czy ciasteczka znikają.
 *
 * Wynik: formularz z §7 refusal-test.md na stdout, JSON obok (--json plik), kod wyjścia 1 przy BLOKERZE.
 * Playwright: bierze pierwszy dostępny — `playwright` z bieżącego katalogu, z NC_PLAYWRIGHT
 * albo z web-core/scripts/node_modules w warsztacie.
 */
import { createRequire } from 'node:module';
import { writeFileSync } from 'node:fs';

const require = createRequire( import.meta.url );
function zaladujPlaywright() {
	const kandydaci = [ process.env.NC_PLAYWRIGHT, 'playwright', 'C:/Claude/websites/.claude/skills/web-core/scripts/node_modules/playwright' ].filter( Boolean );
	for ( const k of kandydaci ) {
		try { return require( k ); } catch ( e ) { /* następny */ }
	}
	throw new Error( 'Brak Playwrighta. Ustaw NC_PLAYWRIGHT na ścieżkę do modułu playwright.' );
}

const argi = process.argv.slice( 2 );
const plikJson = argi.includes( '--json' ) ? argi[ argi.indexOf( '--json' ) + 1 ] : null;
const adresy = argi.filter( ( a, i ) => /^https?:\/\//.test( a ) && argi[ i - 1 ] !== '--json' );
if ( ! adresy.length ) {
	console.error( 'Użycie: node testy/odmowa.mjs <adres> [adres ...] [--json wynik.json]' );
	process.exit( 2 );
}

// Z refusal-test.md §3. Turnstile NIE jest na liście: to ochrona formularza, kategoria niezbędne.
const DOMENY_SLEDZACE = [ 'google-analytics.com', 'analytics.google.com', 'googletagmanager.com', 'facebook.net', 'facebook.com',
	'hotjar.com', 'hotjar.io', 'clarity.ms', 'doubleclick.net', 'googleadservices.com', 'googlesyndication.com', 'fonts.googleapis.com', 'fonts.gstatic.com',
	'youtube.com', 'youtube-nocookie.com', 'ytimg.com', 'player.vimeo.com', 'vimeocdn.com', 'maps.google.com', 'maps.googleapis.com', 'tiktok.com', 'licdn.com', 'linkedin.com',
	'recaptcha.net', 'gstatic.com' ];
const CIASTECZKA_SLEDZACE = /^(_ga|_ga_.*|_gid|_gat.*|_gcl_.*|_fbp|_fbc|_hj.*|_clck|_clsk|IDE|test_cookie|_ttp|_tt_.*|li_.*|bcookie|sbjs_.*|_GRECAPTCHA)$/;
const DOZWOLONE_HOSTY = [ 'challenges.cloudflare.com' ];

const sledzacy = ( host ) => ! DOZWOLONE_HOSTY.includes( host ) && DOMENY_SLEDZACE.some( ( d ) => host === d || host.endsWith( '.' + d ) )
	// google.com sam w sobie to reCAPTCHA / Mapy — liczy się tylko pod konkretnymi ścieżkami, patrz niżej.
	;

const bloker = [];
const defekt = [];
const wynik = { data: new Date().toISOString(), adresy, naglowki: [], przegladarka: [] };

/* ---------- A. nagłówki ---------- */

function nazwyZSetCookie( naglowki ) {
	return ( naglowki.getSetCookie ? naglowki.getSetCookie() : [] ).map( ( l ) => l.split( '=' )[ 0 ].trim() );
}

async function pobierz( adres, ciasteczko ) {
	const r = await fetch( adres, { redirect: 'manual', headers: ciasteczko ? { Cookie: ciasteczko } : {} } );
	return { status: r.status, ciasteczka: nazwyZSetCookie( r.headers ), cache: r.headers.get( 'x-litespeed-cache' ) || r.headers.get( 'x-cache' ) || r.headers.get( 'x-cache-enabler' ) || '' };
}

function ciasteczkoDecyzji( wersja, kat ) {
	return 'nc_zgoda=' + encodeURIComponent( JSON.stringify( { w: wersja, t: Math.floor( Date.now() / 1000 ), id: 'test', kat } ) );
}

async function wersjaPolityki( strona ) {
	return strona.evaluate( () => ( window.ncKonfig && window.ncKonfig.wersja_polityki ) || '1.0' );
}

/* ---------- B/C. przeglądarka ---------- */

async function stan( kontekst, strona, hosty ) {
	// Tylko ciasteczka strony. Ciasteczka stron trzecich (.tiktok.com, .linkedin.com) po zgodzie
	// zostają w przeglądarce i żadna strona nie może ich skasować; przed zgodą nie powstają wcale,
	// bo nie ma żądań — a żądania liczymy osobno.
	const host = new URL( strona.url() ).hostname;
	const wszystkie = await kontekst.cookies();
	const ciasteczka = wszystkie.filter( ( c ) => host === c.domain.replace( /^\./, '' ) || host.endsWith( c.domain.startsWith( '.' ) ? c.domain : '.' + c.domain ) ).map( ( c ) => c.name );
	const pamiec = await strona.evaluate( () => ( { ls: Object.keys( localStorage ), ss: Object.keys( sessionStorage ) } ) ).catch( () => ( { ls: [], ss: [] } ) );
	// Własne skrypty wtyczki (start i konfiguracja banera) zawierają słowa „gtag" i nazwy ciasteczek — to nie jest śledzenie.
	const skrypty = await strona.evaluate( () => [ ...document.querySelectorAll( 'script:not(#nc-bootstrap):not(#nerti-cookies-js-extra)' ) ]
		.filter( ( s ) => /gtag|gtm|fbq|analytics|clarity|hotjar|tiktok|licdn|googleadservices/i.test( ( s.src || s.getAttribute( 'data-nc-src' ) || '' ) + s.textContent ) )
		.map( ( s ) => ( { typ: s.type || '', src: s.src || s.getAttribute( 'data-nc-src' ) || '(inline)' } ) ) ).catch( () => [] );
	return {
		ciasteczka_sledzace: ciasteczka.filter( ( n ) => CIASTECZKA_SLEDZACE.test( n ) ),
		ciasteczka: ciasteczka,
		storage: pamiec,
		hosty_sledzace: [ ...hosty ].filter( sledzacy ).sort(),
		skrypty_wykonywalne: skrypty.filter( ( s ) => s.typ !== 'text/plain' ).map( ( s ) => s.src ),
		skrypty_zablokowane: skrypty.filter( ( s ) => s.typ === 'text/plain' ).length,
	};
}

function nowyZbiorHostow( strona ) {
	const hosty = new Set();
	strona.on( 'request', ( r ) => {
		try {
			const u = new URL( r.url() );
			if ( u.protocol.startsWith( 'http' ) ) {
				// www.google.com liczy się jako śledzący tylko dla reCAPTCHA i Map.
				if ( /(^|\.)google\.com$/.test( u.hostname ) && ! /recaptcha|maps/.test( u.pathname ) ) { return; }
				hosty.add( /(^|\.)google\.com$/.test( u.hostname ) ? 'google.com' + u.pathname.split( '/' ).slice( 0, 2 ).join( '/' ) : u.hostname );
			}
		} catch ( e ) { /* adres nie-URL */ }
	} );
	return hosty;
}
DOMENY_SLEDZACE.push( 'google.com/recaptcha', 'google.com/maps' );

async function przegladarka( pw, adres ) {
	const w = { adres };
	const przeg = await pw.chromium.launch();
	try {
		// B — odmowa
		let kontekst = await przeg.newContext();
		let strona = await kontekst.newPage();
		let hosty = nowyZbiorHostow( strona );
		await strona.goto( adres, { waitUntil: 'load' } );
		await strona.waitForTimeout( 2500 );
		w.baner = await strona.locator( '#nc-baner' ).isVisible().catch( () => false );
		w.przed = await stan( kontekst, strona, hosty );

		const odrzuc = strona.locator( '#nc-baner [data-nc-akcja="odrzuc"]' );
		w.odmowa_na_pierwszym_ekranie = await odrzuc.isVisible().catch( () => false );
		const akcept = strona.locator( '#nc-baner [data-nc-akcja="akceptuj"]' );
		if ( w.odmowa_na_pierwszym_ekranie ) {
			const [ a, b ] = [ await odrzuc.boundingBox(), await akcept.boundingBox() ];
			w.waga_rowna = !! ( a && b && Math.abs( a.height - b.height ) < 2 );
			await odrzuc.click();
		}
		await strona.waitForTimeout( 2500 );
		w.po_odmowie = await stan( kontekst, strona, hosty );

		hosty = nowyZbiorHostow( strona );
		await strona.reload( { waitUntil: 'load' } );
		await strona.waitForTimeout( 2500 );
		w.po_przeladowaniu = await stan( kontekst, strona, hosty );
		w.baner_po_odmowie = await strona.locator( '#nc-baner' ).isVisible().catch( () => false );
		w.wersja = await wersjaPolityki( strona );
		await kontekst.close();

		// C — zgoda, potem wycofanie
		kontekst = await przeg.newContext();
		strona = await kontekst.newPage();
		hosty = nowyZbiorHostow( strona );
		await strona.goto( adres, { waitUntil: 'load' } );
		await strona.locator( '#nc-baner [data-nc-akcja="akceptuj"]' ).click().catch( () => {} );
		await strona.waitForTimeout( 3000 );
		await strona.reload( { waitUntil: 'load' } );
		await strona.waitForTimeout( 3000 );
		w.po_zgodzie = await stan( kontekst, strona, hosty );
		const otworz = strona.locator( '[data-nc-akcja="otworz"]' ).first();
		w.wycofanie_dostepne = ( await otworz.count() ) > 0;
		if ( w.wycofanie_dostepne ) {
			await otworz.click();
			await strona.locator( '#nc-ustawienia .nc-kategoria__pole:not(:disabled)' ).evaluateAll( ( p ) => p.forEach( ( x ) => { x.checked = false; } ) );
			await Promise.all( [ strona.waitForLoadState( 'load' ), strona.locator( '#nc-baner [data-nc-akcja="zapisz"]' ).click() ] );
			await strona.waitForTimeout( 3000 );
			hosty = nowyZbiorHostow( strona );
			await strona.reload( { waitUntil: 'load' } );
			await strona.waitForTimeout( 2500 );
			w.po_wycofaniu = await stan( kontekst, strona, hosty );
		}
		await kontekst.close();
	} finally {
		await przeg.close();
	}
	return w;
}

/* ---------- przebieg ---------- */

const pw = zaladujPlaywright();
for ( const adres of adresy ) {
	const nowy = await pobierz( adres );
	const przeg = await przegladarka( pw, adres );
	const brak = { niezbedne: true, analityczne: false, marketingowe: false };
	const wszystko = { niezbedne: true, analityczne: true, marketingowe: true };
	const poOdmowie = await pobierz( adres, ciasteczkoDecyzji( przeg.wersja, brak ) );
	const poZgodzie = await pobierz( adres, ciasteczkoDecyzji( przeg.wersja, wszystko ) );
	const n = { adres, nowy, poOdmowie, poZgodzie };
	wynik.naglowki.push( n );
	wynik.przegladarka.push( przeg );

	const sl = ( l ) => l.filter( ( x ) => CIASTECZKA_SLEDZACE.test( x ) );
	if ( sl( nowy.ciasteczka ).length ) { bloker.push( adres + ': Set-Cookie przed decyzją: ' + sl( nowy.ciasteczka ).join( ', ' ) ); }
	if ( sl( poOdmowie.ciasteczka ).length ) { bloker.push( adres + ': Set-Cookie po odmowie: ' + sl( poOdmowie.ciasteczka ).join( ', ' ) ); }
	if ( ! przeg.baner ) { bloker.push( adres + ': baneru nie widać w czystej sesji' ); }
	if ( ! przeg.odmowa_na_pierwszym_ekranie ) { bloker.push( adres + ': brak odmowy na pierwszym ekranie' ); }
	if ( przeg.waga_rowna === false ) { defekt.push( adres + ': przyciski odmowy i zgody różnej wysokości' ); }
	for ( const [ etap, s ] of [ [ 'przed decyzją', przeg.przed ], [ 'po odmowie', przeg.po_odmowie ], [ 'po przeładowaniu', przeg.po_przeladowaniu ], [ 'po wycofaniu', przeg.po_wycofaniu ] ] ) {
		if ( ! s ) { continue; }
		if ( s.ciasteczka_sledzace.length ) { bloker.push( `${ adres }: ciasteczka ${ etap }: ${ s.ciasteczka_sledzace.join( ', ' ) }` ); }
		if ( s.hosty_sledzace.length ) { bloker.push( `${ adres }: żądania ${ etap }: ${ s.hosty_sledzace.join( ', ' ) }` ); }
		if ( s.skrypty_wykonywalne.length ) { bloker.push( `${ adres }: skrypty bez blokady ${ etap }: ${ s.skrypty_wykonywalne.join( ', ' ) }` ); }
	}
	if ( przeg.baner_po_odmowie ) { bloker.push( adres + ': baner wraca po odmowie (zapis decyzji nie działa)' ); }
	if ( ! przeg.wycofanie_dostepne ) { bloker.push( adres + ': brak wejścia do wycofania zgody' ); }

	const lista = ( l ) => ( l && l.length ? l.join( ', ' ) : 'czysto' );
	console.log( `
Data / adres:          ${ wynik.data } / ${ adres }
Cache:                 ${ nowy.cache || '(brak nagłówka cache)' }
Baner:                 ${ przeg.baner ? 'jest' : 'brak' }
Odmowa na 1. ekranie:  ${ przeg.odmowa_na_pierwszym_ekranie ? 'TAK' : 'NIE' }
Kliknięć do odmowy:    1  (do zgody: 1)
Waga wizualna równa:   ${ przeg.waga_rowna ? 'TAK' : 'NIE' }
Set-Cookie nowy gość:  ${ lista( nowy.ciasteczka ) }
Set-Cookie po odmowie: ${ lista( poOdmowie.ciasteczka ) }
Set-Cookie po zgodzie: ${ lista( poZgodzie.ciasteczka ) }
Ciasteczka PRZED:      ${ lista( przeg.przed.ciasteczka_sledzace ) }
Skrypty PRZED:         ${ przeg.przed.skrypty_zablokowane } z type="text/plain" / wykonywalne śledzące: ${ lista( przeg.przed.skrypty_wykonywalne ) }
Żądania PRZED:         ${ lista( przeg.przed.hosty_sledzace ) }
Ciasteczka PO ODMOWIE: ${ lista( przeg.po_odmowie.ciasteczka_sledzace ) }
Żądania PO ODMOWIE:    ${ lista( przeg.po_odmowie.hosty_sledzace ) }
Po przeładowaniu:      ${ przeg.po_przeladowaniu.ciasteczka_sledzace.length || przeg.po_przeladowaniu.hosty_sledzace.length ? 'WRACAJĄ' : 'czysto' }
Baner po odmowie:      ${ przeg.baner_po_odmowie ? 'wraca' : 'nie wraca' }
Po zgodzie (kontrola): ciasteczka ${ lista( przeg.po_zgodzie.ciasteczka_sledzace ) }; hosty ${ lista( przeg.po_zgodzie.hosty_sledzace ) }
Wycofanie zgody:       ${ ! przeg.wycofanie_dostepne ? 'brak możliwości' : ( przeg.po_wycofaniu.ciasteczka_sledzace.length ? 'nie kasuje ciasteczek: ' + przeg.po_wycofaniu.ciasteczka_sledzace.join( ', ' ) : 'działa' ) }
Wersja polityki:       ${ przeg.wersja }` );
}

console.log( '\nBLOKERY: ' + ( bloker.length ? '\n - ' + bloker.join( '\n - ' ) : 'brak' ) );
console.log( 'DEFEKTY: ' + ( defekt.length ? '\n - ' + defekt.join( '\n - ' ) : 'brak' ) );
console.log( '\nWERDYKT: ' + ( bloker.length ? 'NO GO' : 'GO' ) );
wynik.blokery = bloker;
wynik.defekty = defekt;
if ( plikJson ) { writeFileSync( plikJson, JSON.stringify( wynik, null, 2 ) ); }
process.exit( bloker.length ? 1 : 0 );
