<?php

/**
 * @file
 * Contains Drupal\acquia_search\AcquiaSearchV3ApiClient.
 */

namespace Drupal\acquia_search;

use Drupal\Component\Serialization\Json;
use GuzzleHttp\Exception\RequestException;

/**
 * Class AcquiaSearchV3ApiClient.
 *
 * @package Drupal\acquia_search\
 */

class AcquiaSearchV3ApiClient {

  /**
   * The HTTP client to fetch the feed data with.
   *
   * @var \GuzzleHttp\Client $client
   */
  protected $client;

  public function __construct($host, $api_key, $http_client, $cache) {
    $this->search_v3_host = $host;
    $this->search_v3_api_key = $api_key;
    $this->httpClient = $http_client;
    $this->headers = array(
      'Content-Type' => 'application/json',
      'Accept' => 'application/json',
    );
    $this->cache = $cache;
  }

  /**
   * Helper function to fetch all search v3 indexes for given network_id.
   *
   * @param $network_id
   *   Subscription network id.
   *
   * @return array|false
   *   Response array or FALSE
   */
  public function getSearchV3Indexes($network_id) {
    $result = FALSE;
    if ($cache = $this->cache->get('acquia_search.v3indexes')) {
      if (!empty($cache->data) && $cache->expire > time()) {
        return $cache->data;
      }
    }
    $indexes = $this->search_request('/index/network_id/get_all?network_id=' . $network_id);
    if (is_array($indexes) && !empty($indexes)) {
      foreach ($indexes as $index) {
        $result[] = array(
          'balancer' => $index['host'],
          'core_id' => $index['name'],
          'version' => 'v3'
        );
      }
    }
    if ($result) {
      $this->cache->set('acquia_search.v3indexes', $result, time() + (24 * 60 * 60));
    }

    return $result;
  }

  /**
   * Helper function to fetch the search v3 index keys for
   * given core_id and network_id.
   *
   * @param $core_id
   * @param $network_id
   *
   * @return array|bool|false
   *   Search v3 index keys.
   */
  public function getKeys($core_id, $network_id) {
    if ($cache = $this->cache->get('acquia_search.v3keys')) {
      if (!empty($cache->data) && $cache->expire > time()) {
        return $cache->data;
      }
    }

    $keys = $this->search_request('/index/key?index_name=' . $core_id . '&network_id=' . $network_id);
    if ($keys) {
      $this->cache->set('acquia_search.v3keys', $keys, time() + (24 * 60 * 60));
    }

    return $keys;
  }

  /**
   * Create and send a request to search controller.
   *
   * @param string $path
   *   Path to call.
   *
   * @return array|false
   *   Response array or FALSE.
   */
  public function search_request($path) {
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

    try {
      $response = $this->httpClient->get($uri, $options);
      if (!$response) {
        throw new \Exception('Empty Response');
      }
      $stream_size = $response->getBody()->getSize();
      $data = Json::decode($response->getBody()->read($stream_size));
      $status_code = $response->getStatusCode();

      if ($status_code < 200 || $status_code > 299) {
        \Drupal::logger('acquia search')->error('Couldn\'t connect to search v3 api: ' . $response->getReasonPhrase());
        return FALSE;
      }
      return $data;
    }
    catch (RequestException $e) {
      \Drupal::logger('acquia search')->error('Couldn\'t connect to search v3 api: ' . $e->getMessage());
    }
    catch (\Exception $e) {
      \Drupal::logger('acquia search')->error('Couldn\'t connect to search v3 api: ' . $e->getMessage());
    }

    return FALSE;
  }
}
