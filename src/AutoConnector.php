<?php

namespace Drupal\acquia_connector;

use Drupal\acquia_connector\Helper\Storage;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;

/**
 * Class AutoConnector.
 *
 * @package Drupal\acquia_connector.
 */
class AutoConnector {

  /**
   * Holds Subscription.
   *
   * @var Subscription
   */
  protected $subscription;

  /**
   * Holds Storage.
   *
   * @var Storage
   */
  protected $storage;

  /**
   * Holds global config.
   *
   * @var array
   */
  protected $globalConfig;

  /**
   * Holds User.
   *
   * @var AccountInterface
   */
  protected $user;

  /**
   * AutoConnector constructor.
   *
   * @param Subscription $subscription
   *   Acquia Subscription.
   * @param Storage $storage
   *   Storage.
   * @param AccountInterface $user
   *   User.
   * @param array $global_config
   *   Global config.
   */
  public function __construct(Subscription $subscription, Storage $storage, AccountInterface $user, array $global_config) {
    $this->subscription = $subscription;
    $this->storage = $storage;
    $this->globalConfig = $global_config;
    $this->user = $user;
  }

  /**
   * Ensures a connection to Acquia Subscription.
   *
   * @return bool|mixed
   *   False or whatever is returned by Subscription::update.
   */
  public function connectToAcquia() {

    if ($this->subscription->hasCredentials()) {
      return FALSE;
    }

    if (empty($this->globalConfig['ah_network_key'])) {
      return FALSE;
    }

    if (empty($this->globalConfig['ah_network_identifier'])) {
      return FALSE;
    }

    $this->storage->setKey($this->globalConfig['ah_network_key']);
    $this->storage->setIdentifier($this->globalConfig['ah_network_identifier']);

    $activated = $this->subscription->update();

    if ($activated && $this->user->hasPermission('administer site configuration')) {
      $this->showMessage();
    }

    return $activated;

  }

  /**
   * Displays DSM about automatically established connection.
   */
  protected function showMessage() {
    if (function_exists('t') && function_exists('drupal_set_message')) {
      $url = Url::fromRoute('acquia_connector.setup')->toString();
      $text = t('Your site has been automatically connected to Acquia. <a href=":url">Change subscription</a>', [':url' => $url]);
      drupal_set_message($text, 'status', FALSE);
    }
  }

}
