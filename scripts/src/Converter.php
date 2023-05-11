<?php

declare(strict_types = 1);

namespace Drupal\automatic_updates\Development;

use Composer\Script\Event;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Converts the contrib module to core merge request.
 *
 * File usage:
 *
 * @code
 * composer core-covert /path/to/core merge-request-branch
 * @endcode
 *
 * The core clone should already have the core merge request locally.
 */
class Converter {

  private const RUN_CHECKS = TRUE;

  /**
   * Prints message.
   *
   * @param string $msg
   *   The message to print.
   */
  private static function info(string $msg): void {
    print "\n$msg";
  }

  /**
   * Converts the contrib module to core merge request.
   *
   * @param \Composer\Script\Event $event
   *   The Composer event.
   */
  public static function doConvert(Event $event): void {
    $args = $event->getArguments();
    $count_arg = count($args);
    if (!($count_arg === 2 || $count_arg === 3)) {
      throw new \Exception("This scripts 2 required arguments: a directory that is a core clone and the branch.\nIt has 1 optional arguments: the branch of this module to use which defaults to 3.0.x");
    }
    $core_dir = $args[0];
    $core_branch = $args[1];
    if (!is_dir($core_dir)) {
      throw new \Exception("$core_dir is not a directory.");
    }
    $contrib_branch = $count_arg === 2 ? '3.0.x' : $args[2];
    $old_machine_name = 'automatic_updates';
    $new_machine_name = 'auto_updates';

    static::switchToBranches($core_dir, $core_branch, $contrib_branch);
    self::info('Switched branches');
    $fs = new Filesystem();

    $core_module_path = static::getCoreModulePath($core_dir);
    $package_manager_core_path = $core_dir . "/core/modules/package_manager";
    // Remove old module.
    $fs->remove($core_module_path);
    self::info('Removed old core module');
    $fs->remove($package_manager_core_path);
    self::info("Removed package manager");

    $fs->mirror(self::getContribDir(), $core_module_path);
    self::info('Mirrored into core module');

    $new_script_path = "$core_dir/core/scripts/PackageManagerFixtureCreator.php";
    $fs->remove($new_script_path);
    $fs->rename($core_module_path . '/scripts/PackageManagerFixtureCreator.php', $new_script_path);
    $script_replacements = [
      "__DIR__ . '/../../../autoload.php'" => "__DIR__ . '/../../autoload.php'",
      "__DIR__ . '/../package_manager/tests/fixtures/fake_site'" => "__DIR__ . '/../modules/package_manager/tests/fixtures/fake_site'",
      "CORE_ROOT_PATH = __DIR__ . '/../../../'" => "CORE_ROOT_PATH = __DIR__ . '/../..'",
      "new Process(['composer', 'phpcbf'], self::FIXTURE_PATH);" => "new Process(['composer', 'phpcbf', self::FIXTURE_PATH], self::CORE_ROOT_PATH);",
    ];
    foreach ($script_replacements as $search => $replace) {
      static::replaceContents([new \SplFileInfo($new_script_path)], $search, $replace);
    }

    // Remove unneeded.
    $removals = [
      'automatic_updates_extensions',
      'drupalci.yml',
      'README.md',
      '.cspell.json',
      '.git',
      'composer.json',
      '.gitattributes',
      '.gitignore',
      'DEVELOPING.md',
      'phpstan.neon.dist',
      // @todo Move ComposerFixtureCreator to its location in core
      //   https://drupal.org/i/3347937.
      'scripts',
      'dictionary.txt',
      // Drupal core does not ship with any Drush commands.
      'drush.services.yml',
      'src/Commands/AutomaticUpdatesCommands.php',
      'src/DrushUpdateStage.php',
    ];
    $removals = array_map(function ($path) use ($core_module_path) {
      return "$core_module_path/$path";
    }, $removals);
    $fs->remove($removals);
    self::info('Remove not needed');

    // Replace in file names and contents.
    static::replaceContents(
      [
        new \SplFileInfo("$core_module_path/automatic_updates.info.yml"),
        new \SplFileInfo("$core_module_path/package_manager/package_manager.info.yml"),
      ],
      "core_version_requirement: ^9.7 || ^10.1",
      "package: Core\nversion: VERSION\nlifecycle: experimental",
    );
    $replacements = [
      $old_machine_name => $new_machine_name,
      'AutomaticUpdates' => 'AutoUpdates',
    ];
    foreach ($replacements as $search => $replace) {
      static::renameFiles(static::getDirContents($core_module_path), $search, $replace);
      static::replaceContents(static::getDirContents($core_module_path, TRUE), $search, $replace);
    }
    self::info('Replacements done.');

    static::removeLines($core_dir);
    self::info('Remove unneeded lines');
    $fs->rename("$core_module_path/package_manager", $core_dir . "/core/modules/package_manager");
    self::info('Move package manager');

    // ⚠️ For now, we're only trying to get package_manager committed, not automatic_updates!
    $fs->remove($core_module_path);

    static::addWordsToDictionary($core_dir, self::getContribDir() . "/dictionary.txt");
    self::info("Added to dictionary");
    $fs->chmod($new_script_path, 0644);
    if (self::RUN_CHECKS) {
      static::runCoreChecks($core_dir);
      self::info('Ran core checks');
    }
    else {
      self::info('⚠️Skipped core checks');
    }
    static::doMakeCommit($core_dir);
    self::info('Make commit');
    self::info("Done. Probably good but you should check before you push. These are the files present in the contrib module absent in core:");
    print shell_exec(sprintf("tree %s/package_manager > /tmp/contrib.txt  && tree %s/core/modules/package_manager > /tmp/core.txt && diff /tmp/contrib.txt /tmp/core.txt", self::getContribDir(), $core_dir));
    self::info('(Run diff /tmp/contrib.txt /tmp/core.txt to see that with color.');
  }

  /**
   * Returns the path to the root of the contrib module.
   *
   * @return string
   *   The full path to the root of the contrib module.
   */
  private static function getContribDir(): string {
    return realpath(__DIR__ . '/../..');
  }

  /**
   * Returns the path where the contrib module will be placed in Drupal Core.
   *
   * @param string $core_dir
   *   The path to the root of Drupal Core.
   *
   * @return string
   *   The path where the contrib module will be placed in Drupal Core
   */
  private static function getCoreModulePath(string $core_dir): string {
    return $core_dir . '/core/modules/auto_updates';
  }

  /**
   * Replaces a string in the contents of the module files.
   *
   * @param array $files
   *   Files to replace.
   * @param string $search
   *   The string to be replaced.
   * @param string $replace
   *   The string to replace.
   */
  private static function replaceContents(array $files, string $search, string $replace): void {
    foreach ($files as $file) {
      $filePath = $file->getRealPath();
      file_put_contents($filePath, str_replace($search, $replace, file_get_contents($filePath)));
    }
  }

  /**
   * Renames the module files.
   *
   * @param array $files
   *   Files to replace.
   * @param string $old_pattern
   *   The old file name.
   * @param string $new_pattern
   *   The new file name.
   */
  private static function renameFiles(array $files, string $old_pattern, string $new_pattern): void {
    // Keep a record of the files and directories to change.
    // We will change all the files first, so we don't change the location of
    // any files in the middle. This probably won't work if we had nested
    // folders with the pattern on 2 folder levels, but we don't.
    $filesToChange = [];
    $dirsToChange = [];
    foreach ($files as $file) {
      $fileName = $file->getFilename();
      if ($fileName === '.') {
        $fullPath = $file->getPath();
        $parts = explode('/', $fullPath);
        $name = array_pop($parts);
        $path = "/" . implode('/', $parts);
      }
      else {
        $name = $fileName;
        $path = $file->getPath();
      }
      if (strpos($name, $old_pattern) !== FALSE) {
        $new_filename = str_replace($old_pattern, $new_pattern, $name);
        if ($file->isFile()) {
          $filesToChange[$file->getRealPath()] = $file->getPath() . "/$new_filename";
        }
        else {
          // Store directories by path depth.
          $depth = count(explode('/', $path));
          $dirsToChange[$depth][$file->getRealPath()] = "$path/$new_filename";
        }
      }
    }
    foreach ($filesToChange as $old => $new) {
      (new Filesystem())->rename($old, $new);
    }
    // Rename directories starting with the most nested to avoid renaming
    // parents directories first.
    krsort($dirsToChange);
    foreach ($dirsToChange as $dirs) {
      foreach ($dirs as $old => $new) {
        (new Filesystem())->rename($old, $new);
      }
    }
  }

  /**
   * Gets the contents of a directory.
   *
   * @param string $path
   *   The path of the directory.
   * @param bool $excludeDirs
   *   (optional) If TRUE, all directories will be excluded. Defaults to FALSE.
   *
   * @return \SplFileInfo[]
   *   Array of objects containing file information.
   */
  private static function getDirContents(string $path, bool $excludeDirs = FALSE): array {
    $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));

    $files = [];
    /** @var \SplFileInfo $file */
    foreach ($rii as $file) {
      if ($excludeDirs && $file->isDir()) {
        continue;
      }
      $files[] = $file;
    }

    return $files;
  }

  /**
   * Ensures the git status is clean.
   *
   * @return bool
   *   TRUE if git status is clean , otherwise returns a exception.
   */
  private static function ensureGitClean(): bool {
    $status_output = shell_exec('git status');
    if (strpos($status_output, 'nothing to commit, working tree clean') === FALSE) {
      throw new \Exception("git not clean: " . $status_output);
    }
    return TRUE;
  }

  /**
   * Gets the current git branch.
   *
   * @return string
   *   The current git branch.
   */
  private static function getCurrentBranch(): string {
    return trim(shell_exec('git rev-parse --abbrev-ref HEAD'));
  }

  /**
   * Switches to the branches we need.
   *
   * @param string $core_dir
   *   The path to the root of Drupal Core.
   * @param string $core_branch
   *   The core merge request branch.
   * @param string $contrib_branch
   *   The contrib branch to switch to.
   */
  private static function switchToBranches(string $core_dir, string $core_branch, string $contrib_branch): void {
    static::switchToBranch($contrib_branch);
    chdir($core_dir);
    static::switchToBranch($core_branch);
  }

  /**
   * Switches to a branches and makes sure it is clean.
   *
   * @param string $branch
   *   The branch to switch to.
   */
  private static function switchToBranch(string $branch): void {
    static::ensureGitClean();
    shell_exec("git checkout $branch");
    if ($branch !== static::getCurrentBranch()) {
      throw new \Exception("could not check $branch");
    }
  }

  /**
   * Makes the commit to the merge request.
   *
   * Should only be used if core code checks fail for a known reason that can
   * be ignored.
   *
   * @param \Composer\Script\Event $event
   *   The Composer event.
   */
  public static function makeCommit(Event $event): void {
    $args = $event->getArguments();
    $count_arg = count($args);
    if ($count_arg !== 1) {
      throw new \Exception("This scripts 1 required arguments: a directory that is a core clone");
    }
    $core_dir = $args[0];
    static::doMakeCommit($core_dir);
  }

  /**
   * Makes commit in the root of Drupal Core.
   *
   * @param string $core_dir
   *   The path to the root of Drupal Core.
   */
  private static function doMakeCommit(string $core_dir): void {
    chdir(self::getContribDir());
    self::ensureGitClean();
    $hash = trim(shell_exec('git rev-parse HEAD'));
    $message = trim(shell_exec("git show -s --format='%s'"));
    chdir($core_dir);
    // Make sure ALL files are committed, including the core/modules/package_manager/tests/fixtures/fake_site/core directory!
    shell_exec('git add -f core/modules/package_manager');
    shell_exec('git add core');
    shell_exec("git commit -m 'Contrib: $message - https://git.drupalcode.org/project/automatic_updates/-/commit/$hash'");
  }

  /**
   * Adds new words to cspell dictionary.
   *
   * @param string $core_dir
   *   The path to the root of Drupal Core.
   * @param string $dict_file_to_merge
   *   The path to the dictionary file with additional words.
   */
  private static function addWordsToDictionary(string $core_dir, string $dict_file_to_merge): void {
    if (!file_exists($dict_file_to_merge)) {
      throw new \LogicException(sprintf('%s does not exist', $dict_file_to_merge));
    }
    $dict_file = $core_dir . '/core/misc/cspell/dictionary.txt';
    $contents = file_get_contents($dict_file);
    $words = explode("\n", $contents);
    $words = array_filter($words);
    $new_words = explode("\n", file_get_contents($dict_file_to_merge));
    $words = array_merge($words, $new_words);
    $words = array_unique($words);
    asort($words);
    file_put_contents($dict_file, implode("\n", $words));
  }

  /**
   * Runs code quality checks.
   *
   * @param string $core_dir
   *   The path to the root of Drupal Core.
   */
  private static function runCoreChecks(string $core_dir): void {
    chdir($core_dir);
    $result = NULL;
    system(' sh ./core/scripts/dev/commit-code-check.sh --branch 10.1.x', $result);
    if ($result !== 0) {
      print "😭commit-code-check.sh failed";
      print "Reset using this command in the core checkout:";
      print "  rm -rf core/modules/package_manager && git checkout -- core && cd core && yarn install && cd ..";
      exit(1);
    }
    print "🎉 commit-code-check.sh passed!";
  }

  /**
   * Removes lines from the module based on a starting and ending token.
   *
   * These are lines that are not needed in core at all.
   *
   * @param string $core_dir
   *   The path to the root of Drupal Core.
   */
  private static function removeLines(string $core_dir): void {
    $files = static::getDirContents(static::getCoreModulePath($core_dir), TRUE);
    foreach ($files as $file) {
      $filePath = $file->getRealPath();
      $contents = file_get_contents($filePath);
      $lines = explode("\n", $contents);
      $skip = FALSE;
      $newLines = [];
      foreach ($lines as $line) {
        if (str_contains($line, '// BEGIN: DELETE FROM CORE MERGE REQUEST')) {
          if ($skip) {
            throw new \Exception("Already found begin");
          }
          $skip = TRUE;
        }
        if (!$skip) {
          $newLines[] = $line;
        }
        if (str_contains($line, '// END: DELETE FROM CORE MERGE REQUEST')) {
          if (!$skip) {
            throw new \Exception("Didn't find matching begin");
          }
          $skip = FALSE;
        }
      }
      if ($skip) {
        throw new \Exception("Didn't find ending token");
      }
      // Remove extra blank.
      if ($newLines[count($newLines) - 1] === '' && $newLines[count($newLines) - 2] === '') {
        array_pop($newLines);
      }
      file_put_contents($filePath, implode("\n", $newLines));
    }
  }

}
