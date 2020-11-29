<?php
namespace Icecube\ComposerNpm;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;

class Plugin implements PluginInterface, EventSubscriberInterface
{
    protected $composer;
    protected $io;

    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    public function deactivate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    public function uninstall(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    public static function getSubscribedEvents()
    {
        return array(
            'post-install-cmd' => array(
                array('onPostInstall', 0)
            ),
            'post-update-cmd' => array(
                array('onPostUpdate', 0)
            ),
        );
    }

    public function onPostInstall(Event $event)
    {
        $assetsLockFile = new JsonFile('composer-npm.lock');
        if (!$assetsLockFile->exists()) {
            //no lock exists, behave like composer update
            $this->onPostUpdate($event);
        } else {
            $assetsLock = $assetsLockFile->read();

            $packages = $this->composer->getRepositoryManager()->getLocalRepository()->getCanonicalPackages();
            $mergedNpmPackages = array();
            foreach ($packages as $package) {
                if ($package instanceof \Composer\Package\CompletePackage) {
                    $extra = $package->getExtra();
                    if (!isset($extra['expose-npm-packages']) || $extra['expose-npm-packages'] != true) {
                        if (isset($assetsLock['npm-dependencies'][$package->getName()])) {
                            $this->_installNpm($this->composer->getConfig()->get('vendor-dir') . '/' .$package->getName(), $package, false, array(), $assetsLock['npm-dependencies'][$package->getName()]);
                        }
                    } else {
                        $mergedNpmPackages[] = $package;
                    }
                }
            }

            if (isset($assetsLock['npm-dependencies']['self'])) {
                $this->_installNpm('.', $this->composer->getPackage(), $event->isDevMode(), $mergedNpmPackages, $assetsLock['npm-dependencies']['self']);
            }
        }
    }

    public function onPostUpdate(Event $event)
    {
        $assetsLock = array(
            'npm-dependencies' => array()
        );

        $packages = $this->composer->getRepositoryManager()->getLocalRepository()->getCanonicalPackages();
        $mergedNpmPackages = array();
        // NPM install for dependencies that are not exposed.
        foreach ($packages as $package) {
            if ($package instanceof \Composer\Package\CompletePackage) {
                $extra = $package->getExtra();
                if (!isset($extra['expose-npm-packages']) || $extra['expose-npm-packages'] != true) {
                    $shrinkwrapDeps = $this->_installNpm($this->composer->getConfig()->get('vendor-dir') . '/' .$package->getName(), $package, false, array(), null);
                    if ($shrinkwrapDeps) {
                        $assetsLock['npm-dependencies'][$package->getName()] = $shrinkwrapDeps;
                    }
                } else {
                    $mergedNpmPackages[] = $package;
                }
            }
        }

        // NPM install for dependencies that are exposed on the root package.
        $shrinkwrapDeps = $this->_installNpm('.', $this->composer->getPackage(), $event->isDevMode(), $mergedNpmPackages, null);
        if ($shrinkwrapDeps) {
            $assetsLock['npm-dependencies']['self'] = $shrinkwrapDeps;
        }

        $this->_createNpmBinaries();

        $packages = array(
            $this->composer->getPackage()
        );
        $packages = array_merge($packages, $this->composer->getRepositoryManager()->getLocalRepository()->getCanonicalPackages());

        $assetsLockFile = new JsonFile('composer-npm.lock');
        $assetsLockFile->write($assetsLock);
    }

    private function _installNpm($path, $package, $devMode, array $mergedPackages, $shrinkwrapDependencies)
    {
        $dependencies = array();
        $scripts = array();
        $config = array();

        $extra = $package->getExtra();
        if ($devMode) {
            if (!empty($extra['require-dev-npm'])) {
                $dependencies = $this->_mergeDependencyVersions($dependencies, $extra['require-dev-npm']);
            }
        }

        if (!empty($extra['require-npm'])) {
            $dependencies = $this->_mergeDependencyVersions($dependencies, $extra['require-npm']);
        }

        foreach ($mergedPackages as $dep) {
            $packageExtra = $dep->getExtra();
            if (!empty($packageExtra['require-npm'])) {
                $dependencies = $this->_mergeDependencyVersions($dependencies, $packageExtra['require-npm']);
            }
            if (!empty($packageExtra['scripts-npm'])) {
                $scripts = $packageExtra['scripts-npm'];
            }
            if (!empty($packageExtra['config-npm'])) {
                $config = $packageExtra['config-npm'];
            }
        }

        $ret = null;
        if ($dependencies || $scripts) {
            $ret = $this->_installNpmDependencies($path, $dependencies, $shrinkwrapDependencies, $scripts, $config);
        }
        return $ret;
    }

    /**
     * Merges 2 version of arrays.
     *
     * @param array $array1
     * @param array $array2
     * @return array
     */
    private function _mergeDependencyVersions(array $array1, array $array2) {
        foreach ($array2 as $package => $version) {
            if (!isset($array1[$package])) {
                $array1[$package] = $version;
            } else {
                if ($array1[$package] != $version) {
                    $array1[$package] .= " ".$version;
                }
            }
        }
        return $array1;
    }

    private function _installNpmDependencies($path, $dependencies, $shrinkwrapDependencies, $scripts, $config)
    {
        $prevCwd = getcwd();
        chdir($path);

        if (file_exists('node_modules/.composer-npm-installed.json')) {
            $installed = json_decode(file_get_contents('node_modules/.composer-npm-installed.json'), true);
            if ($installed == $shrinkwrapDependencies) {
                $this->io->write("npm dependencies in '$path' are up to date...");
                chdir($prevCwd);
                return;
            }
        }

        if (file_exists('node_modules')) {
            //recursively delete node_modules
            //this is done to support shrinkwrap properly
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator('node_modules', \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $i) {
                $i->isDir() && !$i->isLink() ? rmdir($i->getPathname()) : unlink($i->getPathname());
            }
        }

        $jsonFile = new JsonFile('package.json');
        if ($jsonFile->exists()) {
            $packageJson = $jsonFile->read();
            if (!isset($packageJson['name']) || $packageJson['name'] != 'composer-npm') { //assume we can overwrite our own temp one
                throw new \Exception("Can't install npm dependencies as there is already a package.json");
            }
        } else {
            $packageJson = array(
                'name' => 'composer-npm',
                'description' => "this file is auto-generated and will be overwritten by 'icecube/composer-npm'",
                'private' => true
            );
        }

        $composerExtra = $this->composer->getPackage()->getExtra();
        if (!empty($composerExtra['npm-config'])) {
            $config = array_merge($config, $composerExtra['npm-config']);
        }

        $packageJson['dependencies'] = (object) $dependencies;
        $packageJson['scripts'] = (object) $scripts;
        $packageJson['config'] = (object) $config;

        $jsonFile->write($packageJson);

        $shrinkwrapJsonFile = new JsonFile('npm-shrinkwrap.json');
        if ($shrinkwrapDependencies) {
            $shrinkwrapJson = array(
                'name' => 'composer-npm',
                'dependencies' => $shrinkwrapDependencies,
            );
            $shrinkwrapJsonFile->write($shrinkwrapJson);
        } else {
            if ($shrinkwrapJsonFile->exists()) {
                unlink('npm-shrinkwrap.json');
            }
        }

        $this->io->write("");
        $this->io->write("installing npm dependencies in '$path'...");
        $npm = 'npm';
        $cmd = escapeshellarg($npm) . " install";

        $descriptorspec = array();
        $pipes = array();
        $p = proc_open($cmd, $descriptorspec, $pipes);
        $retVar = proc_close($p);

        if ($retVar) {
            throw new \RuntimeException('npm install failed with '.$retVar);
        }

        $ret = null;
        if (!$shrinkwrapDependencies) {
            $cmd = escapeshellarg($npm) . " shrinkwrap";

            $descriptorspec = array();
            $pipes = array();
            $p = proc_open($cmd, $descriptorspec, $pipes);
            $retVar = proc_close($p);
            if ($retVar) {
                throw new \RuntimeException('npm shrinkwrap failed');
            }
            $shrinkwrap = json_decode(file_get_contents('npm-shrinkwrap.json'), true);
            $ret = $shrinkwrap['dependencies'];
        }

        if ($path != '.') {
            unlink('package.json');
        }
        unlink('npm-shrinkwrap.json');

        $installed = $shrinkwrapDependencies;
        if (!$installed) $installed = $ret;
        file_put_contents('node_modules/.composer-npm-installed.json', json_encode($installed));

        chdir($prevCwd);

        return $ret;
    }

    private function _createNpmBinaries() {
        // Let's link binaries, if any:
        $linkWriter = new LinkWriter($this->composer->getConfig()->get('bin-dir'));

        $binaries = glob("node_modules/.bin/*");
        foreach ($binaries as $binary) {
            $linkWriter->writeLink($binary);
        }
    }
}
