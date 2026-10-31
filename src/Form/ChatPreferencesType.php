<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Form;

use Nowo\ChatKitBundle\Entity\ChatUserSettings;
use Nowo\ChatKitBundle\NowoChatKitBundle;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\RangeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The user's chat preferences (1.10) for an application's settings screen, bound to ChatUserSettings: a sound per
 * event (`soundMessage`, `soundMention`, `soundBuzz`, `soundSupport`; each select carries
 * `data-nowo-chat-sound-select` so a ▶ button can preview it with `playSound()`), `volume` (0 – 100),
 * `soundBackgroundOnly`, `buzzEnabled`, `buzzOpenWindow`, `openOnMessage`, `sendKey`, `pushPreview`, `pushEnabled`,
 * `presenceVisible` and `emailDigest`. The application persists the entity after a valid submit; for one control at a
 * time use the JSON endpoint instead (`saveChatPreference()`). The chat status is not here: users set it from the chat.
 *
 * @extends AbstractType<ChatUserSettings>
 */
#[FormKitConfig(NowoChatKitBundle::FORM_KIT_PROFILE)]
final class ChatPreferencesType extends AbstractType
{
    use FormOptionsTrait;

    /**
     * Adds the sounds, the volume, the toggles and the send key.
     *
     * @param FormBuilderInterface<ChatUserSettings|null> $builder The form builder
     * @param array<string, mixed> $options The resolved options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $sounds = [];
        foreach (ChatUserSettings::SOUNDS as $sound) {
            $sounds['chat.settings_sound_' . $sound] = $sound;
        }
        foreach (['message' => 'chat.settings_sound_message', 'mention' => 'chat.settings_sound_mention', 'buzz' => 'chat.settings_sound_buzz_event', 'support' => 'chat.settings_sound_support'] as $event => $label) {
            $this->addWithDefaults($builder, 'sound' . ucfirst($event), ChoiceType::class, [
                'label'       => $label,
                'choices'     => $sounds,
                'placeholder' => false,
                'getter'      => static fn (ChatUserSettings $settings): string => $settings->getSoundFor($event),
                'setter'      => static fn (ChatUserSettings $settings, ?string $sound) => $settings->setSoundFor($event, (string) $sound),
                'attr'        => ['data-nowo-chat-sound-select' => $event],
            ]);
        }
        $this->addWithDefaults($builder, 'volume', RangeType::class, [
            'label'  => 'chat.settings_volume',
            'attr'   => ['min' => 0, 'max' => 100, 'step' => 5],
            'getter' => static fn (ChatUserSettings $settings): int => $settings->getVolume(),
            'setter' => static fn (ChatUserSettings $settings, mixed $volume) => $settings->setVolume(max(0, min(100, (int) $volume))),
        ]);
        $this->addWithDefaults($builder, 'soundBackgroundOnly', CheckboxType::class, ['label' => 'chat.settings_background_only', 'required' => false]);
        $this->addWithDefaults($builder, 'buzzEnabled', CheckboxType::class, ['label' => 'chat.settings_buzz', 'help' => 'chat.settings_buzz_help', 'required' => false]);
        $this->addWithDefaults($builder, 'buzzOpenWindow', CheckboxType::class, ['label' => 'chat.settings_buzz_open', 'help' => 'chat.settings_buzz_open_help', 'required' => false]);
        $this->addWithDefaults($builder, 'openOnMessage', CheckboxType::class, ['label' => 'chat.settings_open_on_message', 'help' => 'chat.settings_open_on_message_help', 'required' => false]);
        $this->addWithDefaults($builder, 'sendKey', ChoiceType::class, [
            'label'    => 'chat.settings_send_key',
            'expanded' => true,
            'choices'  => ['chat.settings_send_key_enter' => 'enter', 'chat.settings_send_key_ctrl_enter' => 'ctrl_enter'],
        ]);
        $this->addWithDefaults($builder, 'pushEnabled', CheckboxType::class, ['label' => 'chat.settings_push', 'help' => 'chat.settings_push_help', 'required' => false]);
        $this->addWithDefaults($builder, 'pushPreview', CheckboxType::class, ['label' => 'chat.settings_push_preview', 'help' => 'chat.settings_push_preview_help', 'required' => false]);
        $this->addWithDefaults($builder, 'presenceVisible', CheckboxType::class, ['label' => 'chat.settings_presence', 'help' => 'chat.settings_presence_help', 'required' => false]);
        $this->addWithDefaults($builder, 'emailDigest', CheckboxType::class, ['label' => 'chat.settings_email_digest', 'help' => 'chat.settings_email_digest_help', 'required' => false]);
    }

    /**
     * Binds ChatUserSettings and the bundle's translation domain.
     *
     * @param OptionsResolver $resolver The options resolver
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => ChatUserSettings::class,
            'translation_domain' => 'NowoChatKitBundle',
        ]);
    }

    /**
     * Block prefix of the form theme and field names.
     *
     * @return string `nowo_chat_preferences`
     */
    public function getBlockPrefix(): string
    {
        return 'nowo_chat_preferences';
    }
}
