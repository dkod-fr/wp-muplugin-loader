<?php

use PHPUnit\Framework\TestCase;
use LkWdwrd\MU_Loader\Assets;

class Assets_Tests extends TestCase {
	/**
	 * Name of the plugin in our tests.
	 */
    const PLUGIN_NAME = 'test-plugin';

	/**
	 * Include the necessary files.
	 */
	public function setUp(): void {
		require_once PROJECT . '/util/assets.php';

		define( 'SYMLINK_PARENT_DIR', __DIR__ . '/tmp/symlink' );
		define( 'WPMU_PLUGIN_DIR', __DIR__ . '/tmp/mu-plugins' );
		define( 'WP_PLUGIN_DIR', __DIR__ . '/tmp/plugins' );
		define( 'WPMU_PLUGIN_URL', 'https://example.com/mu-plugins' );
		define( 'WP_PLUGIN_URL', 'https://example.com/plugins' );

		parent::setUp();
	}

	/**
	 * Remove any directories that may have been created during our tests run.
	 */
	public function tearDown(): void {
		// Remove symbolic link.
		if ( file_exists( __DIR__ . '/tmp/mu-plugins/' . self::PLUGIN_NAME ) ) {
			unlink( __DIR__ . '/tmp/mu-plugins/' . self::PLUGIN_NAME );
		}

		// Remove physical file.
		if ( file_exists( __DIR__ . '/tmp/symlink/' . self::PLUGIN_NAME ) ) {
			rmdir( __DIR__ . '/tmp/symlink/' . self::PLUGIN_NAME );
		}

		parent::tearDown();
	}

	public function test_core_plugins_url_functions_as_expected(): void {
		WP_Mock::userFunction(
			'plugins_url', [
				'args' => [ '*', '*' ],
				'return' => function( $path = '', $plugin = '') {
					// Simplified version of core plugins_url().

					$mu_plugin_dir = WPMU_PLUGIN_DIR;

					if ( ! empty( $plugin ) && 0 === strpos( $plugin, $mu_plugin_dir ) ) {
						$url = WPMU_PLUGIN_URL;
					} else {
						$url = WP_PLUGIN_URL;
					}

					$url = trim( $url );
					if ( substr( $url, 0, 2 ) === '//' ) {
						$url = 'http:' . $url;
					}

					$url = preg_replace( '#^\w+://#', 'https://', $url );

					if ( $path && is_string( $path ) ) {
						$url .= '/' . ltrim( $path, '/' );
					}

					// Couldn't get the filter stuff working as expected in wp_mock, so work around it.
					//return apply_filters( 'plugins_url', $url, $path, $plugin );
					return Assets\plugins_url( $url, $path, $plugin );
				}
			]
		);

		// See above comment about not getting filter stuff working in wp_mock.
		//WP_Mock::onFilter( 'plugins_url' )
		//	->with( [ '', '', ''] )
		//	->reply( Assets\plugins_url( '' ) );

		self::assertEquals( WP_PLUGIN_URL, plugins_url( '', '' ) );
	}

	/**
	 * If a plugins url is already in a known directory, ensure it returns unchanged.
	 */
	public function test_plugins_url_returns_unchanged_url_if_relative_file_in_known_directory(): void {
		$plugins_url = WPMU_PLUGIN_URL . '/' . self::PLUGIN_NAME;
		$plugin_file = WPMU_PLUGIN_DIR . '/' . self::PLUGIN_NAME . '/' . self::PLUGIN_NAME . '.php';
		self::assertEquals( $plugins_url, Assets\plugins_url( $plugins_url, '', $plugin_file ) );
	}

	/**
	 * If a plugin does not exist in the mu directory, ensure it returns unchanged.
	 */
	public function test_plugins_url_returns_unchanged_url_if_no_existing_mu_plugin(): void {
		$plugins_url = WP_PLUGIN_URL . '/' . self::PLUGIN_NAME;
		self::assertEquals( $plugins_url, Assets\plugins_url( $plugins_url ) );
	}

	/**
	 * If a plugin exists in the mu directory, ensure it returns an updated url.
	 */
	public function test_plugins_url_returns_updated_url_if_mu_plugin_exists(): void {
		$plugins_url = WP_PLUGIN_URL . '/' . self::PLUGIN_NAME;

		$symlink_directory = SYMLINK_PARENT_DIR . '/' . self::PLUGIN_NAME;
		$plugin_directory  = WPMU_PLUGIN_DIR . '/' . self::PLUGIN_NAME;

		// Create the directory we wish to symlink.
		if ( ! mkdir( $symlink_directory ) || ! is_dir( $symlink_directory ) ) {
			throw new \RuntimeException( sprintf( 'Directory "%s" was not created', $symlink_directory ) );
		}

		/*
		 * Create a symbolic link, where `$symlink_directory` is a physical folder,
		 * and `$plugin_directory` is where it should be mirrored to.
		 *
		 * Follow up by confirming that the mirrored location is identified as a valid directory.
		 */
		if ( ! symlink( $symlink_directory, $plugin_directory ) || ! is_dir( $plugin_directory ) ) {
			throw new \RuntimeException( sprintf( 'Unable to symlink the "%1$s" directory to "%2$s"', $symlink_directory, $plugin_directory ) );
		}

		$mu_plugins_url = WPMU_PLUGIN_URL . '/' . self::PLUGIN_NAME;

		self::assertEquals( $mu_plugins_url, Assets\plugins_url( $plugins_url ) );
	}
}
