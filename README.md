Automatic Updates
---------------
### Requirements
- The Drupal project's codebase must be writable in order to use Automatic Updates. This includes Drupal, modules, themes and the Composer dependencies in the vendor directory. This makes Automatic Updates incompatible with some hosting platforms.
- The Composer executable must be in the PATH of the web server. If the Composer executable cannot be found the location can be set by adding
  `$config['package_manager.settings']['executables']['composer'] = '/path/to/composer';` in `settings.php`

### Limitations
- Drupal multi-site installations are not supported.
- Automatic Updates does not support version control such as Git. It is the responsibility of site administrators to commit any updates to version control if needed.

### Updating contributed modules and themes
Automatic Updates includes a sub-module, Automatic Updates Extensions, which supports updating contributed modules and themes.

⚠️ ☢️️ **Automatic Updates Extensions is still experimental and under heavy development.** We encourage you to test it in your local development environment, or another low-stakes testing situation, but it is emphatically NOT ready for use in a production environment. ☢️ ⚠️

### Automatic Updates Initiative

- Follow and read up on
  [Ideas queue - Automatic Updates initiative](https://www.drupal.org/project/ideas/issues/2940731)
