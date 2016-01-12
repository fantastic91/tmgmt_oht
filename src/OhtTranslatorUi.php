<?php

/**
 * @file
 * Contains Drupal\tmgmt_oht\OhtTranslatorUi.
 */

namespace Drupal\tmgmt_oht;

use Drupal;
use Drupal\tmgmt\TranslatorPluginUiBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\tmgmt\JobItemInterface;
use Drupal\tmgmt\JobInterface;

/**
 * @file
 * Provides OHT translation plugin controller.
 */
class OhtTranslatorUi extends TranslatorPluginUiBase {

  /**
   * {@inheritdoc}
   */
  public function reviewForm(array $form, FormStateInterface $form_state, JobItemInterface $item) {
    /** @var Drupal\tmgmt_oht\Plugin\tmgmt\Translator\OhtTranslator $oht */
    $oht = $item->getTranslator()->getPlugin();
    $oht->setEntity($item->getTranslator());
    $mappings = $item->getRemoteMappings();
    /** @var Drupal\tmgmt\Entity\RemoteMapping $mapping */
    $mapping = array_shift($mappings);

    $comments = $oht->getProjectComments($mapping->getRemoteIdentifier1());
    $rows = array();
    $new_comment_link = '';

    if (is_array($comments) && count($comments) > 0) {
      foreach ($comments as $comment) {
        $rows[] = array(
          array(
            'data' => t('By %name (%role) at %time', array(
              '%name' => $comment['commenter_name'],
              '%role' => $comment['commenter_role'],
              '%time' => format_date(strtotime($comment['date']))
            )),
            'class' => 'meta',
          ),
          Drupal\Component\Utility\Html::escape($comment['comment_content']),
        );
      }

      $new_comment_link = '<a href="#new-comment">' . t('Add new comment') . '</a>';
    }

    $form['#attached'] = array(
      'library' => array('tmgmt_oht/comments'),
    );

    //$form['#attached']['css'][] = drupal_get_path('module', 'tmgmt_oht') . '/css/tmgmt_oht_comments.css';
    //$form['#attached']['js'][] = drupal_get_path('module', 'tmgmt_oht') . '/js/tmgmt_oht_comments.js';

    $form['oht_comments'] = array(
      '#type' => 'fieldset',
      '#title' => t('OHT Comments'),
      '#collapsible' => TRUE,
    );
    $form['oht_comments']['container'] = array(
      '#prefix' => '<div id="tmgmt-oht-comments-wrapper">',
      '#suffix' => '</div>',
    );
    $form['oht_comments']['container']['comments'] = array(
      '#theme' => 'table',
      '#rows' => $rows,
      '#header' => array(),
      '#empty' => t('No comments'),
      '#prefix' => $new_comment_link,
    );
    $form['oht_comments']['container']['comment'] = array(
      '#type' => 'textarea',
      '#title' => t('Comment text'),
      '#prefix' => '<a name="new-comment"></a>'
    );
    $form['oht_comments']['container']['comment_submitted'] = array(
      '#type' => 'hidden',
      '#value' => $form_state->has('comment_submitted') ? $form_state->get('comment_submitted') : 0,
    );
    $form['oht_comments']['add_comment'] = array(
      '#type' => 'submit',
      '#value' => t('Add new comment'),
      '#submit' => array('tmgmt_oht_add_comment_form_submit'),
      '#ajax' => array(
        'callback' => 'tmgmt_oht_review_form_ajax',
        'wrapper' => 'tmgmt-oht-comments-wrapper',
      ),
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    /** @var \Drupal\tmgmt\TranslatorInterface $translator */
    $translator = $form_state->getFormObject()->getEntity();

    $form['api_public_key'] = array(
      '#type' => 'textfield',
      '#title' => t('OHT API Public Key'),
      '#default_value' => $translator->getSetting('api_public_key'),
      '#description' => t('Please enter your public API key for OneHourTranslation service.'),
    );
    $form['api_secret_key'] = array(
      '#type' => 'textfield',
      '#title' => t('OHT API Secret key'),
      '#default_value' => $translator->getSetting('api_secret_key'),
      '#description' => t('Please enter your secret API key for OneHourTranslation service.'),
    );
    $form['use_sandbox'] = array(
      '#type' => 'checkbox',
      '#title' => t('Use the sandbox'),
      '#default_value' => $translator->getSetting('use_sandbox'),
      '#description' => t('Check to use the testing environment.'),
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function checkoutSettingsForm(array $form, FormStateInterface $form_state, JobInterface $job) {
    /** @var \Drupal\tmgmt_oht\Plugin\tmgmt\Translator\OhtTranslator $translator */
    $translator = $job->getTranslator()->getPlugin();
    $translator->setEntity($job->getTranslator());

    $settings['expertise'] = array(
      '#type' => 'select',
      '#title' => t('Expertise'),
      '#description' => t('Select an expertise to identify the area of the text you will request to translate.'),
      '#empty_option' => ' - ',
      '#options' => $translator->getExpertise($job),
      '#default_value' => $job->getSetting('expertise') ? $job->getSetting('expertise') : '',
    );
    $settings['notes'] = array(
      '#type' => 'textarea',
      '#title' => t('Instructions'),
      '#description' => t('You can provide a set of instructions so that the translator will better understand your requirements.'),
      '#default_value' => $job->getSetting('notes') ? $job->getSetting('notes') : '',
    );
    if ($price_quote = $translator->getQuotation($job)) {
      $currency = $price_quote['currency'] == 'EUR' ? '€' : $price_quote['currency'];
      $total = $price_quote['total'];
      $settings['price_quote'] = array(
        '#type' => 'item',
        '#title' => t('Price quote'),
        '#markup' => t('<strong>@word_count</strong> words, <strong>@credits</strong> credits/<strong>@total_price@currency</strong>.', [
          '@word_count' => $total['wordcount'],
          '@net_price' => $total['net_price'],
          '@transaction_fee' => $total['transaction_fee'],
          '@total_price' => $total['price'],
          '@credits' => $total['credits'],
          '@currency' => $currency,
        ]),
      );
    }
    if ($account_details = $translator->getAccountDetails()) {
      $settings['account_balance'] = array(
        '#type' => 'item',
        '#title' => t('Account balance'),
        '#markup' => t('<strong>@credits</strong> credits', array('@credits' => $account_details['credits'])),
      );
    }
    if (isset($account_details['credits']) && isset($total['price']) && $account_details['credits'] < $total['price']) {
      $settings['low_account_balance'] = array(
        '#type' => 'container',
        '#markup' => t('Your account balance is lower than quoted price and the translation will not be successful.'),
        '#attributes' => array(
          'class' => array('messages messages--warning'),
        ),
      );
    }

    return $settings;
  }

  /**
   * {@inheritdoc}
   */
  public function checkoutInfo(JobInterface $job) {
    $form = array();

    if ($job->isActive()) {
      $form['actions']['pull'] = array(
        '#type' => 'submit',
        '#value' => t('Pull translations'),
        '#submit' => array('_tmgmt_oht_pull_submit'),
        '#weight' => -10,
      );
    }

    return $form;
  }

}
