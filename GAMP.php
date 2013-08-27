<?php
/**
 * Google Analytics Measurement Protocol (GAMP) PHP Class.
 *
 * This class provides a simple mechanism for submitting events and pageviews
 *  to Google (Universal) Analytics using the "Measurement Protocol." This
 *  is very useful for scenarios where the data you want to submit does not
 *  naturally occur in a client-side/browser context, and/or where the most
 *  straightforward place to capture an event occurrence is in a
 *  server-side script.
 * Belying its name somewhat, the Measurement Protocol is only used to submit
 *  data to GA, and has no retrieval methods.
 *
 * @see https://developers.google.com/analytics/devguides/collection/protocol/v1/devguide
 * @see https://developers.google.com/analytics/devguides/collection/protocol/v1/parameters
 */
class GAMP {

  private $tracking_id = NULL;
  private $client_id = NULL;
  private $http_method = NULL;
  private $request_parameters = NULL;
  private $use_cache_buster = NULL;

  /**
   * Instantiate a new GAMP object. Invoker can provide a valid tracking ID
   *  and/or client ID. If not provided, we will try to obtain a tracking ID
   *  and/or client ID from other sources.
   *
   * @param array $args
   *   Args can be:
   *     tracking_id - Unique identifier of user's Google Analytics account.
   *     client_id - A largely numeric string uniquely identifying the current website visitor.
   *     http_method - The method to use when submitting data to GA ('GET' or 'POST').
   *     use_cache_buster - Bypass caching or some junk.
   *     anonymize_ip - Do not send IP address information.
   *
   * @throws GAMP_Exception
   *   When Tracking ID is formatted incorrect.
   */
  public function __construct(array $args = array()) {

    // Sets default args to stop unwanted PHP notices.
    $defaults = array(
      'tracking_id' => '',
      'client_id' => '',
      'http_method' => 'POST',
      'use_cache_buster' => 0,
      'anonymize_ip' => 0,
    );
    $this->setDefaults($args, $defaults);

    if (!$this->validTrackingID($args['tracking_id'])) {
      throw new GAMP_Exception("Tracking ID is not in the correct format.");
    }

    $this->tracking_id = $args['tracking_id'];
    $this->client_id = $this->getValidClientID($args['client_id']);

    // Ensure that the given HTTP method is one that we're prepared to handle.
    if (!in_array($args['http_method'], array('GET', 'POST'))) {
      $args['http_method'] = 'POST';
    }
    $this->http_method = $args['http_method'];
    $this->use_cache_buster = $args['use_cache_buster'];

    $this->request_parameters = array();

    if ($args['anonymize_ip']) {
      $this->request_parameters[self::PARAM_ANON_IP] = 1;
    }
  }

  /**
   * Sets array defaults to empty string to avoid isset() issues.
   *
   * @param array &$array
   *   Array to set default values to.
   *
   * @param array $defaults
   *   Either an associative array of key/value default pairs, or an
   *   array of values that will be set to FALSE if the key is not set.
   *
   * @param boolean $assoc
   *   Whether the $defaults array is associative or not.
   */
  protected function setDefaults(array &$array, array $defaults = array(), $assoc = TRUE) {
    if ($assoc) {
      // With an assoc array, set default keys to the values.
      foreach ($defaults as $key => $val) {
        if (!isset($array[$key])) {
          $array[$key] = $val;
        }
      }
    }
    else {
      // Non-assoc array, just set keys to false.
      foreach ($defaults as $key) {
        if (!isset($array[$key])) {
          $array[$key] = FALSE;
        }
      }
    }
  }

  /**
   * Ensures the Tracking ID is in the correct format.
   *
   * @param string $tracking_id
   *
   * @return boolean
   *   Returns TRUE if the Tracking ID is formatted properly,
   *   or FALSE if not.
   */
  protected function validTrackingID($tracking_id) {
    if (!preg_match('/UA-\d+-\d/', $tracking_id)) {
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Gets a valid ClientID.
   *
   * @param string $client_id
   *   Optional. If no ClientID is passed, one will be returned.
   *
   * @return string
   *   A valid client ID string.
   */
  protected function getValidClientID($client_id = '') {
    // If the given client ID matches the pattern used by GA javascript code
    //  (with or without the two leading components that correspond to cookie
    //  version and domain depth), then use it, after cleaning it as necessary.
    if (preg_match('/^.*(\d{9}\.\d{9})$/', $client_id, $matches)) {
      return $matches[1];
    }

    // Otherwise, see if the given client ID conforms to the UUID v4 standard.
    if (preg_match('/^\d{8}-\d{4}-4\d{3}-[89abAB]\d{3}-\d{12}$/', $client_id)) {
      return $client_id;
    }

    // If not, try to extract the client ID from an existing GA cookie.
    if (isset($_COOKIE['_ga'])) {
      return substr($_COOKIE['_ga'], 6);
    }

    // If all else fails, we can just create a new UUID.
    return $this->getUUID();
  }

  /**
   * Generates a new client ID according to the UUID v4.
   *
   * @see http://www.php.net/manual/en/function.uniqid.php#94959
   */
  private function getUUID() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
      mt_rand(0, 0xffff), mt_rand(0, 0xffff),
      mt_rand(0, 0xffff),
      mt_rand(0, 0x0fff) | 0x4000,
      mt_rand(0, 0x3fff) | 0x8000,
      mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
  }

  /**
   * Send data to GA via HTTP POST/GET.
   *
   * @param array $data
   *   Array containing key-value pairs to be submitted
   *   as part of the POST/GET request.
   *
   * @return string
   *   Response from GA.
   *
   * @throws GAMP_Exception
   */
  protected function submitHTTPRequest($data) {
    $data[self::PARAM_PROTOCOL_VERSION] = self::PROTOCOL_VERSION;
    $data[self::PARAM_TRACKING_ID] = $this->tracking_id;
    $data[self::PARAM_CLIENT_ID] = $this->client_id;

    // Add any other parameters that have been set (custom dimensions and
    //  metrics, traffic source info, etc.)
    $data = array_merge($data, $this->request_parameters);

    // Reset the dimension and metric lists (nothing with a "hit" scope should
    //  persist for future hits; anything with a "session" or "user" scope only
    //  needs to be submitted once per gamp instance) (NRD 2013-08-05).
    //    $this->dimensions = array();
    //    $this->metrics = array();
    // @todo: does this apply to all other data?
    $this->request_parameters = array();

    $url = self::PROTOCOL_URL;
    $method = $this->http_method;

    // Set default CURL options.
    $curl_opts = array(
      CURLOPT_RETURNTRANSFER => TRUE,
    );

    // Pass an accurate user agent string to Google.
    if (isset($_SERVER['HTTP_USER_AGENT'])) {
      $curl_opts[CURLOPT_USERAGENT] = $_SERVER['HTTP_USER_AGENT'];
    }

    if ($method == 'POST') {
      $curl_opts[CURLOPT_POST] = TRUE;
      $curl_opts[CURLOPT_POSTFIELDS] = $data;
    }
    else {
      $query = http_build_query($data);
      $url .= '?' . $query;
      // If specified, add a 14-digit random number to the end of the request,
      //  to prevent browsers or proxies from caching hits.
      if ($this->use_cache_buster) {
        $random_num = str_pad(rand(0, 100000000000000), 14, '0', STR_PAD_LEFT);
        $url .= '&' . self::PARAM_CACHE_BUSTER . '=' . $random_num;
      }
    }

    $curl = curl_init($url);
    curl_setopt_array($curl, $curl_opts);

    $response = curl_exec($curl);
    if ($error = curl_error($curl)) {
      throw new GAMP_Exception($error);
    }
    curl_close($curl);
    return $response;
  }

  /**
   * Sends vars to the GA servers.
   *
   * @param array $required_vars
   * @param array $optional_vars
   *
   * @return string
   */
  protected function send(array $required_vars, array $optional_vars = array()) {
    $request_variables = array_merge($required_vars, $optional_vars);
    // Remove values that equal FALSE. Those don't need to be sent to the server.
    foreach ($request_variables as $key => $val) {
      if ($val === FALSE) {
        unset($request_variables[$key]);
      }
    };
    return $this->submitHTTPRequest($request_variables);
  }

  /**
   * Send an Event.
   *
   * @param array $args
   *   Array of arguments containing a category, action, label (optional), value (optional).
   *
   * @return string
   */
  public function sendEvent(array $args) {
    $required_vars = array(
      self::PARAM_HIT_TYPE => 'event',
      self::PARAM_EVENT_CATEGORY => $args['category'],
      self::PARAM_EVENT_ACTION => $args['action'],
    );
    $this->setDefaults($args, array('label', 'value'), FALSE);
    $optional_vars = array(
      self::PARAM_EVENT_LABEL => $args['label'],
      self::PARAM_EVENT_VALUE => $args['value'],
    );
    return $this->send($required_vars, $optional_vars);
  }

  /**
   * Send a Page View.
   *
   * @param array $args
   *   An array of arguments, optionally including:
   *     path - Document path
   *     title - Document title
   *     host - Document host
   *     location - Full URL
   *     description - Description of the document
   *
   * @return string
   */
  public function sendPageview(array $args) {
    $required_vars = array(
      self::PARAM_HIT_TYPE => 'pageview',
    );
    $this->setDefaults($args, array('path', 'title', 'host', 'location', 'description'), FALSE);
    $optional_vars = array(
      self::PARAM_DOC_PATH => $args['path'],
      self::PARAM_DOC_TITLE => $args['title'],
      self::PARAM_DOC_HOST => $args['host'],
      self::PARAM_DOC_LOCATION => $args['location'],
      self::PARAM_CONTENT_DESC => $args['description'],
    );
    return $this->send($required_vars, $optional_vars);
  }

  /**
   * Send an e-commerce transaction.
   *
   * @param array $args
   *
   * @return string
   */
  public function sendTransaction(array $args) {
    // @todo: validate param lengths (e.g. 500-byte limit), type, etc.
    $required_vars = array(
      self::PARAM_TRANS_AFFILIATION => 'transaction',
      self::PARAM_TRANS_ID => $args['transaction_id'],
    );
    $this->setDefaults($args, array('affiliation', 'revenue', 'shipping_cost', 'tax', 'currency_code'), FALSE);
    $optional_vars = array(
      self::PARAM_TRANS_AFFILIATION => $args['affiliation'],
      self::PARAM_TRANS_REVENUE => $args['revenue'],
      self::PARAM_TRANS_SHIPPING => $args['shipping_cost'],
      self::PARAM_TRANS_TAX => $args['tax'],
      self::PARAM_CURRENCY_CODE => $args['currency_code'],
    );
    return $this->send($required_vars, $optional_vars);
  }

  /**
   * Send an e-commerce item.
   *
   * @param array $args
   *
   * @return string
   */
  public function sendItem(array $args) {
    $required_vars = array(
      self::PARAM_HIT_TYPE => 'item',
      self::PARAM_TRANS_ID => $args['transaction_id'],
      self::PARAM_ITEM_NAME => $args['item_name'],
    );
    $this->setDefaults($args, array('price', 'quantity', 'code', 'category', 'currency_code'), FALSE);
    $optional_vars = array(
      self::PARAM_ITEM_PRICE => $args['price'],
      self::PARAM_ITEM_QUANTITY => $args['quantity'],
      self::PARAM_ITEM_CODE => $args['code'],
      self::PARAM_ITEM_CATEGORY => $args['category'],
      self::PARAM_CURRENCY_CODE => $args['currency_code'],
    );
    return $this->send($required_vars, $optional_vars);
  }

  /**
   * Send a social interaction.
   *
   * @param array $args
   *
   * @return string
   */
  public function sendSocialAction(array $args) {
    // @todo: validate param lengths (e.g. 50-byte limit), type, etc.
    $required_vars = array(
      self::PARAM_HIT_TYPE => 'social',
      self::PARAM_SOCIAL_NETWORK => $args['social_network'],
      self::PARAM_SOCIAL_ACTION => $args['action'],
      self::PARAM_SOCIAL_ACTION_TARGET => $args['target'],
    );
    return $this->send($required_vars);
  }

  /**
   * Send timing data.
   *
   * @param array $args
   *
   * @return string
   */
  public function sendTimingData(array $args) {
    // @todo: validate param lengths (e.g. 500-byte limit), type, etc.
    $required_vars = array(
      self::PARAM_HIT_TYPE => 'timing',
    );
    $this->setDefaults($args, array('page_load_time', 'dns_time', 'page_download_time', 'redirect_response_time', 'tcp_connect_time', 'server_response_time'), FALSE);
    $optional_vars = array(
      self::PARAM_PAGE_LOAD_TIME => $args['page_load_time'],
      self::PARAM_DNS_TIME => $args['dns_time'],
      self::PARAM_DOWNLOAD_TIME => $args['page_download_time'],
      self::PARAM_REDIRECT_RESPONSE_TIME => $args['redirect_response_time'],
      self::PARAM_TCP_CONNECT_TIME => $args['tcp_connect_time'],
      self::PARAM_SERVER_RESPONSE_TIME => $args['server_response_time'],
    );
    return $this->send($required_vars, $optional_vars);
  }

  /**
   * Send user-related timing data.
   *
   * @param array $args
   *
   * @return string
   */
  public function sendUserTimingData(array $args) {
    // @todo: validate param lengths (e.g. 500-byte limit), type, etc.
    $required_vars = array(
      self::PARAM_HIT_TYPE => 'timing',
    );
    $this->setDefaults($args, array('category', 'variable', 'time', 'label'), FALSE);
    $optional_vars = array(
      self::PARAM_USER_TIMING_CATEGORY => $args['category'],
      self::PARAM_USER_TIMING_VARIABLE => $args['variable'],
      self::PARAM_USER_TIMING_TIME => $args['time'],
      self::PARAM_USER_TIMING_LABEL => $args['label'],
    );
    return $this->send($required_vars, $optional_vars);
  }

  /**
   * Send an exception.
   *
   * @param array $args
   *
   * @return string
   */
  public function sendException(array $args) {
    $required_vars = array(
      self::PARAM_HIT_TYPE => 'exception',
    );
    $this->setDefaults($args, array('description', 'is_fatal'), FALSE);
    $optional_vars = array(
      self::PARAM_EXCEPTION_DESC => $args['description'],
      self::PARAM_EXCEPTION_IS_FATAL => $args['is_fatal'] ? 1 : 0,
    );
    return $this->send($required_vars, $optional_vars);
  }

  /**
   * Set values for one or more custom dimensions,
   * to be submitted with the next hit.
   *
   * @param array $dimensions
   *   An array of string values whose keys are pre-coded
   *   indices of custom dimensions that have been defined in Google Analytics.
   *   Example usage:
   *
   * @code
   *   // Custom dimension 3 corresponds to "Gender."
   *   $dimensions = array('cd3' => 'Male');
   *   $gamp = new gamp();
   *   $gamp->setDimensions($dimensions);
   * @endcode
   */
  public function setDimensions(array $dimensions) {
    foreach ($dimensions as $dimension_index => $dimension_value) {
      if (preg_match('/^cd[1-9][0-9]*$/', $dimension_index)) {
        $this->request_parameters[$dimension_index] = $dimension_value;
      }
    }
  }

  /**
   * Set values for one or more custom metrics,
   * to be submitted with the next hit.
   *
   * @param array $metrics
   *   An array of integer values whose keys are pre-coded
   *   indices of custom metrics that have been defined in Google Analytics.
   *   Example usage:
   *
   * @code
   *   // Custom metric 1 corresponds to "Reward Points."
   *   $metrics = array('cm1' => 450);
   *   $gamp = new gamp();
   *   $gamp->setMetrics($metrics);
   * @endcode
   */
  public function setMetrics($metrics) {
    foreach ($metrics as $metric_index => $metric_value) {
      if (preg_match('/^cm[1-9][0-9]*$/', $metric_index)) {
        $this->request_parameters[$metric_index] = $metric_value;
      }
    }
  }

  /**
   * GA Parameter Constants
   * Makes life easier for everyone with easy-to-remember names.
   * https://developers.google.com/analytics/devguides/collection/protocol/v1/parameters
   */
  const PARAM_PROTOCOL_VERSION = 'v';
  const PARAM_TRACKING_ID = 'tid';
  const PARAM_CLIENT_ID = 'cid';
  const PARAM_ANON_IP = 'aip';
  const PARAM_QUEUE_TIME = 'qt';
  const PARAM_CACHE_BUSTER = 'z';
  const PARAM_SESSION_CONTROL = 'sc';
  const PARAM_DOCUMENT_REFERRER = 'dr';

  const PARAM_CAMPAIGN_NAME = 'cn';
  const PARAM_CAMPAIGN_SOURCE = 'cs';
  const PARAM_CAMPAIGN_MEDIUM = 'cm';
  const PARAM_CAMPAIGN_KEYWORD = 'ck';
  const PARAM_CAMPAIGN_CONTENT = 'cc';
  const PARAM_CAMPAIGN_ID = 'ci';

  const PARAM_GOOGLE_ADWORDS_ID = 'gclid';
  const PARAM_GOOGLE_DISPLAYADS_ID = 'dclid';
  const PARAM_SCREEN_RESOLUTION = 'sr';
  const PARAM_VIEWPORT_SIZE = 'vs';
  const PARAM_DOCUMENT_ENCODING = 'de';
  const PARAM_SCREEN_COLORS = 'sd';
  const PARAM_USER_LANGUAGE = 'ul';
  const PARAM_JAVA_ENABLED = 'je';
  const PARAM_FLASH_VERSION = 'fl';
  const PARAM_HIT_TYPE = 't';
  const PARAM_NON_INTERACTIVE_HIT = 'ni';

  const PARAM_DOC_LOCATION = 'dl';
  const PARAM_DOC_HOST = 'dh';
  const PARAM_DOC_PATH = 'dp';
  const PARAM_DOC_TITLE = 'dt';
  const PARAM_CONTENT_DESC = 'cd';

  const PARAM_APP_NAME = 'an';
  const PARAM_APP_VERSION = 'av';

  const PARAM_EVENT_CATEGORY = 'ec';
  const PARAM_EVENT_ACTION = 'ea';
  const PARAM_EVENT_LABEL = 'el';
  const PARAM_EVENT_VALUE = 'ev';

  const PARAM_TRANS_ID = 'ti';
  const PARAM_TRANS_AFFILIATION = 'ta';
  const PARAM_TRANS_REVENUE = 'tr';
  const PARAM_TRANS_SHIPPING = 'ts';
  const PARAM_TRANS_TAX = 'tt';

  const PARAM_ITEM_NAME = 'in';
  const PARAM_ITEM_PRICE = 'ip';
  const PARAM_ITEM_QUANTITY = 'iq';
  const PARAM_ITEM_CODE = 'ic';
  const PARAM_ITEM_CATEGORY = 'iv';

  const PARAM_CURRENCY_CODE = 'cu';
  const PARAM_SOCIAL_NETWORK = 'sn';
  const PARAM_SOCIAL_ACTION = 'sa';
  const PARAM_SOCIAL_ACTION_TARGET = 'st';

  const PARAM_USER_TIMING_CATEGORY = 'utc';
  const PARAM_USER_TIMING_VARIABLE = 'utv';
  const PARAM_USER_TIMING_TIME = 'utt';
  const PARAM_USER_TIMING_LABEL = 'utl';

  const PARAM_PAGE_LOAD_TIME = 'plt';
  const PARAM_DNS_TIME = 'dns';
  const PARAM_DOWNLOAD_TIME = 'pdt';
  const PARAM_REDIRECT_RESPONSE_TIME = 'rrt';
  const PARAM_TCP_CONNECT_TIME = 'tcp';
  const PARAM_SERVER_RESPONSE_TIME = 'srt';

  const PARAM_EXCEPTION_DESC = 'exd';
  const PARAM_EXCEPTION_IS_FATAL = 'exf';

  const PROTOCOL_URL = 'https://www.google-analytics.com/collect';
  const PROTOCOL_VERSION = '1';
  const MAX_GET_URL_LENGTH = 2000;
  const MAX_POST_BODY_LENGTH = 8192;

}

/**
 * Class GAMP_Exception
 */
class GAMP_Exception extends Exception {

  public function __construct($message, $code = 0, Exception $previous = NULL) {
    return parent::__construct($message, $code, $previous);
  }

  public function __toString() {
    return __CLASS__ . ": [{$this->code}]: {$this->message}\n";
  }

}