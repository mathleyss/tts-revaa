<?php
/**
 * Plugin Name: Revaa TTS
 * Plugin URI:  https://mathieu.leyssene.fr
 * Description: Lecteur text-to-speech personnalisé pour la plateforme de formation REVAA
 * Version:     1.0.0
 * Author:      Mathieu Leyssene
 * Author URI:  https://mathieu.leyssene.fr
 * Text Domain: revaa-tts
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * Ce fichier est le point d'entrée du plugin. WordPress le lit lors de
 * l'activation et à chaque chargement de page.
 *
 * Il se contente de :
 *  1. Bloquer l'accès direct (sécurité).
 *  2. Définir les constantes globales du plugin.
 *  3. Charger la classe principale.
 *  4. Instancier la classe via le hook `plugins_loaded`, ce qui garantit
 *     que tous les autres plugins (notamment LifterLMS) sont déjà chargés
 *     avant que l'on enregistre nos propres hooks.
 *
 * @package Revaa_TTS
 */

// Sécurité : empêcher l'accès direct au fichier PHP hors contexte WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * REVAA_TTS_VERSION — version courante du plugin.
 * Utilisée comme cache-buster sur les assets CSS / JS.
 */
define( 'REVAA_TTS_VERSION', '1.0.0' );

/**
 * REVAA_TTS_DIR — chemin absolu vers le répertoire du plugin (avec slash final).
 * Exemple : /var/www/html/wp-content/plugins/revaa-tts/
 */
define( 'REVAA_TTS_DIR', plugin_dir_path( __FILE__ ) );

/**
 * REVAA_TTS_URL — URL publique vers le répertoire du plugin (avec slash final).
 * Exemple : https://example.com/wp-content/plugins/revaa-tts/
 * Utilisée pour pointer vers les assets CSS / JS côté front-end.
 */
define( 'REVAA_TTS_URL', plugin_dir_url( __FILE__ ) );

// Charge le fichier de la classe principale (require_once = chargé une seule fois).
require_once REVAA_TTS_DIR . 'includes/class-revaa-tts.php';

/**
 * Hook `plugins_loaded` : déclenché après le chargement de tous les plugins actifs.
 *
 * On instancie Revaa_TTS ici (et non au niveau racine du fichier) pour être sûr
 * que LifterLMS — dont on vérifie l'existence dans init() — est déjà disponible.
 */
add_action( 'plugins_loaded', function () {
	$plugin = new Revaa_TTS();
	$plugin->init();
} );

// ============================================================
// MODE DEBUG — retirer ce bloc une fois le problème résolu
// Pour activer : définir REVAA_TTS_DEBUG à true ci-dessous.
// ============================================================
define( 'REVAA_TTS_DEBUG', true );

if ( defined( 'REVAA_TTS_DEBUG' ) && REVAA_TTS_DEBUG ) {

	// ── Diagnostic 1 : LifterLMS détecté ? (via plugins_loaded) ──────────
	add_action( 'plugins_loaded', function () {
		$lifterlms_ok = class_exists( 'LifterLMS' ) ? 'OUI' : 'NON ← PROBLÈME';
		error_log( '[REVAA TTS DEBUG] LifterLMS détecté : ' . $lifterlms_ok );
	}, 99 ); // priorité 99 pour s'exécuter après notre propre init().

	// ── Diagnostic 2 : post_type et is_singular en cours de page ─────────
	add_action( 'wp_footer', function () {
		$post_type    = get_post_type() ?: '(inconnu)';
		$is_singular  = is_singular( 'llms_lesson' ) ? 'OUI' : 'NON';
		$llms_class   = class_exists( 'LifterLMS' ) ? 'OUI' : 'NON';
		$filter_added = has_filter( 'the_content', array( 'Revaa_TTS', 'inject_widget' ) )
		                || ( has_filter( 'the_content' ) && false !== strpos( print_r( $GLOBALS['wp_filter']['the_content'] ?? [], true ), 'inject_widget' ) )
		                ? 'OUI' : 'NON';

		printf(
			"\n<!-- REVAA TTS DEBUG\n"
			. "  LifterLMS détecté  : %s\n"
			. "  post_type courant  : %s\n"
			. "  is_singular(llms_lesson) : %s\n"
			. "  Filtre the_content branché : %s\n"
			. "  Widget dans DOM   : %s\n"
			. "-->\n",
			esc_html( $llms_class ),
			esc_html( $post_type ),
			esc_html( $is_singular ),
			esc_html( $filter_added ),
			( is_singular( 'llms_lesson' ) && did_action( 'the_content' ) ) ? 'PEUT-ÊTRE (vérifier source HTML)' : 'NON'
		);
	} );

	// ── Diagnostic 3 : the_content est-il appelé sur une leçon ? ─────────
	add_filter( 'the_content', function ( $content ) {
		if ( is_singular( 'llms_lesson' ) ) {
			$snippet = substr( wp_strip_all_tags( $content ), 0, 80 );
			error_log( '[REVAA TTS DEBUG] the_content déclenché sur llms_lesson. Début : ' . $snippet );
		}
		return $content;
	}, 999 ); // priorité haute pour s'exécuter après notre inject_widget.

}
