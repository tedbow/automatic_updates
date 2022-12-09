<?php

declare(strict_types = 1);

namespace Drupal\Tests\automatic_updates\Build;

use Drupal\Component\Utility\Html;
use Drupal\Tests\package_manager\Build\TemplateProjectTestBase;

/**
 * Base class for tests that perform in-place updates.
 *
 * @internal
 */
abstract class UpdateTestBase extends TemplateProjectTestBase {

  /**
   * {@inheritdoc}
   */
  protected function createTestProject(string $template): void {
    parent::createTestProject($template);
    // @todo Remove in https://www.drupal.org/project/automatic_updates/issues/3284443
    $code = <<<END
\$config['automatic_updates.settings']['cron'] = 'security';
END;
    $this->writeSettings($code);
    // Install Automatic Updates, and other modules needed for testing.
    $this->installModules([
      'automatic_updates',
      'automatic_updates_test_api',
    ]);
  }

  /**
   * Checks for available updates.
   *
   * Assumes that a user with the appropriate access is logged in.
   */
  protected function checkForUpdates(): void {
    $this->visit('/admin/reports/updates');
    $this->getMink()->getSession()->getPage()->clickLink('Check manually');
    $this->waitForBatchJob();
  }

  /**
   * Waits for an active batch job to finish.
   */
  protected function waitForBatchJob(): void {
    $refresh = $this->getMink()
      ->getSession()
      ->getPage()
      ->find('css', 'meta[http-equiv="Refresh"], meta[http-equiv="refresh"]');

    if ($refresh) {
      // Parse the content attribute of the meta tag for the format:
      // "[delay]: URL=[page_to_redirect_to]".
      if (preg_match('/\d+;\s*URL=\'?(?<url>[^\']*)/i', $refresh->getAttribute('content'), $match)) {
        $url = Html::decodeEntities($match['url']);
        $this->visit($url);
        $this->waitForBatchJob();
      }
    }
  }

}
