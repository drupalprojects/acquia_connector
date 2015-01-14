<?php

/**
 * @file
 * Contains \Drupal\acquia_connector\Form\CredentialForm.
 */

namespace Drupal\acquia_connector\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\acquia_connector\Client;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\acquia_connector\Subscription;

/**
 * Class CredentialForm.
 */
class CredentialForm extends ConfigFormBase {

  /**
   * The Acquia client.
   *
   * @var \Drupal\acquia_connector\Client
   */
  protected $client;

  /**
   * Constructs a \Drupal\system\ConfigFormBase object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param Client $client
   */
  public function __construct(ConfigFactoryInterface $config_factory, Client $client) {
    $this->configFactory = $config_factory;
    $this->client = $client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('acquia_connector.client')
    );
  }

 /**
  * {@inheritdoc}
  */
  public function getFormId() {
    return 'acquia_connector_settings_credentials';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('acquia_connector.settings');

    $form['#prefix'] = $this->t('Enter your <a href="@net">identifier and key</a> from your subscriptions overview or <a href="@url">log in</a> to connect your site to the Acquia Network.', array('@net' => Url::fromUri('https://insight.acquia.com/subscriptions')->getUri(), '@url' => \Drupal::url('acquia_connector.setup')));
    $form['acquia_identifier'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Identifier'),
      '#default_value' => $config->get('identifier'),
      '#required' => TRUE,
    );
    $form['acquia_key'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Network key'),
      '#default_value' => $config->get('key'),
      '#required' => TRUE,
    );
    $form['actions'] = array('#type' => 'actions');
    $form['actions']['submit'] = array(
      '#type' => 'submit',
      '#value' => $this->t('Connect'),
    );
    $form['actions']['signup'] = array(
      '#markup' => $this->t('Need a subscription? <a href="@url">Get one</a>.', array('@url' => Url::fromUri('https://www.acquia.com/acquia-cloud-free')->getUri())),
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
//    $response = $this->client->getSubscription(trim($form_state->getValue('acquia_identifier')), trim($form_state->getValue('acquia_key')));  // @todo: remove the line.
    try {
      $response = $this->client->nspiCall(
        '/agent-api/subscription/' . trim($form_state->getValue('acquia_identifier')),
        array('identifier' => trim($form_state->getValue('acquia_identifier'))),
        trim($form_state->getValue('acquia_key')));
    }
    catch (\Exception $e) {
      if ($e->getCode()) {
        acquia_connect_report_restapi_error($e->getCode(), $e->getMessage());
        $form_state->setErrorByName('');
      }
      else {
        $form_state->setErrorByName('', t('Server error, please submit again.'));
      }
      $response['result'] = FALSE;
    }

    $response = $response['result'];
    dpm($response); // @todo: Remove debug.

    if (!empty($response['is_error'])) {
      $form_state->setErrorByName('', t('Server error, please submit again.'));
    }
    elseif (isset($response['body']['error'])) {
      $form_state->setErrorByName('', $response['body']['error']);
    }
    elseif (empty($response['body']['subscription_name'])) {
      $form_state->setErrorByName('acquia_identifier', t('No subscriptions were found.'));
    }
    else {
      $form_state->setValue('subscription', $response['body']['subscription_name']);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('acquia_connector.settings');

    $config->set('key', $form_state->getValue('acquia_key'))
      ->set('identifier', $form_state->getValue('acquia_identifier'))
      ->set('subscription_name', $form_state->getValue('subscription'))
      ->save();

    // Check subscription and send a heartbeat to Acquia Network via XML-RPC.
    // Our status gets updated locally via the return data.
    $subscription_class = new Subscription();
    $subscription = $subscription_class->update();

    // Redirect to the path without the suffix.
    $form_state->setRedirect('acquia_connector.settings');

    drupal_flush_all_caches();

    if ($subscription['active']) {
      drupal_set_message($this->t('<h3>Connection successful!</h3>You are now connected to the Acquia Network.'));
    }
  }

}
