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
		var sciezki = [ '/', location.pathname ];

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

	function odblokuj( kat ) {
		var znaczniki = document.querySelectorAll( 'script[type="text/plain"][data-nc-cat]' );
		Array.prototype.forEach.call( znaczniki, function ( stary ) {
			if ( ! kat[ stary.getAttribute( 'data-nc-cat' ) ] ) {
				return;
			}
			var nowy = document.createElement( 'script' );
			Array.prototype.forEach.call( stary.attributes, function ( a ) {
				if ( a.name === 'type' || a.name === 'data-nc-cat' || a.name === 'data-nc-src' ) {
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
			analytics_storage: kat.analityczne ? 'granted' : 'denied'
		} );
	}

	function zastosuj( kat, akcja, zapisz ) {
		var id = ( czytaj() || {} ).id || nowyId();

		// Kasowanie idzie PRZED odblokowaniem: jeśli ktoś cofa zgodę, ciasteczka mają zniknąć,
		// zanim cokolwiek zdąży je ustawić ponownie.
		var doKasacji = [];
		K.kategorie.forEach( function ( c ) {
			if ( ! c.wymagana && ! kat[ c.id ] ) {
				doKasacji = doKasacji.concat( c.ciasteczka || [] );
			}
		} );
		if ( doKasacji.length ) {
			kasujCiasteczka( doKasacji );
		}

		if ( zapisz !== false ) {
			zapiszCiasteczko( kat, id );
			loguj( id, akcja, kat );
		}

		consentMode( kat );
		odblokuj( kat );

		document.documentElement.setAttribute( 'data-nc', 'decyzja' );
		if ( baner ) {
			baner.hidden = true;
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
			} );
		} catch ( e ) {
			// Brak wpisu w rejestrze nie może zablokować decyzji użytkownika.
		}
	}

	/* ---------- zestawy kategorii ---------- */

	function zestaw( wszystkie ) {
		var k = {};
		K.kategorie.forEach( function ( c ) {
			k[ c.id ] = c.wymagana ? true : !! wszystkie;
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

		var akcja = cel.getAttribute( 'data-nc-akcja' );

		if ( 'akceptuj' === akcja ) {
			zastosuj( zestaw( true ), 'akceptuj' );
		} else if ( 'odrzuc' === akcja ) {
			zastosuj( zestaw( false ), 'odrzuc' );
		} else if ( 'zapisz' === akcja ) {
			zastosuj( zWyboru(), 'wybor' );
		} else if ( 'pokaz-ustawienia' === akcja ) {
			if ( panelUstawien ) {
				var otwarty = ! panelUstawien.hidden;
				panelUstawien.hidden = otwarty;
				cel.setAttribute( 'aria-expanded', otwarty ? 'false' : 'true' );
			}
		} else if ( 'otworz' === akcja ) {
			var stan = czytaj();
			if ( stan && panelUstawien ) {
				var pola2 = panelUstawien.querySelectorAll( '.nc-kategoria__pole' );
				Array.prototype.forEach.call( pola2, function ( p ) {
					p.checked = !! ( stan.kat && stan.kat[ p.value ] ) || p.disabled;
				} );
				panelUstawien.hidden = false;
			}
			pokaz();
		}
	} );

	/* ---------- start ---------- */

	var stanStartowy = czytaj();
	if ( stanStartowy && stanStartowy.kat ) {
		// Decyzja już jest: stosujemy ją bez ponownego zapisu do rejestru.
		zastosuj( stanStartowy.kat, 'istniejaca', false );
	} else {
		pokaz();
	}
} )();
