<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Form;

use Nowo\ChatKitBundle\Controller\ChatApiController;
use Nowo\ChatKitBundle\NowoChatKitBundle;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Inline editor that replaces a message bubble while editing (`_templates.html.twig`, template `edit-form`). The
 * dock script cancels the submit and sends a JSON PATCH (CSRF in the `X-CSRF-TOKEN` header), so the form is the
 * markup contract only (REQ-TWIG-005). The label is translated by the template in the dock locale.
 *
 * @extends AbstractType<array{body?: string|null}|null>
 */
#[FormKitConfig(NowoChatKitBundle::FORM_KIT_PROFILE)]
final class ChatDockEditType extends AbstractType
{
    use FormKitFieldTrait;
    use FormOptionsTrait;

    /**
     * Adds the edited body textarea (`data-part="input"`).
     *
     * @param FormBuilderInterface<array{body?: string|null}|null> $builder The form builder
     * @param array<string, mixed> $options The resolved options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addKitField($builder, 'body', TextareaType::class, [
            'label'    => false,
            'required' => false,
            'attr'     => ['class' => 'nowo-chat-edit__input', 'data-part' => 'input', 'rows' => 2],
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
            'attr'               => ['class' => 'nowo-chat-edit', 'novalidate' => 'novalidate'],
            'translation_domain' => 'NowoChatKitBundle',
            'csrf_token_id'      => ChatApiController::CSRF_ID,
        ]);
    }

    /**
     * Block prefix of the form theme and field names.
     *
     * @return string `nowo_chat_edit`
     */
    public function getBlockPrefix(): string
    {
        return 'nowo_chat_edit';
    }
}
