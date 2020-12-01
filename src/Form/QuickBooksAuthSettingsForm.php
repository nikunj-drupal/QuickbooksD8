<?php

namespace Drupal\quickbooks\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Datetime\DrupalDateTime;

/**
 * Defines a form that configures forms module settings.
 */
class QuickBooksAuthSettingsForm extends ConfigFormBase
{

  /**
   * {@inheritdoc}
   */
    public function getFormId()
    {
        return 'quickbooks_auth_config';
    }

    /**
     * {@inheritdoc}
     */
    protected function getEditableConfigNames()
    {
        return [
          'quickbooks_auth_config.settings',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(array $form, FormStateInterface $form_state)
    {
        $config = $this->config('quickbooks_auth_config.settings');
        $form['realm_id'] = [
          '#type' => 'textfield',
          '#title' => t('Realm Id(Connected commpanyID)'),
          '#default_value' => \Drupal::state()->get('quickbooks_settings_realm_id'),
          '#size' => 45,
          // Hostnames can be 255 characters long.
          '#maxlength' => 255,
          '#disabled' => TRUE,
        ];
        $form['refresh_token'] = [
          '#type' => 'textfield',
          '#title' => t('Refresh Token'),
          '#default_value' => \Drupal::state()->get('quickbooks_settings_access_refreshtoken'),
          '#size' => 45,
          // Hostnames can be 255 characters long.
          '#maxlength' => 255,
          '#disabled' => TRUE,
        ];
        $form['consumer_key'] = [
          '#type' => 'textfield',
          '#title' => t('Consumer Key'),
          '#default_value' => \Drupal::state()->get('quickbooks_settings_consumer_key'),
          '#size' => 45,
          // Hostnames can be 255 characters long.
          '#maxlength' => 255,
        ];
        $form['consumer_secret'] = [
          '#type' => 'textfield',
          '#title' => t('Consumer Secret'),
          '#default_value' => \Drupal::state()->get('quickbooks_settings_consumer_secret'),
          '#size' => 45,
          // Hostnames can be 255 characters long.
          '#maxlength' => 255,
        ];
        $form['access_token'] = [
          '#type' => 'textarea',
          '#title' => t('Access Token'),
          '#default_value' => \Drupal::state()->get('quickbooks_settings_access_token'),
          '#disabled' => TRUE,
        ];

        return parent::buildForm($form, $form_state);
    }

    /**
     * {@inheritdoc}
     */
    public function submitForm(array &$form, FormStateInterface $form_state)
    {   
        $values = $form_state->getValues();
        $consumer_key = $form_state->getValue('consumer_key');
        $consumer_secret = $form_state->getValue('consumer_secret');
        \Drupal::state()->set('quickbooks_settings_consumer_key', $consumer_key);
        \Drupal::state()->set('quickbooks_settings_consumer_secret', $consumer_secret);
        $this->config('quickbooks_auth_config.settings')
          ->set('consumer_key', $consumer_key)
          ->set('consumer_secret', $consumer_secret)
          ->save();
        parent::submitForm($form, $form_state);
    }
}
