<?php

/*
 * This file is part of the Goteo Package.
 *
 * (c) Platoniq y Fundación Goteo <fundacion@goteo.org>
 *
 * For the full copyright and license information, please view the README.md
 * and LICENSE files that was distributed with this source code.
 */

namespace Goteo\Library\Forms\Admin;

use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Form\FormInterface;
use Goteo\Library\Forms\AbstractFormProcessor;
use Symfony\Component\Validator\Constraints;
use Goteo\Library\Text;
use Goteo\Library\Forms\FormModelException;

use Goteo\Model\Node\NodeSections;

class AdminSectionForm extends AbstractFormProcessor {

    public function getConstraints($field) {
        return [new Constraints\NotBlank()];
    }

    public function createForm() {
        $model = $this->getModel();

        $builder = $this->getBuilder();
        $options = $builder->getOptions();
        $defaults = $options['data'];

        $builder
            ->add('section', 'choice', [
                'disabled' => $this->getReadonly(),
                'required' => true,
                'label' => 'admin-channelsection-section',
                'choices' => NodeSections::getSectionNames(),
            ])
            ->add('main_title', 'text', [
                'disabled' => $this->getReadonly(),
                'required' => false,
                'label' => 'regular-title'
            ])
            ->add('main_description', 'textarea', [
                'disabled' => $this->getReadonly(),
                'required' => false,
                'label' => 'regular-description'
            ])
            ->add('main_button', 'textarea', [
                'disabled' => $this->getReadonly(),
                'required' => false,
                'label' => 'admin-channelsection-button'
            ])
            ->add('main_image', 'dropfiles', array(
                'required' => false,
                'limit' => 1,
                'data' => [$model->main_image ? $model->getImage() : null],
                'label' => 'regular-image',
                'accepted_files' => 'image/jpeg,image/png,image/svg+xml'
            ))
            ;

        return $this;
    }

    public function save(FormInterface $form = null, $force_save = false) {

        if(!$form) $form = $this->getBuilder()->getForm();
        if(!$form->isValid() && !$force_save) {
            throw new FormModelException(Text::get('form-has-errors'));
        }

        $data = $form->getData();
        $model = $this->getModel();

        $this->processImageChange($data['main_image'], $model->main_image, false);

        unset($data['main_image']);
        $model->rebuildData($data, array_keys($form->all()));
        $errors = [];
        if (!$model->save($errors)) {
            throw new FormModelException(Text::get('form-sent-error', implode(', ',$errors)));
        }
        return $this;
    }
}
