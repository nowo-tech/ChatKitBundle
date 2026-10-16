<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Form;

use Nowo\ChatKitBundle\Controller\ChatApiController;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SearchType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The two search boxes of the dock (`_templates.html.twig`): conversations (`query`, `data-part="search"`) and
 * the people picker (`people`, `data-part="picker-input"`). The dock script filters as you type; the fields are
 * rendered with `form_widget` and never submitted (REQ-TWIG-005). Placeholders and labels are translated by the
 * template in the dock locale.
 *
 * @extends AbstractType<array{query?: string|null, people?: string|null}|null>
 */
final class ChatDockSearchType extends AbstractType
{
    /**
     * Adds the conversation search and the people search.
     *
     * @param FormBuilderInterface<array{query?: string|null, people?: string|null}|null> $builder The form builder
     * @param array<string, mixed> $options The resolved options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('query', SearchType::class, [
                'label'    => false,
                'required' => false,
                'attr'     => ['class' => 'nowo-chat-search', 'data-part' => 'search', 'autocomplete' => 'off'],
            ])
            ->add('people', SearchType::class, [
                'label'    => false,
                'required' => false,
                'attr'     => ['class' => 'nowo-chat-search', 'data-part' => 'picker-input', 'autocomplete' => 'off'],
            ]);
    }

    /**
     * Sets the translation domain. CSRF stays on (REQ-SEC-005) with the API token id; the fields are never
     * submitted (the dock script reads them and calls the JSON API, which checks the `X-CSRF-TOKEN` header).
     *
     * @param OptionsResolver $resolver The options resolver
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'NowoChatKitBundle',
            'csrf_token_id'      => ChatApiController::CSRF_ID,
        ]);
    }

    /**
     * Block prefix of the form theme and field names.
     *
     * @return string `nowo_chat_search`
     */
    public function getBlockPrefix(): string
    {
        return 'nowo_chat_search';
    }
}
