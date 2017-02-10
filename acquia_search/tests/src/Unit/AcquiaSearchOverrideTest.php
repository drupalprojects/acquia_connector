<?php

/**
 * @file
 * Contains Drupal\Tests\acquia_search\Unit\AcquiaSearchTest.
 */

namespace Drupal\Tests\acquia_search\Unit;

use Drupal\acquia_search\Plugin\SolrConnector\SearchApiSolrAcquiaConnector;
use \Drupal\KernelTests\KernelTestBase;

/**
 *
 * @group Acquia search
 */
class AcquiaSearchOverrideTest extends KernelTestBase {

  public static $modules = [
    'user',
    'acquia_connector',
    'search_api',
    'acquia_search',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp() {

    parent::setUp();

    $this->installConfig(array('acquia_connector'));

  }

  /**
   * Tests to implement:
   *
   * 1. defaultConfiguration returns correct overridden values - Done.
   * 2. getUpdateQuery throws an exception when the config is overridden - To be done.
   * 3. Search API server switches to read-only mode - To be done.
   * 4. Search API index switches to read-only mode - To be done.
   */

  /**
   * No Acquia hosting and DB detected - should override into Readonly.
   */
  public function testNonAcquiaHosted() {

    $this->_setAvailableSearchCores();

    $solr_connector = new SearchApiSolrAcquiaConnector(array(), 'foo', array('foo'));
    $config = $solr_connector->defaultConfiguration();

    $this->assertEquals(ACQUIA_SEARCH_AUTO_OVERRIDE_READ_ONLY, $config['overridden_by_acquia_search']);

  }

  /**
   * Acquia Dev hosting environment detected - configs point to the index on the
   * Dev environment.
   */
  public function testAcquiaHostingEnvironmentDetected() {

    $_ENV['AH_SITE_ENVIRONMENT'] = 'dev';
    $_ENV['AH_SITE_NAME'] = 'testsite1dev';
    $_ENV['AH_SITE_GROUP'] = 'testsite1';

    $this->_setAvailableSearchCores();

    $solr_connector = new SearchApiSolrAcquiaConnector(array(), 'foo', array('foo'));
    $config = $solr_connector->defaultConfiguration();

    $db_name = $this->_getDbName();

    $this->assertEquals(ACQUIA_SEARCH_OVERRIDE_AUTO_SET, $config['overridden_by_acquia_search']);
    $this->assertEquals('WXYZ-12345.dev.' . $db_name, $config['index_id']);

  }

  /**
   * Acquia Test environment and a DB name. According to the mock, no cores
   * available for the Test environment so it is read only.
   */
  public function testAcquiaHostingEnvironmentDetectedNoAvailableCores() {

    $_ENV['AH_SITE_ENVIRONMENT'] = 'test';
    $_ENV['AH_SITE_NAME'] = 'testsite1test';
    $_ENV['AH_SITE_GROUP'] = 'testsite1';

    $this->_setAvailableSearchCores();

    $solr_connector = new SearchApiSolrAcquiaConnector(array(), 'foo', array('foo'));
    $config = $solr_connector->defaultConfiguration();

    $this->assertEquals(ACQUIA_SEARCH_AUTO_OVERRIDE_READ_ONLY, $config['overridden_by_acquia_search']);

  }

  /**
   * Acquia Prod environment and a DB name but AH_PRODUCTION isn't set - so read
   * only.
   */
  public function testAcquiaHostingProdEnvironmentDetectedWithoutProdFlag() {

    $_ENV['AH_SITE_ENVIRONMENT'] = 'prod';
    $_ENV['AH_SITE_NAME'] = 'testsite1prod';
    $_ENV['AH_SITE_GROUP'] = 'testsite1';

    $this->_setAvailableSearchCores();

    $solr_connector = new SearchApiSolrAcquiaConnector(array(), 'foo', array('foo'));
    $config = $solr_connector->defaultConfiguration();

    $this->assertEquals(ACQUIA_SEARCH_AUTO_OVERRIDE_READ_ONLY, $config['overridden_by_acquia_search']);

  }

  /**
   * Acquia Prod environment and a DB name and AH_PRODUCTION is set - so it
   * should override to connect to the prod index.
   */
  public function testAcquiaHostingProdEnvironmentDetectedWithProdFlag() {

    $_ENV['AH_SITE_ENVIRONMENT'] = 'prod';
    $_ENV['AH_SITE_NAME'] = 'testsite1prod';
    $_ENV['AH_SITE_GROUP'] = 'testsite1';

    $_SERVER['AH_PRODUCTION'] = TRUE;

    $this->_setAvailableSearchCores();

    $solr_connector = new SearchApiSolrAcquiaConnector(array(), 'foo', array('foo'));
    $config = $solr_connector->defaultConfiguration();

    $this->assertEquals(ACQUIA_SEARCH_OVERRIDE_AUTO_SET, $config['overridden_by_acquia_search']);
    $this->assertEquals('WXYZ-12345', $config['index_id']);

  }

  /**
   * Tests that it selects the correct preferred search core ID for the
   * override URL when limited number of core ID is available.
   */
  public function testApacheSolrOverrideWhenCoreWithDbNameNotAvailable() {

    // When the core ID with the DB name in it is not available, it should
    // override the URL value with the core ID that has the site folder name
    // in it.

    $_ENV['AH_SITE_ENVIRONMENT'] = 'dev';
    $_ENV['AH_SITE_NAME'] = 'testsite1dev';
    $_ENV['AH_SITE_GROUP'] = 'testsite1';

    $this->_setAvailableSearchCores(TRUE);

    $solr_connector = new SearchApiSolrAcquiaConnector(array(), 'foo', array('foo'));
    $config = $solr_connector->defaultConfiguration();

    $site_folder = $this->_getSiteFolderName();

    $this->assertEquals(ACQUIA_SEARCH_OVERRIDE_AUTO_SET, $config['overridden_by_acquia_search']);
    $this->assertEquals('WXYZ-12345.dev.' . $site_folder, $config['index_id']);

  }

  /**
   * Sets available search cores into the subscription heartbeat data.
   *
   * @param bool $no_db_flag
   *   Allows to set a limited number of search cores by excluding the one that
   *   contains the DB name.
   */
  public function _setAvailableSearchCores($no_db_flag = FALSE) {

    $acquia_identifier = 'WXYZ-12345';
    $solr_hostname = 'mock.acquia-search.com';
    $site_folder = $this->_getSiteFolderName();
    $ah_db_name = $this->_getDbName();

    $config = \Drupal::configFactory()->getEditable('acquia_connector.settings');
    $config->set('identifier', $acquia_identifier)->save();

    $core_with_folder_name = array(
      'balancer' => $solr_hostname,
      'core_id' => "{$acquia_identifier}.dev.{$site_folder}"
    );

    $core_with_db_name = array(
      'balancer' => $solr_hostname,
      'core_id' => "{$acquia_identifier}.dev.{$ah_db_name}"
    );

    $core_with_acquia_identifier = array(
      'balancer' => $solr_hostname,
      'core_id' => "{$acquia_identifier}"
    );

    if ($no_db_flag) {
      $available_cores = array(
        $core_with_folder_name,
        $core_with_acquia_identifier,
      );
    }
    else {
      $available_cores = array(
        $core_with_folder_name,
        $core_with_db_name,
        $core_with_acquia_identifier,
      );
    }

    $config->set('subscription_data', array(
      'heartbeat_data' => array('search_cores' => $available_cores)
    ))->save();

  }

  /**
   * Returns the folder name of the current site folder.
   */
  public function _getSiteFolderName() {
    $conf_path = \Drupal::service('site.path');
    return substr($conf_path, strrpos($conf_path, '/') + 1);
  }

  /**
   * Returns the current DB name.
   */
  public function _getDbName() {
    $db_conn_options = \Drupal\Core\Database\Database::getConnection()->getConnectionOptions();
    return $db_conn_options['database'];
  }

}
