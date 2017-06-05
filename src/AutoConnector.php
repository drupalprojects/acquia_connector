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
   * AutoConnector constructor.
   *
   * @param Subscription $subscription
   * @param Storage $storage
   * @param AccountInterface $user
   * @param array $global_config
   */
  public function __construct(Subscription $subscription, Storage $storage, AccountInterface $user, array $global_config) {
    $this->subscription = $subscription;
    $this->storage = $storage;
    $this->global_config = $global_config;
    $this->user = $user;
  }

  /**
   * Ensures a connection to Acquia Subscription.
   *
   * @return bool|mixed
   */
  public function ensure() {

    if ($this->subscription->hasCredentials()) {
      return FALSE;
    }

    if (empty($this->global_config['ah_network_key'])) {
      return FALSE;
    }

    if (empty($this->global_config['ah_network_identifier'])) {
      return FALSE;
    }

    $this->storage->setKey($this->global_config['ah_network_key']);
    $this->storage->setIdentifier($this->global_config['ah_network_identifier']);

    $activated = $this->subscription->update();

    if ($activated && $this->user->hasPermission('administer site configuration')) {
      $this->showMessage();
    }

    return $activated;

  }

  protected function showMessage() {
    if (function_exists('t') && function_exists('drupal_set_message')) {
      $url = Url::fromRoute('acquia_connector.setup');
      $text = t('Your site has been automatically connected to Acquia. <a href="!url">Change subscription</a>', ['!url' => $url]);
      drupal_set_message($text, 'status', FALSE);
    }
  }

}
