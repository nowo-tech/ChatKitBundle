<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Form;

use Nowo\ChatKitBundle\NowoChatKitBundle;
use Nowo\ChatKitBundle\Search\ChatMessageSearchQuery;
use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SearchType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * 1.37: the message search form of `GET /chat/search` (FormKit profile `chat_kit`, UiKit form theme): the text, "from
 * me", a date range, "has a link" and, when the search was opened from a conversation, that conversation. A GET form
 * without CSRF (it reads only); create it with an empty name so that the fields are plain query parameters (`q`,
 * `from`, `after`, `before`, `link`, `conversation`). Every value is checked again by the search.
 *
 * @extends AbstractType<array<string, mixed>|null>
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
#[FormKitConfig(NowoChatKitBundle::FORM_KIT_PROFILE)]
final class ChatMessageSearchType extends AbstractType
{
    use FormOptionsTrait;

    /**
     * Adds the text, the filters and the submit button.
     *
     * @param FormBuilderInterface<array<string, mixed>|null> $builder The form builder
     * @param array<string, mixed> $options The options (`max_length`: the text's maxlength)
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addWithDefaults($builder, 'q', SearchType::class, [
            'label'    => 'chat_search.label',
            'required' => true,
            'attr'     => ['maxlength' => $options['max_length'], 'autocomplete' => 'off', 'data-testid' => 'chat-search-input'],
        ]);
        $this->addWithDefaults($builder, 'from', ChoiceType::class, [
            'label'       => 'chat_search.from',
            'required'    => false,
            'placeholder' => 'chat_search.from_anyone',
            'choices'     => ['chat_search.from_me' => ChatMessageSearchQuery::ME],
        ]);
        $this->addWithDefaults($builder, 'after', DateType::class, [
            'label'    => 'chat_search.after',
            'required' => false,
            'widget'   => 'single_text',
            'input'    => 'datetime_immutable',
        ]);
        $this->addWithDefaults($builder, 'before', DateType::class, [
            'label'    => 'chat_search.before',
            'required' => false,
            'widget'   => 'single_text',
            'input'    => 'datetime_immutable',
        ]);
        $this->addWithDefaults($builder, 'link', CheckboxType::class, ['label' => 'chat_search.has_link', 'required' => false]);
        $this->addWithDefaults($builder, 'conversation', HiddenType::class, ['required' => false]);
        $this->addWithDefaults($builder, 'search', SubmitType::class, ['label' => 'chat_search.submit']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'NowoChatKitBundle',
            'method'             => 'GET',
            'csrf_protection'    => false,
            'allow_extra_fields' => true,
            'max_length'         => 200,
        ]);
        $resolver->setAllowedTypes('max_length', 'int');
    }

    public function getBlockPrefix(): string
    {
        return 'nowo_chat_message_search';
    }
}
