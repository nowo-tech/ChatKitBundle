<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Form;

use Nowo\ChatKitBundle\Controller\ChatApiController;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Composer of a dock window (`_templates.html.twig`, `data-part="composer"`): one message body. The dock script
 * cancels the submit and posts JSON to the API (CSRF in the `X-CSRF-TOKEN` header), so the form is the markup
 * contract only (REQ-TWIG-005). Placeholder and label are translated by the template in the dock locale.
 *
 * @extends AbstractType<array{body?: string|null}|null>
 */
final class ChatDockComposerType extends AbstractType
{
    /**
     * Adds the message body textarea (`data-part="input"`, one row that grows with the text).
     *
     * @param FormBuilderInterface<array{body?: string|null}|null> $builder The form builder
     * @param array<string, mixed> $options The resolved options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('body', TextareaType::class, [
            'label'    => false,
            'required' => false,
            'attr'     => ['class' => 'nowo-chat-composer__input', 'data-part' => 'input', 'rows' => 1],
        ]);
    }

    /**
     * Sets the form attributes the dock script relies on and the translation domain. CSRF stays on (REQ-SEC-005)
     * with the API token id, so the hidden `_token` carries the same `nowo_chat` token the JSON API checks.
     *
     * @param OptionsResolver $resolver The options resolver
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'attr'               => ['class' => 'nowo-chat-composer', 'data-part' => 'composer', 'novalidate' => 'novalidate'],
            'translation_domain' => 'NowoChatKitBundle',
            'csrf_token_id'      => ChatApiController::CSRF_ID,
        ]);
    }

    /**
     * Block prefix of the form theme and field names.
     *
     * @return string `nowo_chat_composer`
     */
    public function getBlockPrefix(): string
    {
        return 'nowo_chat_composer';
    }
}
