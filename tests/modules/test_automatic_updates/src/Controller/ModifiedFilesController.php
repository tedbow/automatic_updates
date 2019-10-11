<?php

namespace Drupal\test_automatic_updates\Controller;

use Drupal\automatic_updates\ProjectInfoTrait;
use Drupal\automatic_updates\Services\ModifiedFilesInterface;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class ModifiedFilesController.
 */
class ModifiedFilesController extends ControllerBase {
  use ProjectInfoTrait;

  /**
   * The modified files service.
   *
   * @var \Drupal\automatic_updates\Services\ModifiedFilesInterface
   */
  protected $modifiedFiles;

  /**
   * ModifiedFilesController constructor.
   *
   * @param \Drupal\automatic_updates\Services\ModifiedFilesInterface $modified_files
   *   The modified files service.
   */
  public function __construct(ModifiedFilesInterface $modified_files) {
    $this->modifiedFiles = $modified_files;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('automatic_updates.modified_files')
    );
  }

  /**
   * Test modified files service.
   *
   * @param string $project_type
   *   The project type.
   * @param string $extension
   *   The extension name.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   A status message of modified files .
   */
  public function modified($project_type, $extension) {
    // Special edge case for core.
    if ($project_type === 'core') {
      $infos = $this->getInfos('module');
      $extensions = array_filter($infos, function (array $info) {
        return $info['project'] === 'drupal';
      });
    }
    // Filter for the main project.
    else {
      $infos = $this->getInfos($project_type);
      $extensions = array_filter($infos, function (array $info) use ($extension, $project_type) {
        return $info['install path'] === "{$project_type}s/contrib/$extension";
      });
    }

    $response = Response::create('No modified files!');
    $messages = $this->modifiedFiles->getModifiedFiles($extensions);
    if (!empty($messages)) {
      $response->setContent('Modified files include: ' . implode(', ', $messages));
    }
    return $response;
  }

}
