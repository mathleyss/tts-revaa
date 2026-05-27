/**
 * Revaa TTS — Web Speech API player
 *
 * Vanilla JS — no jQuery dependency.
 * Attaches play/pause/stop controls, voice selector and speed selector
 * to the #revaa-tts-widget injected by the PHP class.
 *
 * @package Revaa_TTS
 */

( function () {
	'use strict';

	/* ------------------------------------------------------------------ */
	/* 0. Wait for DOM ready                                                */
	/* ------------------------------------------------------------------ */
	document.addEventListener( 'DOMContentLoaded', function () {
		const widget = document.getElementById( 'revaa-tts-widget' );

		// Nothing to do if the widget is not on this page.
		if ( ! widget ) {
			return;
		}

		/* ---------------------------------------------------------------- */
		/* 1. Check browser support                                          */
		/* ---------------------------------------------------------------- */
		if ( ! ( 'speechSynthesis' in window ) ) {
			widget.style.display = 'none';

			const msg = document.createElement( 'p' );
			msg.className = 'revaa-tts-unsupported';
			msg.innerHTML =
				'🔇 <strong>Lecteur audio non supporté par ce navigateur.</strong><br>' +
				'Pour profiter de la lecture vocale, veuillez utiliser un navigateur moderne compatible : ' +
				'<strong>Google Chrome</strong>, <strong>Microsoft Edge</strong>, ' +
				'<strong>Safari</strong> (macOS / iOS) ou <strong>Firefox</strong>.';
			widget.parentNode.insertBefore( msg, widget );
			return;
		}

		/* ---------------------------------------------------------------- */
		/* 2. Grab UI elements                                               */
		/* ---------------------------------------------------------------- */
		const synth       = window.speechSynthesis;
		const btnPlay     = document.getElementById( 'revaa-tts-play' );
		const btnStop     = document.getElementById( 'revaa-tts-stop' );
		const selSpeed    = document.getElementById( 'revaa-tts-speed' );
		const selVoice    = document.getElementById( 'revaa-tts-voice' );
		const progressBar = document.getElementById( 'revaa-tts-progress' );

		// Translated strings passed from PHP via wp_localize_script.
		const strings = ( typeof revaa_tts_strings !== 'undefined' )
			? revaa_tts_strings
			: { play: 'Lire', pause: 'Pause', stop: 'Arrêter', speed: 'Vitesse', voice: 'Voix' };

		/* ---------------------------------------------------------------- */
		/* 3. Extract readable text from the lesson content                  */
		/* ---------------------------------------------------------------- */

		/**
		 * Return the text content of the lesson, excluding navigation
		 * and the TTS widget itself.
		 *
		 * @returns {string}
		 */
		function extractLessonText() {
			// Selectors to try, in priority order.
			const candidates = [
				'.entry-content',
				'.llms-lesson-content',
				'article .wp-block-post-content',
				'main',
			];

			let container = null;
			for ( const sel of candidates ) {
				container = document.querySelector( sel );
				if ( container ) {
					break;
				}
			}

			if ( ! container ) {
				return document.body.innerText;
			}

			// Clone the container so we can remove unwanted nodes without
			// altering the visible DOM.
			const clone = container.cloneNode( true );

			// Remove elements that should not be read aloud.
			const excludeSelectors = [
				'#revaa-tts-widget',
				'nav',
				'header',
				'footer',
				'.llms-course-navigation',
			];
			excludeSelectors.forEach( function ( sel ) {
				clone.querySelectorAll( sel ).forEach( function ( el ) {
					el.remove();
				} );
			} );

			return clone.innerText || clone.textContent || '';
		}

		/* ---------------------------------------------------------------- */
		/* 4. Voice list                                                     */
		/* ---------------------------------------------------------------- */

		/**
		 * Populate the <select id="revaa-tts-voice"> with available voices.
		 * French voices are listed first, others in a separate optgroup.
		 */
		function populateVoices() {
			const voices = synth.getVoices();

			// Clear existing options.
			selVoice.innerHTML = '';

			if ( voices.length === 0 ) {
				// Rare edge case: no voices available at all.
				const opt = document.createElement( 'option' );
				opt.textContent = 'Aucune voix disponible';
				opt.disabled    = true;
				selVoice.appendChild( opt );
				selVoice.disabled = true;
				return;
			}

			selVoice.disabled = false;

			const frVoices    = voices.filter( v => v.lang.startsWith( 'fr' ) );
			const otherVoices = voices.filter( v => ! v.lang.startsWith( 'fr' ) );

			// French voices (no optgroup label needed, shown first).
			if ( frVoices.length > 0 ) {
				const grpFr = document.createElement( 'optgroup' );
				grpFr.label = '🇫🇷 Voix françaises';
				frVoices.forEach( function ( v ) {
					grpFr.appendChild( makeVoiceOption( v ) );
				} );
				selVoice.appendChild( grpFr );
			}

			// Other languages.
			if ( otherVoices.length > 0 ) {
				const grpOther = document.createElement( 'optgroup' );
				grpOther.label = '🌐 Autres voix';
				otherVoices.forEach( function ( v ) {
					grpOther.appendChild( makeVoiceOption( v ) );
				} );
				selVoice.appendChild( grpOther );
			}
		}

		/**
		 * Create an <option> element for a SpeechSynthesisVoice.
		 *
		 * @param   {SpeechSynthesisVoice} voice
		 * @returns {HTMLOptionElement}
		 */
		function makeVoiceOption( voice ) {
			const opt   = document.createElement( 'option' );
			opt.value   = voice.name;
			opt.textContent = voice.name + ' (' + voice.lang + ')';
			return opt;
		}

		// Voices may load asynchronously (especially in Chrome).
		populateVoices();
		if ( synth.onvoiceschanged !== undefined ) {
			synth.onvoiceschanged = populateVoices;
		}

		/* ---------------------------------------------------------------- */
		/* 5. Player state                                                   */
		/* ---------------------------------------------------------------- */
		let utterance   = null;
		let isPaused    = false;
		let charIndex   = 0;      // tracks boundary position for progress
		let totalLength = 0;      // total text length for progress %
		let keepAlive   = null;   // Chrome keep-alive interval ID

		/* ---------------------------------------------------------------- */
		/* 6. Chrome keep-alive workaround                                  */
		/*                                                                   */
		/* Chrome silently cancels synthesis after ~15 s on long texts.     */
		/* Pausing and immediately resuming every 10 s resets the timer.    */
		/* ---------------------------------------------------------------- */
		function startKeepAlive() {
			stopKeepAlive();
			keepAlive = setInterval( function () {
				if ( synth.speaking && ! isPaused ) {
					synth.pause();
					synth.resume();
				}
			}, 10000 );
		}

		function stopKeepAlive() {
			if ( keepAlive !== null ) {
				clearInterval( keepAlive );
				keepAlive = null;
			}
		}

		/* ---------------------------------------------------------------- */
		/* 7. Reset UI to "Play" state                                      */
		/* ---------------------------------------------------------------- */
		function resetPlayer() {
			isPaused       = false;
			charIndex      = 0;
			btnPlay.innerHTML = '&#9654; ' + strings.play;
			btnPlay.setAttribute( 'aria-label', strings.play );
			progressBar.value = 0;
			stopKeepAlive();
		}

		/* ---------------------------------------------------------------- */
		/* 8. Build and speak an utterance                                  */
		/* ---------------------------------------------------------------- */
		function startSpeaking( text ) {
			synth.cancel(); // cancel any previous utterance

			utterance       = new SpeechSynthesisUtterance( text );
			totalLength     = text.length;
			charIndex       = 0;

			// Apply selected speed.
			utterance.rate  = parseFloat( selSpeed.value ) || 1;

			// Apply selected voice.
			const voices    = synth.getVoices();
			const voiceName = selVoice.value;
			const chosen    = voices.find( v => v.name === voiceName );
			if ( chosen ) {
				utterance.voice = chosen;
				utterance.lang  = chosen.lang;
			} else if ( voices.length > 0 ) {
				// Default to first French voice, or first available.
				const fallback = voices.find( v => v.lang.startsWith( 'fr' ) ) || voices[ 0 ];
				utterance.voice = fallback;
				utterance.lang  = fallback.lang;
			}

			/* Boundary event — update progress bar */
			utterance.addEventListener( 'boundary', function ( e ) {
				if ( e.name === 'word' || e.name === 'sentence' ) {
					charIndex = e.charIndex;
					if ( totalLength > 0 ) {
						progressBar.value = Math.round( ( charIndex / totalLength ) * 100 );
					}
				}
			} );

			/* End of speech */
			utterance.addEventListener( 'end', function () {
				resetPlayer();
			} );

			/* Error handler */
			utterance.addEventListener( 'error', function ( e ) {
				// 'interrupted' fires on manual cancel — not a real error.
				if ( e.error !== 'interrupted' ) {
					console.warn( 'Revaa TTS error:', e.error );
				}
				resetPlayer();
			} );

			synth.speak( utterance );
			startKeepAlive();
		}

		/* ---------------------------------------------------------------- */
		/* 9. Play / Pause button handler                                   */
		/* ---------------------------------------------------------------- */
		btnPlay.addEventListener( 'click', function () {
			if ( synth.speaking ) {
				if ( isPaused ) {
					// Resume from pause.
					synth.resume();
					isPaused = false;
					btnPlay.innerHTML = '&#9646;&#9646; ' + strings.pause;
					btnPlay.setAttribute( 'aria-label', strings.pause );
					startKeepAlive();
				} else {
					// Pause active speech.
					synth.pause();
					isPaused = true;
					stopKeepAlive();
					btnPlay.innerHTML = '&#9654; ' + strings.play;
					btnPlay.setAttribute( 'aria-label', strings.play );
				}
			} else {
				// Start fresh.
				const text = extractLessonText().trim();

				if ( ! text ) {
					console.warn( 'Revaa TTS: no text found to read.' );
					return;
				}

				isPaused = false;
				btnPlay.innerHTML = '&#9646;&#9646; ' + strings.pause;
				btnPlay.setAttribute( 'aria-label', strings.pause );
				startSpeaking( text );
			}
		} );

		/* ---------------------------------------------------------------- */
		/* 10. Stop button handler                                          */
		/* ---------------------------------------------------------------- */
		btnStop.addEventListener( 'click', function () {
			synth.cancel();
			resetPlayer();
		} );

		/* ---------------------------------------------------------------- */
		/* 11. Speed change — restart with new rate if already speaking     */
		/* ---------------------------------------------------------------- */
		selSpeed.addEventListener( 'change', function () {
			if ( synth.speaking ) {
				// Remember position to restart from current charIndex.
				const text  = extractLessonText().trim();
				const remaining = text.slice( charIndex );
				synth.cancel();
				isPaused = false;
				startSpeaking( remaining );
			}
		} );

	} );

} )();
