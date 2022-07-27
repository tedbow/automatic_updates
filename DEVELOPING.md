### Prerequisites
* PHP 7.4 or later.
* Composer 2 or later. (Automatic Updates is not compatible with Composer 1.)
* Git must be installed.

### Caveats
* Symbolic links are not currently supported by Package Manager. If the Drupal core checkout contains any symbolic links, tests will not work. By default, there shouldn't be any, but some may exist in certain situations (for example, if Drush or Drupal core's JavaScript dependencies have been installed). To scan for symbolic links, run `find . -type l` from the Drupal core repository root.

### Step 1: Set up Drupal core
For development and running the module's tests, Automatic Updates assumes you are working from a Git clone of Drupal core. To set one up:
```
git clone https://git.drupalcode.org/project/drupal.git --branch 9.5.x auto-updates-dev
cd auto-updates-dev
composer config platform.php 7.4.0
composer install
```
Replace `9.5.x` with the desired development branch of Drupal core. Be sure to point your web server to the `auto-updates-dev` directory, so you can access this code base in a browser.

### Step 2: Clone Automatic Updates
Clone Automatic Updates' main development branch (8.x-2.x):
```
git clone --branch '8.x-2.x' https://git.drupalcode.org/project/automatic_updates.git ./modules/automatic_updates
```

### Step 3: Install dependencies
From the Drupal repository root:
```
composer require php-tuf/composer-stager "symfony/config:^4.4 || ^6.1"
git reset --hard
```
Note: If you switch to a different branch of Drupal core and delete the `vendor` directory, you will need to do this step again after running `composer install`.
