<?php

declare(strict_types=1);

namespace Drupal\appointment;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Defines the access control handler for the agency entity type.
 *
 * phpcs:disable Drupal.Arrays.Array.LongLineDeclaration
 *
 * @see https://www.drupal.org/project/coder/issues/3185082
 */
final class AgencyAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResult {
    if ($account->hasPermission($this->entityType->getAdminPermission())) {
      return AccessResult::allowed()->cachePerPermissions();
    }

    return match($operation) {
      'view' => AccessResult::allowedIfHasPermission($account, 'view appointment_agency'),
      'update' => AccessResult::allowedIfHasPermission($account, 'edit appointment_agency'),
      'delete' => AccessResult::allowedIfHasPermission($account, 'delete appointment_agency'),
      'delete revision' => AccessResult::allowedIfHasPermission($account, 'delete appointment_agency revision'),
      'view all revisions', 'view revision' => AccessResult::allowedIfHasPermissions($account, ['view appointment_agency revision', 'view appointment_agency']),
      'revert' => AccessResult::allowedIfHasPermissions($account, ['revert appointment_agency revision', 'edit appointment_agency']),
      default => AccessResult::neutral(),
    };
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResult {
    return AccessResult::allowedIfHasPermissions($account, ['create appointment_agency', 'administer appointment_agency'], 'OR');
  }

}
