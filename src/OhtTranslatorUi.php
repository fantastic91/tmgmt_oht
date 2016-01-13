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
 * Oht translator UI.
 */
class OhtTranslatorUi extends TranslatorPluginUiBase {

  /**
   * {@inheritdoc}
   */
  public function reviewForm(array $form, FormStateInterface $form_state, JobItemInterface $item) {
    /** @var Drupal\tmgmt_oht\Plugin\tmgmt\Translator\OhtTranslator $translator_plugin */
    $translator_plugin = $item->getTranslator()->getPlugin();
    $translator_plugin->setTranslator($item->getTranslator());
    $mappings = $item->getRemoteMappings();
    /** @var Drupal\tmgmt\Entity\RemoteMapping $mapping */
    $mapping = array_shift($mappings);

    $comments = $translator_plugin->getProjectComments($mapping->getRemoteIdentifier1());
    $rows = array();
    $new_comment_link = '';

    if (is_array($comments) && count($comments) > 0) {
      foreach ($comments as $comment) {
        $rows[] = array(
          array(
            'data' => t('By %name (%role) at %time', array(
              '%name' => $comment['commenter_name'],
              '%role' => $comment['commenter_role'],
              '%time' => \Drupal::service('date.formatter')->format(strtotime($comment['date'])),
            )),
            'class' => 'meta',
          ),
          $comment['comment_content'],
        );
      }

      $new_comment_link = '<a href="#new-comment">' . t('Add new comment') . '</a>';
    }
    $form['#attached'] = array('library' => array('tmgmt_oht/comments'));
    $form['oht_comments'] = array(
      '#type' => 'fieldset',
      '#title' => t('OHT Comments'),
      '#collapsible' => TRUE,
    );
    $form['oht_comments']['container'] = array(
      '#prefix' => '<div id="tmgmt-translator_plugin-comments-wrapper">',
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
      '#submit' => array(array($this, 'submitAddComment')),
      '#validate' => array(array($this, 'validateComment')),
      '#ajax' => array(
        'callback' => array($this, 'updateReviewForm'),
        'wrapper' => 'tmgmt-translator_plugin-comments-wrapper',
      ),
    );

    return $form;
  }

  /**
   * Validates submitted OHT comment.
   */
  public function validateComment($form, FormStateInterface $form_state) {
    if (empty($form_state->getValue('comment'))) {
      $form_state->setErrorByName('comment', t('The submitted comment cannot be empty.'));
    }
  }

  /**
   * Submit callback to add new comment to an OHT project.
   */
  public function submitAddComment(array $form, FormStateInterface $form_state) {
    /* @var JobItemInterface $job_item */
    $job_item = $form_state->getFormObject()->getEntity();

    /** @var Drupal\tmgmt_oht\Plugin\tmgmt\Translator\OhtTranslator $translator_plugin */
    $translator_plugin = $job_item->getTranslator()->getPlugin();
    $translator_plugin->setTranslator($job_item->getTranslator());
    $mappings = $job_item->getRemoteMappings();

    try {
      /* @var Drupal\tmgmt\Entity\RemoteMapping $mapping */
      $mapping = array_shift($mappings);
      $translator_plugin->addProjectComment($mapping->getRemoteIdentifier1(), $form_state->getValue('comment'));
      $form_state->set('comment_submitted', 1);
      $form_state->setRebuild();
    }
    catch (Drupal\tmgmt\TMGMTException $e) {
      drupal_set_message($e->getMessage(), 'error');
    }
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
      '#description' => t('Please enter your public API key or visit <a href="@url">OneHourTranslation</a> service to get one.', ['@url' => 'https://www.onehourtranslation.com/profile/apiKeys']),
    );
    $form['api_secret_key'] = array(
      '#type' => 'textfield',
      '#title' => t('OHT API Secret key'),
      '#default_value' => $translator->getSetting('api_secret_key'),
      '#description' => t('Please enter your secret API key or visit <a href="@url">OneHourTranslation</a> service to get one.', ['@url' => 'https://www.onehourtranslation.com/profile/apiKeys']),
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
   * Ajax callback for the OHT comment form.
   *
   * @param array $form
   *   The form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array
   *   OHT comments container.
   */
  public function updateReviewForm(array $form, FormStateInterface $form_state) {
    return $form['oht_comments']['container'];
  }

  /**
   * {@inheritdoc}
   */
  public function checkoutSettingsForm(array $form, FormStateInterface $form_state, JobInterface $job) {
    /** @var \Drupal\tmgmt_oht\Plugin\tmgmt\Translator\OhtTranslator $translator_plugin */
    $translator_plugin = $job->getTranslator()->getPlugin();
    $translator_plugin->setTranslator($job->getTranslator());

    $settings['expertise'] = array(
      '#type' => 'select',
      '#title' => t('Expertise'),
      '#description' => t('Select an expertise to identify the area of the text you will request to translate.'),
      '#empty_option' => ' - ',
      '#options' => $translator_plugin->getExpertise($job),
      '#default_value' => $job->getSetting('expertise') ? $job->getSetting('expertise') : '',
    );
    $settings['notes'] = array(
      '#type' => 'textarea',
      '#title' => t('Instructions'),
      '#description' => t('You can provide a set of instructions so that the translator will better understand your requirements.'),
      '#default_value' => $job->getSetting('notes') ? $job->getSetting('notes') : '',
    );
    if ($price_quote = $translator_plugin->getQuotation($job)) {
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
    if ($account_details = $translator_plugin->getAccountDetails()) {
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
        '#submit' => array(array($this, 'submitPullTranslations')),
        '#weight' => -10,
      );
    }

    return $form;
  }

  /**
   * Submit callback to pull translations form OHT.
   */
  public function submitPullTranslations(array $form, FormStateInterface $form_state) {
    /** @var Drupal\tmgmt\Entity\Job $job */
    $job = $form_state->getFormObject()->getEntity();
    /** @var Drupal\tmgmt_oht\Plugin\tmgmt\Translator\OhtTranslator $translator_plugin */
    $translator_plugin = $job->getTranslator()->getPlugin();

    if (!$error_messages = $translator_plugin->fetchJobs($job)) {
      drupal_set_message(t('All available translations from OneHourTranslation have been pulled.'));
    }
    else {
      drupal_set_message(t('An error occurred during the pulling translations.'), 'error');
    }
  }

}
