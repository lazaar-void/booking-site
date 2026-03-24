<?php

declare(strict_types=1);

namespace Drupal\appointment;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Provides an interface defining an agency entity type.
 */
interface AgencyInterface extends ContentEntityInterface, EntityOwnerInterface, EntityChangedInterface {

  /**
   * Gets the agency's physical address.
   */
  public function getAddress(): string;

  /**
   * Sets the agency's physical address.
   */
  public function setAddress(string $address): static;

  /**
   * Gets the agency's phone number.
   */
  public function getPhone(): string;

  /**
   * Sets the agency's phone number.
   */
  public function setPhone(string $phone): static;

  /**
   * Gets the agency's email address.
   */
  public function getEmail(): string;

  /**
   * Sets the agency's email address.
   */
  public function setEmail(string $email): static;

  /**
   * Gets the agency's operating hours description.
   */
  public function getOperatingHours(): ?string;

  /**
   * Sets the agency's operating hours description.
   */
  public function setOperatingHours(string $hours): static;

}
