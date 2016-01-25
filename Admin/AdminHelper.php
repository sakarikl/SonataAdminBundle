<?php

/*
 * This file is part of the Sonata Project package.
 *
 * (c) Thomas Rabaix <thomas.rabaix@sonata-project.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sonata\AdminBundle\Admin;

use Doctrine\Common\Inflector\Inflector;
use Doctrine\Common\Util\ClassUtils;
use Sonata\AdminBundle\Exception\NoValueException;
use Sonata\AdminBundle\Util\FormBuilderIterator;
use Sonata\AdminBundle\Util\FormViewIterator;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormView;

/**
 * Class AdminHelper.
 *
 * @author  Thomas Rabaix <thomas.rabaix@sonata-project.org>
 */
class AdminHelper
{
    /**
     * @var Pool
     */
    protected $pool;

    /**
     * @param Pool $pool
     */
    public function __construct(Pool $pool)
    {
        $this->pool = $pool;
    }

    /**
     * @throws \RuntimeException
     *
     * @param FormBuilderInterface $formBuilder
     * @param string               $elementId
     *
     * @return FormBuilderInterface
     */
    public function getChildFormBuilder(FormBuilderInterface $formBuilder, $elementId)
    {
        foreach (new FormBuilderIterator($formBuilder) as $name => $formBuilder) {
            if ($name == $elementId) {
                return $formBuilder;
            }
        }

        return;
    }

    /**
     * @param FormView $formView
     * @param string   $elementId
     *
     * @return null|FormView
     */
    public function getChildFormView(FormView $formView, $elementId)
    {
        foreach (new \RecursiveIteratorIterator(new FormViewIterator($formView), \RecursiveIteratorIterator::SELF_FIRST) as $name => $formView) {
            if ($name === $elementId) {
                return $formView;
            }
        }

        return;
    }

    /**
     * @deprecated
     *
     * @param string $code
     *
     * @return AdminInterface
     */
    public function getAdmin($code)
    {
        return $this->pool->getInstance($code);
    }

    /**
     * Note:
     *   This code is ugly, but there is no better way of doing it.
     *   For now the append form element action used to add a new row works
     *   only for direct FieldDescription (not nested one).
     *
     * @throws \RuntimeException
     *
     * @param AdminInterface $admin
     * @param object         $subject
     * @param string         $elementId
     *
     * @return array
     */
    public function appendFormFieldElement(AdminInterface $admin, $subject, $elementId)
    {
        // retrieve the subject
        $formBuilder = $admin->getFormBuilder();

        $form = $formBuilder->getForm();
        $form->setData($subject);
        $form->handleRequest($admin->getRequest());

        // get the field element
        $childFormBuilder = $this->getChildFormBuilder($formBuilder, $elementId);
        
        //Child form not found (probably nested one)
        //if childFormBuilder was not found resulted in fatal error getName() method call on non object
        if (!$childFormBuilder) {

            $propertyAccessor = new PropertyAccessor();
            $entity = $admin->getSubject();

            $path = $this->getElementAccessPath($elementId, $entity, $propertyAccessor);

            $collection = $propertyAccessor->getValue($entity, $path);

            if ($collection instanceof ArrayCollection) {
                $entityClassName = $this->entityClassNameFinder($admin, explode('.', preg_replace('#\[\d*?\]#', '', $path)));
            } elseif ($collection instanceof \Doctrine\ORM\PersistentCollection) {
                //since doctrine 2.4
                $entityClassName = $collection->getTypeClass()->getName();
            } else {
                throw new \Exception('unknown collection class');
            }

            $collection->add(new $entityClassName);
            $propertyAccessor->setValue($entity, $path, $collection);

            $fieldDescription = null;
        }
        else {
            // retrieve the FieldDescription
            $fieldDescription = $admin->getFormFieldDescription($childFormBuilder->getName());
    
            try {
                $value = $fieldDescription->getValue($form->getData());
            } catch (NoValueException $e) {
                $value = null;
            }
    
            // retrieve the posted data
            $data = $admin->getRequest()->get($formBuilder->getName());
    
            if (!isset($data[$childFormBuilder->getName()])) {
                $data[$childFormBuilder->getName()] = array();
            }
    
            $objectCount = count($value);
            $postCount   = count($data[$childFormBuilder->getName()]);
    
            $fields = array_keys($fieldDescription->getAssociationAdmin()->getFormFieldDescriptions());
    
            // for now, not sure how to do that
            $value = array();
            foreach ($fields as $name) {
                $value[$name] = '';
            }
    
            // add new elements to the subject
            while ($objectCount < $postCount) {
                // append a new instance into the object
                $this->addNewInstance($form->getData(), $fieldDescription);
                ++$objectCount;
            }
    
            $this->addNewInstance($form->getData(), $fieldDescription);
        }

        $finalForm = $admin->getFormBuilder()->getForm();
        $finalForm->setData($subject);

        // bind the data
        $finalForm->setData($form->getData());

        return array($fieldDescription, $finalForm);
    }

    /**
     * Add a new instance to the related FieldDescriptionInterface value.
     *
     * @param object                    $object
     * @param FieldDescriptionInterface $fieldDescription
     *
     * @throws \RuntimeException
     */
    public function addNewInstance($object, FieldDescriptionInterface $fieldDescription)
    {
        $instance = $fieldDescription->getAssociationAdmin()->getNewInstance();
        $mapping  = $fieldDescription->getAssociationMapping();

        $method = sprintf('add%s', $this->camelize($mapping['fieldName']));

        if (!method_exists($object, $method)) {
            $method = rtrim($method, 's');

            if (!method_exists($object, $method)) {
                $method = sprintf('add%s', $this->camelize(Inflector::singularize($mapping['fieldName'])));

                if (!method_exists($object, $method)) {
                    throw new \RuntimeException(sprintf('Please add a method %s in the %s class!', $method, ClassUtils::getClass($object)));
                }
            }
        }

        $object->$method($instance);
    }

    /**
     * Camelize a string.
     *
     * @static
     *
     * @param string $property
     *
     * @return string
     */
    public function camelize($property)
    {
        return BaseFieldDescription::camelize($property);
    }
    
    /**
     * @param AdminInterface $admin
     * @param $elements
     * @return string
     */
    protected function entityClassNameFinder(AdminInterface $admin, $elements)
    {
        $element = array_shift($elements);
        $associationAdmin = $admin->getFormFieldDescription($element)->getAssociationAdmin();
        if (count($elements) == 0) {
            return $associationAdmin->getClass();
        } else {
            return $this->entityClassNameFinder($associationAdmin, $elements);
        }
    }

    /**
     * get access path to element which works with PropertyAccessor
     *
     * @param string $elementId
     * @param mixed $entity
     * @param PropertyAccessor $propertyAccessor
     * @return string
     * @throws \Exception
     */
    private function getElementAccessPath($elementId, $entity, PropertyAccessor $propertyAccessor)
    {
        $initial = preg_replace('#(_(\d+)_)#', '[$2]', implode('_', explode('_', substr($elementId, strpos($elementId, '_') + 1))));
        $parts = preg_split('#\[\d+\]#', $initial);

        $part_return_value = $return_value = '';
        $current_entity = $entity;

        foreach ($parts as $key => $value){
            $sub_parts = explode('_', $value);
            $id = '';
            $dot = '';

            foreach ($sub_parts as $sub_value) {
                $id .= ($id) ? '_'.$sub_value : $sub_value;

                if ($propertyAccessor->isReadable($current_entity, $part_return_value.$dot.$id)) {
                    $part_return_value .= $id;
                    $dot = '.';
                    $id = '';
                } else $dot = '';
            }

            if ($dot !== '.') throw new \Exception(sprintf('Could not get element id from %s Failing part: %s', $elementId, $sub_value));

            preg_match("#$value\[(\d+)#", $initial, $matches);

            if (isset($matches[1])) $part_return_value .= '['.$matches[1].']';

            $return_value .= $return_value ? '.'.$part_return_value : $part_return_value;
            $part_return_value = '';

            if (isset($parts[$key+1])) $current_entity = $propertyAccessor->getValue($entity, $return_value);
        }

        return $return_value;
    }
}
