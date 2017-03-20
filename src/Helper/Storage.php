<?php

namespace Drupal\acquia_connector\Helper;

/**
 * Class Storage.
 */
class Storage {

  static public function getIdentifier() {
    return \Drupal::state()->get('acquia_connector.identifier');
  }

  static public function getKey() {
    return \Drupal::state()->get('acquia_connector.key');
  }

  static public function setIdentifier($value) {
    \Drupal::state()->set('acquia_connector.identifier', $value);
  }

  static public function setKey($value) {
    \Drupal::state()->set('acquia_connector.key', $value);
  }

}
