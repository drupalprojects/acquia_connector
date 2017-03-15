<?php

namespace Drupal\acquia_search\Tests;

use Drupal\simpletest\WebTestBase;
use Drupal\search_api\Entity\Server;

/**
 * Tests the automatic switching behavior of the Acquia Search module.
 *
 * @group Acquia search
 */
class AcquiaConnectorSearchOverrideTest extends WebTestBase {
  protected $strictConfigSchema = FALSE;
  protected $id;
  protected $key;
  protected $salt;
  protected $server;
  protected $index;


  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = [
    'acquia_connector',
    'search_api',
    'search_api_solr',
    'toolbar',
    'acquia_connector_test',
    'node',
    'acquia_search_test',
  ];


  /**
   * {@inheritdoc}
   */
  public function setUp() {

    parent::setUp();
    // Generate and store a random set of credentials.
    $this->id = 'WXYZ-12345';
    $this->key = $this->randomString(32);
    $this->salt = $this->randomString(32);
    $this->server = 'acquia_search_server';
    $this->index = 'acquia_search_index';

    // Create a new content type.
    $content_type = $this->drupalCreateContentType();

    // Add a node of the new content type.
    $node_data = array(
      'type' => $content_type->id(),
    );

    $this->drupalCreateNode($node_data);
    $this->_connect();
    $this->_setAvailableSearchCores();

  }


  /**
   * Main function that calls the rest of the tests (names start with case*)
   */
  public function testOverrides() {

    $this->caseNonAcquiaHosted();
    $this->caseAcquiaHostingEnvironmentDetected();
    $this->caseAcquiaHostingEnvironmentDetectedNoAvailableCores();
    $this->caseAcquiaHostingProdEnvironmentDetectedWithoutProdFlag();
    $this->caseAcquiaHostingProdEnvironmentDetectedWithProdFlag();

  }


  /**
   * No Acquia hosting and DB detected - should override into Readonly.
   */
  public function caseNonAcquiaHosted() {

    $path = '/admin/config/search/search-api/server/' . $this->server;
    $this->drupalGet($path);

    $this->assertText('automatically enforced read-only mode on this connection.');

    $path = '/admin/config/search/search-api/index/' . $this->index;
    $this->drupalGet($path);

    $this->assertText('automatically enforced read-only mode on this connection.');

  }


  /**
   * Acquia Dev hosting environment detected - configs point to the index on the
   * Dev environment.
   */
  public function caseAcquiaHostingEnvironmentDetected() {

    $overrides = [
      'env-overrides' => 1,
      'AH_SITE_ENVIRONMENT' => 'dev',
      'AH_SITE_NAME' => 'testsite1dev',
      'AH_SITE_GROUP' => 'testsite1',
    ];

    $path = '/admin/config/search/search-api/server/' . $this->server;
    $this->drupalGet($path, ['query' => $overrides ]);

    $this->assertNoText('automatically enforced read-only mode on this connection.');

    $path = '/admin/config/search/search-api/index/' . $this->index;
    $this->drupalGet($path, ['query' => $overrides ]);

    $this->assertNoText('automatically enforced read-only mode on this connection.');

  }


  /**
   * Acquia Test environment and a DB name. According to the mock, no cores
   * available for the Test environment so it is read only.
   */
  public function caseAcquiaHostingEnvironmentDetectedNoAvailableCores() {

    $overrides = [
      'env-overrides' => 1,
      'AH_SITE_ENVIRONMENT' => 'test',
      'AH_SITE_NAME' => 'testsite1test',
      'AH_SITE_GROUP' => 'testsite1',
    ];

    $path = '/admin/config/search/search-api/server/' . $this->server;
    $this->drupalGet($path, ['query' => $overrides ]);

    $this->assertText('automatically enforced read-only mode on this connection.');

    $this->assertText('The following Acquia Search Solr index IDs would have worked for your current environment');
    $this->assertText($this->id . '.test.' . $this->_getDbName());
    $this->assertText($this->id . '.test.' . $this->_getSiteFolderName());

    $path = '/admin/config/search/search-api/index/' . $this->index;
    $this->drupalGet($path, ['query' => $overrides ]);

    // On index edit page, check the read-only mode state.
    $this->assertText('automatically enforced read-only mode on this connection.');

  }


  /**
   * Acquia Prod environment and a DB name but AH_PRODUCTION isn't set - so read
   * only.
   */
  public function caseAcquiaHostingProdEnvironmentDetectedWithoutProdFlag() {

    $overrides = [
      'env-overrides' => 1,
      'AH_SITE_ENVIRONMENT' => 'prod',
      'AH_SITE_NAME' => 'testsite1prod',
      'AH_SITE_GROUP' => 'testsite1',
    ];

    $path = '/admin/config/search/search-api/server/' . $this->server;
    $this->drupalGet($path, ['query' => $overrides ]);

    $this->assertText('automatically enforced read-only mode on this connection.');

    $this->assertText('The following Acquia Search Solr index IDs would have worked for your current environment');
    $this->assertText($this->id . '.prod.' . $this->_getDbName());
    $this->assertText($this->id . '.prod.' . $this->_getSiteFolderName());

    $path = '/admin/config/search/search-api/index/' . $this->index;
    $this->drupalGet($path, ['query' => $overrides ]);

    $this->assertText('automatically enforced read-only mode on this connection.');

  }


  /**
   * Acquia Prod environment and a DB name and AH_PRODUCTION is set - so it
   * should override to connect to the prod index.
   */
  public function caseAcquiaHostingProdEnvironmentDetectedWithProdFlag() {

    $overrides = [
      'env-overrides' => 1,
      'AH_SITE_ENVIRONMENT' => 'prod',
      'AH_SITE_NAME' => 'testsite1prod',
      'AH_SITE_GROUP' => 'testsite1',
      'AH_PRODUCTION' => 1,
    ];

    $path = '/admin/config/search/search-api/server/' . $this->server;
    $this->drupalGet($path, ['query' => $overrides ]);

    $this->assertNoText('automatically enforced read-only mode on this connection.');

    $path = '/admin/config/search/search-api/index/' . $this->index;
    $this->drupalGet($path, ['query' => $overrides ]);

    $this->assertNoText('automatically enforced read-only mode on this connection.');

  }


  /**
   * Connect to the Acquia Subscription.
   */
  public function _connect() {

    \Drupal::configFactory()->getEditable('acquia_connector.settings')->set('spi.ssl_verify', FALSE)->save();
    \Drupal::configFactory()->getEditable('acquia_connector.settings')->set('spi.ssl_override', TRUE)->save();

    $admin_user = $this->_createAdminUser();
    $this->drupalLogin($admin_user);

    $edit_fields = array(
      'acquia_identifier' => $this->id,
      'acquia_key' => $this->key,
    );

    $submit_button = 'Connect';
    $this->drupalPostForm('admin/config/system/acquia-connector/credentials', $edit_fields, $submit_button);

    \Drupal::service('module_installer')->install(array('acquia_search'));
    drupal_flush_all_caches();

  }


  /**
   * Creates an admin user.
   */
  public function _createAdminUser() {

    $permissions = array(
      'administer site configuration',
      'access administration pages',
      'access toolbar',
      'administer search_api',
    );
    return $this->drupalCreateUser($permissions);

  }


  /**
   * Sets available search cores into the subscription heartbeat data.
   *
   * @param bool $no_db_flag
   *   Allows to set a limited number of search cores by excluding the one that
   *   contains the DB name.
   */
  public function _setAvailableSearchCores($no_db_flag = FALSE) {

    $acquia_identifier = $this->id;
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
