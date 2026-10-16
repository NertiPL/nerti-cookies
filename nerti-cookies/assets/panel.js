/*
 * Nerti Cookies — ekran ustawień.
 *
 * Trzy rzeczy i nic więcej: zakładki, podgląd tekstów na żywo, podgląd układu
 * dla wybranego urządzenia. Zero zależności, zero kroku budowania.
 *
 * Wszystko jest progresywne: bez tego pliku ekran nadal działa — sekcje
 * pokazują się jedna pod drugą, a podgląd stoi na wartościach z serwera.
 * Klasę `nc-panel--taby` dokłada dopiero ten skrypt, więc przy wyłączonym JS
 * nikt nie zostaje z pięcioma ukrytymi sekcjami i bez zakładek do klikania.
 */
( function () {
	'use strict';

	var panel = document.querySelector( '.nc-panel' );
	if ( ! panel ) {
		return;
	}

	var podglad = panel.querySelector( '[data-nc-podglad]' );

	/* ---------- Zakładki ---------- */

	var taby = Array.prototype.slice.call( panel.querySelectorAll( '[data-nc-tab]' ) );
	var sekcje = Array.prototype.slice.call( panel.querySelectorAll( '[data-nc-panel]' ) );

	function pokaz( id ) {
		var znalezione = false;

		sekcje.forEach( function ( s ) {
			var moja = s.getAttribute( 'data-nc-panel' ) === id;
			s.classList.toggle( 'nc-sekcja--aktywna', moja );
			if ( moja ) {
				znalezione = true;
			}
		} );

		if ( ! znalezione ) {
			return false;
		}

		taby.forEach( function ( t ) {
			var moj = t.getAttribute( 'data-nc-tab' ) === id;
			t.classList.toggle( 'nav-tab-active', moj );
			t.setAttribute( 'aria-selected', moj ? 'true' : 'false' );
		} );

		// Podgląd ma sens tylko przy wyglądzie, tekstach i kategoriach.
		var zBanerem = [ 'wyglad', 'teksty', 'kategorie' ].indexOf( id ) !== -1;
		if ( podglad ) {
			podglad.parentNode.hidden = ! zBanerem;
		}
		// Przycisk „Zapisz zmiany" tylko przy zakładkach, które są w formularzu.
		var zapis = panel.querySelector( '.nc-zapis' );
		if ( zapis ) {
			zapis.hidden = [ 'stan', 'wyglad', 'teksty', 'kategorie' ].indexOf( id ) === -1;
		}

		try {
			window.history.replaceState( null, '', '#nc-' + id );
		} catch ( e ) {
			/* replaceState bywa zablokowany w ramce — zakładki mają działać mimo tego. */
		}

		return true;
	}

	if ( taby.length && sekcje.length ) {
		panel.classList.add( 'nc-panel--taby' );

		taby.forEach( function ( t ) {
			t.setAttribute( 'role', 'tab' );
			t.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				pokaz( t.getAttribute( 'data-nc-tab' ) );
			} );
		} );

		// Odnośniki „Przejdź" z pulpitu.
		Array.prototype.forEach.call( panel.querySelectorAll( '[data-nc-idz]' ), function ( a ) {
			a.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				pokaz( a.getAttribute( 'data-nc-idz' ) );
				window.scrollTo( 0, 0 );
			} );
		} );

		// Po zapisie WordPress wraca na ten sam adres — kotwica trzyma zakładkę,
		// żeby klient nie lądował za każdym razem na „Stan".
		var zHasza = window.location.hash.replace( /^#nc-/, '' );
		if ( ! zHasza || ! pokaz( zHasza ) ) {
			pokaz( taby[ 0 ].getAttribute( 'data-nc-tab' ) );
		}
	}

	skaner();

	if ( ! podglad ) {
		return;
	}

	/* ---------- Teksty na żywo ---------- */

	var domyslne = {};

	Array.prototype.forEach.call( panel.querySelectorAll( '[data-nc-pole]' ), function ( pole ) {
		var klucz = pole.getAttribute( 'data-nc-pole' );
		var cel = podglad.querySelector( '[data-nc-tekst="' + klucz + '"]' );
		if ( ! cel ) {
			return;
		}

		domyslne[ klucz ] = cel.textContent.trim();

		pole.addEventListener( 'input', function () {
			// Pusty przycisk odmowy nie istnieje — serwer i tak przywróci tekst
			// domyślny (Nerti_Cookies_Ustawienia::pilnuj_odmowy), więc podgląd
			// pokazuje od razu to, co naprawdę się zapisze. Podgląd, który
			// pokazuje pusty przycisk, uczy klienta nieprawdy o własnym banerze.
			cel.textContent = pole.value.trim() || domyslne[ klucz ];
		} );
	} );

	/* ---------- Układ na żywo ---------- */

	var pozycje = ( window.ncPanel && window.ncPanel.pozycje ) || {};
	var rozmiary = ( window.ncPanel && window.ncPanel.rozmiary ) || {};
	var urzadzenie = 'desktop';

	function czytaj( dla, co ) {
		var pole = panel.querySelector( '[data-nc-wyglad="' + co + '"][data-nc-dla="' + dla + '"]' );
		return pole ? pole.value : '';
	}

	function odswiez() {
		var p = pozycje[ czytaj( urzadzenie, 'pozycja' ) ];
		if ( p ) {
			podglad.style.setProperty( '--nc-align', p.align );
			podglad.style.setProperty( '--nc-just', p.just );
		}

		var szer = parseFloat( czytaj( urzadzenie, 'szerokosc' ) );
		if ( ! isNaN( szer ) ) {
			podglad.style.setProperty( '--nc-szer', szer + 'rem' );
		}

		var r = rozmiary[ czytaj( urzadzenie, 'tekst' ) ];
		if ( r && r.fs ) {
			podglad.style.setProperty( '--nc-fs', r.fs );
		} else {
			podglad.style.removeProperty( '--nc-fs' );
		}

		// Klatka zwęża się do szerokości telefonu, żeby „na całą szerokość"
		// znaczyło w podglądzie to samo, co na telefonie. Bez tego dwa układy
		// wyglądałyby identycznie i cały podział na urządzenia byłby na słowo.
		podglad.style.width = 'mobile' === urzadzenie ? '22rem' : '100%';
	}

	Array.prototype.forEach.call( panel.querySelectorAll( '[data-nc-wyglad]' ), function ( pole ) {
		pole.addEventListener( 'input', function () {
			// Zmiana pola drugiego urządzenia przełącza podgląd na nie —
			// inaczej klient kręci suwakiem i nic się nie rusza.
			urzadzenie = pole.getAttribute( 'data-nc-dla' );
			ustawPrzycisk();
			odswiez();
		} );
	} );

	var przyciski = Array.prototype.slice.call( panel.querySelectorAll( '[data-nc-urzadzenie]' ) );

	function ustawPrzycisk() {
		przyciski.forEach( function ( b ) {
			b.classList.toggle( 'is-active', b.getAttribute( 'data-nc-urzadzenie' ) === urzadzenie );
		} );
	}

	przyciski.forEach( function ( b ) {
		b.addEventListener( 'click', function () {
			urzadzenie = b.getAttribute( 'data-nc-urzadzenie' );
			ustawPrzycisk();
			odswiez();
		} );
	} );

	ustawPrzycisk();
	odswiez();

	/* ---------- Skaner ---------- */

	/*
	 * Dwa kroki: serwer (jedno żądanie REST, zwraca listę podstron), potem każda podstrona
	 * w ukrytej ramce. Ramka włącza w baner.js tryb skanu, zbiera ciasteczka po pełnej zgodzie
	 * i odsyła je tutaj przez postMessage; stąd trafiają do REST. Podstrony po kolei, nie
	 * naraz: dziesięć ramek z pełnym zestawem pikseli naraz potrafi zamrozić słabszy laptop.
	 */
	function skaner() {
		var przycisk = document.getElementById( 'nc-skaner' );
		if ( ! przycisk ) {
			return;
		}
		var postep = document.getElementById( 'nc-skaner-postep' );
		var ramki = document.getElementById( 'nc-skaner-ramki' );
		var rest = przycisk.getAttribute( 'data-nc-rest' );
		var nonce = przycisk.getAttribute( 'data-nc-nonce' );

		function wyslij( sciezka, dane ) {
			return fetch( rest + sciezka, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
				body: JSON.stringify( dane || {} )
			} ).then( function ( r ) {
				if ( ! r.ok ) { throw new Error( 'HTTP ' + r.status ); }
				return r.json();
			} );
		}

		function jednaStrona( adres ) {
			return new Promise( function ( gotowe ) {
				var ramka = document.createElement( 'iframe' );
				var koniec = setTimeout( function () { sprzatnij(); gotowe( false ); }, 20000 );
				function odbierz( e ) {
					if ( e.origin !== location.origin || ! e.data || ! e.data.ncSkan || e.source !== ramka.contentWindow ) {
						return;
					}
					sprzatnij();
					wyslij( 'skan-przegladarka', { adres: adres, ciasteczka: e.data.ciasteczka, storage: e.data.storage, hosty: e.data.hosty, zalogowany: !! e.data.zalogowany } )
						.then( function () { gotowe( true ); }, function () { gotowe( false ); } );
				}
				function sprzatnij() {
					clearTimeout( koniec );
					window.removeEventListener( 'message', odbierz );
					if ( ramka.parentNode ) { ramka.parentNode.removeChild( ramka ); }
				}
				window.addEventListener( 'message', odbierz );
				ramka.src = adres;
				ramka.setAttribute( 'tabindex', '-1' );
				ramka.title = 'Skan';
				ramki.appendChild( ramka );
			} );
		}

		przycisk.addEventListener( 'click', function () {
			przycisk.disabled = true;
			postep.textContent = 'Zbieram listę podstron…';
			wyslij( 'skanuj-strone', { tylko_lista: true } ).then( function ( wynik ) {
				var adresy = ( wynik && wynik.adresy ) || [];
				var i = 0;
				var nieudane = 0;
				function nastepna() {
					if ( i >= adresy.length ) {
						postep.textContent = 'Gotowe' + ( nieudane ? ' (' + nieudane + ' podstron bez odpowiedzi z przeglądarki)' : '' ) + '. Odświeżam wynik…';
						window.location.hash = 'nc-skan';
						window.location.reload();
						return;
					}
					postep.textContent = 'Podstrona ' + ( i + 1 ) + ' z ' + adresy.length + ': ' + adresy[ i ];
					// Najpierw serwer (jak nowy gość), potem ramka z pełną zgodą.
					wyslij( 'skanuj-strone', { adresy: [ adresy[ i ] ], dopisz: i > 0 } )
						.then( function () { return jednaStrona( adresy[ i ] ); } )
						.then( function ( ok ) {
							if ( ! ok ) { nieudane++; }
							i++;
							nastepna();
						}, function () {
							nieudane++;
							i++;
							nastepna();
						} );
				}
				nastepna();
			} ).catch( function ( e ) {
				postep.textContent = 'Skan się nie udał: ' + e.message;
				przycisk.disabled = false;
			} );
		} );
	}
} )();
