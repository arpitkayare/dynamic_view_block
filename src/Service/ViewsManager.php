<?php

namespace Drupal\dynamic_view_block\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\views\ViewExecutableFactory;
use Drupal\views\Entity\View;

/**
 * Service for managing Views operations in the Dynamic View Block module.
 */
class ViewsManager {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The view executable factory.
   *
   * @var \Drupal\views\ViewExecutableFactory
   */
  protected ViewExecutableFactory $viewExecutableFactory;

  /**
   * Constructs a new ViewsManager.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\views\ViewExecutableFactory $view_executable_factory
   *   The view executable factory.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    ViewExecutableFactory $view_executable_factory
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->viewExecutableFactory = $view_executable_factory;
  }

  /**
   * Gets all available views as options for form select.
   *
   * @return array
   *   An array of view names keyed by view ID.
   */
  public function getViewsOptions(): array {
    $options = [];

    try {
      $views_storage = $this->entityTypeManager->getStorage('view');
      $views = $views_storage->loadMultiple();

      /** @var \Drupal\views\Entity\View $view */
      foreach ($views as $view_id => $view) {
        $options[$view_id] = $view->label();
      }

      // Sort the views by label.
      asort($options);

      return $options;
    }
    catch (\Exception $e) {
      return [];
    }
  }

  /**
   * Gets all displays for a specific view as options for form select.
   *
   * @param string $view_id
   *   The view ID.
   *
   * @return array
   *   An array of display names keyed by display ID.
   */
  public function getDisplayOptions(string $view_id): array {
    $options = [];

    try {
      $view_storage = $this->getViewStorage($view_id);
      if (!$view_storage) {
        return [];
      }

      $view = $this->viewExecutableFactory->get($view_storage);
      if (!$view) {
        return [];
      }

      $view->initDisplay();

      foreach ($view->displayHandlers as $display_id => $display) {
        // Skip the default display and disabled displays.
        if ($display_id !== 'default' && !$display->isDisabled()) {
          $options[$display_id] = $display->display['display_title'];
        }
      }

      return $options;
    }
    catch (\Exception $e) {
      return [];
    }
  }

  /**
   * Gets only block displays for a specific view as options for form select.
   *
   * @param string $view_id
   *   The view ID.
   *
   * @return array
   *   An array of block display names keyed by display ID.
   */
  public function getBlockDisplayOptions(string $view_id): array {
    $options = [];

    try {
      $view_storage = $this->getViewStorage($view_id);
      if (!$view_storage) {
        return [];
      }

      $view = $this->viewExecutableFactory->get($view_storage);
      if (!$view) {
        return [];
      }

      $view->initDisplay();

      foreach ($view->displayHandlers as $display_id => $display) {
        // Skip the default display, disabled displays, and non-block displays.
        if ($display_id !== 'default' && !$display->isDisabled() && $display->getPluginId() === 'block') {
          $options[$display_id] = $display->display['display_title'];
        }
      }

      return $options;
    }
    catch (\Exception $e) {
      return [];
    }
  }

  /**
   * Gets a view storage entity by ID.
   *
   * @param string $view_id
   *   The view ID.
   *
   * @return \Drupal\views\Entity\View|null
   *   The view entity or NULL if not found.
   */
  public function getViewStorage(string $view_id): ?View {
    try {
      $views_storage = $this->entityTypeManager->getStorage('view');
      return $views_storage->load($view_id);
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

}
