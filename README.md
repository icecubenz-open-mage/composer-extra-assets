
## Composer Plugin for installing NPM packages

This Composer plugin installs NPM package via `composer.json`. Not only the root package can have dependencies.

NPM packages will be installed in package folder - with all dependencies merged.

NPM packages can be required, NPM scripts can be added and NPM config set. NPM config is taken from the package first, it can also be overritten by `npm-config` in the `composer.json`'s `extra` section per project.

### Example Usage

`composer.json`

    "require": {
        "icecube/composer-npm": "~3.0"
    },
    "extra": {
        "require-npm": {
            "grunt": "0.4.*"
        },
        "scripts-npm": {
            "hello": "echo hello"
        },
        "config-npm" {
            "bar": "foo"
        },
        "require-dev-npm": {
        }
    }

### NPM dependencies

NPM dependencies will be installed in the `node_modules` directory of the package that requires the dependency.
Some NPM packages provide binary files (for instance `gulp` and `grunt`).

NPM binaries will be exposed in the `vendor/bin` directory if the NPM dependency is declared in the **root Composer package**.

If you are writing a package and want a NPM package to be available in the `node_modules` directory of Composer's root
 (instead of the `node_modules` directory of your package), you can add the `expose-npm-packages`
attribute to the composer `extra` session of your package:

     "require": {
         "icecube/composer-npm": "~3.0"
     },
     "extra": {
         "require-npm": {
             "gulp": "*"
         },
        "scripts-npm": {
             "hello": "echo hello"
         },
         "expose-npm-packages": true
     }


### Generated files

This plugin will automatically generate 1 file: `package.json`.

Unless you have special requirements, you can ignore this file in your VCS. If you are using git, add this to your `.gitignore`:

.gitignore

    vendor/
    node_modules/
    package.json

### Lock

This plugin will generate a file named `composer-npm.lock` which can be used just like `composer.lock`. Put it
under version control if you want to be able to install the exact same dependencies.

### Warning

This plugins removes (and re-installs) the complete node_modules folder. Any changes will be lost.

### Notes

Originally from: https://github.com/koala-framework/composer-extra-assets

Modified by @rjocoleman
