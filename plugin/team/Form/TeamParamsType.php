<?php

/*
 * This file is part of the Claroline Connect package.
 *
 * (c) Claroline Consortium <consortium@claroline.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Claroline\TeamBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;

class TeamParamsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder->add(
            'maxTeams',
            'integer',
            [
                'attr' => ['min' => 0],
                'required' => false,
            ]
        );
        $builder->add(
            'isPublic',
            'choice',
            [
                'choices' => [
                    true => 'public',
                    false => 'private',
                ],
                'required' => true,
            ]
        );
        $builder->add(
            'selfRegistration',
            'checkbox',
            ['required' => true]
        );
        $builder->add(
            'selfUnregistration',
            'checkbox',
            ['required' => true]
        );
    }

    public function getName()
    {
        return 'team_form';
    }

    public function setDefaultOptions(OptionsResolverInterface $resolver)
    {
        $resolver->setDefaults(['translation_domain' => 'team']);
    }
}
