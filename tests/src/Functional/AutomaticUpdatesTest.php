<?php

namespace Drupal\Tests\automatic_updates\Functional;

use Composer\Semver\VersionParser;
use Drupal\Core\Url;
use Drupal\Tests\BrowserTestBase;

/**
 * Tests of automatic updates.
 *
 * @group automatic_updates
 */
class AutomaticUpdatesTest extends BrowserTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = [
    'automatic_updates',
    'test_automatic_updates',
  ];

  /**
   * A user with permission to administer site configuration.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $user;

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();
    $this->user = $this->drupalCreateUser(['administer site configuration']);
    $this->drupalLogin($this->user);
  }

  /**
   * Tests that the JSON is parsable.
   */
  public function testJson() {
    $this->drupalGet(Url::fromRoute('test_automatic_updates.json_test_controller'));
    $json = json_decode($this->getSession()->getPage()->getContent(), TRUE);
    $this->assertEquals($json[0]['title'], 'Critical Release - PSA-2019-02-19');
    $parser = new VersionParser();
    $constraint = $parser->parseConstraints($json[0]['version']);
    $core_constraint = $parser->parseConstraints(\Drupal::VERSION);
    $this->assertFALSE($constraint->matches($core_constraint));
    $constraint = $parser->parseConstraints($json[1]['version']);
    $core_constraint = $parser->parseConstraints(\Drupal::VERSION);
    $this->assertTRUE($constraint->matches($core_constraint));
  }

}
