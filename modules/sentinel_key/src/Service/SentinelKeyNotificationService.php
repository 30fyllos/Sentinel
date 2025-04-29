<?php

namespace Drupal\sentinel_key\Service;

use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * Provides a service for sending API key notifications.
 *
 * This implementation enqueues notification tasks to be processed asynchronously
 * and offers methods to notify users via email and on-site messenger.
 */
class SentinelKeyNotificationService implements SentinelKeyNotificationServiceInterface {

  use StringTranslationTrait;

  /**
   * The mail manager.
   *
   * @var MailManagerInterface
   */
  protected MailManagerInterface $mailManager;

  /**
   * The messenger service.
   *
   * @var MessengerInterface
   */
  protected MessengerInterface $messenger;

  /**
   * The queue factory.
   *
   * @var QueueFactory
   */
  protected QueueFactory $queueFactory;

  /**
   * The configuration factory.
   *
   * @var ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The logger channel.
   *
   * @var LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * The notification queue.
   *
   * @var QueueInterface
   */
  protected QueueInterface $notificationQueue;

  /**
   * Constructs a new ApiSentinelNotificationService.
   *
   * @param MailManagerInterface $mailManager
   *   The mail manager.
   * @param MessengerInterface $messenger
   *   The messenger service.
   * @param QueueFactory $queueFactory
   *   The queue factory.
   * @param ConfigFactoryInterface $configFactory
   *   The configuration factory.
   * @param LoggerChannelInterface $logger
   *   The logger channel.
   */
  public function __construct(MailManagerInterface $mailManager, MessengerInterface $messenger, QueueFactory $queueFactory, ConfigFactoryInterface $configFactory, LoggerChannelInterface $logger) {
    $this->mailManager = $mailManager;
    $this->messenger = $messenger;
    $this->queueFactory = $queueFactory;
    $this->configFactory = $configFactory;
    $this->logger = $logger;
    // Get or create the notification queue.
    $this->notificationQueue = $this->queueFactory->get('sentinel_key_notification');
  }

  /**
   * {@inheritdoc}
   */
  public function queueNotification(string $type, AccountInterface $account, array $data = []): void {
    if (!$account->getEmail()) {
      return;
    }
    $notification = [
      'type' => $type,
      'account' => $account,
      'email' => $account->getEmail(),
      'langcode' => $account->getPreferredLangcode(),
      'data' => $data,
      'timestamp' => time(),
    ];
    $this->notificationQueue->createItem($notification);
    $this->processNotification($notification);
    $this->logger->info('Notification queued for user @uid (type: @type)', [
      '@uid' => $account->id(),
      '@type' => $type,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function notifyNewKey(AccountInterface $account): void {
    $data = [
      // TODO change link.
      'link' => Url::fromRoute('sentinel_key.view_api_key', ['uid' => $account->id()], ['absolute' => TRUE])->toString(),
    ];
    $this->queueNotification('new_key', $account, $data);
  }

  /**
   * {@inheritdoc}
   */
  public function notifyBlocked(AccountInterface $account): void {
    $this->queueNotification('block', $account);
  }

  /**
   * {@inheritdoc}
   */
  public function notifyUnblocked(AccountInterface $account): void {
    $this->queueNotification('unblock', $account);
  }

  /**
   * {@inheritdoc}
   */
  public function notifyRevoked(AccountInterface $account): void {
    $this->queueNotification('revoke', $account);
  }

  /**
   * {@inheritdoc}
   */
  public function notifyRateLimit(AccountInterface $account): void {
    $this->queueNotification('rate_limit', $account);
  }

  /**
   * {@inheritdoc}
   */
  public function processNotification(array $notification): void {
    $type = $notification['type'];
    $account = $notification['account'];
    $email = $notification['email'];
    $langcode = $notification['langcode'];
    $data = $notification['data'];

    // Determine subject and message based on the notification type.
    switch ($type) {
      case 'new_key':
        $subject = $this->t('Your new API key');
        $message = $this->t('A new API key has been generated for your account.');
        if (!empty($data['link'])) {
          $message .= "\n" . $this->t('Click this secure link to view your API key: @link', ['@link' => $data['link']]);
        }
        break;

      case 'block':
        $subject = $this->t('Your API key has been blocked');
        $message = $this->t('Your API key has been blocked due to security concerns. Please contact support for further information.');
        break;

      case 'unblock':
        $subject = $this->t('Your API key has been unblocked');
        $message = $this->t('Your API key has been unblocked and is active again.');
        break;

      case 'revoke':
        $subject = $this->t('Your API key has been revoked');
        $message = $this->t('Your API key has been revoked. If this is unexpected, please contact support.');
        break;

      case 'rate_limit':
        $subject = $this->t('API key rate limit reached');
        $message = $this->t('Your API key has reached its rate limit. Please reduce your request frequency.');
        break;

      default:
        $subject = $this->t('API Key Notification');
        $message = $this->t('There is an update regarding your API key.');
        break;
    }

    // Prepare email parameters.
    $module = 'sentinel_key';
    $params = [
      'subject' => $subject,
      'message' => $message,
      'account' => $account,
    ];

    $result = $this->mailManager->mail($module, $type, $email, $langcode, $params, NULL, TRUE);

    if ($result['result'] !== TRUE) {
      $this->logger->error('Failed to send notification email to user @uid (type: @type)', [
        '@uid' => $account->id(),
        '@type' => $type,
      ]);
    }
    else {
      $this->logger->info('Notification email sent to user @uid (type: @type)', [
        '@uid' => $account->id(),
        '@type' => $type,
      ]);
    }
  }

}
