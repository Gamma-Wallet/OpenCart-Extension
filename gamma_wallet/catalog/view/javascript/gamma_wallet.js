/*
 * Gamma Wallet for OpenCart — the customer's page.
 *
 * Asks this shop (never Gamma) every 5 seconds whether the customer has finished in Gamma Wallet:
 * - reward box: until the reward is collected;
 * - store-credit box: until the order is settled (then opens the order confirmation) or the code
 *   expires (then offers a new code). Shows the countdown meanwhile.
 */
( function () {
	'use strict';

	var text = window.gammaWalletText || {};
	var POLL_MS = 5000;
	var GIVE_UP_MS = 15 * 60 * 1000;

	function show( el, on ) {
		if ( el ) {
			el.hidden = ! on;
		}
	}

	function get( url ) {
		return fetch( url, { credentials: 'same-origin', headers: { Accept: 'application/json' } } ).then( function ( r ) {
			return r.json().then( function ( body ) {
				return { ok: r.ok, body: body };
			} );
		} );
	}

	function post( url ) {
		return fetch( url, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json' } } ).then( function ( r ) {
			return r.json().then( function ( body ) {
				return { ok: r.ok, body: body };
			} );
		} );
	}

	function rewardBox( box ) {
		var done = box.querySelector( '.gamma-wallet-done' );
		var started = Date.now();
		var timer;

		function tick() {
			if ( document.hidden ) {
				timer = setTimeout( tick, POLL_MS );
				return;
			}
			get( box.dataset.statusUrl )
				.then( function ( r ) {
					if ( r.ok && r.body.status === 'Claimed' ) {
						done.textContent = text.claimed || 'Reward collected.';
						show( done, true );
						box.querySelector( '.gw-card' ).classList.add( 'gw-is-done' );
						return;
					}
					if ( Date.now() - started < GIVE_UP_MS ) {
						timer = setTimeout( tick, POLL_MS );
					}
				} )
				.catch( function () {
					timer = setTimeout( tick, POLL_MS * 2 );
				} );
		}
		timer = setTimeout( tick, POLL_MS );
		window.addEventListener( 'pagehide', function () {
			clearTimeout( timer );
		} );
	}

	function creditBox( box ) {
		var img = box.querySelector( '.gamma-wallet-qr-img' );
		var open = box.querySelector( '.gamma-wallet-open' );
		var countdown = box.querySelector( '.gamma-wallet-countdown' );
		var newCode = box.querySelector( '.gamma-wallet-new-code' );
		var done = box.querySelector( '.gamma-wallet-done' );
		var card = box.querySelector( '.gw-card' );
		var timer = box.querySelector( '.gw-timer' );
		var bar = box.querySelector( '.gw-timer-bar' );
		var secondsLeft = parseInt( box.dataset.secondsLeft, 10 ) || 0;
		// The bar is measured against the code's own validity (60 s on Gamma, longer on a test setup).
		var totalSeconds = Math.max( 60, secondsLeft );
		var finished = false;
		var pollTimer, clockTimer;

		function renderClock() {
			if ( bar ) {
				bar.style.width = Math.max( 0, Math.min( 100, ( secondsLeft / totalSeconds ) * 100 ) ) + '%';
				timer.classList.toggle( 'gw-low', secondsLeft <= 15 );
			}
			show( timer, secondsLeft > 0 );
			if ( secondsLeft > 0 ) {
				countdown.textContent = secondsLeft >= 60
					? ( text.timeLeft || '%s left' ).replace( '%s', Math.floor( secondsLeft / 60 ) + ':' + String( secondsLeft % 60 ).padStart( 2, '0' ) )
					: ( text.secondsLeft || '%d s left' ).replace( '%d', secondsLeft );
			} else {
				// The code may still be settled for a few seconds; the status decides.
				countdown.textContent = '';
				show( img, false );
			}
		}

		function startClock() {
			clearInterval( clockTimer );
			renderClock();
			clockTimer = setInterval( function () {
				secondsLeft = Math.max( 0, secondsLeft - 1 );
				renderClock();
			}, 1000 );
		}

		function expired() {
			clearInterval( clockTimer );
			show( img, false );
			show( open, false );
			countdown.textContent = text.expired || 'This code has expired.';
			show( newCode, true );
		}

		function settled( redirect ) {
			finished = true;
			clearInterval( clockTimer );
			clearTimeout( pollTimer );
			show( img, false );
			show( open, false );
			show( newCode, false );
			countdown.textContent = '';
			done.textContent = text.settled || 'Done!';
			show( done, true );
			card.classList.add( 'gw-is-done' );
			if ( redirect ) {
				setTimeout( function () {
					window.location.href = redirect;
				}, 1500 );
			}
		}

		function poll() {
			if ( finished ) {
				return;
			}
			get( box.dataset.statusUrl )
				.then( function ( r ) {
					var status = r.ok ? r.body.status : 'Unknown';
					if ( status === 'Paid' ) {
						settled( r.body.redirect );
						return;
					}
					if ( status === 'Expired' ) {
						expired();
						return; // Waits for the customer to ask for a new code.
					}
					if ( typeof r.body.secondsLeft === 'number' ) {
						secondsLeft = r.body.secondsLeft;
					}
					pollTimer = setTimeout( poll, POLL_MS );
				} )
				.catch( function () {
					pollTimer = setTimeout( poll, POLL_MS );
				} );
		}

		newCode.addEventListener( 'click', function () {
			newCode.disabled = true;
			post( box.dataset.newCodeUrl )
				.then( function ( r ) {
					newCode.disabled = false;
					if ( r.ok && r.body.status === 'Paid' ) {
						settled( r.body.redirect );
						return;
					}
					if ( ! r.ok || ! r.body.qr ) {
						countdown.textContent = text.unavailable || 'Please try again in a moment.';
						return;
					}
					img.src = r.body.qr;
					open.href = r.body.link;
					secondsLeft = r.body.secondsLeft || 60;
					totalSeconds = Math.max( 60, secondsLeft );
					show( img, true );
					show( open, true );
					show( newCode, false );
					startClock();
					clearTimeout( pollTimer );
					pollTimer = setTimeout( poll, POLL_MS );
				} )
				.catch( function () {
					newCode.disabled = false;
					countdown.textContent = text.unavailable || 'Please try again in a moment.';
				} );
		} );

		startClock();
		pollTimer = setTimeout( poll, secondsLeft > 0 ? POLL_MS : 0 );
	}

	document.querySelectorAll( '.gamma-wallet-box' ).forEach( function ( box ) {
		if ( box.dataset.poll !== '1' ) {
			return;
		}
		if ( box.dataset.kind === 'credit' ) {
			creditBox( box );
		} else {
			rewardBox( box );
		}
	} );
} )();
