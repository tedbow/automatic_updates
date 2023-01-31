<?php

declare(strict_types = 1);

namespace Drupal\package_manager;

use PhpTuf\ComposerStager\Domain\Service\ProcessRunner\ComposerRunnerInterface;

/**
 * Defines a class to get information from Composer.
 *
 * @internal
 *   This is an internal part of Package Manager and may be changed or removed
 *   at any time without warning. External code should not interact with this
 *   class.
 */
final class ComposerInspector {

  /**
   * The Composer runner service from Composer Stager.
   *
   * @var \PhpTuf\ComposerStager\Domain\Service\ProcessRunner\ComposerRunnerInterface
   */
  protected ComposerRunnerInterface $runner;

  /**
   * The JSON process output callback.
   *
   * @var \Drupal\package_manager\JsonProcessOutputCallback
   */
  private JsonProcessOutputCallback $jsonCallback;

  /**
   * Constructs a ComposerInspector object.
   *
   * @param \PhpTuf\ComposerStager\Domain\Service\ProcessRunner\ComposerRunnerInterface $runner
   *   The Composer runner service from Composer Stager.
   */
  public function __construct(ComposerRunnerInterface $runner) {
    $this->runner = $runner;
    $this->jsonCallback = new JsonProcessOutputCallback();
  }

  /**
   * Returns a config value from Composer.
   *
   * @param string $key
   *   The config key to get.
   * @param string $working_dir
   *   The working directory in which to run Composer.
   *
   * @return mixed|null
   *   The output data.
   */
  public function getConfig(string $key, string $working_dir) {
    $this->runner->run(['config', $key, "--working-dir=$working_dir", '--json'], $this->jsonCallback);
    return $this->jsonCallback->getOutputData();
  }

  /**
   * Returns the current Composer version.
   *
   * @param string $working_dir
   *   The working directory in which to run Composer.
   *
   * @return string
   *   The Composer version.
   *
   * @throws \UnexpectedValueException
   *   Thrown if the expect data format is not found.
   */
  public function getVersion(string $working_dir): string {
    $this->runner->run(['--format=json', "--working-dir=$working_dir"], $this->jsonCallback);
    $data = $this->jsonCallback->getOutputData();
    if (isset($data['application']['name'])
      && isset($data['application']['version'])
      && $data['application']['name'] === 'Composer'
      && is_string($data['application']['version'])) {
      return $data['application']['version'];
    }
    throw new \UnexpectedValueException('Unable to determine Composer version');
  }

}
