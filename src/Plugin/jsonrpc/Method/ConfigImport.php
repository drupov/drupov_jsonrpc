<?php

namespace Drupal\drupov_jsonrpc\Plugin\jsonrpc\Method;

use Drupal\Core\Config\ConfigImporterException;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\StorageComparer;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\jsonrpc\MethodInterface;
use Drupal\jsonrpc\Object\ParameterBag;
use Drupal\jsonrpc\Plugin\JsonRpcMethodBase;
use Drupal\Core\Config\ConfigImporter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Import config.
 *
 * @JsonRpcMethod(
 *   id = "config.import",
 *   usage = @Translation("Config import."),
 *   access = {"administer site configuration"},
 * ),
 */
class ConfigImport extends JsonRpcMethodBase {

  /**
   * Message to return.
   *
   * @var string
   */
  protected $message = '';

  /**
   * The database lock object.
   *
   * @var \Drupal\Core\Lock\LockBackendInterface
   */
  protected $lock;

  /**
   * The sync configuration object.
   *
   * @var \Drupal\Core\Config\StorageInterface
   */
  protected $syncStorage;

  /**
   * The active configuration object.
   *
   * @var \Drupal\Core\Config\StorageInterface
   */
  protected $activeStorage;

  /**
   * The snapshot configuration object.
   *_
   *
   * @var \Drupal\Core\Config\StorageInterface
   */
  protected $snapshotStorage;

  /**
   * Event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * The configuration manager.
   *
   * @var \Drupal\Core\Config\ConfigManagerInterface
   */
  protected $configManager;

  /**
   * The typed config manager.
   *
   * @var \Drupal\Core\Config\TypedConfigManagerInterface
   */
  protected $typedConfigManager;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The theme handler.
   *
   * @var \Drupal\Core\Extension\ThemeHandlerInterface
   */
  protected $themeHandler;

  /**
   * The module installer.
   *
   * @var \Drupal\Core\Extension\ModuleInstallerInterface
   */
  protected $moduleInstaller;

  /**
   * The renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, string $plugin_id, MethodInterface $plugin_definition, StorageInterface $sync_storage, StorageInterface $active_storage, StorageInterface $snapshot_storage, LockBackendInterface $lock, EventDispatcherInterface $event_dispatcher, ConfigManagerInterface $config_manager, TypedConfigManagerInterface $typed_config, ModuleHandlerInterface $module_handler, ModuleInstallerInterface $module_installer, ThemeHandlerInterface $theme_handler, RendererInterface $renderer) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->syncStorage = $sync_storage;
    $this->activeStorage = $active_storage;
    $this->snapshotStorage = $snapshot_storage;
    $this->lock = $lock;
    $this->eventDispatcher = $event_dispatcher;
    $this->configManager = $config_manager;
    $this->typedConfigManager = $typed_config;
    $this->moduleHandler = $module_handler;
    $this->moduleInstaller = $module_installer;
    $this->themeHandler = $theme_handler;
    $this->renderer = $renderer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.storage.sync'),
      $container->get('config.storage'),
      $container->get('config.storage.snapshot'),
      $container->get('lock.persistent'),
      $container->get('event_dispatcher'),
      $container->get('config.manager'),
      $container->get('config.typed'),
      $container->get('module_handler'),
      $container->get('module_installer'),
      $container->get('theme_handler'),
      $container->get('renderer')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function execute(ParameterBag $params) {
    $storage_comparer = new StorageComparer($this->syncStorage, $this->activeStorage);

    $config_importer = new ConfigImporter(
      $storage_comparer,
      $this->eventDispatcher,
      $this->configManager,
      $this->lock,
      $this->typedConfigManager,
      $this->moduleHandler,
      $this->moduleInstaller,
      $this->themeHandler,
      $this->getStringTranslation()
    );
    if ($config_importer->alreadyImporting()) {
      $this->message .= $this->t('Another request may be synchronizing configuration already.');
    }
    else {
      try {
        $sync_steps = $config_importer->initialize();
        foreach ($sync_steps as $sync_step) {
          $config_importer->doSyncStep($sync_step, $context);
        }
        $this->message = $this->t( 'The configuration was imported successfully');
      } catch (ConfigImporterException $e) {
        $this->message .= $this->t('The configuration cannot be imported because it failed validation for the following reasons: ');

        foreach ($config_importer->getErrors() as $message) {
          $this->message .= $message;
        }
      }

    }

    return $this->message;
  }

  /**
   * {@inheritdoc}
   */
  public static function outputSchema() {
    return ['type' => 'string'];
  }

}
