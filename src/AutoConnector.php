<?php

namespace Drupal\acquia_connector;

use Drupal\acquia_connector\Helper\Storage;
use Drupal\Core\Url;

/**
 * Class Subscription.
 *
 * @package Drupal\acquia_connector.
 */
class AutoConnector {

  public function __construct(Subscription $subscription, Storage $storage, $user, array $global_config) {
    $this->subscription = $subscription;
    $this->storage = $storage;
    $this->global_config = $global_config;
    $this->user = $user;
  }


  public function ensure() {

    if ($this->subscription->hasCredentials()) {
      return FALSE;
    }

    $this->storage->setKey($this->global_config['ah_network_key']);
    $this->storage->setIdentifier($this->global_config['ah_network_identifier']);

    $activated = $this->subscription->update();

    if ($activated && $this->user->hasPermission('administer site configuration')) {
      if (function_exists('t') && function_exists('drupal_set_message')) {
        $url = Url::fromRoute('acquia_connector.setup');
        $text = t('Your site has been automatically connected to Acquia. <a href="!url">Change subscription</a>', array('!url' => $url));
        drupal_set_message($text, 'status', FALSE);
      }
    }

    return $activated;

  }
}
