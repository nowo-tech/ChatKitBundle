<?php

declare(strict_types=1);

namespace Nowo\ChatKitBundle\Form;

use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormTypeInterface;

use function array_key_exists;
use function is_string;

/**
 * Adds a field with the options FormKit merges from the bundle profile ({@see FormOptionsTrait}),
 * keeping an explicit `label: false`: FormOptionsMerger drops it (Symfony would then print the humanized field name).
 * Used by form types that also use FormOptionsTrait (REQ-TWIG-005 rule 11).
 */
trait FormKitFieldTrait
{
    /**
     * @param FormBuilderInterface<mixed> $builder
     * @param class-string<FormTypeInterface<mixed>> $type
     * @param array<string, mixed> $options Field options; they win over the profile
     */
    protected function addKitField(FormBuilderInterface $builder, string $name, string $type, array $options = []): void
    {
        if (!array_key_exists('translation_domain', $options)) {
            $domain = $builder->getOption('translation_domain');
            if (is_string($domain) && $domain !== '') {
                $options['translation_domain'] = $domain;
            }
        }

        $resolved = $this->resolveFieldOptions($name, $type, $options);
        if (array_key_exists('label', $options) && $options['label'] === false) {
            $resolved['label'] = false;
        }

        $builder->add($name, $type, $resolved);
    }
}
