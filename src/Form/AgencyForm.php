<?php

declare(strict_types=1);

namespace Drupal\appointment\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form controller for the Agency entity edit form.
 *
 * Replaces the raw `operating_hours` JSON textarea with a structured,
 * per-day widget (checkbox + open/close time selects). On save the values
 * are serialised back to the JSON format consumed by AppointmentManagerService.
 */
final class AgencyForm extends ContentEntityForm {

  /**
   * Ordered list of days with their JSON key and human label.
   */
  private const DAYS = [
    'mon' => 'Monday',
    'tue' => 'Tuesday',
    'wed' => 'Wednesday',
    'thu' => 'Thursday',
    'fri' => 'Friday',
    'sat' => 'Saturday',
    'sun' => 'Sunday',
  ];

  // ---------------------------------------------------------------------------
  // Form build
  // ---------------------------------------------------------------------------

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildForm($form, $form_state);

    // Hide the raw operating_hours string field — we replace it with the
    // structured widget below.
    $form['operating_hours']['#access'] = FALSE;

    // Parse the currently stored JSON (if any) so we can pre-populate the
    // widget when editing an existing agency.
    /** @var \Drupal\appointment\Entity\Agency $agency */
    $agency   = $this->entity;
    $stored   = $agency->get('operating_hours')->value ?? '{}';
    $schedule = json_decode($stored, TRUE) ?? [];

    // Build the per-day widget.
    $form['hours_widget'] = [
      '#type'        => 'details',
      '#title'       => $this->t('Operating hours'),
      '#open'        => TRUE,
      '#description' => $this->t('Select the opening and closing times for each working day. Leave a day unchecked if the agency is closed that day.'),
      '#tree'        => TRUE,
      '#weight'      => $form['operating_hours']['#weight'] ?? 5,
      '#attached'    => ['library' => ['appointment/booking-wizard']],
    ];

    $timeOptions = $this->buildTimeOptions();

    foreach (self::DAYS as $key => $label) {
      $dayData   = $schedule[$key] ?? NULL;
      $isOpen    = !empty($dayData);
      $openTime  = $dayData[0] ?? '09:00';
      $closeTime = $dayData[1] ?? '17:00';

      $form['hours_widget'][$key] = [
        '#type'       => 'fieldset',
        '#title'      => $this->t($label),
        '#attributes' => ['class' => ['hours-day-row']],
      ];

      $form['hours_widget'][$key]['open'] = [
        '#type'          => 'checkbox',
        '#title'         => $this->t('Open'),
        '#default_value' => $isOpen ? 1 : 0,
        '#attributes'    => [
          'class'                          => ['day-open-toggle'],
          'data-day'                       => $key,
        ],
      ];

      $form['hours_widget'][$key]['start'] = [
        '#type'          => 'select',
        '#title'         => $this->t('Opens at'),
        '#options'       => $timeOptions,
        '#default_value' => $openTime,
        '#states'        => [
          'visible' => [
            ':input[name="hours_widget[' . $key . '][open]"]' => ['checked' => TRUE],
          ],
        ],
      ];

      $form['hours_widget'][$key]['end'] = [
        '#type'          => 'select',
        '#title'         => $this->t('Closes at'),
        '#options'       => $timeOptions,
        '#default_value' => $closeTime,
        '#states'        => [
          'visible' => [
            ':input[name="hours_widget[' . $key . '][open]"]' => ['checked' => TRUE],
          ],
        ],
      ];
    }

    return $form;
  }

  // ---------------------------------------------------------------------------
  // Validation
  // ---------------------------------------------------------------------------

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $widget = $form_state->getValue('hours_widget') ?? [];
    foreach (self::DAYS as $key => $label) {
      $day = $widget[$key] ?? [];
      if (!empty($day['open'])) {
        if (($day['start'] ?? '') >= ($day['end'] ?? '')) {
          $form_state->setErrorByName(
            "hours_widget][{$key}][end",
            $this->t('@day: closing time must be later than opening time.', ['@day' => $this->t($label)])
          );
        }
      }
    }
  }

  // ---------------------------------------------------------------------------
  // Save
  // ---------------------------------------------------------------------------

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    // Serialise the widget values back to JSON before the entity is saved.
    $widget   = $form_state->getValue('hours_widget') ?? [];
    $schedule = [];

    foreach (self::DAYS as $key => $label) {
      $day = $widget[$key] ?? [];
      if (!empty($day['open'])) {
        $schedule[$key] = [$day['start'], $day['end']];
      }
    }

    $this->entity->set('operating_hours', json_encode($schedule));

    $result      = parent::save($form, $form_state);
    $messageArgs = ['%label' => $this->entity->toLink()->toString()];
    $loggerArgs  = [
      '%label' => $this->entity->label(),
      'link'   => $this->entity->toLink($this->t('View'))->toString(),
    ];

    match ($result) {
      SAVED_NEW     => [
        $this->messenger()->addStatus($this->t('New agency %label has been created.', $messageArgs)),
        $this->logger('appointment')->notice('New agency %label has been created.', $loggerArgs),
      ],
      SAVED_UPDATED => [
        $this->messenger()->addStatus($this->t('The agency %label has been updated.', $messageArgs)),
        $this->logger('appointment')->notice('The agency %label has been updated.', $loggerArgs),
      ],
      default       => throw new \LogicException('Could not save the entity.'),
    };

    $form_state->setRedirectUrl($this->entity->toUrl());
    return $result;
  }

  // ---------------------------------------------------------------------------
  // Helpers
  // ---------------------------------------------------------------------------

  /**
   * Builds a time options array in 30-minute increments from 00:00 to 23:30.
   *
   * @return array<string, string>  e.g. ['09:00' => '09:00', '09:30' => '09:30']
   */
  private function buildTimeOptions(): array {
    $options = [];
    for ($h = 0; $h < 24; $h++) {
      foreach ([0, 30] as $m) {
        $time = sprintf('%02d:%02d', $h, $m);
        $options[$time] = $time;
      }
    }
    return $options;
  }

}
