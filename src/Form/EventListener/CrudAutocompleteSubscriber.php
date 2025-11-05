<?php

namespace EasyCorp\Bundle\EasyAdminBundle\Form\EventListener;

use Doctrine\DBAL\Types\Type;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;

/**
 * @author Yonel Ceruto <yonelceruto@gmail.com>
 * @author Marcel Berteler <pluk77@gmail.com>
 */
class CrudAutocompleteSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            FormEvents::PRE_SET_DATA => 'preSetData',
            FormEvents::PRE_SUBMIT => 'preSubmit',
        ];
    }

    /**
     * @return void
     */
    public function preSetData(FormEvent $event)
    {
        $form = $event->getForm();
        $data = $event->getData() ?? [];

        $options = $form->getConfig()->getOptions();
        $options['compound'] = false;
        $options['choices'] = is_iterable($data) ? $data : [$data];

        $form->add('autocomplete', EntityType::class, $options);
    }

    /**
     * This method assumes that the object has a single-column primary key.
     *
     * @return void
     */
    public function preSubmit(FormEvent $event)
    {
        $data = $event->getData();
        $form = $event->getForm();
        $options = $form->get('autocomplete')->getConfig()->getOptions();

        if (!isset($data['autocomplete']) || '' === $data['autocomplete']) {
            $options['choices'] = [];
        } else {
            $data['autocomplete'] = $this->processAutocompleteValues($data['autocomplete'], $options);

            // Assume primary key is not a composit key, or take the first key
            $idFieldName = current($options['em']->getClassMetadata($options['class'])->getIdentifierFieldNames());
            
            $options['choices'] = $options['em']->getRepository($options['class'])->findBy([
                $idFieldName => $data['autocomplete'],
            ]);
        }

        // reset some critical lazy options
        unset($options['em'], $options['loader'], $options['empty_data'], $options['choice_list'], $options['choices_as_values']);

        $form->add('autocomplete', EntityType::class, $options);
    }

    /**
     * Some field types require a conversion to the database value before 
     * it can be used in the findBy query. Examples of these are UUIDs and ULIDs.
     *
     * Integers and string types are returned without processing
     *
     * If the primary key of a type defined Doctrine then the values are converted
     * from the entity to the database value.
     *
     * Otherwise, the values are returned without change.
     *
     * For example, if the used platform is MySQL:
     *
     *      App\Entity\Category {#1040 ▼
     *          -id: Symfony\Component\Uid\UuidV6 {#1046 ▼
     *              #uid: "1ec4d51f-c746-6f60-b698-634384c1b64c"
     *          }
     *          -title: "cat 2"
     *      }
     *
     *  gets processed to a binary value:
     *
     *      b"\x1EÄÕ\x1FÇFo`¶˜cC„Á¶L"
     */
    private function processAutocompleteValues(mixed $autocompleteValues, array $options): array
    {
        if (!\is_array($autocompleteValues)) {
            $autocompleteValues = [$autocompleteValues];
        }

        $classMetadata = $options['em']->getClassMetadata($options['class']);
        $ids = $classMetadata->getIdentifierFieldNames();

        // Assume primary key is not a composit key, or take the first
        $idType = $classMetadata->getTypeOfField(current($ids));

        // integers and strings do not need processing
        if (\in_array($idType, ['integer', 'smallint', 'bigint', 'string'], true)) {
            return $autocompleteValues;
        }

        try {
            // all values have the same platform and type
            $type = Type::getType($idType);
            $platform = $options['em']->getConnection()->getDatabasePlatform();

            return array_map(
                function ($v) use ($type, $platform) {
                    return $type->convertToDatabaseValue($v, $platform);
                },
                $autocompleteValues
            );
        } catch (\Throwable $exception) {
            // if the conversion fails we return the unmodified values
        }

        return $autocompleteValues;
    }
}
