<?php

namespace Drupal\dynamic_view_block\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Utility\Token;
use Drupal\views\ViewExecutableFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\dynamic_view_block\Service\ViewsManager;
use Drupal\Core\Extension\ModuleHandlerInterface;

/**
 * Provides a block to display a view with dynamic display and arguments.
 *
 * @Block(
 *   id = "dynamic_view_block",
 *   admin_label = @Translation("Dynamic View Block"),
 *   category = @Translation("Views")
 * )
 */
class DynamicViewBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The token service.
   *
   * @var \Drupal\Core\Utility\Token
   */
  protected Token $token;

  /**
   * The view executable factory.
   *
   * @var \Drupal\views\ViewExecutableFactory
   */
  protected ViewExecutableFactory $viewExecutableFactory;

  /**
   * The views manager service.
   *
   * @var \Drupal\dynamic_view_block\Service\ViewsManager
   */
  protected ViewsManager $viewsManager;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * Constructs a new DynamicViewBlock.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Utility\Token $token
   *   The token service.
   * @param \Drupal\views\ViewExecutableFactory $view_executable_factory
   *   The view executable factory.
   * @param \Drupal\dynamic_view_block\Service\ViewsManager $views_manager
   *   The views manager service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    Token $token,
    ViewExecutableFactory $view_executable_factory,
    ViewsManager $views_manager,
  ModuleHandlerInterface $module_handler
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->token = $token;
    $this->viewExecutableFactory = $view_executable_factory;
    $this->viewsManager = $views_manager;
    $this->moduleHandler = $module_handler;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('token'),
      $container->get('views.executable'),
      $container->get('dynamic_view_block.views_manager'),
      $container->get('module_handler')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'view_id' => '',
      'display_id' => '',
      'arguments' => '',
      'override_title' => FALSE,
      'override_title_text' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array {
    $form = parent::blockForm($form, $form_state);
    $config = $this->getConfiguration();

    // Get all available views.
    $views_options = $this->viewsManager->getViewsOptions();

    // Get triggered element.
    $trigger = $form_state->getTriggeringElement();
    $selected_view = NULL;

    // Handle AJAX updates.
    if ($trigger && isset($trigger['#name']) && $trigger['#name'] == 'settings[view_id]') {
      $selected_view = $form_state->getValue(['settings', 'view_id']);
    }
    elseif (!empty($config['view_id'])) {
      $selected_view = $config['view_id'];
    }

    // View selection dropdown.
    $form['view_id'] = [
      '#type' => 'select',
      '#title' => $this->t('View'),
      '#description' => $this->t('Select the view to display.'),
      '#options' => $views_options,
      '#default_value' => $config['view_id'],
      '#required' => TRUE,
      '#empty_option' => $this->t('- Select a View -'),
      '#ajax' => [
        'callback' => [$this, 'updateDisplayOptions'],
        'wrapper' => 'display-id-wrapper',
        'event' => 'change',
      ],
      '#name' => 'settings[view_id]',
    ];

    // Display selection dropdown.
    $display_options = [];
    if ($selected_view) {
      $display_options = $this->viewsManager->getBlockDisplayOptions($selected_view);
    }

    $form['display_id_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'display-id-wrapper'],
    ];

    $form['display_id_wrapper']['display_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Display'),
      '#description' => $this->t('Select the block display to use.'),
      '#options' => $display_options,
      '#default_value' => $config['display_id'],
      '#required' => TRUE,
      '#empty_option' => $this->t('- Select a display -'),
      '#disabled' => empty($display_options),
    ];

    // Arguments with token support.
    $form['arguments'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Arguments'),
      '#description' => $this->t('Enter the arguments to pass to the view. Separate multiple arguments with a forward slash (/). You can use tokens like [current-user:uid].'),
      '#default_value' => $config['arguments'],
    ];

    // Add token browser if token module is enabled.
    if ($this->moduleHandler->moduleExists('token')) {
      $form['token_help'] = [
        '#type' => 'details',
        '#title' => $this->t('Available tokens'),
        '#collapsed' => TRUE,
      ];

      $form['token_help']['browser'] = [
        '#theme' => 'token_tree_link',
        '#token_types' => ['user', 'node', 'term', 'site'],
      ];
    }

    // Title override option.
    $form['override_title'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Override view title'),
      '#default_value' => $config['override_title'],
    ];

    $form['override_title_text'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Custom title'),
      '#default_value' => $config['override_title_text'],
      '#states' => [
        'visible' => [
          ':input[name="settings[override_title]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    return $form;
  }

  /**
   * AJAX callback to update the display options when the view is changed.
   */
  public function updateDisplayOptions(array $form, FormStateInterface $form_state): array {
    return $form['settings']['display_id_wrapper'];
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    $this->configuration['view_id'] = $form_state->getValue(['view_id']);
    $this->configuration['display_id'] = $form_state->getValue(['display_id']);
    $this->configuration['arguments'] = $form_state->getValue(['arguments']);
    $this->configuration['override_title'] = $form_state->getValue(['override_title']);
    $this->configuration['override_title_text'] = $form_state->getValue(['override_title_text']);
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $config = $this->getConfiguration();
    $view_id = $config['view_id'];
    $display_id = $config['display_id'];
    $arguments_string = $config['arguments'];

    // Return early with error message if view or display is not set.
    if (empty($view_id) || empty($display_id)) {
      return [
        '#markup' => $this->t('Dynamic View Block: No view or display selected.'),
      ];
    }

    // Get the view.
    $view_storage = $this->viewsManager->getViewStorage($view_id);
    if (!$view_storage) {
      return [
        '#markup' => $this->t('Dynamic View Block: View %view not found.', ['%view' => $view_id]),
      ];
    }

    $view = $this->viewExecutableFactory->get($view_storage);
    if (!$view) {
      return [
        '#markup' => $this->t('Dynamic View Block: Unable to create view executable for %view.', ['%view' => $view_id]),
      ];
    }

    // Check if the display exists.
    if (!$view->setDisplay($display_id)) {
      return [
        '#markup' => $this->t('Dynamic View Block: Display %display not found in view %view.', [
          '%display' => $display_id,
          '%view' => $view_id,
        ]),
      ];
    }

    // Process arguments with token replacement.
    $arguments = [];
    if (!empty($arguments_string)) {
      $processed_args = $this->token->replace($arguments_string, [], ['clear' => TRUE]);
      $arguments = explode('/', $processed_args);
    }

    // Override title if specified.
    if (!empty($config['override_title']) && !empty($config['override_title_text'])) {
      $view->setTitle($config['override_title_text']);
    }

    // Execute the view with the processed arguments.
    $view->preExecute($arguments);
    $view->execute();

    // Return the view render array.
    $render = $view->buildRenderable($display_id, $arguments);

    // Add the block as a cache dependency.
    $render['#cache']['contexts'] = Cache::mergeContexts(
      $render['#cache']['contexts'] ?? [],
      ['url']
    );

    return $render;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    // Add url as cache context since views might depend on URL parameters.
    return Cache::mergeContexts(parent::getCacheContexts(), ['url']);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags(): array {
    $config = $this->getConfiguration();
    $tags = parent::getCacheTags();

    // Add the view's cache tags if a view is selected.
    if (!empty($config['view_id'])) {
      $view_storage = $this->viewsManager->getViewStorage($config['view_id']);
      if ($view_storage) {
        $tags = Cache::mergeTags($tags, $view_storage->getCacheTags());
      }
    }

    return $tags;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge(): int {
    // Use the default block cache max age.
    return parent::getCacheMaxAge();
  }

}
