<?php

namespace Drupal\Tests\acquia_connector\Unit;

use Drupal\acquia_connector\AutoConnector;
use Drupal\acquia_connector\Helper\Storage;
use Drupal\acquia_connector\Subscription;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\acquia_connector\AutoConnector
 *
 * @group Acquia connector
 */
class AutoConnectorTest extends UnitTestCase {

  public function testAutoConnect() {

    $subscription_mock = $this->prophesize(Subscription::CLASS);
    $subscription_mock->hasCredentials()->willReturn(FALSE);
    $subscription_mock->update()->willReturn(TRUE);

    $storage_mock = $this->prophesize(Storage::CLASS);

    $user_mock = $this->prophesize(AccountInterface::CLASS);
    $user_mock->hasPermission('administer site configuration')->willReturn(TRUE);

    $config = [
      'ah_network_identifier' => 'WXYZ-12345',
      'ah_network_key' => '12345678901234567890',
    ];

    $auto_connect = new AutoConnector($subscription_mock->reveal(), $storage_mock->reveal(), $user_mock->reveal(), $config);

    $auto_connected = $auto_connect->ensure();

    $this->assertTrue($auto_connected);

    $storage_mock->setKey('12345678901234567890')->shouldHaveBeenCalled();
    $storage_mock->setIdentifier('WXYZ-12345')->shouldHaveBeenCalled();
    $subscription_mock->update()->shouldHaveBeenCalled();

  }

  public function testAutoConnectWhenAlreadyConnected() {

    $subscription_mock = $this->prophesize(Subscription::CLASS);
    $subscription_mock->hasCredentials()->willReturn(TRUE);
    $subscription_mock->update()->shouldNotBeCalled();

    $storage_mock = $this->prophesize(Storage::CLASS);
    $storage_mock->setKey()->shouldNotBeCalled();
    $storage_mock->setIdentifier()->shouldNotBeCalled();

    $user_mock = $this->prophesize(AccountInterface::CLASS);

    $config = [
      'ah_network_identifier' => 'WXYZ-12345',
      'ah_network_key' => '12345678901234567890',
    ];

    $auto_connect = new AutoConnector($subscription_mock->reveal(), $storage_mock->reveal(), $user_mock->reveal(), $config);

    $auto_connected = $auto_connect->ensure();

    $this->assertFalse($auto_connected);

  }

  public function testAutoConnectWhenNoCredsInGlobalConfig() {

    $subscription_mock = $this->prophesize(Subscription::CLASS);
    $subscription_mock->hasCredentials()->willReturn(FALSE);
    $subscription_mock->update()->shouldNotBeCalled();

    $storage_mock = $this->prophesize(Storage::CLASS);
    $storage_mock->setKey()->shouldNotBeCalled();
    $storage_mock->setIdentifier()->shouldNotBeCalled();

    $user_mock = $this->prophesize(AccountInterface::CLASS);

    $config = [];

    $auto_connect = new AutoConnector($subscription_mock->reveal(), $storage_mock->reveal(), $user_mock->reveal(), $config);

    $auto_connected = $auto_connect->ensure();

    $this->assertFalse($auto_connected);

  }

}
