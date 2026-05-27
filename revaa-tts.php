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
 * @package Revaa_TTS
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Plugin constants.
define( 'REVAA_TTS_VERSION', '1.0.0' );
define( 'REVAA_TTS_DIR', plugin_dir_path( __FILE__ ) );
define( 'REVAA_TTS_URL', plugin_dir_url( __FILE__ ) );

// Load the main class.
require_once REVAA_TTS_DIR . 'includes/class-revaa-tts.php';

// Instantiate the plugin after all plugins are loaded.
add_action( 'plugins_loaded', function () {
	$plugin = new Revaa_TTS();
	$plugin->init();
} );
