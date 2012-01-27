<?php

/**
 * @file
 *   Acquia Agent configuration page.
 */

/**
 * Helper function. Creates an authenticator for xmlrpc calls
 */
function _acquia_agent_create_authenticator($body, $pass = NULL) {
  $path = drupal_get_path('module', 'acquia_agent');
  require_once $path .'/acquia_agent_streams.inc';

  $auth = array();
  $auth['time'] = time();
  $nonce = md5(acquia_agent_random_bytes(55));
  $auth['nonce'] = $nonce;
  // We need an hmac to authenticate to an acquia.com account.
  if (isset($pass)) {
    $auth['hash'] = _acquia_agent_hmac($pass, $auth['time'], $auth['nonce'], $body);
  }
  else {
    // rpc.acquia.com XML-RPC interface requires this parameter to be a string.
    // Just pass a dummy value.
    $auth['hash'] = 'x';
  }
  return $auth;
}

/**
 * Hash a password according to Drupal's password_crypt and settings from remote.
 */
function _acquia_agent_hash_password_crypt($algorithm, $pass, $setting, $extra_md5 = FALSE) {
  // Server may state that password needs to be hashed with MD5 first.
  if ($extra_md5) {
    $pass = md5($pass);
  }
  // If module phpass exists include its password.inc, otherwise use the one
  // included with Acquia Agent (a copy of phpass' password.inc).
  if (module_exists('phpass')) {
    module_load_include('inc', 'phpass', 'password');
    $hash = _password_crypt($algorithm, $pass, $setting);
  }
  else {
    module_load_include('inc', 'acquia_agent', 'password');
    $hash = _password_crypt($algorithm, $pass, $setting);
  }
  // Match the hash stored on the server which has prefix character 'U' if
  // password was first hashed with MD5.
  if ($extra_md5) {
    $hash = 'U' . $hash;
  }
  return $hash;
}

/**
 * Helper function that creates a new AN subscription via xmlrpc call. It stores id/key
 * if success and sets page error otherwise
 */
function _acquia_agent_call_provision_freetrial($body, $authenticator, $pass) {
  $md5_pass = md5($pass);
  $values = array('body' => $body, 'authenticator' => $authenticator);
  $result = xmlrpc(acquia_agent_network_address(), 'acquia.agent.provision.freetrial', $values);
  if ($errno = xmlrpc_errno()) {
    drupal_set_message(t('Error getting free trial: @message (@errno)', array('@message' => xmlrpc_error_msg(), '@errno' => xmlrpc_errno())), 'error');
    watchdog('acquia agent', '@message (@errno): %server - %method - <pre>@data</pre>', array('@message' => xmlrpc_error_msg(), '@errno' => xmlrpc_errno(), '%server' => acquia_agent_network_address(), '%method' => 'acquia.agent.provision.freetrial', '@data' => print_r($values, TRUE)), WATCHDOG_ERROR);
    $result = FALSE;
  }
  elseif (!empty($result['body']['nid'])) {
    if (!empty($result['body']['hashkey']) && !empty($md5_pass)) {
      // We have a key XOR'd with a hash.
      $hash = str_pad(_acquia_agent_hmac(
        $md5_pass,
        $authenticator['time'],
        $authenticator['nonce'],
        $result['body']['identifier']), 64, chr(0x00));
      // Repeat the XOR and remove trailing NUL bytes.
      $key = rtrim(base64_decode($result['body']['hashkey']) ^ $hash);
      // Strip off the padding marker character.
      $key = substr($key, 0, -1);
    }
    else {
      $key = $result['body']['key'];
    }
    variable_set('acquia_key', $key);
    variable_set('acquia_identifier', $result['body']['identifier']);
    drupal_set_message(t('The Acquia configuration options have been saved.'));
    // Check subscription and send a heartbeat to Acquia Network via XML-RPC.
    // Our status gets updated locally via the return data.
    acquia_agent_check_subscription();
    cache_clear_all();
  }
  return $result;
}

/**
 *  Main 30 day trial form function
 */
function acquia_agent_an_start_form(&$form_state, $banner) {
  $form = array(
    '#prefix' =>  acquia_agent_an_info_header(),
    'banner' => array('#value' => $banner),
    '#theme' => 'acquia_agent_banner_form',
  );
  return $form;
}

/**
 * Main page function
 */
function acquia_agent_settings_page($arg = NULL) {
  $banner = '';
  $identifier = acquia_agent_settings('acquia_identifier');
  $key = acquia_agent_settings('acquia_key');
  $path = drupal_get_path('module', 'acquia_agent');
  $dynamic_banner = variable_get('acquia_dynamic_banner', FALSE);
  if ($dynamic_banner) {
    drupal_add_js(array('acquia_network' => array('id' => $identifier ? $identifier : FALSE)), 'setting');
    drupal_add_js(array('acquia_network' => array('url' => variable_get('acquia_banner_service', 'http://insightdev.network.acquia-sites.com/system/acquia-banner'))), 'setting');
    $src = variable_get('acquia_banner_serve', 'http://insightdev.network.acquia-sites.com/sites/all/modules/custom/acquia_banner/acquia_banner.js');
    $banner = "<script type='text/javascript' src='" . htmlentities($src) . "'></script>";
  }
  else {
    $banner = theme('image', $path .'/acquia_vertical_banner.png');
    $banner = '<a href="http://www.acquia.com/trial" target="_blank">'. $banner . '</a>';
  }
  drupal_add_css($path .'/acquia_agent.css', 'module', 'all', FALSE);

  if (empty($identifier) && empty($key) && $arg == 'setup') {
    return drupal_get_form('acquia_agent_automatic_setup_form');
  }
  elseif (($identifier && $key) || $arg == 'connection') {
    return acquia_agent_settings_form_page($banner);
  }
  else {
    drupal_set_title(t('Get a free 30 day trial of the Acquia Network'));
    return drupal_get_form('acquia_agent_an_start_form', $banner);
  }
}

/**
 * Menu callback for settings page.
 */
function acquia_agent_settings_form_page($banner = NULL) {
  $identifier = acquia_agent_settings('acquia_identifier');
  $key = acquia_agent_settings('acquia_key');

  $output =  '';
  // Check $_POST so we don't send extra XML-RPC requests during form submission.
  if ($identifier && $key && empty($_POST)) {
    // Check our connection to the Acquia Network and validity of the crenditials.
    $acquia_network_address = acquia_agent_settings('acquia_network_address');
    if (acquia_agent_valid_credentials($identifier, $key, $acquia_network_address)) {
      $subscription = acquia_agent_settings('acquia_subscription_data');
    }
    else {
      $error_message = acquia_agent_connection_error_message();
      drupal_set_message($error_message, 'error', FALSE);
    }
  }
  return drupal_get_form('acquia_agent_settings_form', $banner);
}

function acquia_agent_automatic_setup_form($form_state) {
  if (isset($form_state['storage']['choose'])) {
    return _acquia_agent_automatic_setup_form_choose($form_state);
  }
  else {
    return _acquia_agent_automatic_setup_form_start($form_state);
  }
}

function _acquia_agent_automatic_setup_form_start(&$form_state) {
  global $base_url;
  $form = array(
    '#prefix' =>  t('To setup the connection to your Acquia Network subscription enter your Acquia.com account email address and password.'),
    'email' => array(
      '#type' => 'textfield',
      '#title' => t('Acquia Network email'),
      '#description' => t('Your Acquia.com email address. The value will not be stored locally.'),
      '#required' => TRUE,
    ),
    'pass' => array(
      '#type' => 'password',
      '#title' => t('Acquia.com password'),
      '#description' => t('Your Acquia.com account password. The value will not be stored locally and will be sent securely to acquia.com. <a href="!url" target="_blank">Forgot password?</a>', array('!url' => url('http://acquia.com/user/password'))),
      '#size' => 32,
      '#required' => TRUE,
    ),
    '#suffix' =>  t('<a href="!url">If you prefer to manually enter your Acquia subscription information you may do so on the connection page</a>.', array('!url' => url('admin/settings/acquia-agent/connection'))),
    'continue' => array(
      '#type' => 'submit',
      '#value' => t('Next'),
    ),
  );
  return $form;
}

function _acquia_agent_automatic_setup_form_choose(&$form_state) {
  $options = array();
  foreach ($form_state['storage']['subscriptions'] as $credentials) {
    $options[] = $credentials['name'];
  }
  $form = array(
    '#prefix' =>  t('You have multiple subscriptions available. Please choose the subscription you would like to finish connecting.'),
    'subscription' => array(
      '#type' => 'select',
      '#title' => t('Available subscriptions'),
      '#options' => $options,
      '#description' => t('Choose from your available descriptions.'),
      '#required' => TRUE,
    ),
    'continue' => array(
      '#type' => 'submit',
      '#value' => t('Submit'),
    ),
  );
  return $form;
}

function acquia_agent_automatic_setup_form_validate($form, &$form_state) {
  if (!isset($form_state['storage']['choose'])) {
    // Validate e-mail address and get account hash settings.
    $data = array(
      'email' => $form_state['values']['email'],
    );
    $result = xmlrpc(acquia_agent_network_address(), 'acquia.agent.communication.settings', $data);

    if ($errno = xmlrpc_errno()) {
      acquia_agent_report_xmlrpc_error();
      // Set form error to prevent switching to the next page.
      form_set_error('');
    }
    elseif (!$result) {
      // Email doesn't exist.
      form_set_error('email', t("Email address not found on the Acquia Network."));
    }
    else {
      // Build hashed password from account password settings for further 
      // XML-RPC communications with acquia.com.
      $pass = _acquia_agent_hash_password_crypt($result['algorithm'], $form_state['values']['pass'], $result['hash_setting'], $result['extra_md5']);
      $form_state['storage']['pass'] = $pass;
    }
  }
}

function acquia_agent_automatic_setup_form_submit($form, &$form_state) {
  if (isset($form_state['storage']['choose']) && isset($form_state['storage']['subscriptions'][$form_state['values']['subscription']])) {
    $sub = $form_state['storage']['subscriptions'][$form_state['values']['subscription']];
    variable_set('acquia_key', $sub['key']);
    variable_set('acquia_identifier', $sub['identifier']);
    drupal_goto('admin/settings/acquia-agent/connection');
  }
  else {
    _acquia_agent_automatic_start_submit($form_state);
  }
}

function _acquia_agent_automatic_start_submit(&$form_state) {
  // Make hashed password signed request to Acquia Network for subscriptions.
  $body = array(
    'email' => $form_state['values']['email'],
  );
  // acquia.com authenticator uses hash of client-supplied password hashed with
  // remote settings so that the hash can match. pass was hashed in
  // _acquia_agent_setup_form_validate().
  $authenticator = _acquia_agent_create_authenticator($body, $form_state['storage']['pass']);
  $data = array('body' => $body, 'authenticator' => $authenticator);
  $result = xmlrpc(acquia_agent_network_address(), 'acquia.agent.subscription.credentials', $data);

  if ($errno = xmlrpc_errno()) {
    acquia_agent_report_xmlrpc_error();
    // Set form error to prevent switching to the next page.
    form_set_error('');
  }
  elseif (!$result) {
    // Email doesn't exist
    form_set_error('email', t("Server error, please submit again."));
  }
  elseif ($result['is_error']) {
    form_set_error('email', t("Server error, please submit again."));
  }
  elseif (!isset($result['body']['subscription'])) {
    form_set_error('host', t('No subscriptions were found.'));
  }
  elseif (count($result['body']['subscription']) > 1) {
    // Multistep form for choosing from available subscriptions.
    $form_state['storage']['choose'] = TRUE;
    $form_state['storage']['subscriptions'] = $result['body']['subscription'];
  }
  else {
    // One subscription so set id/key pair.
    $sub = $result['body']['subscription'][0];
    variable_set('acquia_key', $sub['key']);
    variable_set('acquia_identifier', $sub['identifier']);
    drupal_goto('admin/settings/acquia-agent/connection');
  }
}

/**
 * Settings form builder function.
 */
function acquia_agent_settings_form($form_state, $banner) {
  $identifier = acquia_agent_settings('acquia_identifier');
  $key = acquia_agent_settings('acquia_key');
  $acquia_network_address = acquia_agent_settings('acquia_network_address');

  // Help for copying the identifier and key.
  $copy_help = t('Copy from <a href="@subscriptions-overview">your subscriptions overview on the Acquia Network</a>.', array('@subscriptions-overview' => 'http://acquia.com/network/dashboard/subscription'));

  $form['cs'] = array(
    '#type' => 'fieldset',
    '#title' => t('Acquia Network keys'),
    '#collapsible' => FALSE,
    '#collapsed' => FALSE,
  );

  $form['cs']['acquia_identifier'] = array(
    '#type' => 'textfield',
    '#title' => t('Identifier'),
    '#default_value' => $identifier,
    '#description' => $copy_help,
    '#required' => TRUE,
  );
  $form['cs']['acquia_key'] = array(
    '#type' => 'textfield',
    '#title' => t('Key'),
    '#default_value' => $key,
    '#description' => $copy_help,
    '#required' => TRUE,
  );
  $form['cs']['buttons']['submit'] = array(
    '#type' => 'submit',
    '#value' => t('Save Settings'),
    '#validate' => array('acquia_agent_set_validate'),
    '#submit' => array('acquia_agent_set_submit'),
  );
  $form['cs']['buttons']['delete'] = array(
    '#type' => 'submit',
    '#value' => t('Clear Settings'),
    '#access' => (!empty($identifier) || !empty($key)),
    '#submit' => array('acquia_agent_delete_submit'),
  );

  $ssl_available = (in_array('ssl', stream_get_transports(), TRUE) && !defined('ACQUIA_DEVELOPMENT_NOSSL'));

  $form['connection'] = array(
    '#type' => 'fieldset',
    '#title' => t('Connector settings'),
    '#collapsible' => FALSE,
  );
  $form['connection']['acquia_dynamic_banner'] = array(
    '#type' => 'checkbox',
    '#title' => t('Receive updates'),
    '#default_value' => variable_get('acquia_dynamic_banner', FALSE),
    '#description' => t('Receive dynamic updates on this page from Acquia.com about your subscription and new features.'),
  );
  $form['connection']['acquia_agent_verify_peer'] = array(
    '#type' => 'radios',
    '#title' => t('Enhanced SSL security'),
    '#default_value' => (int) (variable_get('acquia_agent_verify_peer', 0) && $ssl_available),
    '#options' => array(0 => t('Disabled'), 1 => t('Enabled')),
    '#description' => t('If enabled the Acquia Connector will attempt to verify Acquia server identities before sending data.  May cause communication to fail, however, depending on your local configuration.'),
    '#disabled' => !$ssl_available,
  );
  if ($ssl_available) {
    $form['connection']['acquia_agent_verify_peer']['#description'] .= ' <div class="ok">'. t('PHP has SSL support and may support this feature.') .'</div>';
  }
  else {
    $form['connection']['acquia_agent_verify_peer']['#description'] .= ' <div class="error">'. t('Your installation of PHP does not have SSL support. Please enable the SSL extension or compile PHP with SSL, see: <a href="http://php.net/manual/en/book.openssl.php" target="_blank">http://php.net/manual/en/book.openssl.php</a>.') .'</div>';
  }
  $form['connection']['buttons']['submit'] = array(
    '#type' => 'submit',
    '#value' => t('Save configuration'),
    '#submit' => array('acquia_agent_settings_submit'),
  );

  $form['banner'] = array('#value' => $banner);
  $form['#theme'] = 'acquia_agent_banner_form';
  return $form;
}

/**
 * Submit acquia_agent_settings ssl setting.
 */
function acquia_agent_settings_submit($form, &$form_state) {
  variable_set('acquia_agent_verify_peer', $form_state['values']['acquia_agent_verify_peer']);
  variable_set('acquia_dynamic_banner', $form_state['values']['acquia_dynamic_banner']);
  drupal_set_message(t('The configuration options have been saved.'));
}


/**
 * Validate acquia_agent_settings form submissions.
 */
function acquia_agent_set_validate($form, &$form_state) {
  // Trim all input to get rid of possible whitespace pasted from the website.
  foreach ($form_state['values'] as $key => $value) {
    $form_state['values'][$key] = trim($value);
  }
  $identifier = $form_state['values']['acquia_identifier'];
  $key = $form_state['values']['acquia_key'];
  $acquia_network_address = $form_state['values']['acquia_network_address'];

  // Don't make the XML-RPC call with empty values.
  if (!empty($identifier) && !empty($key) && !empty($rpc_server)) {
    if (!acquia_agent_valid_credentials($identifier, $key, $acquia_network_address)) {
      $error_message = acquia_agent_connection_error_message();
      form_error($form, $error_message);
    }
  }
}

/**
 * Save acquia_agent_settings form submissions.
 */
function acquia_agent_set_submit($form, &$form_state) {
  variable_set('acquia_key', $form_state['values']['acquia_key']);
  variable_set('acquia_identifier', $form_state['values']['acquia_identifier']);
  drupal_set_message(t('The Acquia configuration options have been saved.'));
  // Check subscription and send a heartbeat to Acquia Network via XML-RPC.
  // Our status gets updated locally via the return data.
  acquia_agent_check_subscription();
  // Redirect to the path without the suffix.
  $form_state['redirect'] = 'admin/settings/acquia-agent';
  cache_clear_all();
}

/**
 * Delete acquia_agent settings.
 */
function acquia_agent_delete_submit($form, &$form_state) {
  variable_del('acquia_key');
  variable_del('acquia_identifier');
  variable_del('acquia_network_address');
  variable_del('acquia_subscription_data');
  drupal_set_message(t('Your Acquia configuration has been deleted.'));
  // Redirect to the path with the suffix.
  $form_state['redirect'] = 'admin/settings/acquia-agent/connection';
  cache_clear_all();
}

function theme_acquia_agent_banner_form($form) {
  if (empty($form['banner'])) {
    return drupal_render($form);
  }
  $banner = drupal_render($form['banner']);
  $output = '<div id="an-pg-container"><div id="an-pg-form">';
  $output .= drupal_render($form);
  $output .= "\n</div>\n";
  $output .= '<div class="an-pg-banner" id="'. $form['#id'] .'-banner">';
  $output .= $banner;
  $output .= "\n</div>\n</div>\n";
  return $output;
}

function acquia_agent_an_info_header() {
  $l_opt = array('attributes' => array('target' => '_blank'));
  $output = '<h2 id="an-info-header">' . t('With the <a href="@acquia-network" target="_blank">Acquia Network</a> you get:', array('@acquia-network' => 'http://acquia.com/products-services/acquia-network')) . '</h2>';
  $output .= '<div id="an-info-box">';
  $output .=   '<div class="cell an-left">';
  $output .=     '<h2>' . t('Knowledge Library') . '</h2>';
  $output .=     t("Get access to the Acquia Library's articles, videos, and podcasts covering expert tips and tricks, help and how-tos.");
  $output .=     '<br/>' . l(t('Learn more'), 'http://library.acquia.com/', $l_opt);
  $output .=   '</div>';
  $output .=   '<div class="cell an-center">';
  $output .=     '<h2>' . t('Tools and Services') . '</h2>';
  $output .=     t('Sit back while Insight inspects your site for security, performance, SEO, and best practices. Leverage services for faceted search, automated load testing, spam blocking, A/B site testing, performance tuning, training videos, and more!');
  $output .=     '<br/>' . l(t('Learn more'), 'http://www.acquia.com/products-services/acquia-network/cloud-services', $l_opt);
  $output .=   '</div>';
  $output .=   '<div class="cell an-right">';
  $output .=     '<h2>' . t('Expert Support') . '</h2>';
  $output .=     t("Get advice for even the toughest Drupal questions from Acquia's Drupal experts.");
  $output .=     '<br/>' . l(t('Learn more'), 'http://www.acquia.com/drupal-support', $l_opt);
  $output .=   '</div>';
  $output .= '</div>';
  return $output;
}

function acquia_agent_existing_account_msg() {
  $output = '<div class="messages warning" style="margin-top: 0px">';
  $output .= t('An existing Acquia.com account is associated with that email address. Enter your password to associate this free trial with that account, or <a href="@an_trial">try another email address</a>', array('@an_trial' => url('admin/settings/acquia-agent')));
  $output .= '</div>';
  return $output;
}

/**
 * List of countries in the Acquia Network database.
 */
function _acquia_agent_countries() {
  return array (
    'United States' => 'United States',
    'Afghanistan' => 'Afghanistan',
    'Albania' => 'Albania',
    'Algeria' => 'Algeria',
    'American Samoa' => 'American Samoa',
    'Andorra' => 'Andorra',
    'Angola' => 'Angola',
    'Anguilla' => 'Anguilla',
    'Antigua and Barbuda' => 'Antigua and Barbuda',
    'Argentina' => 'Argentina',
    'Armenia' => 'Armenia',
    'Ascension Island' => 'Ascension Island',
    'Australia' => 'Australia',
    'Austria' => 'Austria',
    'Azerbaijan' => 'Azerbaijan',
    'Bahamas' => 'Bahamas',
    'Bahrain' => 'Bahrain',
    'Bangladesh' => 'Bangladesh',
    'Barbados' => 'Barbados',
    'Belarus' => 'Belarus',
    'Belgium' => 'Belgium',
    'Belize' => 'Belize',
    'Benin' => 'Benin',
    'Bermuda' => 'Bermuda',
    'Bhutan' => 'Bhutan',
    'Bolivia' => 'Bolivia',
    'Bosnia and Herzegovina' => 'Bosnia and Herzegovina',
    'Botswana' => 'Botswana',
    'Brazil' => 'Brazil',
    'British Indian Ocean Territory' => 'British Indian Ocean Territory',
    'Brunei Darussalam' => 'Brunei Darussalam',
    'Bulgaria' => 'Bulgaria',
    'Burkina Faso' => 'Burkina Faso',
    'Burundi' => 'Burundi',
    'Cambodia' => 'Cambodia',
    'Cameroon' => 'Cameroon',
    'Canada' => 'Canada',
    'Cape Verde' => 'Cape Verde',
    'Cayman Islands' => 'Cayman Islands',
    'Central African Republic' => 'Central African Republic',
    'Chad' => 'Chad',
    'Chile' => 'Chile',
    'China' => 'China',
    'Colombia' => 'Colombia',
    'Comoros' => 'Comoros',
    'Congo' => 'Congo',
    'Cook Islands' => 'Cook Islands',
    'Costa Rica' => 'Costa Rica',
    'Cote D Ivoire' => 'Cote D Ivoire',
    'Croatia' => 'Croatia',
    'Cuba' => 'Cuba',
    'Cyprus' => 'Cyprus',
    'Czech Republic' => 'Czech Republic',
    'Denmark' => 'Denmark',
    'Djibouti' => 'Djibouti',
    'Dominica' => 'Dominica',
    'Dominican Republic' => 'Dominican Republic',
    'Ecuador' => 'Ecuador',
    'Egypt' => 'Egypt',
    'El Salvador' => 'El Salvador',
    'Equatorial Guinea' => 'Equatorial Guinea',
    'Eritrea' => 'Eritrea',
    'Estonia' => 'Estonia',
    'Ethiopia' => 'Ethiopia',
    'Falkland Islands' => 'Falkland Islands',
    'Faroe Islands' => 'Faroe Islands',
    'Federated States of Micronesia' => 'Federated States of Micronesia',
    'Fiji' => 'Fiji',
    'Finland' => 'Finland',
    'France' => 'France',
    'French Guiana' => 'French Guiana',
    'French Polynesia' => 'French Polynesia',
    'Gabon' => 'Gabon',
    'Georgia' => 'Georgia',
    'Germany' => 'Germany',
    'Ghana' => 'Ghana',
    'Greece' => 'Greece',
    'Greenland' => 'Greenland',
    'Grenada' => 'Grenada',
    'Guadeloupe' => 'Guadeloupe',
    'Guatemala' => 'Guatemala',
    'Guam' => 'Guam',
    'Guinea' => 'Guinea',
    'Guinea Bissau' => 'Guinea Bissau',
    'Guyana' => 'Guyana',
    'Haiti' => 'Haiti',
    'Holy See (Vatican City)' => 'Holy See (Vatican City)',
    'Honduras' => 'Honduras',
    'Hong Kong' => 'Hong Kong',
    'Hungary' => 'Hungary',
    'Iceland' => 'Iceland',
    'India' => 'India',
    'Indonesia' => 'Indonesia',
    'Iran' => 'Iran',
    'Ireland' => 'Ireland',
    'Isle of Man' => 'Isle of Man',
    'Israel' => 'Israel',
    'Italy' => 'Italy',
    'Jamaica' => 'Jamaica',
    'Japan' => 'Japan',
    'Jordan' => 'Jordan',
    'Kazakhstan' => 'Kazakhstan',
    'Kenya' => 'Kenya',
    'Kiribati' => 'Kiribati',
    'Korea' => 'Korea',
    'Kuwait' => 'Kuwait',
    'Kyrgyzstan' => 'Kyrgyzstan',
    'Laos' => 'Laos',
    'Latvia' => 'Latvia',
    'Lebanon' => 'Lebanon',
    'Lesotho' => 'Lesotho',
    'Liberia' => 'Liberia',
    'Libya' => 'Libya',
    'Liechtenstein' => 'Liechtenstein',
    'Lithuania' => 'Lithuania',
    'Luxembourg' => 'Luxembourg',
    'Macau' => 'Macau',
    'Macedonia' => 'Macedonia',
    'Madagascar' => 'Madagascar',
    'Malawi' => 'Malawi',
    'Malaysia' => 'Malaysia',
    'Maldives' => 'Maldives',
    'Mali' => 'Mali',
    'Malta' => 'Malta',
    'Marshall Islands' => 'Marshall Islands',
    'Martinique' => 'Martinique',
    'Mauritius' => 'Mauritius',
    'Mayotte' => 'Mayotte',
    'Mexico' => 'Mexico',
    'Moldova' => 'Moldova',
    'Monaco' => 'Monaco',
    'Mongolia' => 'Mongolia',
    'Montenegro' => 'Montenegro',
    'Montserrat' => 'Montserrat',
    'Morocco' => 'Morocco',
    'Mozambique' => 'Mozambique',
    'Myanmar' => 'Myanmar',
    'Namibia' => 'Namibia',
    'Nauru' => 'Nauru',
    'Nepal' => 'Nepal',
    'Netherlands' => 'Netherlands',
    'Netherlands Antilles' => 'Netherlands Antilles',
    'New Caledonia' => 'New Caledonia',
    'New Zealand' => 'New Zealand',
    'Nicaragua' => 'Nicaragua',
    'Niger' => 'Niger',
    'Nigeria' => 'Nigeria',
    'Niue' => 'Niue',
    'Norfolk Island' => 'Norfolk Island',
    'Northern Mariana Islands' => 'Northern Mariana Islands',
    'Norway' => 'Norway',
    'Oman' => 'Oman',
    'Pakistan' => 'Pakistan',
    'Palau' => 'Palau',
    'Palestine' => 'Palestine',
    'Panama' => 'Panama',
    'Papua New Guinea' => 'Papua New Guinea',
    'Paraguay' => 'Paraguay',
    'Peru' => 'Peru',
    'Philippines' => 'Philippines',
    'Pitcairn' => 'Pitcairn',
    'Poland' => 'Poland',
    'Portugal' => 'Portugal',
    'Puerto Rico' => 'Puerto Rico',
    'Qatar' => 'Qatar',
    'Reunion' => 'Reunion',
    'Romania' => 'Romania',
    'Russian Federation' => 'Russian Federation',
    'Rwanda' => 'Rwanda',
    'Saint Vincent and the Grenadines' => 'Saint Vincent and the Grenadines',
    'San Marino' => 'San Marino',
    'Sao Tome and Principe' => 'Sao Tome and Principe',
    'Saudi Arabia' => 'Saudi Arabia',
    'Senegal' => 'Senegal',
    'Serbia' => 'Serbia',
    'Seychelles' => 'Seychelles',
    'Sierra Leone' => 'Sierra Leone',
    'Singapore' => 'Singapore',
    'Slovakia' => 'Slovakia',
    'Slovenia' => 'Slovenia',
    'Solomon Islands' => 'Solomon Islands',
    'Somalia' => 'Somalia',
    'South Africa' => 'South Africa',
    'South Georgia' => 'South Georgia',
    'Spain' => 'Spain',
    'Sri Lanka' => 'Sri Lanka',
    'St. Kitts and Nevis' => 'St. Kitts and Nevis',
    'St. Lucia' => 'St. Lucia',
    'St. Pierre and Miquelon' => 'St. Pierre and Miquelon',
    'Sudan' => 'Sudan',
    'Suriname' => 'Suriname',
    'Swaziland' => 'Swaziland',
    'Sweden' => 'Sweden',
    'Switzerland' => 'Switzerland',
    'Syrian Arab Republic' => 'Syrian Arab Republic',
    'Taiwan' => 'Taiwan',
    'Tajikistan' => 'Tajikistan',
    'Tanzania' => 'Tanzania',
    'Thailand' => 'Thailand',
    'The Gambia' => 'The Gambia',
    'Togo' => 'Togo',
    'Tokelau' => 'Tokelau',
    'Tonga' => 'Tonga',
    'Trinidad and Tobago' => 'Trinidad and Tobago',
    'Tunisia' => 'Tunisia',
    'Turkey' => 'Turkey',
    'Turkmenistan' => 'Turkmenistan',
    'Turks and Caicos Islands' => 'Turks and Caicos Islands',
    'Tuvalu' => 'Tuvalu',
    'Uganda' => 'Uganda',
    'Ukraine' => 'Ukraine',
    'United Arab Emirates' => 'United Arab Emirates',
    'United Kingdom' => 'United Kingdom',
    'Uruguay' => 'Uruguay',
    'Uzbekistan' => 'Uzbekistan',
    'Vanuatu' => 'Vanuatu',
    'Venezuela' => 'Venezuela',
    'Viet Nam' => 'Viet Nam',
    'Virgin Islands' => 'Virgin Islands',
    'Western Samoa' => 'Western Samoa',
    'Yemen' => 'Yemen',
    'Yugoslavia' => 'Yugoslavia',
    'Zaire' => 'Zaire',
    'Zambia' => 'Zambia',
    'Zimbabwe' => 'Zimbabwe',
  );
};


