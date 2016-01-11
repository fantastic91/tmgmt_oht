<?php
/**
 * @file
 * Contains \Drupal\tmgmt_oht\OhtConnector.
 */

namespace Drupal\tmgmt_oht;

use Drupal\tmgmt\Entity\Translator;
use Drupal\tmgmt\TMGMTException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use Drupal\Core\Url;
use GuzzleHttp;

/**
 * Class OhtConnector
 * Implements methods for connecting and getting data from OneHourTranslation.
 */
class OhtConnector {

  /**
   * Translation service URL.
   */
  const PRODUCTION_URL = 'https://api.onehourtranslation.com/api/';

  /**
   * Translation sandbox service URL.
   */
  const SANDBOX_URL = 'https://sandbox.onehourtranslation.com/api/';

  /**
   * Translation service API version.
   *
   * @var string
   */
  const API_VERSION = '2';

  /**
   * The response code.
   *
   * @var int
   */
  private $responseCode;

  /**
   * The response status.
   *
   * @var string
   */
  private $responseStatus;

  /**
   * Use sandbox URL to connect to the service.
   *
   * @var bool
   */
  private $useSandbox = FALSE;

  /**
   * The public key.
   *
   * @var string
   */
  private $publicKey;

  /**
   * The secret key.
   *
   * @var string
   */
  private $secretKey;

  /**
   * Flag to trigger debug watchdog logging of requests.
   *
   * Use variable_set('tmgmt_oht_debug', TRUE); to toggle debugging.
   *
   * @var bool
   */
  private $debug = FALSE;

  /**
   * Guzzle HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $client;

  /**
   * Construct the connector to OHT service.
   *
   * @param \Drupal\tmgmt\Entity\Translator $translator
   *   Translator which has the connection settings.
   * @param \GuzzleHttp\ClientInterface $client
   *   An HTTP Client.
   */
  public function __construct(Translator $translator, ClientInterface $client) {
    $this->useSandbox = $translator->getSetting('use_sandbox');
    $this->publicKey = $translator->getSetting('api_public_key');
    $this->secretKey = $translator->getSetting('api_secret_key');
    $this->debug = \Drupal::config('tmgmt_oht.settings')->get('tmgmt_oht_debug');
    $this->client = $client;
  }

  /**
   * Does a request to OHT services.
   *
   * @param string $path
   *   Resource path.
   * @param string $method
   *   HTTP method (GET, POST...)
   * @param array $data
   *   Data to send to OHT service.
   * @param bool $download
   *   If we expect resource to be downloaded.
   * @param string $content_type
   *   (optional) Content-type to use.
   *
   * @return object
   *   Response object from OHT.
   *
   * @throws \Drupal\tmgmt\TMGMTException
   */
  protected function request($path, $method = 'GET', $data = array(), $download = FALSE, $content_type = 'application/x-www-form-urlencoded') {
    $options = array();
    $headers = array();

    if ($this->useSandbox) {
      $url = self::SANDBOX_URL . '/' . self::API_VERSION . '/' . $path;
    }
    else {
      $url = self::PRODUCTION_URL . '/' . self::API_VERSION . '/' . $path;
    }

    if (is_array($data)) {
      $data['public_key'] = $this->publicKey;
      $data['secret_key'] = $this->secretKey;
    }

    try {
      // If we will send GET request - add params to the URL.
      if ($method == 'GET') {
        $url = Url::fromUri($url)->setOptions(array(
          'query' => $data,
          'absolute' => TRUE,
        ))->toString();
      }
      // Otherwise - configure $options array.
      elseif ($method == 'POST') {
        $options += array(
          'headers' => array('Content-Type' => $content_type),
          'method' => 'POST',
        );
        if (is_array($data)) {
          $options['data'] = $data;
        }
        // Support for multipart form data.
        else {
          $options['data'] = $data;
        }

        $url = Url::fromUri($url)->setOptions(array('absolute' => TRUE))->toString();
//      $response = $this->client->request($method, $url, ['form_params' => $data, 'headers' => $headers]);
      }

      $response = $this->client->request($method, $url, $options);

      if ($this->debug) {
        \Drupal::logger('tmgmt_oht')->info("Sending request to OHT at @url method @method with data @data\n\nResponse: @response", array(
          '@url' => $url,
          '@method' => $method,
          '@data' => var_export($data, TRUE),
          '@response' => var_export($response, TRUE),
        ));
      }
    } catch (BadResponseException $e) {
      $response = $e->getResponse();
      throw new TMGMTException('Unable to connect to OHT service due to following error: @error', ['@error' => $response->getReasonPhrase()], $response->getStatusCode());
    }

    $this->responseCode = $response->code;

    if ($response->code != 200) {
      throw new TMGMTException('Unable to connect to the OHT service due to following error: @error at @url',
        array('@error' => $response->error, '@url' => $url));
    }

    // If we are expecting a download, just return received data.
    if ($download) {
      return $response->data;
    }

    $response = json_decode($response->data);

    $this->responseStatus = $response['status'];

    if ($response['status']['code'] != 0) {
      throw new TMGMTException('OHT service returned validation error: #%code %error',
        array(
          '%code' => $response['status']['code'],
          '%error' => $response['status']['msg'],
        ));
    }

    if (!empty($response['errors'])) {
      \Drupal::logger('tmgmt_oht')
        ->notice('OHT error: @error', array('@error' => implode('; ', $response['errors'])));
      throw new TMGMTException('OHT service returned following error: %error',
        array('%error' => $response['status']['msg']));
    }

    return $response['results'];
  }

  /**
   * Creates a text resource at OHT.
   *
   * @param string $text
   *   Text to be translated.
   *
   * @return array
   *   OHT uuid of the resource.
   */
  public function uploadTextResource($text) {
    $params['text'] = $text;
    return $this->request('resources/text', 'POST', $params);
  }

  /**
   * Downloads resource.
   *
   * @param string $oht_uuid
   *
   * @return array
   *   Resource xml.
   */
  public function getResourceDownload($oht_uuid, $project_id = NULL) {
    return $this->request('resources/' . $oht_uuid . '/download', 'GET', ($project_id) ? array('project_id' => $project_id) : array(), TRUE);
  }

  /**
   * Returns supported language pairs.
   *
   * @return mixed
   *   The list of supported language pairs.
   */
  public function getSupportedLanguagePairs() {
    return $this->request('discover/language_pairs', 'GET', array(), TRUE);
  }

  /**
   * Returns supported languages.
   *
   * @return mixed
   *   The list of supported languages.
   */
  public function getSupportedLanguages() {
    return $this->request('discover/languages', 'GET', array(), TRUE);
  }

  /**
   * Creates new translation project at OHT.
   *
   * @param int $tjiid
   *   Translation job item id.
   * @param string $source_language
   *   Source language.
   * @param string $target_language
   *   Target language.
   * @param string $oht_uuid
   *   OHT uuid.
   * @param string $notes
   *   Notes to be sent with the job.
   * @param string $expertise
   *   Expertise code.
   * @param array $params
   *   Additional params.
   *
   * @return array
   *   OHT project data.
   */
  public function newTranslationProject($tjiid, $source_language, $target_language, $oht_uuid, $notes = NULL, $expertise = NULL, $params = array()) {
    $params['source_language'] = $source_language;
    $params['target_language'] = $target_language;
    $params['sources'] = $oht_uuid;
    $params['notes'] = $notes;
    $params['callback_url'] = Url::fromRoute('tmgmt_oht.callback')->setAbsolute()->toString();
    $params['custom0'] = $tjiid;
    $params['custom1'] = tmgmt_oht_hash($tjiid);

    if (!empty($expertise)) {
      $params['expertise'] = $expertise;
    }

    return $this->request('projects/translation', 'POST', $params);
  }

  /**
   * Gets OHT project data.
   *
   * @param int $project_id
   *   OHT project id.
   *
   * @return array
   *   Project info.
   */
  public function getProjectDetails($project_id) {
    return $this->request('projects/' . $project_id);
  }

  /**
   * Create new comment to project.
   *
   * @param int $project_id
   * @param string $content (optional)
   *
   * @return array
   *   Response.
   */
  public function addProjectComment($project_id, $content = '') {
    $params['content'] = $content;
    return $this->request('projects/' . $project_id . '/comments', 'POST', $params);
  }

  /**
   * Fetch comments by project id
   *
   * @param integer $project_id
   *
   * @return array
   *   Project comments.
   */
  public function getProjectComments($project_id) {
    return $this->request('projects/' . $project_id . '/comments', 'GET');
  }

  /**
   * Gets wordcount.
   *
   * @param string $oht_uuid
   *   OHT resource uuid.
   *
   * @return array
   *   Wordcount info.
   */
  public function getWordcount($oht_uuid) {
    $params['resources'] = $oht_uuid;
    return $this->request('tools/wordcount', 'GET', $params);
  }

  /**
   * Returns account details.
   *
   * @return mixed
   *   The array containing account details.
   */
  public function getAccountDetails() {
    return $this->request('account');
  }

  /**
   * Gets quotation.
   *
   * @param array $resource_uuids
   *   List of OHT resource uuids.
   * @param int $word_count
   *   The number of words to translate.
   * @param string $source_language
   *   The source language.
   * @param string $target_language
   *   The target language.
   * @param string $service
   *   (optional) Service value. It can be one option from:
   *    - translation (default value)
   *    - proofreading
   *    - transproof
   *    - transcription
   * @param string $expertise
   *   (optional) Expertise code.
   * @param string $proofreading
   *   (optional) If to provide proofreading.
   * @param string $currency
   *   (optional) Currency code.
   *
   * @return mixed
   *   Quotation data.
   */
  public function getQuotation(array $resource_uuids, $word_count = 0, $source_language, $target_language, $service = 'translation', $expertise = NULL, $proofreading = NULL, $currency = NULL) {
    $params['resources'] = implode(',', $resource_uuids);
    $params['word_count'] = $word_count;
    $params['source_language'] = $source_language;
    $params['target_language'] = $target_language;
    $params['service'] = $service;
    $params['expertise'] = $expertise;
    $params['proofreading'] = $proofreading;
    $params['currency'] = $currency;

    return $this->request('tools/quote', 'GET', $params);
  }

  /**
   * Returns list of expertise options.
   *
   * @param string $source_language
   *   (optional) The source language. Mandatory if target language is
   *   specified.
   * @param string $target_language
   *   (optional) The target language. Mandatory if source language is
   *   specified.
   *
   * @return array
   *   List of expertise options, keyed by their code.
   */
  public function getExpertise($source_language = NULL, $target_language = NULL) {
    $params['source_language'] = $source_language;
    $params['target_language'] = $target_language;

    return $this->request('discover/expertise', 'GET', $params);
  }

  /**
   * Creates a file resource at OHT.
   *
   * @param string $xliff
   *   .XLIFF string to be translated. It is send as a file.
   * @param string $name
   *   File name of the .XLIFF file.
   *
   * @return array
   *   OHT uuid of the resource.
   */
  public function uploadFileResource($xliff, $name) {
    $boundary = '------------------------acee0aaa2902bfa2';
    $content_type = "multipart/form-data; boundary=$boundary";

    $output = "";
    $output .= "--$boundary\r\n";
    $output .= "Content-Disposition: form-data; name=\"upload\"; filename=\"$name.xliff\"\r\nContent-Type: text/plain\r\n\r\n";
    $output .= $xliff;
    $output .= "\r\n\r\n";
    $output .= "--$boundary\r\n";
    $output .= "Content-Disposition: form-data; name=\"public_key\"\r\n\r\n$this->publicKey\r\n";
    $output .= "--$boundary\r\n";
    $output .= "Content-Disposition: form-data; name=\"secret_key\"\r\n\r\n$this->secretKey\r\n";
    $output .= "--$boundary--";

    return $this->request('resources/file', 'POST', $output, FALSE, $content_type);
  }
}
