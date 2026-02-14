<?php

namespace Drupal\langbly_translator\Plugin\tmgmt\Translator;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\tmgmt\ContinuousTranslatorInterface;
use Drupal\tmgmt\JobInterface;
use Drupal\tmgmt\JobItemInterface;
use Drupal\tmgmt\TMGMTException;
use Drupal\tmgmt\TranslatorInterface;
use Drupal\tmgmt\TranslatorPluginBase;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Langbly translation plugin for TMGMT.
 *
 * @TranslatorPlugin(
 *   id = "langbly",
 *   label = @Translation("Langbly"),
 *   description = @Translation("Machine translation via the Langbly API. LLM-powered translations for 100+ languages."),
 *   ui = "Drupal\tmgmt\TranslatorPluginUiBase",
 *   logo = "icons/langbly.svg",
 * )
 */
class LangblyTranslator extends TranslatorPluginBase implements ContainerFactoryPluginInterface {

  /**
   * Maximum strings per API request.
   */
  const MAX_BATCH_SIZE = 50;

  /**
   * Maximum characters per API request.
   */
  const MAX_BATCH_CHARS = 10000;

  /**
   * Default API base URL.
   */
  const DEFAULT_API_URL = 'https://api.langbly.com';

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a LangblyTranslator object.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    ClientInterface $http_client,
    LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->httpClient = $http_client;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('http_client'),
      $container->get('logger.factory')->get('langbly_translator'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function checkAvailable(TranslatorInterface $translator) {
    $api_key = $translator->getSetting('api_key');
    if (empty($api_key)) {
      return $this->availabilityResult(FALSE, t('Langbly API key is not configured.'));
    }

    try {
      $this->doRequest($translator, [
        'q' => 'test',
        'target' => 'en',
        'source' => 'en',
      ]);
      return $this->availabilityResult(TRUE);
    }
    catch (\Exception $e) {
      return $this->availabilityResult(FALSE, t('Could not connect to Langbly API: @error', ['@error' => $e->getMessage()]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function checkTranslatable(TranslatorInterface $translator, JobInterface $job) {
    return $this->checkAvailable($translator);
  }

  /**
   * {@inheritdoc}
   */
  public function requestTranslation(JobInterface $job) {
    $translator = $job->getTranslator();
    $source_lang = $job->getRemoteSourceLanguage();
    $target_lang = $job->getRemoteTargetLanguage();

    foreach ($job->getItems() as $job_item) {
      try {
        $this->translateJobItem($job_item, $translator, $source_lang, $target_lang);
      }
      catch (\Exception $e) {
        $job_item->addMessage('Translation failed: @error', ['@error' => $e->getMessage()], 'error');
        $this->logger->error('Langbly translation failed for job item @id: @error', [
          '@id' => $job_item->id(),
          '@error' => $e->getMessage(),
        ]);
      }
    }

    if (!$job->isContinuous()) {
      $job->submitted('Job has been submitted to Langbly for translation.');
    }
  }

  /**
   * Translates a single job item via the Langbly API.
   *
   * @param \Drupal\tmgmt\JobItemInterface $job_item
   *   The job item to translate.
   * @param \Drupal\tmgmt\TranslatorInterface $translator
   *   The translator entity.
   * @param string $source_lang
   *   Remote source language code.
   * @param string $target_lang
   *   Remote target language code.
   *
   * @throws \Drupal\tmgmt\TMGMTException
   */
  protected function translateJobItem(JobItemInterface $job_item, TranslatorInterface $translator, string $source_lang, string $target_lang) {
    // Extract all translatable text segments.
    $data = $job_item->getData();
    $keys_and_texts = $this->extractTranslatableData($data);

    if (empty($keys_and_texts)) {
      $job_item->addMessage('No translatable content found.', [], 'warning');
      return;
    }

    $keys = array_keys($keys_and_texts);
    $texts = array_values($keys_and_texts);

    // Split into batches respecting API limits.
    $batches = $this->createBatches($texts);

    $all_translations = [];
    foreach ($batches as $batch) {
      $response = $this->doRequest($translator, [
        'q' => $batch,
        'target' => $target_lang,
        'source' => $source_lang,
        'format' => 'html',
      ]);

      if (empty($response['data']['translations'])) {
        throw new TMGMTException('Langbly API returned empty translations.');
      }

      foreach ($response['data']['translations'] as $translation) {
        $all_translations[] = $translation['translatedText'];
      }
    }

    // Map translations back to the data structure.
    $translated_data = [];
    foreach ($keys as $index => $key_path) {
      if (isset($all_translations[$index])) {
        $translated_data[$key_path] = [
          '#text' => $all_translations[$index],
        ];
      }
    }

    $job_item->addTranslatedData($this->unflattenData($translated_data));
  }

  /**
   * Extracts translatable text segments from job item data.
   *
   * @param array $data
   *   The job item data array.
   * @param string $prefix
   *   Key path prefix for nested data.
   *
   * @return array
   *   Associative array of key paths to text values.
   */
  protected function extractTranslatableData(array $data, string $prefix = '') {
    $result = [];

    foreach ($data as $key => $value) {
      if ($key === '#text' && isset($data['#translate']) && $data['#translate']) {
        $result[$prefix] = $data['#text'];
        return $result;
      }

      if (is_array($value)) {
        $child_prefix = $prefix ? ($prefix . '|' . $key) : $key;
        $result += $this->extractTranslatableData($value, $child_prefix);
      }
    }

    return $result;
  }

  /**
   * Unflattens a flat key-path array back to a nested structure.
   *
   * @param array $flat_data
   *   Array with pipe-separated key paths.
   *
   * @return array
   *   Nested array.
   */
  protected function unflattenData(array $flat_data) {
    $result = [];

    foreach ($flat_data as $key_path => $value) {
      $keys = explode('|', $key_path);
      $current = &$result;

      foreach ($keys as $key) {
        if (!isset($current[$key])) {
          $current[$key] = [];
        }
        $current = &$current[$key];
      }

      $current = $value;
      unset($current);
    }

    return $result;
  }

  /**
   * Splits texts into batches respecting API limits.
   *
   * @param array $texts
   *   Array of text strings.
   *
   * @return array
   *   Array of batches (each batch is an array of strings).
   */
  protected function createBatches(array $texts) {
    $batches = [];
    $current_batch = [];
    $current_chars = 0;

    foreach ($texts as $text) {
      $text_chars = mb_strlen($text);

      if (!empty($current_batch) && (
        count($current_batch) >= self::MAX_BATCH_SIZE ||
        $current_chars + $text_chars > self::MAX_BATCH_CHARS
      )) {
        $batches[] = $current_batch;
        $current_batch = [];
        $current_chars = 0;
      }

      $current_batch[] = $text;
      $current_chars += $text_chars;
    }

    if (!empty($current_batch)) {
      $batches[] = $current_batch;
    }

    return $batches;
  }

  /**
   * Makes an HTTP request to the Langbly API.
   *
   * @param \Drupal\tmgmt\TranslatorInterface $translator
   *   The translator entity.
   * @param array $body
   *   The request body.
   *
   * @return array
   *   Decoded JSON response.
   *
   * @throws \Drupal\tmgmt\TMGMTException
   */
  protected function doRequest(TranslatorInterface $translator, array $body) {
    $api_key = $translator->getSetting('api_key');
    $api_url = $translator->getSetting('api_url') ?: self::DEFAULT_API_URL;
    $url = rtrim($api_url, '/') . '/language/translate/v2';

    try {
      $response = $this->httpClient->post($url, [
        'headers' => [
          'Authorization' => 'Bearer ' . $api_key,
          'Content-Type' => 'application/json',
          'User-Agent' => 'langbly-drupal-tmgmt/1.0.0',
        ],
        'json' => $body,
        'timeout' => 30,
      ]);

      $data = json_decode((string) $response->getBody(), TRUE);
      if ($data === NULL) {
        throw new TMGMTException('Invalid JSON response from Langbly API.');
      }

      return $data;
    }
    catch (RequestException $e) {
      $message = $e->getMessage();
      if ($e->hasResponse()) {
        $error_body = json_decode((string) $e->getResponse()->getBody(), TRUE);
        if (!empty($error_body['error']['message'])) {
          $message = $error_body['error']['message'];
        }
      }
      throw new TMGMTException('Langbly API error: @error', ['@error' => $message]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedRemoteLanguages(TranslatorInterface $translator) {
    return [
      'af' => 'Afrikaans', 'sq' => 'Albanian', 'am' => 'Amharic',
      'ar' => 'Arabic', 'hy' => 'Armenian', 'az' => 'Azerbaijani',
      'eu' => 'Basque', 'be' => 'Belarusian', 'bn' => 'Bengali',
      'bs' => 'Bosnian', 'bg' => 'Bulgarian', 'ca' => 'Catalan',
      'zh-CN' => 'Chinese (Simplified)', 'zh-TW' => 'Chinese (Traditional)',
      'hr' => 'Croatian', 'cs' => 'Czech', 'da' => 'Danish',
      'nl' => 'Dutch', 'en' => 'English', 'et' => 'Estonian',
      'fi' => 'Finnish', 'fr' => 'French', 'gl' => 'Galician',
      'ka' => 'Georgian', 'de' => 'German', 'el' => 'Greek',
      'gu' => 'Gujarati', 'ht' => 'Haitian Creole', 'he' => 'Hebrew',
      'hi' => 'Hindi', 'hu' => 'Hungarian', 'is' => 'Icelandic',
      'id' => 'Indonesian', 'ga' => 'Irish', 'it' => 'Italian',
      'ja' => 'Japanese', 'kn' => 'Kannada', 'kk' => 'Kazakh',
      'ko' => 'Korean', 'lv' => 'Latvian', 'lt' => 'Lithuanian',
      'mk' => 'Macedonian', 'ms' => 'Malay', 'ml' => 'Malayalam',
      'mt' => 'Maltese', 'mr' => 'Marathi', 'mn' => 'Mongolian',
      'ne' => 'Nepali', 'no' => 'Norwegian', 'fa' => 'Persian',
      'pl' => 'Polish', 'pt' => 'Portuguese', 'pa' => 'Punjabi',
      'ro' => 'Romanian', 'ru' => 'Russian', 'sr' => 'Serbian',
      'sk' => 'Slovak', 'sl' => 'Slovenian', 'es' => 'Spanish',
      'sw' => 'Swahili', 'sv' => 'Swedish', 'ta' => 'Tamil',
      'te' => 'Telugu', 'th' => 'Thai', 'tr' => 'Turkish',
      'uk' => 'Ukrainian', 'ur' => 'Urdu', 'uz' => 'Uzbek',
      'vi' => 'Vietnamese', 'cy' => 'Welsh',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedTargetLanguages(TranslatorInterface $translator, $source_language) {
    $languages = $this->getSupportedRemoteLanguages($translator);
    unset($languages[$source_language]);
    return $languages;
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultRemoteLanguagesMappings() {
    return [
      'zh-hans' => 'zh-CN',
      'zh-hant' => 'zh-TW',
      'pt-br' => 'pt',
      'pt-pt' => 'pt',
      'nb' => 'no',
      'nn' => 'no',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function hasCheckoutSettings(JobInterface $job) {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultSettings() {
    return [
      'api_key' => '',
      'api_url' => self::DEFAULT_API_URL,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    /** @var \Drupal\tmgmt\TranslatorInterface $translator */
    $translator = $form_state->getFormObject()->getEntity();

    $form['api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Langbly API Key'),
      '#default_value' => $translator->getSetting('api_key'),
      '#required' => TRUE,
      '#description' => $this->t('Your Langbly API key. Get one at <a href="@url" target="_blank">langbly.com/dashboard</a>.', [
        '@url' => 'https://langbly.com/dashboard/api-keys',
      ]),
    ];

    $form['api_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API Base URL'),
      '#default_value' => $translator->getSetting('api_url') ?: self::DEFAULT_API_URL,
      '#description' => $this->t('Only change this if you are using a custom endpoint.'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);

    $values = $form_state->getValues();
    $api_key = $values['settings']['api_key'] ?? '';

    if (empty($api_key)) {
      return;
    }

    // Test the API key.
    try {
      $api_url = $values['settings']['api_url'] ?? self::DEFAULT_API_URL;
      $url = rtrim($api_url, '/') . '/language/translate/v2';

      $response = $this->httpClient->post($url, [
        'headers' => [
          'Authorization' => 'Bearer ' . $api_key,
          'Content-Type' => 'application/json',
        ],
        'json' => [
          'q' => 'hello',
          'target' => 'nl',
          'source' => 'en',
        ],
        'timeout' => 10,
      ]);

      $data = json_decode((string) $response->getBody(), TRUE);
      if (empty($data['data']['translations'])) {
        $form_state->setErrorByName('settings][api_key', $this->t('Langbly API returned an unexpected response. Please check your API key.'));
      }
    }
    catch (\Exception $e) {
      $form_state->setErrorByName('settings][api_key', $this->t('Could not connect to Langbly API: @error', ['@error' => $e->getMessage()]));
    }
  }

  /**
   * Returns an availability result.
   *
   * @param bool $available
   *   Whether the translator is available.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $message
   *   Optional message.
   *
   * @return \Drupal\tmgmt\TranslatorPluginBase::availableResult|array
   */
  protected function availabilityResult(bool $available, $message = NULL) {
    if ($available) {
      return parent::checkAvailable($this->getTranslator() ?? NULL) ?: TRUE;
    }
    return $message ? (object) ['success' => FALSE, 'message' => $message] : FALSE;
  }

}
