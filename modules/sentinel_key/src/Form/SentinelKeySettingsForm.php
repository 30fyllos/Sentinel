<?php

declare(strict_types=1);

namespace Drupal\sentinel_key\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeBundleInfo;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\sentinel_key\Enum\Timeframe;
use Drupal\sentinel_key\Service\SentinelKeyManagerInterface;
use Drupal\user\Entity\Role;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration form for a sentinel key entity type.
 */
final class SentinelKeySettingsForm extends ConfigFormBase {

  /**
   * The API key manager service.
   *
   * @var SentinelKeyManagerInterface
   */
  protected SentinelKeyManagerInterface $sentinelKeyManager;

  /**
   * Constructs a new SentinelKeyEntitiesSettingsForm.
   *
   * @param ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param SentinelKeyManagerInterface $sentinelKeyManager
   *   The API key manager service.
   */
  public function __construct(ConfigFactoryInterface $config_factory, TypedConfigManagerInterface $typedConfigManager, SentinelKeyManagerInterface $sentinelKeyManager) {
    parent::__construct($config_factory, $typedConfigManager);
    $this->sentinelKeyManager = $sentinelKeyManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('sentinel_key.manager')
    );
  }

  /**
  * {@inheritdoc}
  */
  protected function getEditableConfigNames() {
    // Specify the configuration object names that this form edits.
    return ['sentinel_key.settings'];
  }

  /**
  * {@inheritdoc}
  */
  public function getFormId(): string {
    return 'sentinel_key_settings';
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    if ($form_state->getValue('encryption_mode') === 'env') {
      if (!$this->sentinelKeyManager->getEnvValue()) {
        $form_state->setErrorByName('encryption_mode', $this->t('Environment variable mode is selected but the env value SENTINEL_ENCRYPTION_KEY was not found. Please create the .env file if not exist and define SENTINEL_ENCRYPTION_KEY, or choose configuration storage instead.'));
      }
    }
  }

  /**
  * Builds the API Sentinel settings form.
  *
  * @param array $form
  *   The form structure.
  * @param FormStateInterface $form_state
  *   The current state of the form.
  *
  * @return array
  *   The complete form structure.
  */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    // Load the configuration once.
    $config = $this->config('sentinel_key.settings');

    // Cache the Timeframe options so we don't call Timeframe::options() multiple times.
    $timeframe_options = Timeframe::options();

    $form['tabs'] = [
      '#type' => 'vertical_tabs',
      '#title' => $this->t('Sentinel Key Settings'),
      '#weight' => 0,
    ];

    $form['general'] = [
      '#type' => 'details',
      '#title' => $this->t('General settings'),
      '#group' => 'tabs',
    ];
    $form['general']['encryption_notice'] = [
      '#type' => 'markup',
      '#markup' => '<div class="messages messages--warning"><strong>' . $this->t('Security Advisory:') . '</strong> ' . $this->t('Please select your preferred encryption key storage method. <strong>Changing this setting after keys have been issued will invalidate all existing keys and require regeneration.</strong> We strongly recommend using the environment variable option (<code>SENTINEL_ENCRYPTION_KEY</code>) for enhanced security, isolation of secrets from codebase, and improved deployment practices.') . '</div>',
    ];
    $form['general']['encryption_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Encryption Key Storage Mode'),
      '#description' => $this->t('Choose how encryption keys should be stored. Using an environment variable such as <code>SENTINEL_ENCRYPTION_KEY</code> is strongly recommended for production environments.'),
      '#options' => [
        'config' => $this->t('Auto-generated store in Drupal Configuration'),
        'env' => $this->t('Use Environment Variable (SENTINEL_ENCRYPTION_KEY)'),
      ],
      '#default_value' => $config->get('encryption_mode') ?? 'config',
    ];

    $form['security'] = [
      '#type' => 'details',
      '#title' => $this->t('Security settings'),
      '#group' => 'tabs',
    ];
    $form['security']['whitelist_ips'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Whitelisted IP Addresses'),
      '#description' => $this->t('Enter allowed IPs (one per line). If set, only these IPs can use API keys.'),
      '#default_value' => implode("\n", (array) $config->get('whitelist_ips') ?? []),
    ];
    $form['security']['blacklist_ips'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Blacklisted IP Addresses'),
      '#description' => $this->t('Enter blocked IPs (one per line). Requests from these IPs will be rejected.'),
      '#default_value' => implode("\n", (array) $config->get('blacklist_ips') ?? []),
    ];
    // Custom HTTP header for API authentication.
    $form['security']['custom_auth_header'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Custom Authentication Header'),
      '#description' => $this->t('Enter a custom HTTP header for authentication. Default: X-API-KEY'),
      '#default_value' => $config->get('custom_auth_header') ?? 'X-API-KEY',
      '#required' => TRUE,
    ];
    $form['security']['allowed_paths'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Allowed API Paths'),
      '#description' => $this->t('Enter allowed API paths (one per line). Use wildcards (*) for dynamic segments, e.g., /api/*'),
      '#default_value' => implode("\n", (array) $config->get('allowed_paths') ?? []),
    ];

    $form['rate_limit'] = [
      '#type' => 'details',
      '#title' => $this->t('Rate limiting'),
      '#group' => 'tabs',
    ];
    // Maximum failed authentication attempts.
    $form['rate_limit']['failure_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Max Failed Attempts Before Block'),
      '#default_value' => $config->get('failure_limit', 100),
      '#description' => $this->t('If an API key fails authentication this many times, it will be blocked. Set to 0 to disable.'),
      '#min' => 0,
    ];
    // Timeframe over which failures are counted.
    $form['rate_limit']['failure_limit_time'] = [
      '#type' => 'select',
      '#title' => $this->t('Failure Limit Time Per'),
      '#options' => $timeframe_options,
      '#default_value' => $config->get('failure_limit_time', Timeframe::ONE_HOUR->value),
      '#states' => [
        // Show only if failure_limit is not 0.
        'visible' => [
          ':input[name="failure_limit"]' => ['!value' => '0'],
        ],
      ],
    ];
    // Maximum allowed API requests.
    $form['rate_limit']['max_rate_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Max Requests Allowed'),
      '#default_value' => $config->get('max_rate_limit', 100),
      '#description' => $this->t('The maximum number of API requests allowed within the selected period. Set to 0 to disable.'),
      '#min' => 0,
    ];
    // Timeframe for rate limiting.
    $form['rate_limit']['max_rate_limit_time'] = [
      '#type' => 'select',
      '#title' => $this->t('Rate Limit Time Period'),
      '#options' => $timeframe_options,
      '#default_value' => $config->get('max_rate_limit_time', Timeframe::ONE_HOUR->value),
      '#states' => [
        // Show only if max_rate_limit is not 0.
        'visible' => [
          ':input[name="max_rate_limit"]' => ['!value' => '0'],
        ],
      ],
    ];

    $form['auto_generate_tab'] = [
      '#type' => 'details',
      '#title' => $this->t('Auto-generate settings'),
      '#group' => 'tabs',
    ];
    $form['auto_generate_tab']['auto_generate_settings'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'auto-generate-settings-wrapper'],
    ];

    // Checkbox to enable auto-generation.
    $form['auto_generate_tab']['auto_generate_settings']['auto_generate'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Auto-Generate API Keys on New Registration'),
      '#default_value' => $config->get('auto_generate_enabled') ?: 0,
      '#ajax' => [
        'callback' => '::ajaxSaveCallback',
        'wrapper'  => 'auto-generate-settings-wrapper',
        'event'    => 'change',
      ],
      '#description' => $this->t('When enabled, new users with the selected roles will automatically receive an API key.'),
    ];

    // Determine whether the auto_generate checkbox is checked.
    // We check the current form state value (if set) or fall back to the stored config.
    $auto_generate = $form_state->getValue('auto_generate', $config->get('auto_generate_enabled'));

    // If auto-generation is enabled, display additional settings.
    if ($auto_generate) {
      // Load all roles (except anonymous).
      $roles = Role::loadMultiple();
      $role_options = [];
      foreach ($roles as $role) {
        if ($role->id() == 'anonymous') {
          continue;
        }
        $role_options[$role->id()] = $role->label();
      }

      // Checkboxes for selecting roles.
      $form['auto_generate_tab']['auto_generate_settings']['auto_generate_roles'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Select Roles for Auto-Generation'),
        '#options' => $role_options,
        '#default_value' => $config->get('auto_generate_roles') ?: [],
        '#ajax' => [
          'callback' => '::ajaxSaveCallback',
          'wrapper'  => 'auto-generate-settings-wrapper',
          'event'    => 'click',
        ],
        '#description' => $this->t('Users registering with these roles will automatically receive an API key.'),
      ];

      $form['auto_generate_tab']['auto_generate_settings']['duration_wrapper'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Default Expiration Date'),
        '#description' => $this->t('Optional expiration date for auto-generated API keys. Leave blank for no expiration.'),
        '#attributes' => ['class' => ['duration-container']],
      ];

      $form['auto_generate_tab']['auto_generate_settings']['duration_wrapper']['auto_generate_duration'] = [
        '#type' => 'number',
        '#title' => $this->t('Duration'),
        '#min' => 0,
        '#max' => 100,
        '#default_value' => $config->get('auto_generate_duration') ?: 0,
        '#ajax' => [
          'callback' => '::ajaxSaveCallback',
          'wrapper'  => 'auto-generate-settings-wrapper',
          'event'    => 'change',
        ],
      ];

      $form['auto_generate_tab']['auto_generate_settings']['duration_wrapper']['auto_generate_duration_unit'] = [
        '#type' => 'select',
        '#title' => $this->t('Unit'),
        '#options' => [
          'days' => $this->t('Day(s)'),
          'months' => $this->t('Month(s)'),
          'years' => $this->t('Year(s)'),
        ],
        '#default_value' => $config->get('auto_generate_duration_unit') ?: 'years',
        '#ajax' => [
          'callback' => '::ajaxSaveCallback',
          'wrapper'  => 'auto-generate-settings-wrapper',
          'event'    => 'change',
        ],
        '#states' => [
          // Show only if failure_limit is not 0.
          'visible' => [
            ':input[name="auto_generate_duration"]' => ['!value' => '0'],
          ],
        ],
      ];

    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * AJAX callback to save configuration on value change.
   *
   * This callback is triggered when any of the auto-generation fields change.
   * It saves the configuration and returns the updated container.
   *
   * @param array $form
   *   The full form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   *
   * @return array
   *   The updated auto-generation settings container.
   */
  public function ajaxSaveCallback(array &$form, FormStateInterface $form_state): array
  {
    // Save the configuration using our submitForm() method.
    $this->submitForm($form, $form_state);
    // Return the container that holds our auto-generation settings.
    return $form['auto_generate_tab']['auto_generate_settings'];
  }

  /**
  * Handles form submission.
  *
  * Saves the updated configuration settings and forces key regeneration if
  * the encryption settings have changed.
  *
  * @param array $form
  *   The complete form structure.
  * @param FormStateInterface $form_state
  *   The current state of the form.
  */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $config = $this->configFactory()->getEditable('sentinel_key.settings');
    $previous_mode = $config->get('encryption_mode');
    $new_mode = $form_state->getValue('encryption_mode');

    // Save configuration settings.
    $config
      ->set('encryption_mode', $form_state->getValue('encryption_mode'))
      ->set('whitelist_ips', array_filter(explode("\n", trim($form_state->getValue('whitelist_ips')))))
      ->set('blacklist_ips', array_filter(explode("\n", trim($form_state->getValue('blacklist_ips')))))
      ->set('custom_auth_header', trim($form_state->getValue('custom_auth_header')))
      ->set('allowed_paths', array_filter(explode("\n", trim($form_state->getValue('allowed_paths')))))
      ->set('failure_limit', $form_state->getValue('failure_limit'))
      ->set('failure_limit_time', $form_state->getValue('failure_limit_time'))
      ->set('max_rate_limit', $form_state->getValue('max_rate_limit'))
      ->set('max_rate_limit_time', $form_state->getValue('max_rate_limit_time'));

    // Save the auto-generation enabled flag.
    $config->set('auto_generate_enabled', $form_state->getValue('auto_generate'));
    // Save roles and expiration only if auto-generation is enabled.
    if ($form_state->getValue('auto_generate')) {
      $config->set('auto_generate_roles', $form_state->getValue('auto_generate_roles'));
      $config->set('auto_generate_duration', $form_state->getValue('auto_generate_duration'));
      $config->set('auto_generate_duration_unit', $form_state->getValue('auto_generate_duration_unit'));
    }
    else {
      // Clear the settings if auto-generation is disabled.
      $config->set('auto_generate_roles', []);
      $config->set('auto_generate_duration', 0);
      $config->set('auto_generate_duration_unit', 'years');
    }

    $config->save();

    // Regenerate keys if encryption mode has changed.
    if ($previous_mode && $previous_mode !== $new_mode) {
      $this->sentinelKeyManager->forceRegenerateAllKeys();
      $this->messenger()->addWarning($this->t('Encryption mode has changed. All API keys have been regenerated.'));
    }

    parent::submitForm($form, $form_state);
  }

}
