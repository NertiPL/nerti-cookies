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
		if ( podglad ) {
			podglad.parentNode.hidden = ( 'stan' === id || 'rejestr' === id );
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

		// Po zapisie WordPress wraca na ten sam adres — kotwica trzyma zakładkę,
		// żeby klient nie lądował za każdym razem na „Stan".
		var zHasza = window.location.hash.replace( /^#nc-/, '' );
		if ( ! zHasza || ! pokaz( zHasza ) ) {
			pokaz( taby[ 0 ].getAttribute( 'data-nc-tab' ) );
		}
	}

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
} )();
