<?php

namespace Drupal\langbly_translator\Plugin\tmgmt\Translator;

use Drupal\Core\Form\FormStateInterface;
use Drupal\tmgmt\TranslatorPluginUiBase;

/**
 * Langbly translator UI.
 */
class LangblyTranslatorUi extends TranslatorPluginUiBase {

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
      '#default_value' => $translator->getSetting('api_url') ?: 'https://api.langbly.com',
      '#description' => $this->t('Only change this if you are using a custom endpoint.'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\tmgmt\TranslatorInterface $translator */
    $translator = $form_state->getFormObject()->getEntity();
    $translator->getPlugin()->validateConfigurationForm($form, $form_state);
  }

}
