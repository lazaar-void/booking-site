<?php

declare(strict_types=1);

namespace Drupal\appointment\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Link;
use Drupal\Core\Url;

/**
 * Provides a 'Book an Appointment' block.
 *
 * @Block(
 *   id = "appointment_booking_cta",
 *   admin_label = @Translation("Appointment: Book Now CTA"),
 *   category = @Translation("Appointment")
 * )
 */
final class AppointmentBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $url = Url::fromRoute('appointment.booking_wizard');
    $link = Link::fromTextAndUrl($this->t('Book an Appointment'), $url)->toRenderable();

    // Add CSS classes for styling.
    $link['#attributes']['class'][] = 'appointment-block-cta';

    return [
      '#prefix' => '<div class="appointment-booking-block">',
      '#suffix' => '</div>',
      'link' => $link,
      '#attached' => [
        'library' => ['appointment/booking-wizard'],
      ],
    ];
  }

}
