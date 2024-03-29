<?php

namespace Drupal\Tests\automatic_updates\Traits;

use Drupal\Core\Test\AssertMailTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * Contains helper methods for testing e-mail sent by Automatic Updates.
 */
trait EmailNotificationsTestTrait {

  use AssertMailTrait;
  use UserCreationTrait;

  /**
   * The people who should be emailed about successful or failed updates.
   *
   * The keys are the email addresses, and the values are the langcode they
   * should be emailed in.
   *
   * @var string[]
   *
   * @see ::setUpEmailRecipients()
   */
  protected $emailRecipients = [];

  /**
   * Prepares the recipient list for e-mails related to Automatic Updates.
   */
  protected function setUpEmailRecipients(): void {
    // First, create a user whose preferred language is different from the
    // default language, so we can be sure they're emailed in their preferred
    // language; we also ensure that an email which doesn't correspond to a user
    // account is emailed in the default language.
    $default_language = $this->container->get('language_manager')
      ->getDefaultLanguage()
      ->getId();
    $this->assertNotSame('fr', $default_language);

    $account = $this->createUser([], NULL, FALSE, [
      'preferred_langcode' => 'fr',
    ]);
    $this->emailRecipients['emissary@deep.space'] = $default_language;
    $this->emailRecipients[$account->getEmail()] = $account->getPreferredLangcode();

    $this->config('update.settings')
      ->set('notification.emails', array_keys($this->emailRecipients))
      ->save();
  }

  /**
   * Asserts that all recipients received a given email.
   *
   * @param string $subject
   *   The subject line of the email that should have been sent.
   * @param string $body
   *   The beginning of the body text of the email that should have been sent.
   *
   * @see ::$emailRecipients
   */
  protected function assertMessagesSent(string $subject, string $body): void {
    $sent_messages = $this->getMails([
      'subject' => $subject,
    ]);
    $this->assertNotEmpty($sent_messages);
    $this->assertSame(count($this->emailRecipients), count($sent_messages));

    // Ensure the body is formatted the way the PHP mailer would do it.
    $message = [
      'body' => [$body],
    ];
    $message = $this->container->get('plugin.manager.mail')
      ->createInstance('php_mail')
      ->format($message);
    $body = $message['body'];

    foreach ($sent_messages as $message) {
      $email = $message['to'];
      $expected_langcode = $this->emailRecipients[$email];

      $this->assertSame($expected_langcode, $message['langcode']);
      // The message, and every line in it, should have been sent in the
      // expected language.
      // @see automatic_updates_test_mail_alter()
      $this->assertArrayHasKey('line_langcodes', $message);
      $this->assertSame([$expected_langcode], $message['line_langcodes']);
      $this->assertStringStartsWith($body, $message['body']);
    }
  }

}
