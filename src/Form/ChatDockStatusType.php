<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Form;

use Nowo\ChatKitBundle\Controller\ChatApiController;
use Nowo\ChatKitBundle\DependencyInjection\Configuration;
use Nowo\ChatKitBundle\Entity\ChatUserSettings;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

use function is_array;
use function is_string;

/**
 * The custom status of the dock's status menu (`_templates.html.twig`, template `status-menu`): an emoji
 * (`data-part="status-emoji"`), a text of at most 80 characters (`status-text`) and when it clears
 * (`status-clear-after`: never, 30 minutes, 1 hour, 2 hours, 4 hours, today, this week). Rendered with `form_widget` and never
 * submitted (REQ-TWIG-005): the dock script reads the fields and calls the JSON API (`POST /api/status`), which
 * validates them again.
 *
 * 1.12: option `status_presets` (`nowo_chat_kit.status_presets`) — the `quick` one-click suggestions (view var
 * `status_presets`, rendered as buttons by the template) and the `more` groups, the "More statuses" drop-down
 * (`moreStatus`, `data-part="status-more"`; each option carries `data-preset-emoji / -text / -clear-after`).
 *
 * @phpstan-type StatusPreset array{emoji: string, label: string, clear_after: string}
 * @phpstan-type StatusPresets array{quick: list<StatusPreset>, more: list<array{label: string, statuses: list<StatusPreset>}>}
 *
 * @extends AbstractType<array<string, mixed>|null>
 */
final class ChatDockStatusType extends AbstractType
{
    /**
     * Builds the type; labels and choices are translated in the dock locale (option `locale`), not the request's.
     *
     * @param TranslatorInterface|null $translator The translator; null leaves the translation keys
     */
    public function __construct(private readonly ?TranslatorInterface $translator = null)
    {
    }

    /**
     * Adds the emoji, the text and the "clear after" choice.
     *
     * @param FormBuilderInterface<array<string, mixed>|null> $builder The form builder
     * @param array<string, mixed> $options The resolved options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $locale  = is_string($options['locale']) ? $options['locale'] : null;
        $label   = fn (string $key): string => $this->trans($key, $locale);
        $choices = [];
        foreach (ChatUserSettings::CLEAR_AFTER as $choice) {
            $choices[$label('chat.status_clear_' . $choice)] = $choice;
        }
        $this->addMoreStatuses($builder, $this->presets($options), $label);
        $builder
            ->add('emoji', TextType::class, [
                'label'    => false,
                'required' => false,
                'attr'     => ['class' => 'nowo-chat-status-form__emoji', 'data-part' => 'status-emoji', 'maxlength' => ChatUserSettings::STATUS_EMOJI_MAX, 'autocomplete' => 'off', 'inputmode' => 'text'],
            ])
            ->add('text', TextType::class, [
                'label'    => false,
                'required' => false,
                'attr'     => ['class' => 'nowo-chat-status-form__text', 'data-part' => 'status-text', 'maxlength' => ChatUserSettings::STATUS_TEXT_MAX, 'autocomplete' => 'off'],
            ])
            ->add('clearAfter', ChoiceType::class, [
                'label'       => $label('chat.status_clear_after'),
                'required'    => true,
                'choices'     => $choices,
                'placeholder' => false,
                'data'        => 'never',
                'attr'        => ['class' => 'nowo-chat-status-form__clear-after', 'data-part' => 'status-clear-after'],
            ])
            // 1.11: the automatic reply (saved on its own, POST /api/auto-reply).
            ->add('autoReplyEnabled', CheckboxType::class, [
                'label'    => $label('chat.auto_reply_enabled'),
                'required' => false,
                'attr'     => ['data-part' => 'auto-reply-enabled'],
            ])
            ->add('autoReplyText', TextareaType::class, [
                'label'    => $label('chat.auto_reply_text'),
                'required' => false,
                'attr'     => ['class' => 'nowo-chat-auto-reply__text', 'data-part' => 'auto-reply-text', 'maxlength' => ChatUserSettings::AUTO_REPLY_MAX, 'rows' => 3],
            ])
            ->add('autoReplyWhen', ChoiceType::class, [
                'label'       => $label('chat.auto_reply_when'),
                'required'    => true,
                'placeholder' => false,
                'choices'     => [$label('chat.auto_reply_when_status') => 'status', $label('chat.auto_reply_when_range') => 'range'],
                'data'        => 'status',
                'attr'        => ['class' => 'nowo-chat-status-form__clear-after', 'data-part' => 'auto-reply-when'],
            ])
            ->add('autoReplyFrom', DateTimeType::class, [
                'label'    => $label('chat.auto_reply_from'),
                'required' => false,
                'widget'   => 'single_text',
                'input'    => 'datetime_immutable',
                'attr'     => ['class' => 'nowo-chat-status-form__clear-after', 'data-part' => 'auto-reply-from'],
            ])
            ->add('autoReplyUntil', DateTimeType::class, [
                'label'    => $label('chat.auto_reply_until'),
                'required' => false,
                'widget'   => 'single_text',
                'input'    => 'datetime_immutable',
                'attr'     => ['class' => 'nowo-chat-status-form__clear-after', 'data-part' => 'auto-reply-until'],
            ])
            ->add('autoReplyMentions', CheckboxType::class, [
                'label'    => $label('chat.auto_reply_mentions'),
                'required' => false,
                'attr'     => ['data-part' => 'auto-reply-mentions'],
            ]);
    }

    /**
     * Passes the quick suggestions, translated, to the template (view var `status_presets`: emoji, text, clear_after).
     *
     * @param FormView $view The form view
     * @param FormInterface<array<string, mixed>|null> $form The form
     * @param array<string, mixed> $options The resolved options
     */
    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $locale = is_string($options['locale']) ? $options['locale'] : null;
        $quick  = [];
        foreach ($this->presets($options)['quick'] as $preset) {
            $quick[] = ['emoji' => $preset['emoji'], 'text' => $this->trans($preset['label'], $locale), 'clear_after' => $preset['clear_after']];
        }
        $view->vars['status_presets'] = $quick;
    }

    /**
     * Sets the translation domain. CSRF stays on (REQ-SEC-005) with the API token id; the fields are never
     * submitted (the API checks the `X-CSRF-TOKEN` header).
     *
     * @param OptionsResolver $resolver The options resolver
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            // Labels and choices come translated in the dock locale (see buildForm()).
            'translation_domain' => false,
            'locale'             => null,
            'csrf_token_id'      => ChatApiController::CSRF_ID,
            'attr'               => ['class' => 'nowo-chat-status-form', 'data-part' => 'status-form'],
            'status_presets'     => Configuration::DEFAULT_STATUS_PRESETS,
        ]);
        $resolver->setAllowedTypes('status_presets', 'array');
    }

    /**
     * Block prefix of the form theme and field names.
     *
     * @return string `nowo_chat_status`
     */
    public function getBlockPrefix(): string
    {
        return 'nowo_chat_status';
    }

    /**
     * The "More statuses" drop-down (1.12): one option group per `more` group; picking an option fills the custom
     * status like a quick suggestion. Not added when there is no group.
     *
     * @param FormBuilderInterface<array<string, mixed>|null> $builder The form builder
     * @param StatusPresets $presets The suggestions
     * @param callable(string): string $label Translates a key in the dock locale
     */
    private function addMoreStatuses(FormBuilderInterface $builder, array $presets, callable $label): void
    {
        $choices = [];
        $labels  = [];
        $attrs   = [];
        foreach ($presets['more'] as $g => $group) {
            $options = [];
            foreach ($group['statuses'] as $i => $status) {
                $value           = $g . '-' . $i;
                $text            = $label($status['label']);
                $options[$value] = $value;
                $labels[$value]  = $status['emoji'] . ' ' . $text;
                $attrs[$value]   = ['data-preset-emoji' => $status['emoji'], 'data-preset-text' => $text, 'data-preset-clear-after' => $status['clear_after']];
            }
            $choices[$label($group['label'])] = $options;
        }
        if ($choices === []) {
            return;
        }
        $builder->add('moreStatus', ChoiceType::class, [
            'label'        => $label('chat.status_more'),
            'required'     => false,
            'choices'      => $choices,
            'placeholder'  => $label('chat.status_more_placeholder'),
            'choice_label' => static fn (string $choice): string => $labels[$choice],
            'choice_attr'  => static fn (string $choice): array => $attrs[$choice],
            'attr'         => ['class' => 'nowo-chat-status-form__clear-after nowo-chat-status-form__more', 'data-part' => 'status-more'],
        ]);
    }

    /**
     * The `status_presets` option.
     *
     * @param array<string, mixed> $options The resolved options
     *
     * @return StatusPresets The suggestions
     */
    private function presets(array $options): array
    {
        /** @var StatusPresets $presets */
        $presets = (is_array($options['status_presets']) ? $options['status_presets'] : []) + Configuration::DEFAULT_STATUS_PRESETS;

        return $presets;
    }

    private function trans(string $key, ?string $locale): string
    {
        return $this->translator?->trans($key, [], 'NowoChatKitBundle', $locale) ?? $key;
    }
}
