<?php

/**
 * @file
 * Contains \Drupal\acquia_connector\Tests\Unit\AcquiaConnectorUnitTest.
 */

namespace Drupal\Tests\acquia_connector\Unit;

use Drupal\Core\Session\AccountInterface;
use Drupal\acquia_connector\Controller\StatusController;
use Drupal\acquia_connector\AutoConnector;
use Drupal\acquia_connector\Helper\Storage;
use Drupal\acquia_connector\Subscription;
use Drupal\Tests\UnitTestCase;
use Drupal\acquia_connector\Client;

if (!defined('REQUEST_TIME')) {
  define('REQUEST_TIME', (int) $_SERVER['REQUEST_TIME']);
}

/**
 * @coversDefaultClass \Drupal\acquia_connector\Client
 *
 * @group Acquia connector
 */
class AcquiaConnectorUnitTest extends UnitTestCase {
  protected $id;
  protected $key;
  protected $salt;
  protected $derivedKey;

  /**
   * {@inheritdoc}
   */
  protected function setUp() {

    parent::setUp();
  }

  /**
   * Test authenticators.
   */
  public function testAuthenticators() {
    $identifier = $this->randomMachineName();
    $key = $this->randomMachineName();
    $params = array('time', 'nonce', 'hash');

    $client = new ClientTest();
    $result = $client->buildAuthenticator($key, $params);
    // Test Client::buildAuthenticator.
    $valid = is_array($result);
    $this->assertTrue($valid, 'Client::buildAuthenticator returns an array');
    if ($valid) {
      foreach ($params as $key) {
        if (!array_key_exists($key, $result)) {
          $valid = FALSE;
          break;
        }
      }
      $this->assertTrue($valid, 'Array has expected keys');
    }
    // Test Client::buildAuthenticator.
    $result = $client->buildAuthenticator($identifier, array());
    $valid = is_array($result);
    $this->assertTrue($valid, 'Client::buildAuthenticator returns an array');
    if ($valid) {
      foreach ($params as $key) {
        if (!array_key_exists($key, $result)) {
          $valid = FALSE;
          break;
        }
      }
      $this->assertTrue($valid, 'Array has expected keys');
    }
  }

  /**
   * Test Id From Subscription.
   */
  public function testIdFromSub() {
    $statusController = new StatusControllerTest();
    $uuid = $statusController->getIdFromSub(array('uuid' => 'test'));
    $this->assertEquals('test', $uuid, 'UUID property identical');
    $data = array('href' => 'http://example.com/network/uuid/test/dashboard');
    $uuid = $statusController->getIdFromSub($data);
    $this->assertEquals('test', $uuid, 'UUID extracted from href');
  }

  public function testAutoConnect() {

    $subscription_mock = $this->prophesize(Subscription::CLASS);
    $subscription_mock->hasCredentials()->willReturn(FALSE);
    $subscription_mock->update()->willReturn(TRUE);

    $storage_mock = $this->prophesize(Storage::CLASS);

    $user_mock = $this->prophesize(AccountInterface::CLASS);
    $user_mock->hasPermission('administer site configuration')->willReturn(TRUE);

    $config = array(
      'ah_network_identifier' => 'WXYZ-12345',
      'ah_network_key' => '12345678901234567890',
    );

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

    $config = array(
      'ah_network_identifier' => 'WXYZ-12345',
      'ah_network_key' => '12345678901234567890',
    );

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

    $config = array();

    $auto_connect = new AutoConnector($subscription_mock->reveal(), $storage_mock->reveal(), $user_mock->reveal(), $config);

    $auto_connected = $auto_connect->ensure();

    $this->assertFalse($auto_connected);

  }

}
/**
 * {@inheritdoc}
 */
class ClientTest extends Client {

  /**
   * Construction method.
   */
  public function __construct(){}

  /**
   * {@inheritdoc}
   */
  public  function buildAuthenticator($key, $params = array()) {
    return parent::buildAuthenticator($key, $params);
  }

}

/**
 * Class StatusController.
 */
class StatusControllerTest extends StatusController {

  /**
   * Construction method.
   */
  public function __construct(){}

  /**
   * Gets the subscription UUID from subscription data.
   *
   * @param array $sub_data
   *   An array of subscription data.
   *
   * @see acquia_agent_settings('acquia_subscription_data')
   *
   * @return string
   *   The UUID taken from the subscription data.
   */
  public function getIdFromSub($sub_data) {
    return parent::getIdFromSub($sub_data);
  }

}
