<?php

namespace Drupal\automatic_updates\EventSubscriber;

use Drupal\automatic_updates\Event\PostUpdateEvent;
use Drupal\automatic_updates\Event\UpdateEvents;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Post update event subscriber.
 */
class PostUpdateSubscriber implements EventSubscriberInterface {
  use StringTranslationTrait;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailManager;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * PostUpdateSubscriber constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Mail\MailManagerInterface $mail_manager
   *   The mail manager.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   Entity type manager.
   */
  public function __construct(ConfigFactoryInterface $config_factory, MailManagerInterface $mail_manager, LanguageManagerInterface $language_manager, EntityTypeManagerInterface $entity_type_manager) {
    $this->configFactory = $config_factory;
    $this->mailManager = $mail_manager;
    $this->languageManager = $language_manager;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      UpdateEvents::POST_UPDATE => ['onPostUpdate'],
    ];
  }

  /**
   * Send notification on post update with success/failure.
   *
   * @param \Drupal\automatic_updates\Event\PostUpdateEvent $event
   *   The post update event.
   */
  public function onPostUpdate(PostUpdateEvent $event) {
    $notify_list = $this->configFactory->get('update.settings')->get('notification.emails');
    if (!empty($notify_list)) {
      $params['subject'] = $this->t('Automatic update of "@project" succeeded', ['@project' => $event->getUpdateMetadata()->getProjectName()]);
      if (!$event->success()) {
        $params['subject'] = $this->t('Automatic update of "@project" failed', ['@project' => $event->getUpdateMetadata()->getProjectName()]);
      }
      $params['body'] = [
        '#theme' => 'automatic_updates_post_update',
        '#success' => $event->success(),
        '#metadata' => $event->getUpdateMetadata(),
      ];
      $default_langcode = $this->languageManager->getDefaultLanguage()->getId();
      $params['langcode'] = $default_langcode;
      foreach ($notify_list as $to) {
        $this->doSend($to, $params);
      }
    }
  }

  /**
   * Composes and send the email message.
   *
   * @param string $to
   *   The email address where the message will be sent.
   * @param array $params
   *   Parameters to build the email.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function doSend($to, array $params) {
    $users = $this->entityTypeManager->getStorage('user')
      ->loadByProperties(['mail' => $to]);
    foreach ($users as $user) {
      $to_user = reset($users);
      $params['langcode'] = $to_user->getPreferredLangcode();
      $this->mailManager->mail('automatic_updates', 'post_update', $to, $params['langcode'], $params);
    }
  }

}
