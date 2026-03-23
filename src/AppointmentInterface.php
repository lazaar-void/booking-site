<?php

declare(strict_types=1);

namespace Drupal\appointment;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Provides an interface defining an appointment entity type.
 */
interface AppointmentInterface extends ContentEntityInterface, EntityOwnerInterface, EntityChangedInterface {

  /**
   * Gets the appointment date/time.
   */
  public function getAppointmentDate(): ?DrupalDateTime;

  /**
   * Sets the appointment date/time.
   */
  public function setAppointmentDate(DrupalDateTime $date): static;

  /**
   * Gets the appointment status (pending|confirmed|cancelled).
   */
  public function getAppointmentStatus(): string;

  /**
   * Sets the appointment status.
   */
  public function setAppointmentStatus(string $status): static;

  /**
   * Gets the customer's full name.
   */
  public function getCustomerName(): string;

  /**
   * Sets the customer's full name.
   */
  public function setCustomerName(string $name): static;

  /**
   * Gets the customer's email address.
   */
  public function getCustomerEmail(): string;

  /**
   * Sets the customer's email address.
   */
  public function setCustomerEmail(string $email): static;

  /**
   * Gets the customer's phone number.
   */
  public function getCustomerPhone(): string;

  /**
   * Sets the customer's phone number.
   */
  public function setCustomerPhone(string $phone): static;

  /**
   * Gets the internal notes.
   */
  public function getNotes(): ?string;

  /**
   * Sets the internal notes.
   */
  public function setNotes(string $notes): static;

}

