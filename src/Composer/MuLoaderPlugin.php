<?php
/**
 * This file contains the Composer plugin for dealing with MU Plugin loading.
 *
 * The main job of this plugin is to dump the bootstrap file when the Composer
 * autoloader is dumped. It also contains the method for overriding the type
 * in normal plugins, placing them in the MU directory on install.
 *
 * @license MIT
 * @copyright Luke Woodward
 * @package WP_MUPlugin_Loader
 */

namespace LkWdwrd\MuPluginLoader\Composer;

/**
 * Use the necessary namespaces.
 */

use Composer\Composer;
use Composer\Config;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\Installer\PackageEvent;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\EventDispatcher\EventSubscriberInterface;
use LkWdwrd\MuPluginLoader\Util;

/**
 * Require the utility functions.
 */
require_once dirname(__DIR__) . '/Util/util.php';

/**
 * A Composer plugin for autoloading WordPress Must-Use plugins.
 *
 * This plugin subscribes to two events. When the autoloader is dumped for
 * Composer, this plugin also dumps a loader file into the `mu-plugins` folder.
 * The second event fires during plugin install. It checks to see if the slug
 * matches an extras key, and if so overrides the type so that it will load as
 * an Must-Use plugin.
 */
class MuLoaderPlugin implements PluginInterface, EventSubscriberInterface
{
    /**
     * Version for the generated docblock.
     */
    public const VERSION = '2.0.0';

    /**
     * Default name of our generated mu require file.
     *
     * @var string
     */
    private const DEFAULT_MU_REQUIRE_FILE = 'mu-require.php';

    /**
     * Holds the extras array for the root Composer project.
     *
     * @var array
     */
    private $extras = [];

    /**
     * Holds the config object for the root Composer project.
     *
     * @var Config
     */
    private $config = null;

    /**
     * Version of the package to use for the docblock.
     *
     * @var string
     */
    private $version = self::VERSION;

    /**
     * Stores the extras array and config object for later use.
     *
     * @param Composer    $composer The main Composer object.
     * @param IOInterface $io       The I/O Helper object.
     *
     * @return void
     */
    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->extras = $composer->getPackage()->getExtra();
        $this->config = $composer->getConfig();
        $this->version = self::VERSION;
    }

    /**
     * Subscribes to autoload dump and package install events.
     *
     * When `pre-autoload-dump` fires, run the `dumpRequireFile` method.
     * When `pre-package-install` fires, run the `overridePluginTypes` method.
     *
     * @return array The event subscription map.
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'pre-autoload-dump' => 'dumpRequireFile',
            'pre-package-install' => 'overridePluginTypes',
            'pre-package-update' => 'overridePluginTypes',
        ];
    }

    /**
     * Checks the package being installed and conditionally overrides type.
     *
     * If the package being installed is the `wordpress-plugin` type, this will
     * check the extras array to see if the package's slug is present in the
     * `force-mu` array. If it is, the package type is updated to so that it
     * will install as if it were the `wordpress-muplugin` type.
     *
     * This takes into account WPackagist. If overriding the type in a plugin
     * from WPackagist, there is no need to include the `wpackagist-plugin`
     * prefix. Just use the slug as it appears in the wordpress.org repository.
     *
     * @param PackageEvent $event The package event for the current package.
     *
     * @return void
     */
    public function overridePluginTypes(PackageEvent $event): void
    {
        // Get the package being worked on.
        $operation = $event->getOperation();
        if ($operation instanceof UpdateOperation) {
            $package = $operation->getTargetPackage();
        } else {
            $package = $operation->getPackage();
        }

        // Only act on wordpress-plugin types
        if ('wordpress-plugin' !== $package->getType()) {
            return;
        }

        // Only act when there is a force-mu key holding an array in extras
        $extras = $this->extras;
        if (empty($extras['force-mu']) || ! is_array($extras['force-mu'])) {
            return;
        }

        // Check to see if the current package is in the force-mu extra
        // If it is, set its type to 'wordpress-muplugin'
        $slug = str_replace('wpackagist-plugin/', '', $package->getName());
        if (in_array($slug, $extras['force-mu'], true)) {
            $package->setType('wordpress-muplugin');
        }
    }

    /**
     * Controls dumping the require file into the `mu-plugins` folder.
     *
     * This method finds the relative path from the `mu-plugins` folder to this
     * composer package. It then writes a very simple PHP file into the
     * `mu-plugins` folder, which runs a `require_once` to load the main
     * `mu-loader.php` which can remain cozy back in the vendor directory.
     *
     * @return void
     */
    public function dumpRequireFile(): void
    {
        $muPath = $this->getMuPath();

        if ($muPath === '') {
            return;
        }

        $muRequireFile = $this->getMuRequireFile();

        $ds = $this->getDirectorySeparator();
        $loadFile = dirname(__DIR__) . $ds . 'mu-loader.php';
        $toLoader = $ds . Util\rel_path($muPath, $loadFile, $ds);

        // This allows users to also turn off the auto generation of mu-require if they wish.
        if ($muRequireFile !== 'false') {
            // Write the bootstrapping PHP file.
            if (! file_exists($muPath)) {
                if (! mkdir($muPath, 0755, true) && ! is_dir($muPath)) {
                    throw new \RuntimeException(sprintf('Directory "%s" was not created', $muPath));
                }
            }

            file_put_contents(
                $muPath . $muRequireFile,
                // Need to break up __DIR__ to stop this https://github.com/composer/composer/blob/32966a3b1d48bc01472a8321fd6472b44fad033a/src/Composer/Plugin/PluginManager.php#L193 occurring.
                "<?php\n" . $this->getMuRequireGeneratedDocBlock() . "\n" . 'require_once __DI' . 'R__ . ' . "'{$toLoader}';\n"
            );
        }
    }

    /**
     * Extracts the Must-Use Plugins directory from the extra definition.
     *
     * The Composer Installers plugins uses a specific extras definition to
     * determine where Must-User plugins should be installed. This method makes
     * sure that the type definition exists there, and if so, extracts the
     * path, stripped of the `{name}` token, and returns it.
     *
     * If the lookup fails, this method returns false.
     *
     * @return string|bool Either the relative path or false.
     */
    protected function findMURelPath()
    {
        $path = false;
        // Only keep going if we have install-paths in extras.
        if (empty($this->extras['installer-paths']) || ! is_array($this->extras['installer-paths'])) {
            return false;
        }
        // Find the array to the mu-plugin path.
        foreach ($this->extras['installer-paths'] as $path => $types) {
            if (! is_array($types)) {
                continue;
            }
            if (! in_array('type:wordpress-muplugin', $types, true)) {
                continue;
            }
            $path = str_replace('{$name}', '', $path);
            break;
        }

        return $path;
    }

    /**
     * Takes the relative Must-Use plugins path and send back the abslute path.
     *
     * @param string $relpath The relative `mu-plugins` path.
     *
     * @return string          The absolute `mu-plugins` path.
     */
    protected function resolveMURelPath(string $relpath): string
    {
        // Find the actual base path by removing the vendor-dir raw config path.
        if ($this->config->has('vendor-dir')) {
            $tag = $this->config->raw()['config']['vendor-dir'];
        } else {
            $tag = '';
        }
        $basepath = str_replace($tag, '', $this->config->get('vendor-dir'));

        // Return the absolute path.
        return $basepath . $relpath;
    }

    /**
     * Find MU path.
     *
     * @return string
     */
    private function getMuPath(): string
    {
        $muRelPath = $this->findMURelPath();

        // If we didn't find a relative MU Plugins path, bail.
        if (! $muRelPath) {
            return '';
        }

        // Find the relative path from the mu-plugins dir to the mu-loader file.
        return $this->resolveMURelPath($muRelPath);
    }

    /**
     * Get the filename for the mu require file.
     *
     * @return string
     */
    private function getMuRequireFile(): string
    {
        // Allow the name of the mu-require to be specified.
        $muRequireFile = $this->extras['mu-require-file'] ?? self::DEFAULT_MU_REQUIRE_FILE;

        if ($muRequireFile === false) {
            return 'false';
        }

        return $muRequireFile;
    }

    /**
     * Get the directory separator to use for the generated loader
     *
     * This defaults to the DIRECTORY_SEPARATOR constant, but can be overridden in
     * the composer.json extra section with "force-unix-separator" set to true.
     *
     * @return string The directory separator character to use
     */
    protected function getDirectorySeparator(): string
    {
        $separator = DIRECTORY_SEPARATOR;
        if (! empty($this->extras['force-unix-separator'])) {
            $separator = '/';
        }

        return $separator;
    }

    /**
     * Get the docblock for our generated mu-require file.
     *
     * @return string
     */
    public function getMuRequireGeneratedDocBlock(): string
    {
        $version = $this->version;
        return <<<DOCBLOCK
/**
 * Plugin Name: MU Plugin Loader
 * Plugin URI: https://github.com/boxuk/wp-muplugin-loader
 * Description: MU Plugin Loader - Autoload your mu-plugin directories.
 * Version: {$version}
 * Author: Box UK / Luke Woodward
 * Author URI: https://github.com/boxuk/wp-muplugin-loader
 *
 * @since 1.1.0
 */
DOCBLOCK;
    }

    /**
     * This is used to remove any hooks from composer. We'll use it to unset properties set in activation.
     *
     * @param Composer $composer
     * @param IOInterface $io
     */
    public function deactivate(Composer $composer, IOInterface $io): void
    {
        $this->extras = [];
        $this->config = new Config();
    }

    /**
     * This is used for removal of anything added by the plugin. We will use it to remove our dumped autoload file.
     *
     * @param Composer $composer
     * @param IOInterface $io
     */
    public function uninstall(Composer $composer, IOInterface $io): void
    {
        $muPath = $this->getMuPath();
        $muRequireFile = $this->getMuRequireFile();

        if (file_exists($muPath . $muRequireFile)) {
            unlink($muPath . $muRequireFile);
        }
    }
}
