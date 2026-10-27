<?php
/**
 * Plugin Name:       TranslateWP
 * Plugin URI:        https://translatewp.ai/
 * Description:       Translate WordPress with AI, guide its wording with your Lexicon, and add a language switcher. Translations follow changes to your content.
 * Version:           1.0.3
 * Author:            Code Snippets
 * Author URI:        https://translatewp.ai/
 * License:           GPL-3.0+
 * License URI:       http://www.gnu.org/licenses/gpl-3.0.txt
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Text Domain:       translate
 * Domain Path:       /languages
 */

if ( ! defined( 'WPINC' ) ) {
    die( 'YOU SHALL NOT PASS!' );
}

// PLUGIN CONSTANTS.
define( 'TRANSLATEWP_NAME', 'translatewp' );
define( 'TRANSLATEWP_VERSION', '1.0.3' );
define( 'TRANSLATEWP_PATH', plugin_dir_path( __FILE__ ) );
define( 'TRANSLATEWP_BASENAME', plugin_basename( __FILE__ ) );
define( 'TRANSLATEWP_URL', plugin_dir_url( __FILE__ ) );
define( 'TRANSLATEWP_ASSETS_PATH', TRANSLATEWP_PATH . 'build/' );
define( 'TRANSLATEWP_ASSETS_URL', TRANSLATEWP_URL . 'build/' );

// AUTOLOAD.
if ( file_exists( TRANSLATEWP_PATH . 'vendor/autoload.php' ) ) {
    require_once TRANSLATEWP_PATH . 'vendor/autoload.php';
}

// LYFECYCLE.
register_activation_hook( __FILE__, [ TranslateWP\Includes\Lyfecycle::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ TranslateWP\Includes\Lyfecycle::class, 'deactivate' ] );
// Uninstall runs from uninstall.php, which does not boot the plugin.

// LOAD ALL FILES.
$loader = new TranslateWP\Includes\Loader();
