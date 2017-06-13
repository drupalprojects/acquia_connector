<?php

namespace Drupal\Tests\acquia_search\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\acquia_search\AcquiaSearchV3ApiClient;
use Prophecy\Argument;
use Drupal\Component\Serialization\Json;

/**
 * @coversDefaultClass \Drupal\acquia_search\AcquiaSearchV3ApiClient
 *
 * @group Acquia search
 */
class AcquiaSearchV3ApiClientTest extends UnitTestCase {

  public function setUp() {
    parent::setUp();
    $this->search_v3_host = 'https://api.sr-dev.acquia.com';
    $this->search_v3_api_key = 'XXXXXXXXXXyyyyyyyyyyXXXXXXXXXXyyyyyyyyyy';
  }

  /**
   * Tests call to search v3 api.
   */
  public function testSearchV3ApiCall() {

    $path = '/index/network_id/get_all?network_id=WXYZ-12345';
    $data = array(
      'host' => $this->search_v3_host,
      'headers' => array(
        'x-api-key' => $this->search_v3_api_key,
      )
    );
    $uri = $data['host'] . $path;
    $options = array(
      'headers' => $data['headers'],
      'body' => Json::encode($data),
    );

    $json = '[{"name":"WXYZ-12345.dev.drupal8","host":"test.sr-dev.acquia.com"}]';
    $stream = $this->prophesize('Psr\Http\Message\StreamInterface');
    $stream->getSize()->willReturn(1000);
    $stream->read(1000)->willReturn($json);

    $response = $this->prophesize('Psr\Http\Message\ResponseInterface');
    $response->getStatusCode()->willReturn(200);
    $response->getBody()->willReturn($stream);

    $guzzleClient = $this->prophesize('\GuzzleHttp\Client');
    $guzzleClient->get($uri, $options)->willReturn($response);

    $cache = $this->prophesize('\Drupal\Core\Cache\CacheBackendInterface');

    $expected = [
      [
        'balancer' => 'test.sr-dev.acquia.com',
        'core_id' => 'WXYZ-12345.dev.drupal8',
        'version' => 'v3'
      ]
    ];

    $client = new AcquiaSearchV3ApiClient($this->search_v3_host, $this->search_v3_api_key, $guzzleClient->reveal(), $cache->reveal());
    $this->assertEquals($expected, $client->getSearchV3Indexes('WXYZ-12345'));
  }

}
