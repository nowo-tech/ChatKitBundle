<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Form;

use Nowo\ChatKitBundle\Controller\ChatApiController;
use Nowo\ChatKitBundle\Entity\ChatUserSettings;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The custom status of the dock's status menu (`_templates.html.twig`, template `status-menu`): an emoji
 * (`data-part="status-emoji"`), a text of at most 80 characters (`status-text`) and when it clears
 * (`status-clear-after`: never, 30 minutes, 1 hour, 4 hours, today, this week). Rendered with `form_widget` and never
 * submitted (REQ-TWIG-005): the dock script reads the fields and calls the JSON API (`POST /api/status`), which
 * validates them again.
 *
 * @extends AbstractType<array<string, mixed>|null>
 */
final class ChatDockStatusType extends AbstractType
{
    /**
     * Adds the emoji, the text and the "clear after" choice.
     *
     * @param FormBuilderInterface<array<string, mixed>|null> $builder The form builder
     * @param array<string, mixed> $options The resolved options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $choices = [];
        foreach (ChatUserSettings::CLEAR_AFTER as $choice) {
            $choices['chat.status_clear_' . $choice] = $choice;
        }
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
                'label'       => 'chat.status_clear_after',
                'required'    => true,
                'choices'     => $choices,
                'placeholder' => false,
                'data'        => 'never',
                'attr'        => ['class' => 'nowo-chat-status-form__clear-after', 'data-part' => 'status-clear-after'],
            ])
            // 1.11: the automatic reply (saved on its own, POST /api/auto-reply).
            ->add('autoReplyEnabled', CheckboxType::class, [
                'label'    => 'chat.auto_reply_enabled',
                'required' => false,
                'attr'     => ['data-part' => 'auto-reply-enabled'],
            ])
            ->add('autoReplyText', TextareaType::class, [
                'label'    => 'chat.auto_reply_text',
                'required' => false,
                'attr'     => ['class' => 'nowo-chat-auto-reply__text', 'data-part' => 'auto-reply-text', 'maxlength' => ChatUserSettings::AUTO_REPLY_MAX, 'rows' => 3],
            ])
            ->add('autoReplyWhen', ChoiceType::class, [
                'label'       => 'chat.auto_reply_when',
                'required'    => true,
                'placeholder' => false,
                'choices'     => ['chat.auto_reply_when_status' => 'status', 'chat.auto_reply_when_range' => 'range'],
                'data'        => 'status',
                'attr'        => ['class' => 'nowo-chat-status-form__clear-after', 'data-part' => 'auto-reply-when'],
            ])
            ->add('autoReplyFrom', DateTimeType::class, [
                'label'    => 'chat.auto_reply_from',
                'required' => false,
                'widget'   => 'single_text',
                'input'    => 'datetime_immutable',
                'attr'     => ['class' => 'nowo-chat-status-form__clear-after', 'data-part' => 'auto-reply-from'],
            ])
            ->add('autoReplyUntil', DateTimeType::class, [
                'label'    => 'chat.auto_reply_until',
                'required' => false,
                'widget'   => 'single_text',
                'input'    => 'datetime_immutable',
                'attr'     => ['class' => 'nowo-chat-status-form__clear-after', 'data-part' => 'auto-reply-until'],
            ])
            ->add('autoReplyMentions', CheckboxType::class, [
                'label'    => 'chat.auto_reply_mentions',
                'required' => false,
                'attr'     => ['data-part' => 'auto-reply-mentions'],
            ]);
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
            'translation_domain' => 'NowoChatKitBundle',
            'csrf_token_id'      => ChatApiController::CSRF_ID,
            'attr'               => ['class' => 'nowo-chat-status-form', 'data-part' => 'status-form'],
        ]);
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
}
