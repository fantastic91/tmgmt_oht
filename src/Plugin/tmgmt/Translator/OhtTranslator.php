<?php

/**
 * @file
 * Contains \Drupal\tmgmt_oht\Plugin\tmgmt\Translator\OhtTranslator.
 */

namespace Drupal\tmgmt_oht\Plugin\tmgmt\Translator;

use Drupal\tmgmt_oht\OhtConnector;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\tmgmt\Entity\Job;
use Drupal\tmgmt\TMGMTException;
use Drupal\tmgmt\TranslatorPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use GuzzleHttp\ClientInterface;
use Drupal\tmgmt\TranslatorInterface;
use Drupal\tmgmt\JobInterface;
use Drupal\Core\Url;
use Drupal\tmgmt\Entity\JobItem;
use Drupal\tmgmt\Entity\RemoteMapping;
use Drupal\tmgmt\Translator\AvailableResult;

/**
 * OHT translation plugin controller.
 *
 * @TranslatorPlugin(
 *   id = "oht",
 *   label = @Translation("OHT translator"),
 *   description = @Translation("A OneHourTranslation translator service."),
 *   ui = "Drupal\tmgmt_oht\OhtTranslatorUi",
 * )
 */
class OhtTranslator extends TranslatorPluginBase implements ContainerFactoryPluginInterface {

  /**
   * {@inheritdoc}
   */
  protected $escapeStart = '[[[';

  /**
   * {@inheritdoc}
   */
  protected $escapeEnd = ']]]';

  /**
   * If set it will be sent by job post action as a comment.
   *
   * @var string
   */
  protected $serviceComment;

  /**
   * Guzzle HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $client;

  /**
   * List of default Drupal 8 and OHT language mapping.
   *
   * @var array
   */
  protected $ohtLanguagesMapping = array(
    'af' => 'af',
    'ar' => 'ar-sa',
    'az' => 'az-az',
    'bg' => 'bg-bg',
    'bn' => 'bn-bd',
    'bs' => 'bs-ba',
    'ca' => 'ca-es',
    'cs' => 'cs-cz',
    'da' => 'da',
    'de' => 'de-de',
    'el' => 'el-gr',
    'en' => 'en-us',
    // 'es-ar' => 'es-ar', // Not supported in Drupal.
    'es' => 'es-es',
    'et' => 'et-ee',
    // 'fa' => 'fa-af', // Not supported in Drupal.
    'fa' => 'fa-ir',
    'fi' => 'fi-fi',
    // 'fl-be' => 'fl-be', // Not supported in Drupal.
    // 'fl-fl' => 'fl-fl', // Not supported in Drupal.
    'fr' => 'fr-fr',
    // 'fr' => 'fr-ca', // Not supported in Drupal.
    'gu' => 'gu-in',
    'he' => 'he-il',
    'hi' => 'hi-in',
    'hr' => 'hr-hr',
    'ht' => 'ht',
    'hu' => 'hu-hu',
    'hy' => 'hy-am',
    'id' => 'id-id',
    'is' => 'is-is',
    'it' => 'it-it',
    'ja' => 'ja-jp',
    'ka' => 'ka-ge',
    'kk' => 'kk-kz',
    'km' => 'km-kh',
    'ko' => 'ko-kp',
    'ku' => 'ku-tr',
    'lt' => 'lt-lt',
    'lv' => 'lv-lv',
    'mk' => 'mk-mk',
    'mr' => 'mr-in',
    'ms' => 'ms-my',
    'nl' => 'nl-nl',
    'nb' => 'no-no', // We are using Norwegian Bokmål.
    'pa' => 'pa-in',
    'pl' => 'pl-pl',
    'ps' => 'ps',
    'pt' => 'pt-pt',
    'pt-br' => 'pt-br',
    'pt-pt' => 'pt-pt',
    'ro' => 'ro-ro',
    'ru' => 'ru-ru',
    'sa' => 'sa-in',
    'sk' => 'sk-sk',
    'sl' => 'sl-si',
    'sq' => 'sq-al',
    'sr' => 'sr-rs',
    'sv' => 'sv-se',
    'ta' => 'ta-in',
    'th' => 'th-th',
    'tl' => 'tl-ph',
    'tr' => 'tr-tr',
    'uk' => 'uk-ua',
    'ur' => 'ur-pk',
    'uz' => 'uz-uz',
    'vi' => 'vi-vn',
    'zh-hans' => 'zh-cn-cmn-s',
    'zh-hant' => 'zh-cn-cmn-t',
    // 'zh-cn-yue' => 'zh-cn-yue',  // Not supported in Drupal.
  );

  /**
   * List of supported languages by OHT.
   *
   * @var array
   */
  protected $supportedRemoteLanguages = array();

  /**
   * Constructs a OHTTranslator object.
   *
   * @param \GuzzleHttp\ClientInterface $client
   *   The Guzzle HTTP client.
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param array $plugin_definition
   *   The plugin implementation definition.
   */
  public function __construct(ClientInterface $client, array $configuration, $plugin_id, array $plugin_definition) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->client = $client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $container->get('http_client'),
      $configuration,
      $plugin_id,
      $plugin_definition
    );
  }

  /**
   * {@inheritdoc}
   */
  public function requestTranslation(JobInterface $job) {
    $translator = $job->getTranslator();
    $xlf_converter = \Drupal::service('plugin.manager.tmgmt_file.format')->createInstance('xlf');

    /**
     * @var JobItem $job_item
     */
    $job_item = NULL;
    $oht_uuid = NULL;

    try {
      foreach ($job->getItems() as $job_item) {
        $conditions = array('tjiid' => array('value' => $job_item->tjiid));
        $xlf = $xlf_converter->export($job, $conditions);
        $name = "JobID" . $job->tjid . '_JobItemID' . $job_item->tjiid . '_' . $job->source_language . '_' . $job->target_language;
        $connector = $this->getConnector($translator);
        $oht_uuid = $connector->uploadFileResource($xlf, $name);
        $oht_uuid = array_shift($oht_uuid);

        $result = $connector->newTranslationProject(
          $job_item->tjiid,
          $this->mapToRemoteLanguage($translator, $job->source_language),
          $this->mapToRemoteLanguage($translator, $job->target_language),
          $oht_uuid,
          $job->settings['notes'],
          $job->settings['expertise']
        );

        $job_item->addRemoteMapping(NULL, $result['project_id'], array(
          'remote_identifier_2' => $oht_uuid,
          'word_count' => $result['wordcount'],
          'remote_data' => array(
            'credits' => $result['credits'],
          ),
        ));

        $job_item->addMessage('OHT Project ID %project_id created. @credits credits reduced from your account.',
          array('%project_id' => $result['project_id'], '@credits' => $result['credits']));
      }

      $job->submitted('Job has been successfully submitted for translation.');
    }
    catch (TMGMTException $e) {
      watchdog_exception('tmgmt_oht', $e);
      $job->rejected('Job has been rejected with following error: @error',
        array('@error' => $e->getMessage()), 'error');
    }
  }

  /**
   * Implements TMGMTTranslatorPluginControllerInterface::getDefaultRemoteLanguagesMappings().
   */
  public function getDefaultRemoteLanguagesMappings() {
    return $this->ohtLanguagesMapping;
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedRemoteLanguages(TranslatorInterface $translator) {
    if (!empty($this->supportedRemoteLanguages)) {
      return $this->supportedRemoteLanguages;
    }

    try {
      $connector = $this->getConnector($translator);
      $result = $connector->getSupportedLanguages();
      $result = json_decode($result, TRUE);

      // Parse languages.
      if (isset($result['results'])) {
        foreach ($result['results'] as $language) {
          $this->supportedRemoteLanguages[$language['code']] = $language['code'];
        }
      }
    } catch (\Exception $e) {
      return array();
    }
    return $this->supportedRemoteLanguages;
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedTargetLanguages(TranslatorInterface $translator, $source_language) {
    $results = array();
    $language_pairs = $translator->getSupportedLanguagePairs();
    foreach ($language_pairs as $language_pair) {
      if ($source_language == $translator->mapToLocalLanguage($language_pair['source_language'])) {
        $target_language = $translator->mapToLocalLanguage($language_pair['target_language']);
        $results[$target_language] = $target_language;
      }
    }

    return $results;
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedLanguagePairs(TranslatorInterface $translator) {
    $language_pairs = array();

    try {
      $connector = $this->getConnector($translator);
      $result = $connector->getSupportedLanguagePairs();
      $result = json_decode($result, TRUE);

      // Build a mapping of source and target language pairs.
      foreach ($result['results'] as $language) {
        foreach ($language['targets'] as $target_language) {
          $language_pairs[] = array('source_language' => $language['source']['code'], 'target_language' => $target_language['code']);
        }
      }
    } catch (\Exception $e) {
      return array();
    }

    return $language_pairs;
  }

  /**
   * {@inheritdoc}
   */
  public function checkAvailable(TranslatorInterface $translator) {
    if ($translator->getSetting('api_public_key') && $translator->getSetting('api_secret_key')) {
      return AvailableResult::yes();
    }
    return AvailableResult::no(t('@translator is not available. Make sure it is properly <a href=:configured>configured</a>.', [
      '@translator' => $translator->label(),
      ':configured' => $translator->url()
    ]));
  }


  /**
   * Gets OHT service connector.
   *
   * @param \Drupal\tmgmt\TranslatorInterface $translator
   *   Current job translator.
   *
   * @return \Drupal\tmgmt_oht\OhtConnector
   *   OHT connector instance.
   */
  public function getConnector(TranslatorInterface $translator) {
    return new OhtConnector($translator, $this->client);
  }
}
