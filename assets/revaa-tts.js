/**
 * Revaa TTS — Web Speech API player
 *
 * Ce script est enqueué uniquement sur les pages de leçon LifterLMS
 * (voir includes/class-revaa-tts.php → enqueue_assets()).
 *
 * Il s'appuie exclusivement sur l'API Web Speech native du navigateur :
 *   - window.speechSynthesis    : interface principale de synthèse vocale.
 *   - SpeechSynthesisUtterance  : représente un énoncé à lire.
 *
 * Aucune dépendance externe (pas de jQuery, pas de bibliothèque tierce).
 * Compatible WordPress 6.0+, navigateurs modernes (voir README).
 *
 * @package Revaa_TTS
 */

( function () {
	'use strict';

	/* ------------------------------------------------------------------ */
	/* 0. Attendre que le DOM soit entièrement chargé                      */
	/*    On utilise DOMContentLoaded plutôt que window.load pour démarrer */
	/*    dès que le HTML est parsé, sans attendre images et CSS.          */
	/* ------------------------------------------------------------------ */
	document.addEventListener( 'DOMContentLoaded', function () {

		// Récupère le conteneur du widget injecté par le PHP.
		const widget = document.getElementById( 'revaa-tts-widget' );

		// Si le widget n'existe pas sur cette page, on ne fait rien.
		if ( ! widget ) {
			return;
		}

		/* ---------------------------------------------------------------- */
		/* 1. Vérification du support navigateur                            */
		/*    L'API Web Speech n'est pas disponible dans tous les           */
		/*    navigateurs (ex. : Opera Mini, certains navigateurs mobiles   */
		/*    rares). On vérifie sa présence avant toute opération.         */
		/* ---------------------------------------------------------------- */
		if ( ! ( 'speechSynthesis' in window ) ) {
			// Masquer le widget pour ne pas afficher des boutons non fonctionnels.
			widget.style.display = 'none';

			// Afficher un message explicite invitant à changer de navigateur.
			const msg       = document.createElement( 'p' );
			msg.className   = 'revaa-tts-unsupported';
			msg.innerHTML   =
				'🔇 <strong>Lecteur audio non supporté par ce navigateur.</strong><br>' +
				'Pour profiter de la lecture vocale, veuillez utiliser un navigateur moderne compatible : ' +
				'<strong>Google Chrome</strong>, <strong>Microsoft Edge</strong>, ' +
				'<strong>Safari</strong> (macOS / iOS) ou <strong>Firefox</strong>.';

			// Insérer le message avant le widget dans le DOM.
			widget.parentNode.insertBefore( msg, widget );
			return;
		}

		/* ---------------------------------------------------------------- */
		/* 2. Références aux éléments DOM du widget                         */
		/* ---------------------------------------------------------------- */
		const synth       = window.speechSynthesis; // Interface SpeechSynthesis.
		const btnPlay     = document.getElementById( 'revaa-tts-play' );
		const btnStop     = document.getElementById( 'revaa-tts-stop' );
		const selSpeed    = document.getElementById( 'revaa-tts-speed' );
		const selVoice    = document.getElementById( 'revaa-tts-voice' );
		const progressBar = document.getElementById( 'revaa-tts-progress' );

		// Libellés traduits passés depuis PHP via wp_localize_script.
		// Fallback en français si l'objet n'existe pas (dev / test hors WP).
		const strings = ( typeof revaa_tts_strings !== 'undefined' )
			? revaa_tts_strings
			: { play: 'Lire', pause: 'Pause', stop: 'Arrêter', speed: 'Vitesse', voice: 'Voix' };

		/* ---------------------------------------------------------------- */
		/* 3. Extraction du texte de la leçon                               */
		/*                                                                   */
		/*    On cherche le conteneur principal du contenu pédagogique en   */
		/*    testant plusieurs sélecteurs dans l'ordre de priorité.        */
		/*    Ensuite, on clone le nœud pour supprimer les éléments         */
		/*    indésirables (navigation, widget TTS lui-même…) sans altérer  */
		/*    le DOM visible de la page.                                     */
		/* ---------------------------------------------------------------- */

		/**
		 * Extrait et retourne le texte lisible de la leçon.
		 *
		 * @returns {string} Texte brut à synthétiser.
		 */
		function extractLessonText() {
			// Sélecteurs testés dans l'ordre de priorité.
			const candidates = [
				'.entry-content',                   // Thème classique WordPress.
				'.llms-lesson-content',             // Conteneur spécifique LifterLMS.
				'article .wp-block-post-content',   // Éditeur de blocs (FSE).
				'main',                             // Fallback générique.
			];

			let container = null;
			for ( const sel of candidates ) {
				container = document.querySelector( sel );
				if ( container ) {
					break;
				}
			}

			// Dernier recours : utiliser tout le body.
			if ( ! container ) {
				return document.body.innerText;
			}

			// Clone profond : on peut supprimer des nœuds sans toucher au DOM réel.
			const clone = container.cloneNode( true );

			// Éléments à exclure de la lecture (navigation, chrome du site, widget).
			const excludeSelectors = [
				'#revaa-tts-widget',        // Le widget lui-même.
				'nav',                      // Menus de navigation.
				'header',                   // En-têtes.
				'footer',                   // Pieds de page.
				'.llms-course-navigation',  // Navigation LifterLMS leçon suivante/précédente.
			];
			excludeSelectors.forEach( function ( sel ) {
				clone.querySelectorAll( sel ).forEach( function ( el ) {
					el.remove();
				} );
			} );

			// innerText respecte le rendu CSS (ignore display:none).
			// On revient sur textContent si innerText n'est pas disponible.
			return clone.innerText || clone.textContent || '';
		}

		/* ---------------------------------------------------------------- */
		/* 4. Gestion de la liste des voix                                  */
		/*                                                                   */
		/*    speechSynthesis.getVoices() retourne un tableau de            */
		/*    SpeechSynthesisVoice. Dans Chrome, ce tableau est vide au     */
		/*    premier appel synchrone : il faut écouter l'événement         */
		/*    `voiceschanged` pour être notifié du chargement asynchrone.   */
		/* ---------------------------------------------------------------- */

		/**
		 * Peuple le <select> des voix.
		 * Les voix françaises apparaissent en premier dans leur propre optgroup.
		 * Les autres voix sont groupées séparément.
		 */
		function populateVoices() {
			const voices = synth.getVoices();

			// Vider les options existantes avant de re-peupler.
			selVoice.innerHTML = '';

			if ( voices.length === 0 ) {
				// Cas rare : aucune voix disponible sur ce système/navigateur.
				const opt       = document.createElement( 'option' );
				opt.textContent = 'Aucune voix disponible';
				opt.disabled    = true;
				selVoice.appendChild( opt );
				selVoice.disabled = true;
				return;
			}

			selVoice.disabled = false;

			// Sépare les voix françaises des autres.
			const frVoices    = voices.filter( v => v.lang.startsWith( 'fr' ) );
			const otherVoices = voices.filter( v => ! v.lang.startsWith( 'fr' ) );

			// Groupe des voix françaises (affiché en premier).
			if ( frVoices.length > 0 ) {
				const grpFr = document.createElement( 'optgroup' );
				grpFr.label = '🇫🇷 Voix françaises';
				frVoices.forEach( function ( v ) {
					grpFr.appendChild( makeVoiceOption( v ) );
				} );
				selVoice.appendChild( grpFr );
			}

			// Groupe des autres langues.
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
		 * Crée un élément <option> pour une SpeechSynthesisVoice.
		 *
		 * @param   {SpeechSynthesisVoice} voice  La voix à représenter.
		 * @returns {HTMLOptionElement}
		 */
		function makeVoiceOption( voice ) {
			const opt       = document.createElement( 'option' );
			opt.value       = voice.name; // La `value` sert à retrouver la voix plus tard.
			opt.textContent = voice.name + ' (' + voice.lang + ')';
			return opt;
		}

		// Premier appel synchrone (peut être vide dans Chrome).
		populateVoices();

		// Écouter le chargement asynchrone des voix (surtout Chrome).
		if ( synth.onvoiceschanged !== undefined ) {
			synth.onvoiceschanged = populateVoices;
		}

		/* ---------------------------------------------------------------- */
		/* 5. État interne du lecteur                                        */
		/* ---------------------------------------------------------------- */
		let utterance   = null;  // SpeechSynthesisUtterance en cours.
		let isPaused    = false; // true si la lecture est en pause.
		let charIndex   = 0;     // Index de caractère courant (événement boundary).
		let totalLength = 0;     // Longueur totale du texte (pour le % de progression).
		let keepAlive   = null;  // ID du setInterval du workaround Chrome.

		/* ---------------------------------------------------------------- */
		/* 6. Workaround Chrome — keep-alive                                */
		/*                                                                   */
		/*    Chrome annule silencieusement la synthèse après ~15 s sur     */
		/*    les textes longs. La solution consiste à appeler               */
		/*    speechSynthesis.pause() puis .resume() toutes les 10 s pour   */
		/*    réinitialiser le timer interne du navigateur sans interruption */
		/*    perceptible pour l'utilisateur.                                */
		/* ---------------------------------------------------------------- */

		/** Démarre (ou redémarre) l'intervalle keep-alive. */
		function startKeepAlive() {
			stopKeepAlive(); // Toujours nettoyer avant d'en créer un nouveau.
			keepAlive = setInterval( function () {
				// N'agit que si la synthèse est en cours et non en pause manuelle.
				if ( synth.speaking && ! isPaused ) {
					synth.pause();
					synth.resume();
				}
			}, 10000 ); // Toutes les 10 secondes.
		}

		/** Arrête l'intervalle keep-alive et libère la référence. */
		function stopKeepAlive() {
			if ( keepAlive !== null ) {
				clearInterval( keepAlive );
				keepAlive = null;
			}
		}

		/* ---------------------------------------------------------------- */
		/* 7. Remise à zéro de l'interface                                  */
		/*    Appelée à la fin naturelle de la lecture ET lors d'un Stop.   */
		/* ---------------------------------------------------------------- */

		/** Remet le bouton Play et la barre de progression dans leur état initial. */
		function resetPlayer() {
			isPaused              = false;
			charIndex             = 0;
			// Icône ▶ + libellé traduit.
			btnPlay.innerHTML     = '&#9654; ' + strings.play;
			btnPlay.setAttribute( 'aria-label', strings.play );
			progressBar.value     = 0;
			stopKeepAlive();
		}

		/* ---------------------------------------------------------------- */
		/* 8. Démarrage de la synthèse                                      */
		/* ---------------------------------------------------------------- */

		/**
		 * Crée un SpeechSynthesisUtterance, configure la voix et la vitesse
		 * choisies, branche les événements, puis appelle synth.speak().
		 *
		 * @param {string} text  Texte à lire.
		 */
		function startSpeaking( text ) {
			synth.cancel(); // Stoppe toute lecture précédente (sécurité).

			utterance   = new SpeechSynthesisUtterance( text );
			totalLength = text.length;
			charIndex   = 0;

			// Applique la vitesse sélectionnée dans le <select>.
			utterance.rate = parseFloat( selSpeed.value ) || 1;

			// Cherche la voix correspondant au nom sélectionné dans le <select>.
			const voices    = synth.getVoices();
			const voiceName = selVoice.value;
			const chosen    = voices.find( v => v.name === voiceName );

			if ( chosen ) {
				// Voix explicitement choisie par l'utilisateur.
				utterance.voice = chosen;
				utterance.lang  = chosen.lang;
			} else if ( voices.length > 0 ) {
				// Aucune voix sélectionnée : on prend la première voix française,
				// ou la première voix disponible si aucune voix française n'existe.
				const fallback  = voices.find( v => v.lang.startsWith( 'fr' ) ) || voices[ 0 ];
				utterance.voice = fallback;
				utterance.lang  = fallback.lang;
			}
			// Si voices.length === 0, l'API utilisera la voix système par défaut.

			/* -- Événement boundary : mis à jour à chaque mot / phrase ---
			 * `e.charIndex` indique la position dans le texte.
			 * On calcule un pourcentage pour la barre de progression.
			 */
			utterance.addEventListener( 'boundary', function ( e ) {
				if ( e.name === 'word' || e.name === 'sentence' ) {
					charIndex = e.charIndex;
					if ( totalLength > 0 ) {
						progressBar.value = Math.round( ( charIndex / totalLength ) * 100 );
					}
				}
			} );

			/* -- Fin naturelle de la lecture ----------------------------- */
			utterance.addEventListener( 'end', function () {
				resetPlayer();
			} );

			/* -- Erreur de synthèse -------------------------------------- */
			utterance.addEventListener( 'error', function ( e ) {
				// L'erreur 'interrupted' se déclenche lors d'un cancel manuel :
				// ce n'est pas une vraie erreur, on l'ignore silencieusement.
				if ( e.error !== 'interrupted' ) {
					console.warn( 'Revaa TTS — erreur synthèse vocale :', e.error );
				}
				resetPlayer();
			} );

			// Lance la synthèse vocale.
			synth.speak( utterance );

			// Démarre le keep-alive pour contourner le bug Chrome.
			startKeepAlive();
		}

		/* ---------------------------------------------------------------- */
		/* 9. Bouton Play / Pause                                           */
		/*                                                                   */
		/*    Ce bouton joue un triple rôle selon l'état courant :          */
		/*    - Inactif  → démarre la lecture.                              */
		/*    - En cours → met en pause.                                    */
		/*    - En pause → reprend la lecture.                              */
		/* ---------------------------------------------------------------- */
		btnPlay.addEventListener( 'click', function () {

			if ( synth.speaking ) {
				if ( isPaused ) {
					/* ---- Reprise après pause ---- */
					synth.resume();
					isPaused = false;
					// Icône ⏸ + libellé "Pause".
					btnPlay.innerHTML = '&#9646;&#9646; ' + strings.pause;
					btnPlay.setAttribute( 'aria-label', strings.pause );
					startKeepAlive(); // Reprend le keep-alive suspendu.

				} else {
					/* ---- Mise en pause ---- */
					synth.pause();
					isPaused = true;
					stopKeepAlive(); // Inutile de tourner pendant la pause.
					// Retour à l'icône ▶ + libellé "Lire".
					btnPlay.innerHTML = '&#9654; ' + strings.play;
					btnPlay.setAttribute( 'aria-label', strings.play );
				}

			} else {
				/* ---- Démarrage d'une nouvelle lecture ---- */
				const text = extractLessonText().trim();

				if ( ! text ) {
					// Aucun texte trouvé : ne rien faire (cas dégradé très rare).
					console.warn( 'Revaa TTS : aucun texte trouvé dans la leçon.' );
					return;
				}

				isPaused = false;
				// Icône ⏸ + libellé "Pause" (on passe immédiatement en état "lecture").
				btnPlay.innerHTML = '&#9646;&#9646; ' + strings.pause;
				btnPlay.setAttribute( 'aria-label', strings.pause );
				startSpeaking( text );
			}
		} );

		/* ---------------------------------------------------------------- */
		/* 10. Bouton Stop                                                   */
		/*     Annule la synthèse en cours et remet tout à zéro.            */
		/* ---------------------------------------------------------------- */
		btnStop.addEventListener( 'click', function () {
			synth.cancel(); // Interrompt la synthèse (déclenche l'événement 'error' avec e.error = 'interrupted').
			resetPlayer();
		} );

		/* ---------------------------------------------------------------- */
		/* 11. Changement de vitesse en cours de lecture                    */
		/*                                                                   */
		/*     La vitesse (utterance.rate) ne peut pas être modifiée sur    */
		/*     un SpeechSynthesisUtterance déjà en cours. Pour l'appliquer  */
		/*     immédiatement, on annule la lecture en cours et on redémarre  */
		/*     depuis la position approximative actuelle (charIndex).        */
		/* ---------------------------------------------------------------- */
		selSpeed.addEventListener( 'change', function () {
			if ( synth.speaking ) {
				// Récupère le texte complet et reprend à partir du dernier charIndex.
				const text      = extractLessonText().trim();
				const remaining = text.slice( charIndex ); // Portion non encore lue.
				synth.cancel();
				isPaused = false;
				startSpeaking( remaining );
			}
		} );

	} ); // fin DOMContentLoaded

} )(); // fin IIFE
