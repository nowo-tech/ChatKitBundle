<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Tests\Support;

use Nowo\ChatKitBundle\DependencyInjection\NowoChatKitExtension;
use Nowo\ChatKitBundle\Form\ChatDockComposerType;
use Nowo\ChatKitBundle\Form\ChatDockEditType;
use Nowo\ChatKitBundle\Form\ChatDockSearchType;
use Nowo\ChatKitBundle\Form\ChatDockStatusType;
use Nowo\ChatKitBundle\Form\ChatPageMessageType;
use Nowo\ChatKitBundle\Form\ChatPreferencesType;
use Nowo\ChatKitBundle\NowoChatKitBundle;
use Nowo\FormKitBundle\Form\Constraint\ConstraintDefinitionFactory;
use Nowo\FormKitBundle\Form\FormOptionsMerger;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Form\PreloadedExtension;

/**
 * FormKit wiring for form tests without a kernel: the bundle form types are FormKit Types
 * (#[FormKitConfig] + FormOptionsTrait) and need the FormOptionsMerger the FormKit compiler pass injects.
 */
final class FormKitTestSupport
{
    public static function merger(): FormOptionsMerger
    {
        $profile = NowoChatKitExtension::formKitProfile();
        unset($profile['alias']);

        /** @var array<string, array{translation_domain: string, defaults: array{attr: array<string, mixed>, row_attr: array<string, mixed>}, field_types: array<string, array<string, mixed>>}> $profiles */
        $profiles = [NowoChatKitBundle::FORM_KIT_PROFILE => $profile + ['by_form' => []]];

        return new FormOptionsMerger(
            $profiles,
            NowoChatKitBundle::FORM_KIT_PROFILE,
            new ConstraintDefinitionFactory(),
        );
    }

    public static function formFactory(): FormFactoryInterface
    {
        $merger = self::merger();
        $types  = [new ChatDockComposerType(), new ChatDockEditType(), new ChatDockSearchType(), new ChatDockStatusType(), new ChatPageMessageType(), new ChatPreferencesType()];
        foreach ($types as $type) {
            $type->setFormOptionsMerger($merger);
        }

        return Forms::createFormFactoryBuilder()
            ->addExtension(new PreloadedExtension($types, []))
            ->getFormFactory();
    }
}
