<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\DependencyInjection;

use InvalidArgumentException;
use Nowo\ChatKitBundle\Contract\ChatReferenceProviderInterface;
use Nowo\ChatKitBundle\Contract\ChatSidebarSectionProviderInterface;
use Nowo\ChatKitBundle\DependencyInjection\Compiler\PrivateEntityManagerPass;
use Nowo\ChatKitBundle\DependencyInjection\Compiler\PrivateEntityManagerReferencesPass;
use Nowo\ChatKitBundle\Doctrine\ChatKitEntityManager;
use Nowo\ChatKitBundle\Doctrine\ChatKitManagerRegistry;
use Nowo\ChatKitBundle\Doctrine\ChatUserReferences;
use Nowo\ChatKitBundle\Doctrine\PrivateEntityManagerMiddleware;
use Nowo\ChatKitBundle\Doctrine\StringReferenceSchemaGuard;
use Nowo\ChatKitBundle\Doctrine\TablePrefixListener;
use Nowo\ChatKitBundle\Doctrine\UserReferenceListener;
use Nowo\ChatKitBundle\Model\ChatUserInterface;
use Nowo\ChatKitBundle\Model\ChatUserReferenceInterface;
use Nowo\ChatKitBundle\NowoChatKitBundle;
use Nowo\ChatKitBundle\RuntimeSetting\RuntimeSettingRetentionPolicyProvider;
use Nowo\ChatKitBundle\Search\ChatSearchSchemaListener;
use Nowo\ChatKitBundle\SearchKit\ChatConversationSearchDefinition;
use Nowo\ChatKitBundle\SearchKit\ChatConversationSearchProvider;
use Nowo\ChatKitBundle\SearchKit\ChatMessageSearchDefinition;
use Nowo\ChatKitBundle\SearchKit\ChatMessageSearchProvider;
use Nowo\ChatKitBundle\SearchKit\ChatSearchKitViewer;
use Nowo\ChatKitBundle\Support\ChatEmojiCatalog;
use Nowo\ChatKitBundle\Support\ChatRetention;
use Nowo\ChatKitBundle\Transaction\ChatKitTransactionRunner;
use Nowo\ChatKitBundle\Worker\ChatRequestBoundaryListener;
use Nowo\RuntimeSettingContract\Bridge\Symfony\DependencyInjection\RuntimeSettingsConfiguration;
use Nowo\RuntimeSettingContract\Contract\Exception\InvalidRuntimeSettingId;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingDefinition;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingId;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingResolver;
use Nowo\RuntimeSettingContract\Contract\RuntimeSettingType;
use Nowo\RuntimeSettingContract\Contract\Validator\IntRange;
use Nowo\SearchKitBundle\Contract\SearchProviderInterface;
use Nowo\SearchKitBundle\Contract\SearchReferenceResolverInterface;
use Nowo\SearchKitBundle\Provider\Entity\EntitySearchDefinition;
use Nowo\SearchKitBundle\Provider\Entity\SearchItemContext;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;

use function array_key_exists;
use function class_exists;
use function in_array;
use function interface_exists;
use function is_a;
use function is_array;
use function is_string;
use function sprintf;

/**
 * Loads `nowo_chat_kit` configuration and registers the bundle asset package.
 *
 * Container parameters:
 * - `nowo_chat_kit.default_profile` (string)
 * - `nowo_chat_kit.profiles` (array<string, array<string, mixed>>)
 * - `nowo_chat_kit.{user_class,retention_days,realtime,push,push_url,push_icon,push_preview,cache_pool,reactions,presence_ttl,auto_away_minutes,status_presets,limits,dock,links}`
 *   and `nowo_chat_kit.links.{enabled,max_pinned,allow_http,channel_managers,providers}` (1.19)
 *   (default profile)
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class NowoChatKitExtension extends Extension implements PrependExtensionInterface
{
    /**
     * Public path created by `assets:install` for this bundle (REQ-ASSETS-004).
     */
    public const ASSET_BASE_PATH = '/bundles/nowochatkit';

    /** 1.32: parameter holding the `runtime.retention_days` setting id of the default profile (null: not configured). */
    public const RUNTIME_RETENTION_PARAMETER = 'nowo_chat_kit.runtime.retention_days';

    /** 1.32: the retention RuntimeSettingDefinition (tagged for the contract's registry). */
    public const RUNTIME_RETENTION_DEFINITION = 'nowo_chat_kit.runtime_setting.retention_days';

    /** 1.32: the bounded validator of the retention runtime setting (30..36500 days, refuse, never clamp). */
    public const RUNTIME_RETENTION_VALIDATOR = 'nowo_chat_kit.runtime_setting.retention_days.validator';

    /**
     * Registers the Doctrine mapping, maps ChatUserInterface to the configured user class and registers the
     * `nowo_chat_kit` asset package.
     *
     * @param ContainerBuilder $container The container being built
     */
    public function prepend(ContainerBuilder $container): void
    {
        /** @var array{default_profile: string, profiles: array<string, array{user_class: ?string, limits: array<string, int>, doctrine: array{user_reference: string}}>} $config */
        $config  = (new Processor())->processConfiguration(new Configuration(), $this->prependableConfigs($container));
        $profile = $config['profiles'][$config['default_profile']];
        $this->prependDoctrine($container, $config['default_profile'], $profile['user_class'], $profile['doctrine']['user_reference']);
        $this->prependFormKitProfile($container);

        if (!$container->hasExtension('framework')) {
            return;
        }

        $limits  = $profile['limits'];
        $limiter = static fn (int $limit, string $interval): array => ['policy' => 'sliding_window', 'limit' => $limit, 'interval' => $interval];
        $container->prependExtensionConfig('framework', [
            'rate_limiter' => [
                'nowo_chat_send'        => $limiter($limits['send_per_minute'], '1 minute'),
                'nowo_chat_action'      => $limiter($limits['actions_per_minute'], '1 minute'),
                'nowo_chat_typing'      => $limiter($limits['typing_per_minute'], '1 minute'),
                'nowo_chat_read'        => $limiter($limits['reads_per_minute'], '1 minute'),
                'nowo_chat_reference'   => $limiter($limits['references_per_minute'], '1 minute'),
                'nowo_chat_buzz'        => $limiter(1, $limits['buzz_interval_seconds'] . ' seconds'),
                'nowo_chat_buzz_global' => $limiter($limits['buzz_per_minute'], '1 minute'),
                'nowo_chat_mention_all' => $limiter($limits['mention_all_per_hour'], '1 hour'),
                // 1.37: message searches (page, API, palette).
                'nowo_chat_search' => $limiter($limits['searches_per_minute'], '1 minute'),
            ],
        ]);

        $container->prependExtensionConfig('framework', [
            'assets' => [
                'packages' => [
                    Configuration::ALIAS => [
                        'base_path' => self::ASSET_BASE_PATH,
                    ],
                ],
            ],
        ]);
    }

    /**
     * Default FormKit profile of the bundle forms: translation domain, no auto help / placeholder convention keys.
     * REQ-UI-003: no framework classes here — the UiKit form theme paints inputs through its theme slots; the dock
     * classes a type sets itself (`nowo-chat-*`, `data-part`) stay on the types.
     *
     * @return array<string, mixed>
     */
    public static function formKitProfile(): array
    {
        return [
            'alias'              => NowoChatKitBundle::FORM_KIT_PROFILE,
            'translation_domain' => NowoChatKitBundle::TRANSLATION_DOMAIN,
            'auto_help'          => false,
            'auto_placeholder'   => false,
            'defaults'           => [
                'attr'     => [],
                'row_attr' => [],
            ],
            'field_types' => [],
        ];
    }

    /**
     * Registers the FormKit profile {@see NowoChatKitBundle::FORM_KIT_PROFILE} used by every bundle form type
     * (#[FormKitConfig]). A host overrides it by defining `nowo_form_kit.profiles.chat_kit` itself (nothing is
     * prepended then). Does not change the host `default_profile`.
     */
    private function prependFormKitProfile(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('nowo_form_kit')) {
            return;
        }

        foreach ($container->getExtensionConfig('nowo_form_kit') as $config) {
            $profiles = $config['profiles'] ?? null;
            if (is_array($profiles) && array_key_exists(NowoChatKitBundle::FORM_KIT_PROFILE, $profiles)) {
                return;
            }
        }

        $container->prependExtensionConfig('nowo_form_kit', [
            'profiles' => [NowoChatKitBundle::FORM_KIT_PROFILE => self::formKitProfile()],
        ]);
    }

    /**
     * Processes the configuration and loads `Resources/config/services.php`.
     *
     * @param array<array<string, mixed>> $configs Raw `nowo_chat_kit` configs
     * @param ContainerBuilder $container The container being built
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        // No configuration at all still yields one defaulted profile (normalization only runs on given input).
        $config = $this->processConfiguration(new Configuration(), $configs === [] ? [[]] : $configs);

        $container->setParameter(Configuration::ALIAS . '.default_profile', $config['default_profile']);
        $container->setParameter(Configuration::ALIAS . '.profiles', $config['profiles']);

        $profile          = $config['profiles'][$config['default_profile']];
        $profile['icons'] = array_replace(Configuration::DEFAULT_ICONS, $profile['icons']);
        // 1.19: one dock icon per link provider (`provider_<key>`); an explicit `icons` entry wins.
        foreach ($profile['links']['providers'] as $key => $provider) {
            if ($provider['icon'] !== null) {
                $profile['icons']['provider_' . $key] ??= $provider['icon'];
            }
        }
        foreach (['user_class', 'retention_days', 'realtime', 'push', 'push_url', 'push_icon', 'push_preview', 'cache_pool', 'page_layout', 'web_ui', 'reactions', 'icon_set', 'icons', 'presence_ttl', 'auto_away_minutes', 'status_presets', 'max_pins', 'auto_reply_interval_hours', 'limits', 'digest', 'doctrine', 'support', 'dock', 'links'] as $key) {
            $container->setParameter(Configuration::ALIAS . '.' . $key, $profile[$key]);
        }
        // 1.35: the message menu; the quick reactions must be ones the server accepts.
        $accepted = ChatEmojiCatalog::accepted($profile['reactions'], $profile['message_menu']['quick_reactions']['picker']);
        foreach ($profile['message_menu']['quick_reactions']['emojis'] as $emoji) {
            if (!in_array($emoji, $accepted, true)) {
                throw new InvalidConfigurationException(sprintf('nowo_chat_kit: message_menu.quick_reactions.emojis has "%s", which is neither in "reactions" nor (with quick_reactions.picker) in the picker catalog.', $emoji));
            }
        }
        $container->setParameter(Configuration::ALIAS . '.message_menu', $profile['message_menu']);
        foreach ($profile['message_menu'] as $key => $value) {
            $container->setParameter(Configuration::ALIAS . '.message_menu.' . $key, $value);
        }
        $container->setParameter(Configuration::ALIAS . '.accepted_reactions', $accepted);
        // 1.37: message search and its optional SearchKit provider.
        foreach ($profile['search'] as $key => $value) {
            $container->setParameter(Configuration::ALIAS . '.search.' . $key, $value);
        }
        $container->setParameter(Configuration::ALIAS . '.search_kit.enabled', $profile['search_kit']['enabled']);
        $container->setParameter(Configuration::ALIAS . '.search.dock_link', $profile['search']['enabled'] === true && $profile['search']['dock_entry'] === true);

        // #references: every application service implementing the contract is a provider.
        $container->registerForAutoconfiguration(ChatReferenceProviderInterface::class)->addTag(ChatReferenceProviderInterface::TAG);
        // 1.25: sidebar sections, likewise (priority through #[AsTaggedItem] or the tag's `priority`).
        $container->registerForAutoconfiguration(ChatSidebarSectionProviderInterface::class)->addTag(ChatSidebarSectionProviderInterface::TAG);

        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.php');
        foreach ($profile['digest'] as $key => $value) {
            $container->setParameter(Configuration::ALIAS . '.digest.' . $key, $value);
        }
        $container->setParameter(Configuration::ALIAS . '.doctrine.table_prefix', $profile['doctrine']['table_prefix']);
        $container->setParameter(Configuration::ALIAS . '.doctrine.table_name_prefix', $profile['doctrine']['table_name_prefix']);
        // 1.24: users referenced by id — the listener exists only then (the default mode costs nothing).
        $container->setParameter(Configuration::ALIAS . '.doctrine.user_reference', $profile['doctrine']['user_reference']);
        $userReference = $profile['doctrine']['user_reference'];
        if ($userReference === ChatUserReferences::RELATION) {
            $container->removeDefinition(UserReferenceListener::class);
        }
        // 1.27 (audit OJ-L1): string references only in `string` mode, and integer users never in it.
        $userClass = $profile['user_class'];
        if (is_string($userClass) && class_exists($userClass) && is_a($userClass, ChatUserReferenceInterface::class, true) !== ($userReference === ChatUserReferences::STRING)) {
            $message = $userReference === ChatUserReferences::STRING
                ? 'nowo_chat_kit: doctrine.user_reference is "string" but %s does not implement %s.'
                : 'nowo_chat_kit: %s implements %s, which needs "doctrine.user_reference: string".';

            throw new InvalidConfigurationException(sprintf($message, $userClass, ChatUserReferenceInterface::class));
        }
        if ($userReference !== ChatUserReferences::STRING) {
            $container->removeDefinition(StringReferenceSchemaGuard::class);
        }
        $this->registerSearch($container, $profile['search_kit']);
        $this->registerRuntimeRetention($container, $profile['runtime']['retention_days'] ?? null, $profile['retention_days']);
        $this->registerTableNames($container, $configs, $config['default_profile'], $profile['doctrine']);
        $this->registerPrivateEntityManager($container, $profile['doctrine']['private_entity_manager'], $profile['doctrine']['entity_manager']);
        foreach ($profile['links'] as $key => $value) {
            $container->setParameter(Configuration::ALIAS . '.links.' . $key, $value);
        }
        $container->setParameter(Configuration::ALIAS . '.links.channel_members', $profile['links']['channel_managers'] === 'members');
        foreach ($profile['support'] as $key => $value) {
            $container->setParameter(Configuration::ALIAS . '.support.' . $key, $value);
        }
        $container->setAlias('nowo_chat_kit.cache', $profile['cache_pool']);
        // REQ-UI-003: the 1.x default layout means "no host layout" (the UiKit layout applies).
        $legacyLayout = $profile['page_layout'] === Configuration::LEGACY_PAGE_LAYOUT ? null : $profile['page_layout'];
        $container->setParameter(Configuration::ALIAS . '.page_layout', $legacyLayout);
        $container->setParameter(Configuration::ALIAS . '.web_ui.layout_template', $profile['web_ui']['layout_template']);
        $container->setParameter(Configuration::ALIAS . '.web_ui.templates.page', $profile['web_ui']['templates']['page']);
        $container->setParameter(Configuration::ALIAS . '.web_ui.assets', $profile['web_ui']['assets']);
        $container->setParameter(Configuration::ALIAS . '.web_ui.channels_url', $profile['web_ui']['channels_url']);
        $container->setParameter(Configuration::ALIAS . '.web_ui.page.full_height', $profile['web_ui']['page']['full_height']);
        // 1.25: the sidebar.
        $container->setParameter(Configuration::ALIAS . '.web_ui.page.open_recent', $profile['web_ui']['page']['open_recent']);
        $container->setParameter(Configuration::ALIAS . '.web_ui.dock.sidebar_sections', $profile['web_ui']['dock']['sidebar_sections']);
        $container->setParameter(Configuration::ALIAS . '.web_ui.dock.dialog_runtime', $profile['web_ui']['dock']['dialog_runtime']);
        // 1.33: the message context menu (null = UiKit's theme flag).
        $container->setParameter(Configuration::ALIAS . '.web_ui.message_context_menu', $profile['web_ui']['message_context_menu']);
    }

    /**
     * 1.37: {@see ChatSearchSchemaListener} maps the MySQL / MariaDB FULLTEXT index (with search on, or while the index
     * exists: audit VA-4); with `search_kit.enabled` and nowo-tech/search-kit-bundle ^1.10 installed,
     * {@see ChatMessageSearchProvider} (`search_kit.messages`) and 1.38 {@see ChatConversationSearchProvider}
     * (`search_kit.channels`) are registered as SearchKit providers and recent resolvers (and only kept while SearchKit
     * revalidates recents or has them off: audit VA-3, {@see SearchKitRecentsPass}).
     *
     * @param array{enabled: bool, priority: int, messages: array{enabled: bool, max_candidates: int}, channels: array{enabled: bool, priority: int, max_candidates: int, direct_scan: int}} $searchKit The processed `search_kit` options
     */
    private function registerSearch(ContainerBuilder $container, array $searchKit): void
    {
        // Audit VA-4: always registered (it keeps an existing index even with search off); the options are read at run time.
        $container->register(ChatSearchSchemaListener::class, ChatSearchSchemaListener::class)
            ->setArguments([
                '%' . Configuration::ALIAS . '.doctrine.table_prefix%',
                '%' . Configuration::ALIAS . '.doctrine.table_name_prefix%',
                '%' . Configuration::ALIAS . '.search.enabled%',
                '%' . Configuration::ALIAS . '.search.fulltext%',
            ])
            ->addTag('doctrine.event_listener', ['event' => 'postGenerateSchema', 'method' => 'postGenerateSchema']);
        if ($searchKit['enabled'] !== true || !interface_exists(SearchProviderInterface::class) || !interface_exists(SearchReferenceResolverInterface::class)
            || !class_exists(EntitySearchDefinition::class) || !class_exists(SearchItemContext::class)) {
            return;
        }
        // 1.38: both groups on SearchKit's provider base; ChatKit registers the providers itself (no EntitySearchPass tag)
        // so that SearchKitRecentsPass can remove them while recents keep titles in the browser.
        $container->register(ChatSearchKitViewer::class, ChatSearchKitViewer::class)->setAutowired(true);
        if ($searchKit['messages']['enabled'] === true) {
            $container->register(ChatMessageSearchDefinition::class, ChatMessageSearchDefinition::class)
                ->setAutowired(true)
                ->setArgument('$maxCandidates', $searchKit['messages']['max_candidates']);
            $container->register(ChatMessageSearchProvider::class, ChatMessageSearchProvider::class)
                ->setAutowired(true)
                ->addTag(SearchProviderInterface::TAG, ['priority' => $searchKit['priority']])
                ->addTag(SearchReferenceResolverInterface::REFERENCE_RESOLVER_TAG, ['priority' => $searchKit['priority']]);
        }
        if ($searchKit['channels']['enabled'] === true) {
            $container->register(ChatConversationSearchDefinition::class, ChatConversationSearchDefinition::class)
                ->setAutowired(true)
                ->setArgument('$maxCandidates', $searchKit['channels']['max_candidates'])
                ->setArgument('$directScan', $searchKit['channels']['direct_scan']);
            $container->register(ChatConversationSearchProvider::class, ChatConversationSearchProvider::class)
                ->setAutowired(true)
                ->addTag(SearchProviderInterface::TAG, ['priority' => $searchKit['channels']['priority']])
                ->addTag(SearchReferenceResolverInterface::REFERENCE_RESOLVER_TAG, ['priority' => $searchKit['channels']['priority']]);
        }
    }

    /**
     * 1.32: `runtime.retention_days` registers the retention as a runtime setting of nowo-tech/runtime-setting-contract
     * (an optional dependency): a {@see RuntimeSettingDefinition} with a bounded validator, tagged for the contract's
     * registry (so `nowo:runtime-setting:*` can change it), and {@see RuntimeSettingRetentionPolicyProvider} behind the
     * retention port ({@see Compiler\RuntimeRetentionPass} aliases it once the host's services are known). Without the
     * node nothing is registered and nothing changes.
     *
     * @param array{source: string, key: string, on_error: string}|null $runtime The processed `runtime.retention_days`
     * @param mixed $configured The configured `retention_days` (an int or an `%env()%` placeholder): the env fallback
     *
     * @throws InvalidConfigurationException When the contract is not installed or the key is not a valid setting id
     */
    private function registerRuntimeRetention(ContainerBuilder $container, ?array $runtime, mixed $configured): void
    {
        $container->setParameter(self::RUNTIME_RETENTION_PARAMETER, $runtime['key'] ?? null);
        if ($runtime === null) {
            return;
        }
        if (!class_exists(RuntimeSettingDefinition::class) || !class_exists(RuntimeSettingsConfiguration::class)) {
            throw new InvalidConfigurationException('nowo_chat_kit: runtime.retention_days needs nowo-tech/runtime-setting-contract ^1.0 (composer require nowo-tech/runtime-setting-contract), or remove the node to keep the configured retention_days.');
        }

        try {
            RuntimeSettingId::fromString($runtime['key']);
        } catch (InvalidRuntimeSettingId) {
            throw new InvalidConfigurationException(sprintf('nowo_chat_kit: runtime.retention_days.key "%s" is not a valid runtime setting id: use "namespace.key" (lowercase) or an environment variable name.', $runtime['key']));
        }

        $container->register(self::RUNTIME_RETENTION_VALIDATOR, IntRange::class)
            ->setArguments([ChatRetention::MIN_DAYS, ChatRetention::MAX_DAYS, false]);
        RuntimeSettingsConfiguration::registerDefinition(
            $container,
            self::RUNTIME_RETENTION_DEFINITION,
            $runtime,
            RuntimeSettingType::Int,
            $configured,
            validatorServiceId: self::RUNTIME_RETENTION_VALIDATOR,
            // Audit RN-M1: no default. With `source: db` and nothing stored the purge fails instead of applying a
            // bundle default shorter than the host's retention_days (which would delete messages it meant to keep).
            required: true,
        );
        // The definition is built on first use, inside retentionDays(): a configuration error fails the purge cleanly.
        $container->register(RuntimeSettingRetentionPolicyProvider::class, RuntimeSettingRetentionPolicyProvider::class)
            ->setArguments([
                new Reference(RuntimeSettingResolver::class),
                new ServiceClosureArgument(new Reference(self::RUNTIME_RETENTION_DEFINITION)),
                $runtime['key'],
            ]);
    }

    /**
     * 1.27 (REQ-ARCH-001 rule 6): `doctrine.private_entity_manager: true` registers {@see ChatKitEntityManager} and
     * {@see ChatKitManagerRegistry}; {@see PrivateEntityManagerReferencesPass} then points every bundle service at them,
     * {@see PrivateEntityManagerPass} attaches the rollback middleware (DBAL 4) to the source manager's connection, and
     * the boundary listener drops the private manager after the memos. null keeps the 1.26 wiring; false too, with a
     * deprecation (the private manager is the only mode in 2.0).
     */
    private function registerPrivateEntityManager(ContainerBuilder $container, ?bool $private, string $emName): void
    {
        $container->setParameter(PrivateEntityManagerReferencesPass::PARAMETER, $private === true);
        $container->setParameter(PrivateEntityManagerReferencesPass::SOURCE_PARAMETER, $emName);
        // 1.30: with the private manager ChatKit's runner drives it alone (the registry's source name resolves to it).
        if ($private === true && $container->hasDefinition(ChatKitTransactionRunner::SERVICE_ID)) {
            $container->getDefinition(ChatKitTransactionRunner::SERVICE_ID)->replaceArgument(2, $emName)->replaceArgument(3, true);
        }
        if ($private === false) {
            trigger_deprecation('nowo-tech/chat-kit-bundle', '1.27', '"nowo_chat_kit ... doctrine.private_entity_manager: false" is deprecated: chat writes then flush the application\'s entity manager, including its pending changes; the private entity manager becomes the only mode in 2.0. Set it to true (see UPGRADING.md).');
        }
        if ($private !== true) {
            return;
        }

        $container->register(ChatKitEntityManager::class, ChatKitEntityManager::class)
            ->setArguments([new Reference('doctrine'), $emName, new Reference('logger', ContainerInterface::IGNORE_ON_INVALID_REFERENCE)])
            ->addTag('kernel.reset', ['method' => 'reset'])
            // Audit NJV-1: loads of the private manager inside a transaction are tracked like its writes.
            ->addTag('doctrine.event_listener', ['event' => 'postLoad', 'method' => 'postLoad', 'lazy' => true, 'entity_manager' => $emName]);
        $container->register(ChatKitManagerRegistry::class, ChatKitManagerRegistry::class)
            ->setArguments([new Reference('doctrine'), new Reference(ChatKitEntityManager::class), $emName]);
        if (PrivateEntityManagerPass::supportsRollbackMiddleware()) {
            $container->register(PrivateEntityManagerMiddleware::class, PrivateEntityManagerMiddleware::class)
                ->setArguments([new Reference(ChatKitEntityManager::class)])
                ->addTag('doctrine.middleware');
        }
        // The private manager last, after the memos that may still hold its rows.
        $listener = $container->getDefinition(ChatRequestBoundaryListener::class);
        $memos    = $listener->getArgument('$memos');
        $values   = $memos instanceof IteratorArgument ? $memos->getValues() : [];
        $values[] = new Reference(ChatKitEntityManager::class, ContainerInterface::IGNORE_ON_UNINITIALIZED_REFERENCE);
        $listener->setArgument('$memos', new IteratorArgument($values));
    }

    /**
     * The raw configs with only what prepend() needs (user class, limits, 1.24 `doctrine.user_reference`): other keys may hold %env()% values,
     * which are resolved later, in load(), never at prepend time.
     *
     * @return list<array<string, mixed>>
     */
    private function prependableConfigs(ContainerBuilder $container): array
    {
        $configs = [];
        foreach ($container->getExtensionConfig(Configuration::ALIAS) as $config) {
            foreach ($config['profiles'] ?? [] as $name => $profile) {
                $kept = array_intersect_key(is_array($profile) ? $profile : [], ['user_class' => true, 'limits' => true]);
                // 1.24: only `doctrine.user_reference` (an enum, never an %env()%) is read here.
                $doctrine = is_array($profile) && is_array($profile['doctrine'] ?? null) ? $profile['doctrine'] : [];
                if (array_key_exists('user_reference', $doctrine)) {
                    $kept['doctrine'] = ['user_reference' => $doctrine['user_reference']];
                }
                $config['profiles'][$name] = $kept;
            }
            $configs[] = $config;
        }

        return $configs === [] ? [[]] : $configs;
    }

    /**
     * Entity mapping plus `resolve_target_entities` (ChatUserInterface → default profile `user_class`); 1.24: in
     * `doctrine.user_reference: id` mode no target is resolved (UserReferenceListener maps the user columns as strings).
     */
    private function prependDoctrine(ContainerBuilder $container, string $profileName, ?string $userClass, string $userReference): void
    {
        if (!$container->hasExtension('doctrine')) {
            return;
        }
        $byId = $userReference !== ChatUserReferences::RELATION;
        if (!$byId && ($userClass === null || $userClass === '')) {
            throw new InvalidArgumentException(sprintf('The "nowo_chat_kit.profiles.%s.user_class" configuration value is required.', $profileName));
        }

        $container->prependExtensionConfig('doctrine', [
            'orm' => ($byId ? [] : ['resolve_target_entities' => [ChatUserInterface::class => $userClass]]) + [
                'mappings' => [
                    'NowoChatKitBundle' => [
                        'type'      => 'attribute',
                        'is_bundle' => false,
                        'dir'       => __DIR__ . '/../Entity',
                        'prefix'    => 'Nowo\\ChatKitBundle\\Entity',
                        'alias'     => 'NowoChatKit',
                    ],
                ],
            ],
        ]);
    }

    /**
     * Returns the configuration alias (`nowo_chat_kit`).
     *
     * @return string The alias
     */
    public function getAlias(): string
    {
        return Configuration::ALIAS;
    }

    /**
     * REQ-ORM-002: validates the table naming of the active profile and publishes the physical table names
     * (`nowo_chat_kit.doctrine.tables` and `nowo_chat_kit.doctrine.table.<key>`) for host SQL such as GDPR erasers.
     * The names are global: a non-default profile may only repeat the default profile's `doctrine` values.
     *
     * @param array<array<string, mixed>> $configs Raw `nowo_chat_kit` configs
     * @param string $defaultProfile Name of the active profile
     * @param array{table_name_prefix: string, table_prefix: string, user_reference: string} $doctrine Processed `doctrine` options of the active profile
     */
    private function registerTableNames(ContainerBuilder $container, array $configs, string $defaultProfile, array $doctrine): void
    {
        foreach ($configs as $raw) {
            $profiles = $raw['profiles'] ?? null;
            foreach (is_array($profiles) ? $profiles : [] as $name => $profile) {
                $options = is_array($profile) ? ($profile['doctrine'] ?? null) : null;
                if ($name === $defaultProfile || !is_array($options)) {
                    continue;
                }
                foreach ($options as $key => $value) {
                    if (($doctrine[$key] ?? null) !== $value) {
                        throw new InvalidConfigurationException(sprintf('nowo_chat_kit.profiles.%s.doctrine.%s is ignored: table names are global and come from the default profile "%s". Remove it or repeat the default profile\'s value.', (string) $name, (string) $key, $defaultProfile));
                    }
                }
            }
        }

        $tablePrefix     = $doctrine['table_prefix'];
        $tableNamePrefix = $doctrine['table_name_prefix'];
        // Table names are part of the schema: %env()% is not supported, so every published name is validated.
        if ($container->resolveEnvPlaceholders($tablePrefix) !== $tablePrefix || $container->resolveEnvPlaceholders($tableNamePrefix) !== $tableNamePrefix) {
            throw new InvalidConfigurationException('doctrine.table_prefix and doctrine.table_name_prefix do not support %env()%: the table names are part of the schema; set literal values.');
        }
        try {
            TablePrefixListener::validate($tablePrefix, $tableNamePrefix);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidConfigurationException($exception->getMessage(), 0, $exception);
        }

        $tables = [];
        foreach (TablePrefixListener::TABLES as $key => $mapped) {
            $tables[$key] = TablePrefixListener::physicalName($mapped, $tablePrefix, $tableNamePrefix);
            $container->setParameter(Configuration::ALIAS . '.doctrine.table.' . $key, $tables[$key]);
        }
        $container->setParameter(Configuration::ALIAS . '.doctrine.tables', $tables);
    }
}
