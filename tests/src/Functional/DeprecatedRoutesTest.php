<?php

namespace Drupal\Tests\automatic_updates\Functional;

use Drupal\Core\Url;

/**
 * @covers \Drupal\automatic_updates\Controller\UpdateController::redirectDeprecatedRoute
 * @covers \Drupal\automatic_updates\Routing\RouteSubscriber
 *
 * @group automatic_updates
 * @group legacy
 */
class DeprecatedRoutesTest extends AutomaticUpdatesFunctionalTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['automatic_updates'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests that deprecated routes are redirected with an informative message.
   */
  public function testDeprecatedRoutesAreRedirected(): void {
    $account = $this->createUser(['administer software updates']);
    $this->drupalLogin($account);

    $routes = [
      'automatic_updates.module_update' => 'update.module_update',
      'automatic_updates.report_update' => 'update.report_update',
      'automatic_updates.theme_update' => 'update.theme_update',
    ];
    $assert_session = $this->assertSession();

    foreach ($routes as $deprecated_route => $redirect_route) {
      $deprecated_url = Url::fromRoute($deprecated_route)
        ->setAbsolute()
        ->toString();
      $redirect_url = Url::fromRoute($redirect_route)
        ->setAbsolute()
        ->toString();

      $this->drupalGet($deprecated_url);
      $assert_session->statusCodeEquals(200);
      $assert_session->addressEquals($redirect_url);
      $assert_session->responseContains("This page was accessed from $deprecated_url, which is deprecated and will not work in the next major version of Automatic Updates. Please use <a href=\"$redirect_url\">$redirect_url</a> instead.");
    }
  }

}
