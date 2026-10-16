<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Form;

use Nowo\ChatKitBundle\Controller\ChatPageController;
use Nowo\ChatKitBundle\Entity\ChatMessage;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Composer of the full-page chat (`/chat/{uuid}`, works without JavaScript): one message body, Symfony CSRF.
 * Length and emptiness are checked by ChatService (same rules as the JSON API).
 *
 * @extends AbstractType<array{body?: string|null}|null>
 */
final class ChatPageMessageType extends AbstractType
{
    /**
     * Adds the message body textarea (translated placeholder, `maxlength` from the `max_length` option).
     *
     * @param FormBuilderInterface<array{body?: string|null}|null> $builder The form builder
     * @param array<string, mixed> $options The resolved options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('body', TextareaType::class, [
            'label'      => 'chat.composer_placeholder',
            'label_attr' => ['class' => 'nowo-chat-sr-only'],
            'attr'       => [
                'rows'        => 3,
                'maxlength'   => $options['max_length'],
                'placeholder' => 'chat.composer_placeholder',
            ],
        ]);
    }

    /**
     * Sets the translation domain, the CSRF token id and the `max_length` option (int, default ChatMessage::MAX_LENGTH).
     *
     * @param OptionsResolver $resolver The options resolver
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'NowoChatKitBundle',
            'csrf_token_id'      => ChatPageController::CSRF_ID,
            'max_length'         => ChatMessage::MAX_LENGTH,
        ]);
        $resolver->setAllowedTypes('max_length', 'int');
    }

    /**
     * Block prefix of the form theme and field names.
     *
     * @return string `nowo_chat_message`
     */
    public function getBlockPrefix(): string
    {
        return 'nowo_chat_message';
    }
}
