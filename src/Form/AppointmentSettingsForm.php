<?php

declare(strict_types=1);

namespace Drupal\appointment\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configuration form for the appointment module.
 */
final class AppointmentSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'appointment_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['appointment.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('appointment.settings');

    $form['slot_duration_minutes'] = [
      '#type'          => 'number',
      '#title'         => $this->t('Slot duration (minutes)'),
      '#description'   => $this->t('The duration of each appointment slot. Recommended: 30.'),
      '#default_value' => $config->get('slot_duration_minutes') ?? 30,
      '#min'           => 15,
      '#max'           => 120,
      '#step'          => 15,
      '#required'      => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('appointment.settings')
      ->set('slot_duration_minutes', (int) $form_state->getValue('slot_duration_minutes'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
