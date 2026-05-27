<?php
/**
 * Classe principale du plugin Revaa TTS.
 *
 * Responsabilités :
 *  - Vérifier que LifterLMS est actif avant d'enregistrer les hooks.
 *  - Afficher une notice d'administration si LifterLMS est manquant.
 *  - Enqueue le CSS et le JS uniquement sur les pages de leçon LifterLMS.
 *  - Injecter le HTML du widget TTS dans le contenu de la leçon.
 *
 * Ce fichier est chargé par revaa-tts.php via require_once.
 *
 * @package Revaa_TTS
 */

// Sécurité : empêcher l'accès direct au fichier PHP hors contexte WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Revaa_TTS
 *
 * Toute la logique du plugin est encapsulée dans cette classe pour éviter
 * les conflits avec d'autres plugins ou thèmes (pas de variables globales,
 * pas de fonctions au niveau racine).
 */
class Revaa_TTS {

	/**
	 * Point d'entrée : enregistre tous les hooks WordPress.
	 *
	 * Appelé depuis le hook `plugins_loaded` défini dans revaa-tts.php.
	 * Si LifterLMS n'est pas actif, on ajoute uniquement une notice admin
	 * et on sort immédiatement (le plugin reste inactif côté front-end).
	 *
	 * @return void
	 */
	public function init() {
		// Vérification de la dépendance LifterLMS.
		// class_exists('LifterLMS') retourne true uniquement si LifterLMS
		// est installé ET activé (car sa classe principale est chargée à ce stade).
		if ( ! class_exists( 'LifterLMS' ) ) {
			// Affiche une notice dans le tableau de bord WordPress.
			add_action( 'admin_notices', array( $this, 'admin_notice_lifterlms_missing' ) );
			return; // Stop — aucun hook front-end enregistré.
		}

		// Filtre `the_content` : injecte le widget dans le contenu de la leçon.
		// Priorité 10 (défaut), 1 argument (le contenu).
		add_filter( 'the_content', array( $this, 'inject_widget' ) );

		// Action `wp_enqueue_scripts` : charge CSS et JS sur les pages de leçon.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Affiche une notice dans l'administration quand LifterLMS est absent.
	 *
	 * La classe CSS WordPress `notice-warning` produit un bandeau jaune.
	 * `is-dismissible` ajoute un bouton × pour fermer la notice.
	 *
	 * @return void
	 */
	public function admin_notice_lifterlms_missing() {
		echo '<div class="notice notice-warning is-dismissible">'
			. '<p><strong>Revaa TTS :</strong> '
			. esc_html__( 'Ce plugin nécessite LifterLMS pour fonctionner.', 'revaa-tts' )
			. '</p></div>';
	}

	/**
	 * Enqueue le CSS et le JS du plugin.
	 *
	 * Exécuté sur l'action `wp_enqueue_scripts` (chargement front-end).
	 * On vérifie d'abord `is_singular('llms_lesson')` : si on n'est pas sur
	 * une leçon LifterLMS, on ne charge rien (performance).
	 *
	 * Le script est chargé avec `$in_footer = true` (dernier paramètre) pour
	 * s'assurer qu'il s'exécute après que le DOM de la leçon est construit.
	 *
	 * `wp_localize_script` injecte un objet JS `revaa_tts_strings` contenant
	 * les libellés traduits, ce qui permet une internationalisation complète
	 * sans chaînes en dur dans le JavaScript.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		// Charger les assets uniquement sur les pages de leçon LifterLMS.
		if ( ! is_singular( 'llms_lesson' ) ) {
			return;
		}

		// Feuille de style du widget TTS.
		wp_enqueue_style(
			'revaa-tts-style',              // Handle unique.
			REVAA_TTS_URL . 'assets/revaa-tts.css', // URL du fichier.
			array(),                        // Pas de dépendances CSS.
			REVAA_TTS_VERSION               // Versionnage pour cache-busting.
		);

		// Script du lecteur TTS (chargé dans le pied de page).
		wp_enqueue_script(
			'revaa-tts-script',              // Handle unique.
			REVAA_TTS_URL . 'assets/revaa-tts.js', // URL du fichier.
			array(),                         // Pas de dépendances JS (pas de jQuery).
			REVAA_TTS_VERSION,               // Versionnage pour cache-busting.
			true                             // Charger dans le footer (après le DOM).
		);

		// Passe les libellés traduits au JavaScript via un objet global.
		// Dans le JS, ces chaînes sont accessibles via `revaa_tts_strings.play`, etc.
		wp_localize_script(
			'revaa-tts-script',
			'revaa_tts_strings',
			array(
				'play'  => __( 'Lire', 'revaa-tts' ),
				'pause' => __( 'Pause', 'revaa-tts' ),
				'stop'  => __( 'Arrêter', 'revaa-tts' ),
				'speed' => __( 'Vitesse', 'revaa-tts' ),
				'voice' => __( 'Voix', 'revaa-tts' ),
			)
		);
	}

	/**
	 * Injecte le HTML du widget TTS dans le contenu de la leçon.
	 *
	 * Ce callback est attaché au filtre `the_content`.
	 * WordPress passe le contenu de l'article courant en paramètre ;
	 * on retourne le contenu modifié.
	 *
	 * Stratégie d'insertion :
	 *  1. On cherche la première occurrence de </h1> ou </h2>.
	 *  2. Si trouvée, on insère le widget juste après le tag fermant.
	 *  3. Si aucun titre n'est trouvé (ex. : contenu sans heading),
	 *     on préfixe le widget en tête du contenu (fallback).
	 *
	 * Un garde anti-doublon (`strpos` sur `id="revaa-tts-widget"`) empêche
	 * une éventuelle double injection si le filtre est appelé plusieurs fois.
	 *
	 * @param  string $content Contenu HTML brut de l'article.
	 * @return string Contenu HTML avec le widget inséré.
	 */
	public function inject_widget( $content ) {
		// Vérifier que l'on est bien sur une leçon LifterLMS en mode singular.
		if ( ! is_singular( 'llms_lesson' ) ) {
			return $content;
		}

		// Garde anti-doublon : si le widget est déjà présent, on ne ré-injecte pas.
		if ( false !== strpos( $content, 'id="revaa-tts-widget"' ) ) {
			return $content;
		}

		// Génère le HTML du widget.
		$widget = $this->get_widget_html();

		// Tente d'insérer après le premier </h1>, sinon après le premier </h2>.
		foreach ( array( '</h1>', '</h2>' ) as $tag ) {
			$pos = strpos( $content, $tag );
			if ( false !== $pos ) {
				// Position juste après le tag fermant.
				$insert_at = $pos + strlen( $tag );
				return substr( $content, 0, $insert_at )
					. $widget
					. substr( $content, $insert_at );
			}
		}

		// Fallback : aucun heading trouvé → on place le widget en tête de contenu.
		return $widget . $content;
	}

	/**
	 * Construit et retourne la chaîne HTML du widget TTS.
	 *
	 * On utilise ob_start() / ob_get_clean() pour profiter de la syntaxe
	 * HTML native (plus lisible que la concaténation de chaînes).
	 * Toutes les chaînes visibles passent par esc_html_e() / esc_attr_e()
	 * pour l'internationalisation et la sécurité XSS.
	 *
	 * Structure du widget :
	 *  - Bouton Play/Pause (id="revaa-tts-play")
	 *  - Bouton Stop       (id="revaa-tts-stop")
	 *  - Label + Select Vitesse (id="revaa-tts-speed")
	 *  - Label + Select Voix   (id="revaa-tts-voice") — peuplé dynamiquement en JS
	 *  - Barre de progression  (id="revaa-tts-progress") — mise à jour en JS
	 *
	 * @return string HTML du widget.
	 */
	private function get_widget_html() {
		ob_start();
		?>
<div id="revaa-tts-widget" role="region" aria-label="<?php esc_attr_e( 'Lecteur audio de la leçon', 'revaa-tts' ); ?>">
  <button id="revaa-tts-play" aria-label="<?php esc_attr_e( 'Lire', 'revaa-tts' ); ?>">&#9654; <?php esc_html_e( 'Lire', 'revaa-tts' ); ?></button>
  <button id="revaa-tts-stop" aria-label="<?php esc_attr_e( 'Arrêter', 'revaa-tts' ); ?>">&#9209;</button>
  <label for="revaa-tts-speed"><?php esc_html_e( 'Vitesse', 'revaa-tts' ); ?></label>
  <select id="revaa-tts-speed">
    <option value="0.75">0.75&times;</option>
    <option value="1" selected>1&times;</option>
    <option value="1.25">1.25&times;</option>
    <option value="1.5">1.5&times;</option>
    <option value="2">2&times;</option>
  </select>
  <label for="revaa-tts-voice"><?php esc_html_e( 'Voix', 'revaa-tts' ); ?></label>
  <select id="revaa-tts-voice"></select><!-- Peuplé dynamiquement par revaa-tts.js -->
  <progress id="revaa-tts-progress" value="0" max="100" aria-label="<?php esc_attr_e( 'Progression de la lecture', 'revaa-tts' ); ?>"></progress>
</div>
		<?php
		return ob_get_clean();
	}
}
