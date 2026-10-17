/**
 * Nerti Cookies — logika banera.
 *
 * Trzy rzeczy, które ten plik musi zrobić poprawnie i które w większości wdrożeń są zepsute:
 *  1. odmowa jest zapamiętywana tak samo trwale jak zgoda (inaczej po odświeżeniu skrypty wstają);
 *  2. odblokowanie skryptu polega na PODMIANIE znacznika, nie na zmianie atrybutu
 *     (zmiana `type` na istniejącym elemencie nic nie robi — przeglądarka już go pominęła);
 *  3. wycofanie zgody KASUJE ciasteczka, a nie tylko przestaje ustawiać nowe.
 */
( function () {
	'use strict';

	var K = window.ncKonfig;
	if ( ! K ) {
		return;
	}

	var baner = document.getElementById( 'nc-baner' );
	var panelUstawien = document.getElementById( 'nc-ustawienia' );
	var powrotFokusu = null;
	var uruchomione = {};

	/* ---------- ciasteczko decyzji ---------- */

	function czytaj() {
		try {
			var m = document.cookie.match( new RegExp( '(?:^|; )' + K.ciasteczko + '=([^;]*)' ) );
			if ( ! m ) {
				return null;
			}
			var d = JSON.parse( decodeURIComponent( m[ 1 ] ) );
			if ( ! d || String( d.w ) !== String( K.wersja_polityki ) ) {
				return null; // zgoda na starą wersję polityki nie jest zgodą na obecną
			}
			d.kat = d.kat || {};
			K.kategorie.forEach( function (c) { d.kat[c.id] = c.wymagana || (!c.ukryta && d.kat[c.id] === true); } );
			return d;
		} catch ( e ) {
			return null;
		}
	}

	function zapiszCiasteczko( kat, id ) {
		var dane = { w: K.wersja_polityki, t: Math.floor( Date.now() / 1000 ), id: id, kat: kat };
		var wygasa = new Date( Date.now() + K.waznosc_dni * 864e5 ).toUTCString();
		document.cookie = K.ciasteczko + '=' + encodeURIComponent( JSON.stringify( dane ) ) +
			';expires=' + wygasa + ';path=/;SameSite=Lax' +
			( location.protocol === 'https:' ? ';Secure' : '' );
	}

	function nowyId() {
		var b = new Uint8Array( 16 );
		if ( window.crypto && window.crypto.getRandomValues ) {
			window.crypto.getRandomValues( b );
		} else {
			for ( var i = 0; i < 16; i++ ) {
				b[ i ] = Math.floor( Math.random() * 256 );
			}
		}
		return Array.prototype.map.call( b, function ( x ) {
			return ( '0' + x.toString( 16 ) ).slice( -2 );
		} ).join( '' );
	}

	/* ---------- kasowanie ciasteczek odrzuconych kategorii ---------- */

	function domeny() {
		var h = location.hostname.split( '.' );
		var lista = [ '', location.hostname ];
		while ( h.length > 1 ) {
			lista.push( '.' + h.join( '.' ) );
			h.shift();
		}
		return lista;
	}

	function kasujCiasteczka( wzorce ) {
		var obecne = document.cookie.split( ';' ).map( function ( c ) {
			return c.split( '=' )[ 0 ].trim();
		} );
		var sciezki = [ '/' ];
		location.pathname.split('/').filter(Boolean).reduce(function(p,part){ p += '/' + part; sciezki.push(p,p+'/'); return p; }, '');

		obecne.forEach( function ( nazwa ) {
			var pasuje = wzorce.some( function ( w ) {
				if ( w.slice( -1 ) === '*' ) {
					return nazwa.indexOf( w.slice( 0, -1 ) ) === 0;
				}
				return nazwa === w;
			} );
			if ( ! pasuje ) {
				return;
			}
			domeny().forEach( function ( d ) {
				sciezki.forEach( function ( p ) {
					document.cookie = nazwa + '=;expires=Thu, 01 Jan 1970 00:00:01 GMT;path=' + p +
						( d ? ';domain=' + d : '' );
				} );
			} );
		} );
	}

	/* ---------- odblokowanie ---------- */

	function kasujStorage( wzorce ) {
		[ 'localStorage', 'sessionStorage' ].forEach(function(typ) {
			try { var storage = window[typ]; Object.keys(storage).forEach(function(nazwa) {
				if (wzorce.some(function(w) { return w.slice(-1) === '*' ? nazwa.indexOf(w.slice(0,-1)) === 0 : nazwa === w; })) storage.removeItem(nazwa);
			}); } catch(e) { /* Storage może być niedostępny. */ }
		});
	}

	function odblokuj( kat ) {
		var znaczniki = document.querySelectorAll( 'script[type="text/plain"][data-nc-cat]' );
		Array.prototype.forEach.call( znaczniki, function ( stary ) {
			if ( ! kat[ stary.getAttribute( 'data-nc-cat' ) ] ) {
				return;
			}
			uruchomione[stary.getAttribute('data-nc-cat')] = true;
			var nowy = document.createElement( 'script' );
			nowy.async = false;
			var typ = stary.getAttribute('data-nc-type');
			if (typ) nowy.type = typ;
			Array.prototype.forEach.call( stary.attributes, function ( a ) {
				if ( a.name === 'type' || a.name === 'data-nc-cat' || a.name === 'data-nc-src' || a.name === 'data-nc-type' ) {
					return;
				}
				nowy.setAttribute( a.name, a.value );
			} );
			var src = stary.getAttribute( 'data-nc-src' );
			if ( src ) {
				nowy.src = src;
			} else {
				nowy.text = stary.textContent;
			}
			stary.parentNode.insertBefore( nowy, stary );
			stary.parentNode.removeChild( stary );
		} );

		// Piksele obrazkowe i podpowiedzi połączeń (preconnect) — oddajemy im adres.
		Array.prototype.forEach.call( document.querySelectorAll( 'img[data-nc-src][data-nc-cat]' ), function ( img ) {
			if ( kat[ img.getAttribute( 'data-nc-cat' ) ] ) {
				uruchomione[ img.getAttribute( 'data-nc-cat' ) ] = true;
				img.src = img.getAttribute( 'data-nc-src' );
				img.removeAttribute( 'data-nc-src' );
				img.removeAttribute( 'data-nc-cat' );
			}
		} );
		Array.prototype.forEach.call( document.querySelectorAll( 'link[data-nc-href][data-nc-cat]' ), function ( l ) {
			if ( kat[ l.getAttribute( 'data-nc-cat' ) ] ) {
				l.href = l.getAttribute( 'data-nc-href' );
				l.removeAttribute( 'data-nc-href' );
				l.removeAttribute( 'data-nc-cat' );
			}
		} );

		var osadzenia = document.querySelectorAll( '.nc-osadzenie[data-nc-cat]' );
		Array.prototype.forEach.call( osadzenia, function ( el ) {
			if ( kat[ el.getAttribute( 'data-nc-cat' ) ] ) {
				wstawOsadzenie( el );
			}
		} );
	}

	function wstawOsadzenie( el ) {
		var atrybuty = el.getAttribute( 'data-nc-atrybuty' ) || '';
		var src = el.getAttribute( 'data-nc-src' );
		if ( ! src ) {
			return;
		}
		var ramka = document.createElement( 'iframe' );
		uruchomione[el.getAttribute('data-nc-cat')] = true;
		ramka.src = src;
		// Przenosimy tylko atrybuty bezpieczne — nie wstrzykujemy surowego HTML-u z bufora.
		[ 'width', 'height', 'title', 'allow', 'allowfullscreen', 'loading', 'style' ].forEach( function ( n ) {
			var m = atrybuty.match( new RegExp( '\\s' + n + '\\s*=\\s*(["\'])([^"\']*)\\1', 'i' ) );
			if ( m ) {
				ramka.setAttribute( n, m[ 2 ] );
			}
		} );
		ramka.setAttribute( 'referrerpolicy', 'no-referrer' );
		el.parentNode.replaceChild( ramka, el );
	}

	/* ---------- zastosowanie decyzji ---------- */

	function consentMode( kat ) {
		if ( ! K.consentMode || typeof window.gtag !== 'function' ) {
			return;
		}
		window.gtag( 'consent', 'update', {
			ad_storage: kat.marketingowe ? 'granted' : 'denied',
			ad_user_data: kat.marketingowe ? 'granted' : 'denied',
			ad_personalization: kat.marketingowe ? 'granted' : 'denied',
			personalization_storage: kat.marketingowe ? 'granted' : 'denied',
			analytics_storage: kat.analityczne ? 'granted' : 'denied'
		} );
	}

	/*
	 * Przekazanie decyzji wtyczkom, które same czekają na zgodę.
	 * WP Consent API: pięć kategorii API na nasze trzy (mapa jak w class-integracje.php).
	 * Meta Pixel for WordPress: publiczne API `fbwcsignal` — wołane tylko przy zmianie stanu,
	 * bo każde wywołanie to żądanie AJAX.
	 */
	var MAPA_WP_CONSENT = { functional: 'niezbedne', statistics: 'analityczne', 'statistics-anonymous': 'analityczne', marketing: 'marketingowe', preferences: 'marketingowe' };

	function integracje( kat ) {
		if ( typeof window.wp_set_consent === 'function' ) {
			window.wp_consent_type = 'optin';
			Object.keys( MAPA_WP_CONSENT ).forEach( function ( c ) {
				var nasza = MAPA_WP_CONSENT[ c ];
				try { window.wp_set_consent( c, ( nasza === 'niezbedne' || kat[ nasza ] ) ? 'allow' : 'deny' ); } catch ( e ) { /* cudzy błąd nie zatrzymuje banera */ }
			} );
		}
		var fb = window.fbwcsignal;
		if ( fb && typeof fb.getState === 'function' ) {
			try {
				var stan = fb.getState();
				if ( kat.marketingowe && stan !== 'active' ) { fb.release(); }
				if ( ! kat.marketingowe && stan === 'active' ) { fb.hold(); }
			} catch ( e ) { /* jw. */ }
		}
	}

	function zastosuj( kat, akcja, zapisz ) {
		var poprzednia = czytaj() || {};
		var id = poprzednia.id || nowyId();
		var cofniecie = K.kategorie.some(function(c) { return !c.wymagana && !kat[c.id] && (uruchomione[c.id] || (poprzednia.kat || {})[c.id]); });

		// Kasowanie idzie PRZED odblokowaniem: jeśli ktoś cofa zgodę, ciasteczka mają zniknąć,
		// zanim cokolwiek zdąży je ustawić ponownie.
		var doKasacji = [], storageDoKasacji = [];
		K.kategorie.forEach( function ( c ) {
			if ( ! c.wymagana && ! kat[ c.id ] ) {
				doKasacji = doKasacji.concat( c.ciasteczka || [] );
				storageDoKasacji = storageDoKasacji.concat(c.storage || []);
			}
		} );
		if ( doKasacji.length ) {
			kasujCiasteczka( doKasacji );
		}

		if ( zapisz !== false ) {
			zapiszCiasteczko( kat, id );
			loguj( id, akcja, kat );
		}

		kasujStorage(storageDoKasacji);
		consentMode( kat );
		integracje( kat );
		if (cofniecie && zapisz !== false) { location.reload(); return; }
		odblokuj( kat );

		document.documentElement.setAttribute( 'data-nc', 'decyzja' );
		if ( baner ) {
			baner.hidden = true;
			if (powrotFokusu && powrotFokusu.isConnected) powrotFokusu.focus();
		}
		document.dispatchEvent( new CustomEvent( 'nc:zgoda', { detail: { kategorie: kat, akcja: akcja } } ) );
	}

	function loguj( id, akcja, kat ) {
		if ( ! K.rest ) {
			return;
		}
		try {
			fetch( K.rest, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': K.nonce },
				keepalive: true,
				body: JSON.stringify( { zgoda_id: id, akcja: akcja, kategorie: kat, adres: location.href } )
			} ).catch(function() { /* Decyzja działa także bez rejestru. */ });
		} catch ( e ) {
			// Brak wpisu w rejestrze nie może zablokować decyzji użytkownika.
		}
	}

	/* ---------- zestawy kategorii ---------- */

	function zestaw( wszystkie ) {
		var k = {};
		K.kategorie.forEach( function ( c ) {
			k[ c.id ] = c.wymagana ? true : !c.ukryta && !! wszystkie;
		} );
		return k;
	}

	function zWyboru() {
		var k = {};
		K.kategorie.forEach( function ( c ) {
			k[ c.id ] = !! c.wymagana;
		} );
		if ( panelUstawien ) {
			var pola = panelUstawien.querySelectorAll( '.nc-kategoria__pole' );
			Array.prototype.forEach.call( pola, function ( p ) {
				k[ p.value ] = p.checked || p.disabled;
			} );
		}
		return k;
	}

	/* ---------- interakcja ---------- */

	function pokaz() {
		if ( ! baner ) {
			return;
		}
		powrotFokusu = document.activeElement;
		baner.hidden = false;
		var pierwszy = baner.querySelector( '[data-nc-akcja]' );
		if ( pierwszy ) {
			pierwszy.focus();
		}
	}

	document.addEventListener( 'click', function ( e ) {
		var cel = e.target.closest ? e.target.closest( '[data-nc-akcja]' ) : null;

		if ( ! cel ) {
			var osadz = e.target.closest ? e.target.closest( '.nc-osadzenie__btn' ) : null;
			if ( osadz ) {
				var kontener = osadz.closest( '.nc-osadzenie' );
				if ( kontener ) {
					wstawOsadzenie( kontener );
				}
			}
			return;
		}

		e.preventDefault();
		var akcja = cel.getAttribute( 'data-nc-akcja' );

		if ( 'akceptuj' === akcja ) {
			zastosuj( zestaw( true ), 'akceptuj' );
		} else if ( 'odrzuc' === akcja ) {
			zastosuj( zestaw( false ), 'odrzuc' );
		} else if ( 'zamknij' === akcja ) {
			baner.hidden = true;
			if (powrotFokusu && powrotFokusu.isConnected) powrotFokusu.focus();
		} else if ( 'zapisz' === akcja ) {
			zastosuj( zWyboru(), 'wybor' );
		} else if ( 'pokaz-ustawienia' === akcja ) {
			if ( panelUstawien ) {
				var otwarty = ! panelUstawien.hidden;
				panelUstawien.hidden = otwarty;
				cel.setAttribute( 'aria-expanded', otwarty ? 'false' : 'true' );
				/* Po rozwinięciu „Dostosuj" nie ma już czego robić — jego miejsce zajmuje
				   „Zapisz mój wybór" pod listą. Dwa przyciski o tej samej roli obok siebie
				   to pytanie, na które użytkownik nie umie odpowiedzieć. */
				cel.hidden = ! otwarty;
				var zapisz = baner.querySelector( '[data-nc-akcja="zapisz"]' );
				if ( zapisz ) { zapisz.hidden = otwarty; }
				etykiety( ! otwarty );
				var pole = panelUstawien.querySelector('.nc-kategoria__pole:not(:disabled)');
				if (!otwarty && pole) pole.focus();
			}
		} else if ( 'otworz' === akcja ) {
			var stan = czytaj();
			if ( stan && panelUstawien ) {
				var pola2 = panelUstawien.querySelectorAll( '.nc-kategoria__pole' );
				Array.prototype.forEach.call( pola2, function ( p ) {
					p.checked = !! ( stan.kat && stan.kat[ p.value ] ) || p.disabled;
				} );
				panelUstawien.hidden = false;
				baner.querySelector('[data-nc-akcja="zapisz"]').hidden = false;
				baner.querySelector('[data-nc-akcja="pokaz-ustawienia"]').hidden = true;
				etykiety( true );
			}
			pokaz();
		}
	} );

	/* Krótkie „Akceptuję / Odrzucam” pasują do pierwszego ekranu. Po rozwinięciu kategorii te same
	   przyciski stoją obok przełączników, więc muszą mówić „wszystkie” — inaczej „Akceptuję”
	   czyta się jak „akceptuję to, co zaznaczyłem” (zgłoszenie Nertiego, 2026-10-17). */
	function etykiety( pelne ) {
		Array.prototype.forEach.call( baner.querySelectorAll( '[data-nc-pelny]' ), function ( b ) {
			if ( ! b.hasAttribute( 'data-nc-krotki' ) ) { b.setAttribute( 'data-nc-krotki', b.textContent.trim() ); }
			b.textContent = pelne ? b.getAttribute( 'data-nc-pelny' ) : b.getAttribute( 'data-nc-krotki' );
		} );
	}

	/* ---------- tryb skanu (ramka na ekranie skanera w panelu) ---------- */

	/*
	 * Włącza się tylko wtedy, gdy stronę osadził ekran skanera z panelu TEJ SAMEJ domeny.
	 * Odczyt `parent.location` z innej domeny rzuca wyjątek, a zwykły odwiedzający nie ma
	 * ekranu `#nc-skaner` — więc nikt z zewnątrz nie włączy sobie „pełnej zgody bez zapisu".
	 * Nic nie trafia do ciasteczka decyzji ani do rejestru; po zebraniu wyników przywracamy
	 * prawdziwą decyzję administratora i kasujemy to, na co się nie zgodził.
	 */
	function trybSkanu() {
		try {
			return window.parent !== window && window.parent.location.origin === location.origin &&
				window.parent.location.pathname.indexOf( '/wp-admin/' ) !== -1 && !! window.parent.document.getElementById( 'nc-skaner' );
		} catch ( e ) {
			return false;
		}
	}

	if ( trybSkanu() ) {
		var prawdziwa = czytaj();
		zastosuj( zestaw( true ), 'skan', false );
		setTimeout( function () {
			var hosty = [];
			// Pasek administratora (awatar z Gravatara) jest tylko w sesji skanującego, nie u gościa.
			var zPaska = {};
			Array.prototype.forEach.call( document.querySelectorAll( '#wpadminbar img[src]' ), function ( img ) {
				try { zPaska[ new URL( img.src ).hostname ] = true; } catch ( e ) { /* pomijamy */ }
			} );
			Array.prototype.forEach.call( document.querySelectorAll( 'img[src]:not(#wpadminbar img)' ), function ( img ) {
				try { delete zPaska[ new URL( img.src ).hostname ]; } catch ( e ) { /* pomijamy */ }
			} );
			try {
				performance.getEntriesByType( 'resource' ).forEach( function ( r ) {
					var h = new URL( r.name ).hostname;
					if ( h && h !== location.hostname && ! zPaska[ h ] && hosty.indexOf( h ) === -1 ) { hosty.push( h ); }
				} );
			} catch ( e ) { /* stara przeglądarka */ }
			var klucze = function ( typ ) { try { return Object.keys( window[ typ ] ); } catch ( e ) { return []; } };
			window.parent.postMessage( {
				ncSkan: true,
				adres: location.href,
				ciasteczka: document.cookie.split( ';' ).map( function ( c ) { return c.split( '=' )[ 0 ].trim(); } ).filter( Boolean ),
				storage: klucze( 'localStorage' ).concat( klucze( 'sessionStorage' ) ),
				hosty: hosty,
				// Skan idzie w sesji administratora: klasa `logged-in` na <body> albo pasek admina.
				// Serwer dzięki temu odróżnia ślad panelu (builder, Site Kit) od śledzenia gościa.
				zalogowany: !! ( document.getElementById( 'wpadminbar' ) || document.body.classList.contains( 'logged-in' ) )
			}, location.origin );
			// Sprzątanie: prawdziwa decyzja administratora wraca, reszta znika.
			var kat = prawdziwa && prawdziwa.kat ? prawdziwa.kat : zestaw( false );
			var doKasacji = [];
			K.kategorie.forEach( function ( c ) { if ( ! c.wymagana && ! kat[ c.id ] ) { doKasacji = doKasacji.concat( c.ciasteczka || [] ); } } );
			kasujCiasteczka( doKasacji );
			integracje( kat );
		}, 6000 );
		return;
	}

	/* ---------- start ---------- */

	var stanStartowy = czytaj();
	if ( stanStartowy && stanStartowy.kat ) {
		// Decyzja już jest: stosujemy ją bez ponownego zapisu do rejestru.
		zastosuj( stanStartowy.kat, 'istniejaca', false );
		// baner.js stoi w stopce, a część zablokowanych skryptów (np. PixelYourSite) jest
		// wypisywana PO nim. Bez drugiego przejścia zostałyby text/plain mimo zgody.
		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', function () { odblokuj( stanStartowy.kat ); } );
		}
	} else {
		zastosuj(zestaw(false), 'brak', false);
		pokaz();
	}
} )();
