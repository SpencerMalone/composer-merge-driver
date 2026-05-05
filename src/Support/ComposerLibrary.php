<?php

declare(strict_types=1);

namespace ComposerMergeDriver\Support;

use Composer\Factory;
use Composer\IO\NullIO;
use Composer\Installer;
use Composer\Package\Loader\ValidatingArrayLoader;
use Composer\Util\ConfigValidator;
use ComposerMergeDriver\Exception\MergeException;

/**
 * Wraps the Composer PHP API to re-resolve lock file conflicts
 * without shelling out to an external binary.
 *
 * Composer classes are loaded from whichever vendor/autoload.php was bootstrapped
 * first: the target project's (preferred) or our own fallback (see bin/composer-merge-driver).
 */
final class ComposerLibrary implements ComposerLibraryInterface
{
    public function __construct() {}

    /**
     * Update only the listed packages (re-resolves their versions and dependencies)
     * and write a new composer.lock in $cwd.  Packages are not downloaded.
     *
     * @param list<string> $packages
     * @param string       $cwd      Directory containing composer.json + composer.lock to update
     */
    public function update(array $packages, string $cwd): bool
    {
        try {
            $io       = new NullIO();
            $composer = (new Factory())->createComposer(
                $io,
                $cwd . DIRECTORY_SEPARATOR . 'composer.json',
                true,   // disablePlugins
                $cwd,
                true,   // fullLoad
                true,   // disableScripts
            );

            $installer = Installer::create($io, $composer)
                ->setUpdate(true)
                ->setInstall(false)   // resolve only — do not download packages
                ->setRunScripts(false)
                ->setUpdateAllowList($packages)
                ->disablePlugins();

            return $installer->run() === 0;
        } catch (\Throwable $e) {
            throw new MergeException('Composer update failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Regenerate autoload files (vendor/composer/*) for the project at $cwd.
     * Mirrors what `composer dump-autoload` does: calls AutoloadGenerator::dump()
     * directly without installing or downloading packages.
     */
    public function dumpAutoload(string $cwd): bool
    {
        try {
            $io       = new NullIO();
            $composer = (new Factory())->createComposer(
                $io,
                $cwd . DIRECTORY_SEPARATOR . 'composer.json',
                true,   // disablePlugins
                $cwd,
                true,   // fullLoad
                true,   // disableScripts
            );

            $generator = $composer->getAutoloadGenerator();
            $generator->setRunScripts(false);
            $generator->dump(
                $composer->getConfig(),
                $composer->getRepositoryManager()->getLocalRepository(),
                $composer->getPackage(),
                $composer->getInstallationManager(),
                'composer',
                false,  // optimize
                null,
                $composer->getLocker(),
            );

            return true;
        } catch (\Throwable $e) {
            throw new MergeException('Composer dump-autoload failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Validate a composer.json file (equivalent to `composer validate --no-check-publish`).
     * Returns a list of error strings, empty on success.
     *
     * @return list<string>
     * @throws MergeException
     */
    public function validate(string $composerJsonPath): array
    {
        try {
            $validator = new ConfigValidator(new NullIO());
            [$errors, , ] = $validator->validate(
                $composerJsonPath,
                ValidatingArrayLoader::CHECK_ALL,
                0,  // no CHECK_VERSION — version fields are uncommon but valid
            );
            return $errors;
        } catch (\Throwable $e) {
            throw new MergeException('composer.json validation failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Return true if the Composer library is available (classes can be loaded).
     */
    public function isAvailable(): bool
    {
        return class_exists(Factory::class);
    }
}
