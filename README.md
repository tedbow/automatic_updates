Automatic Updates
---------------
### Requirements
- The Drupal project's codebase must be writable in order to use Automatic Updates. This includes Drupal, modules, themes and the Composer dependencies in the vendor directory. This makes Automatic Updates incompatible with some hosting platforms.
- The Composer executable must be in the PATH of the web server. If the Composer executable cannot be found the location can be set by adding
  `$config['package_manager.settings']['executables']['composer'] = '/path/to/composer';` in `settings.php`

### Limitations
- Drupal multi-site installations are not supported.
- Automatic Updates does not support version control such as Git. It is the responsibility of site administrators to commit any updates to version control if needed.
- Automatic Updates does not support symlinks. See [What if Automatic Updates says I have symlinks in my codebase?](#what-if-automatic-updates-says-i-have-symlinks-in-my-codebase) for help if you have any.

### Updating contributed modules and themes
Automatic Updates includes a sub-module, Automatic Updates Extensions, which supports updating contributed modules and themes.

⚠️ ☢️️ **Automatic Updates Extensions is still experimental and under heavy development.** We encourage you to test it in your local development environment, or another low-stakes testing situation, but it is emphatically NOT ready for use in a production environment. ☢️ ⚠️

### Automatic Updates Initiative

- Follow and read up on
  [Ideas queue - Automatic Updates initiative](https://www.drupal.org/project/ideas/issues/2940731)

### FAQ

#### What if Automatic Updates says I have symlinks in my codebase?

A fresh Drupal installation should not have any symlinks, but third party libraries and custom code can add them. If Automatic Updates says you have some, run the following command in your terminal to find them:

```shell
cd /var/www # Wherever your active directory is located.
find . -type l
```

You might see output like the below, indicating symlinks in Drush's `docs` directory, as an example:

```
./vendor/drush/drush/docs/misc/icon_PhpStorm.png
./vendor/drush/drush/docs/img/favicon.ico
./vendor/drush/drush/docs/contribute/CONTRIBUTING.md
./vendor/drush/drush/docs/drush_logo-black.png
```

##### Composer libraries

Symlinks in Composer libraries can be addressed with [Drupal's Vendor Hardening Composer Plugin](https://www.drupal.org/docs/develop/using-composer/using-drupals-vendor-hardening-composer-plugin), which "removes extraneous directories from the project's vendor directory". Use it as follows.

First, add `drupal/core-vendor-hardening` to your Composer project:

```shell
composer require drupal/core-vendor-hardening
```

Then, add the following to the `composer.json` in your site root to handle the most common, known culprits. Add your own as necessary.

```json
"extra": {
    "drupal-core-vendor-hardening": {
        "drush/drush": ["docs"],
        "grasmash/yaml-expander": ["scenarios"]
    }
}
```

The new configuration will take effect on the next Composer install or update event. Do this to apply it immediately:

```shell
composer install
```

##### Custom code

Symlinks are seldom truly necessary and should be avoided in your own code. No solution currently exists to get around them--they must be removed in order to use Automatic Updates.
