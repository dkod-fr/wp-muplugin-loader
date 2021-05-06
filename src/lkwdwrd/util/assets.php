<?php
/**
 * Methods used to ensure composer-based mu-plugins are always treated like normal mu-plugins.
 *
 * @license MIT
 * @copyright Luke Woodward
 * @package WP_MUPlugin_Loader
 */
namespace LkWdwrd\MU_Loader\Assets;

/**
 * Make sure symlinked mu-plugins can use `plugins_url` like physical plugins can.
 *
 * @param string $url    The full URL to the plugin asset.
 * @param string $path   The relative path to the asset from the plugin location.
 * @param string $plugin The absolute path to the file requesting the asset URL.
 * @return string        The full URL to the plugin asset.
 */
function plugins_url( $url, $path, $plugin ) {
	// If the relative file is within a known directory, return the URL as usual.
	if ( strstr( $plugin, WPMU_PLUGIN_DIR ) || strstr( $plugin, WP_PLUGIN_DIR ) ) {
		return $url;
	}

	// Extract the relative path from the URL for comparison.
	$relative_path = str_replace( WP_PLUGIN_URL, '', $url );

	// Only modify the URL if the path is to an existing mu plugin with the file existing.
	if ( file_exists( WPMU_PLUGIN_DIR . $relative_path ) ) {
		$url = WPMU_PLUGIN_URL . $relative_path;
	}

	return $url;
}

/**
 * Filter URLs created by `plugins_url` to support symlinked content.
 */
add_filter(
	'plugins_url',
	__NAMESPACE__ . '\\plugins_url',
	10,
	3
);
