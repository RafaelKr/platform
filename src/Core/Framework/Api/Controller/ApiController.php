<?php declare(strict_types=1);

namespace Shopware\Core\Framework\Api\Controller;

use Shopware\Core\Framework\Api\Exception\NoEntityClonedException;
use Shopware\Core\Framework\Api\Exception\ResourceNotFoundException;
use Shopware\Core\Framework\Api\Exception\UnknownRepositoryVersionException;
use Shopware\Core\Framework\Api\OAuth\Scope\WriteScope;
use Shopware\Core\Framework\Api\Response\ResponseFactoryInterface;
use Shopware\Core\Framework\Api\Response\Type\Api\JsonType;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityTranslationDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\DefinitionNotFoundException;
use Shopware\Core\Framework\DataAbstractionLayer\Field\AssociationInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Field;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\OneToManyAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\TranslationsAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Read\ReadCriteria;
use Shopware\Core\Framework\DataAbstractionLayer\RepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\RequestCriteriaBuilder;
use Shopware\Core\Framework\Search\CompositeEntitySearcher;
use Shopware\Core\PlatformRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Serializer\Exception\InvalidArgumentException;
use Symfony\Component\Serializer\Exception\UnexpectedValueException;
use Symfony\Component\Serializer\Serializer;

class ApiController extends AbstractController
{
    public const WRITE_UPDATE = 'update';
    public const WRITE_CREATE = 'create';

    /**
     * @var DefinitionRegistry
     */
    private $definitionRegistry;

    /**
     * @var Serializer
     */
    private $serializer;

    /**
     * @var RequestCriteriaBuilder
     */
    private $searchCriteriaBuilder;

    /**
     * @var CompositeEntitySearcher
     */
    private $compositeEntitySearcher;

    public function __construct(
        DefinitionRegistry $definitionRegistry,
        Serializer $serializer,
        RequestCriteriaBuilder $searchCriteriaBuilder,
        CompositeEntitySearcher $compositeEntitySearcher
    ) {
        $this->definitionRegistry = $definitionRegistry;
        $this->serializer = $serializer;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->compositeEntitySearcher = $compositeEntitySearcher;
    }

    /**
     * @Route("/api/v{version}/_search", name="api.composite.search", methods={"GET"})
     */
    public function compositeSearch(Request $request, Context $context): JsonResponse
    {
        $term = $request->query->get('term');
        $limit = $request->query->getInt('limit', 20);

        $result = $this->compositeEntitySearcher->search($term, $limit, $context, $context->getSourceContext()->getUserId());

        $result = json_decode(json_encode($result), true);

        return new JsonResponse(JsonType::format($result));
    }

    /**
     * @Route("/api/v{version}/_action/clone/{entity}/{id}", name="api.clone", methods={"POST"})
     */
    public function clone(Request $request, Context $context, string $entity, string $id)
    {
        $entity = $this->urlToSnakeCase($entity);

        $definition = $this->definitionRegistry->get($entity);

        $repository = $this->getRepository($definition, $request);

        $eventContainer = $repository->clone($id, $context);

        $event = $eventContainer->getEventByDefinition($definition);
        if (!$event) {
            throw new NoEntityClonedException($entity, $id);
        }

        $ids = $event->getIds();
        $newId = array_shift($ids);

        return new JsonResponse(['id' => $newId]);
    }

    public function detail(Request $request, Context $context, ResponseFactoryInterface $responseFactory, string $entityName, string $path): Response
    {
        $pathSegments = $this->buildEntityPath($entityName, $path);

        $root = $pathSegments[0]['entity'];
        $id = $pathSegments[\count($pathSegments) - 1]['value'];

        $definition = $this->definitionRegistry->get($root);

        $associations = array_column($pathSegments, 'entity');
        array_shift($associations);

        if (empty($associations)) {
            $repository = $this->getRepository($definition, $request);
        } else {
            /** @var EntityDefinition $definition */
            $field = $this->getAssociation($definition::getFields(), $associations);

            $definition = $field->getReferenceClass();
            if ($field instanceof ManyToManyAssociationField) {
                $definition = $field->getReferenceDefinition();
            }

            $repository = $this->getRepository($definition, $request);
        }

        /** @var RepositoryInterface $repository */
        $entities = $repository->read(new ReadCriteria([$id]), $context);
        $entity = $entities->get($id);

        if ($entity === null) {
            throw new ResourceNotFoundException($definition::getEntityName(), ['id' => $id]);
        }

        return $responseFactory->createDetailResponse($entity, $definition, $request, $context);
    }

    public function search(Request $request, Context $context, ResponseFactoryInterface $responseFactory, string $entityName, string $path): Response
    {
        $result = $this->fetchListing($request, $context, $entityName, $path);

        $definition = $this->getDefinitionOfPath($entityName, $path);

        return $responseFactory->createListingResponse($result, $definition, $request, $context);
    }

    public function list(Request $request, Context $context, ResponseFactoryInterface $responseFactory, string $entityName, string $path): Response
    {
        $result = $this->fetchListing($request, $context, $entityName, $path);

        $definition = $this->getDefinitionOfPath($entityName, $path);

        return $responseFactory->createListingResponse($result, $definition, $request, $context);
    }

    public function create(Request $request, Context $context, ResponseFactoryInterface $responseFactory, string $entityName, string $path): Response
    {
        if (!$this->hasScope($request, WriteScope::IDENTIFIER)) {
            throw new AccessDeniedHttpException('You don\'t have write access using this access key.');
        }

        return $this->write($request, $context, $responseFactory, $entityName, $path, self::WRITE_CREATE);
    }

    public function update(Request $request, Context $context, ResponseFactoryInterface $responseFactory, string $entityName, string $path): Response
    {
        if (!$this->hasScope($request, WriteScope::IDENTIFIER)) {
            throw new AccessDeniedHttpException('You don\'t have write access using this access key.');
        }

        return $this->write($request, $context, $responseFactory, $entityName, $path, self::WRITE_UPDATE);
    }

    public function delete(Request $request, Context $context, ResponseFactoryInterface $responseFactory, string $entityName, string $path): Response
    {
        if (!$this->hasScope($request, WriteScope::IDENTIFIER)) {
            throw new AccessDeniedHttpException('You don\'t have write access using this access key.');
        }

        $pathSegments = $this->buildEntityPath($entityName, $path);

        $last = $pathSegments[\count($pathSegments) - 1];

        $id = $last['value'];

        $first = array_shift($pathSegments);

        /* @var string|EntityDefinition $definition */
        if (\count($pathSegments) === 0) {
            //first api level call /product/{id}
            $definition = $first['definition'];

            $this->doDelete($request, $context, $definition, ['id' => $id]);

            return $responseFactory->createRedirectResponse($definition, $id, $request, $context);
        }

        $child = array_pop($pathSegments);
        $parent = $first;
        if (!empty($pathSegments)) {
            $parent = array_pop($pathSegments);
        }

        $definition = $child['definition'];

        /** @var AssociationInterface $association */
        $association = $child['field'];

        // DELETE api/product/{id}/manufacturer/{id}
        if ($association instanceof ManyToOneAssociationField) {
            $this->doDelete($request, $context, $definition, ['id' => $id]);

            return $responseFactory->createRedirectResponse($definition, $id, $request, $context);
        }

        // DELETE api/product/{id}/category/{id}
        if ($association instanceof ManyToManyAssociationField) {
            $local = $definition::getFields()->getByStorageName(
                $association->getMappingLocalColumn()
            );

            $reference = $definition::getFields()->getByStorageName(
                $association->getMappingReferenceColumn()
            );

            $mapping = [
                $local->getPropertyName() => $parent['value'],
                $reference->getPropertyName() => $id,
            ];

            $this->doDelete($request, $context, $definition, $mapping);

            return $responseFactory->createRedirectResponse($definition, $id, $request, $context);
        }

        if ($association instanceof TranslationsAssociationField) {
            /** @var EntityTranslationDefinition $refClass */
            $refClass = $association->getReferenceClass();

            $refPropName = $refClass::getFields()->getByStorageName($association->getReferenceField())->getPropertyName();
            $refLanguagePropName = $refClass::getPrimaryKeys()->getByStorageName($association->getLanguageField())->getPropertyName();

            $mapping = [
                $refPropName => $parent['value'],
                $refLanguagePropName => $id,
            ];

            $this->doDelete($request, $context, $definition, $mapping);

            return $responseFactory->createRedirectResponse($definition, $id, $request, $context);
        }

        if ($association instanceof OneToManyAssociationField) {
            $this->doDelete($request, $context, $definition, ['id' => $id]);

            return $responseFactory->createRedirectResponse($definition, $id, $request, $context);
        }

        throw new \RuntimeException(sprintf('Unsupported association for field %s', $association->getPropertyName()));
    }

    private function fetchListing(Request $request, Context $context, string $entityName, string $path): EntitySearchResult
    {
        $pathSegments = $this->buildEntityPath($entityName, $path);

        $first = array_shift($pathSegments);

        /** @var EntityDefinition|string $definition */
        $definition = $first['definition'];

        if (!$definition) {
            throw new NotFoundHttpException('The requested entity does not exist.');
        }

        $repository = $this->getRepository($definition, $request);

        $criteria = new Criteria();
        if (empty($pathSegments)) {
            $criteria = $this->searchCriteriaBuilder->handleRequest($request, $criteria, $definition, $context);

            return $repository->search($criteria, $context);
        }

        $child = array_pop($pathSegments);
        $parent = $first;

        if (!empty($pathSegments)) {
            $parent = array_pop($pathSegments);
        }

        $association = $child['field'];

        $parentDefinition = $parent['definition'];

        $definition = $child['definition'];
        if ($association instanceof ManyToManyAssociationField) {
            $definition = $association->getReferenceDefinition();
        }

        $criteria = $this->searchCriteriaBuilder->handleRequest($request, $criteria, $definition, $context);

        if ($association instanceof ManyToManyAssociationField) {
            //fetch inverse association definition for filter
            $reverse = $definition::getFields()->filter(
                function (Field $field) use ($association) {
                    return $field instanceof ManyToManyAssociationField && $association->getMappingDefinition() === $field->getMappingDefinition();
                }
            );

            //contains now the inverse side association: category.products
            $reverse = $reverse->first();

            /* @var ManyToManyAssociationField $reverse */
            $criteria->addFilter(
                new EqualsFilter(
                    sprintf('%s.%s.id', $definition::getEntityName(), $reverse->getPropertyName()),
                    $parent['value']
                )
            );
        } elseif ($association instanceof OneToManyAssociationField) {
            /*
             * Example
             * Route:           /api/product/SW1/prices
             * $definition:     \Shopware\Core\Content\Product\Definition\ProductPriceDefinition
             */

            //get foreign key definition of reference
            $foreignKey = $definition::getFields()->getByStorageName(
                $association->getReferenceField()
            );

            $criteria->addFilter(
                new EqualsFilter(
                //add filter to parent value: prices.productId = SW1
                    $definition::getEntityName() . '.' . $foreignKey->getPropertyName(),
                    $parent['value']
                )
            );
        } elseif ($association instanceof ManyToOneAssociationField) {
            /*
             * Example
             * Route:           /api/product/SW1/manufacturer
             * $definition:     \Shopware\Core\Content\Product\Aggregate\ProductManufacturer\ProductManufacturerDefinition
             */

            //get inverse association to filter to parent value
            $reverse = $definition::getFields()->filter(
                function (Field $field) use ($parentDefinition) {
                    return $field instanceof OneToManyAssociationField && $parentDefinition === $field->getReferenceClass();
                }
            );
            $reverse = $reverse->first();

            /* @var OneToManyAssociationField $reverse */
            $criteria->addFilter(
                new EqualsFilter(
                //filter inverse association to parent value:  manufacturer.products.id = SW1
                    sprintf('%s.%s.id', $definition::getEntityName(), $reverse->getPropertyName()),
                    $parent['value']
                )
            );
        }

        $repository = $this->getRepository($definition, $request);

        return $repository->search($criteria, $context);
    }

    private function getDefinitionOfPath(string $entityName, string $path): string
    {
        $pathSegments = $this->buildEntityPath($entityName, $path);

        $first = array_shift($pathSegments);

        /** @var EntityDefinition|string $definition */
        $definition = $first['definition'];

        if (empty($pathSegments)) {
            return $definition;
        }

        $child = array_pop($pathSegments);

        $association = $child['field'];

        if ($association instanceof ManyToManyAssociationField) {
            /*
             * Example:
             * route:           /api/product/SW1/categories
             * $definition:     \Shopware\Core\Content\Category\CategoryDefinition
             */
            return $association->getReferenceDefinition();
        }

        return $child['definition'];
    }

    private function write(Request $request, Context $context, ResponseFactoryInterface $responseFactory, string $entityName, string $path, string $type): Response
    {
        $payload = $this->getRequestBody($request);
        $noContent = !$request->query->has('_response');
        // safari bug prevents us from using the location header
        $appendLocationHeader = false;

        if ($this->isCollection($payload)) {
            throw new BadRequestHttpException('Only single write operations are supported. Please send the entities one by one or use the /sync api endpoint.');
        }

        $pathSegments = $this->buildEntityPath($entityName, $path);

        $last = $pathSegments[\count($pathSegments) - 1];

        if ($type === self::WRITE_CREATE && !empty($last['value'])) {
            $methods = ['GET', 'PATCH', 'DELETE'];
            throw new MethodNotAllowedHttpException($methods, sprintf('No route found for "%s %s": Method Not Allowed (Allow: %s)', $request->getMethod(), $request->getPathInfo(), implode(', ', $methods)));
        }

        if ($type === self::WRITE_UPDATE && isset($last['value'])) {
            $payload['id'] = $last['value'];
        }

        $first = array_shift($pathSegments);

        /* @var string|EntityDefinition $definition */
        if (\count($pathSegments) === 0) {
            $definition = $first['definition'];

            $repository = $this->getRepository($definition, $request);

            $events = $this->executeWriteOperation($repository, $payload, $context, $type);
            $event = $events->getEventByDefinition($definition);
            $eventIds = $event->getIds();
            $entityId = array_pop($eventIds);

            if ($noContent) {
                return $responseFactory->createRedirectResponse($definition, $entityId, $request, $context);
            }

            $entities = $repository->read(new ReadCriteria($event->getIds()), $context);

            return $responseFactory->createDetailResponse($entities->first(), $definition, $request, $context, $appendLocationHeader);
        }

        $child = array_pop($pathSegments);

        $parent = $first;
        if (!empty($pathSegments)) {
            $parent = array_pop($pathSegments);
        }

        $definition = $child['definition'];

        $association = $child['field'];

        /** @var EntityDefinition|string $parentDefinition */
        $parentDefinition = $parent['definition'];

        /* @var Entity $entity */
        if ($association instanceof OneToManyAssociationField) {
            $foreignKey = $definition::getFields()
                ->getByStorageName($association->getReferenceField());

            $payload[$foreignKey->getPropertyName()] = $parent['value'];

            $repository = $this->getRepository($definition, $request);
            $events = $this->executeWriteOperation($repository, $payload, $context, $type);

            if ($noContent) {
                return $responseFactory->createRedirectResponse($definition, $parent['value'], $request, $context);
            }

            $event = $events->getEventByDefinition($definition);

            $repository = $this->getRepository($definition, $request);

            $entities = $repository->read(new ReadCriteria($event->getIds()), $context);

            return $responseFactory->createDetailResponse($entities->first(), $definition, $request, $context, $appendLocationHeader);
        }

        if ($association instanceof ManyToOneAssociationField) {
            $repository = $this->getRepository($definition, $request);
            $events = $this->executeWriteOperation($repository, $payload, $context, $type);
            $event = $events->getEventByDefinition($definition);

            $entityIds = $event->getIds();
            $entityId = array_pop($entityIds);

            $foreignKey = $parentDefinition::getFields()->getByStorageName($association->getStorageName());

            $payload = [
                'id' => $parent['value'],
                $foreignKey->getPropertyName() => $entityId,
            ];

            $repository = $this->getRepository($parentDefinition, $request);
            $repository->update([$payload], $context);

            if ($noContent) {
                return $responseFactory->createRedirectResponse($definition, $entityId, $request, $context);
            }

            $entities = $repository->read(new ReadCriteria($event->getIds()), $context);

            return $responseFactory->createDetailResponse($entities->first(), $definition, $request, $context, $appendLocationHeader);
        }

        /** @var ManyToManyAssociationField $association */

        /** @var EntityDefinition|string $reference */
        $reference = $association->getReferenceDefinition();

        $repository = $this->getRepository($reference, $request);
        $events = $this->executeWriteOperation($repository, $payload, $context, $type);
        $event = $events->getEventByDefinition($reference);

        $repository = $this->getRepository($reference, $request);

        $entities = $repository->read(new ReadCriteria($event->getIds()), $context);

        $entity = $entities->first();

        $repository = $this->getRepository($parentDefinition, $request);

        $payload = [
            'id' => $parent['value'],
            $association->getPropertyName() => [
                ['id' => $entity->getId()],
            ],
        ];

        $repository->update([$payload], $context);

        if ($noContent) {
            return $responseFactory->createRedirectResponse($reference, $entity->getId(), $request, $context);
        }

        return $responseFactory->createDetailResponse($entity, $definition, $request, $context, $appendLocationHeader);
    }

    /**
     * @param RepositoryInterface $repository
     * @param array               $payload
     * @param Context             $context
     * @param string              $type
     *
     * @return EntityWrittenContainerEvent
     */
    private function executeWriteOperation(RepositoryInterface $repository, array $payload, Context $context, string $type): EntityWrittenContainerEvent
    {
        if ($type === self::WRITE_CREATE) {
            return $repository->create([$payload], $context);
        }

        if ($type === self::WRITE_UPDATE) {
            return $repository->update([$payload], $context);
        }

        throw new \RuntimeException('Unsupported write operation.');
    }

    private function getAssociation(FieldCollection $fields, array $keys): AssociationInterface
    {
        $key = array_shift($keys);

        /** @var AssociationInterface $field */
        $field = $fields->get($key);

        if (empty($keys)) {
            return $field;
        }

        /** @var string|EntityDefinition $reference */
        $reference = $field->getReferenceClass();

        $nested = $reference::getFields();

        return $this->getAssociation($nested, $keys);
    }

    private function buildEntityPath(string $entityName, string $pathInfo): array
    {
        $exploded = explode('/', $entityName . '/' . ltrim($pathInfo, '/'));

        $parts = [];
        foreach ($exploded as $index => $part) {
            if ($index % 2) {
                continue;
            }
            if (empty($part)) {
                continue;
            }
            $value = null;
            if (isset($exploded[$index + 1])) {
                $value = $exploded[$index + 1];
            }

            if (empty($parts)) {
                $part = $this->urlToSnakeCase($part);
            } else {
                $part = $this->urlToCamelCase($part);
            }

            $parts[] = [
                'entity' => $part,
                'value' => $value,
            ];
        }

        $parts = array_filter($parts);
        $first = array_shift($parts);

        try {
            $root = $this->definitionRegistry->get($first['entity']);
        } catch (DefinitionNotFoundException $e) {
            throw new NotFoundHttpException($e->getMessage(), $e);
        }

        $entities = [
            [
                'entity' => $first['entity'],
                'value' => $first['value'],
                'definition' => $root,
                'field' => null,
            ],
        ];

        foreach ($parts as $part) {
            $fields = $root::getFields();

            /** @var AssociationInterface|null $field */
            $field = $fields->get($part['entity']);
            if (!$field) {
                $path = implode('.', array_column($entities, 'entity')) . '.' . $part['entity'];
                throw new NotFoundHttpException(sprintf('Resource at path "%s" is not an existing relation.', $path));
            }

            if ($field instanceof ManyToManyAssociationField) {
                $root = $field->getReferenceDefinition();
            } else {
                $root = $field->getReferenceClass();
            }

            $entities[] = [
                'entity' => $part['entity'],
                'value' => $part['value'],
                'definition' => $field->getReferenceClass(),
                'field' => $field,
            ];
        }

        return $entities;
    }

    private function urlToSnakeCase(string $name): string
    {
        return str_replace('-', '_', $name);
    }

    private function urlToCamelCase(string $name): string
    {
        $parts = explode('-', $name);
        $parts = array_map('ucfirst', $parts);

        return lcfirst(implode('', $parts));
    }

    /**
     * Return a nested array structure of based on the content-type
     *
     * @param Request $request
     *
     * @return array
     */
    private function getRequestBody(Request $request): array
    {
        $contentType = $request->headers->get('CONTENT_TYPE', '');
        $semicolonPosition = strpos($contentType, ';');

        if ($semicolonPosition !== false) {
            $contentType = substr($contentType, 0, $semicolonPosition);
        }

        try {
            switch ($contentType) {
                case 'application/vnd.api+json':
                    return $this->serializer->decode($request->getContent(), 'jsonapi');
                case 'application/json':
                    return $request->request->all();
            }
        } catch (InvalidArgumentException | UnexpectedValueException $exception) {
            throw new BadRequestHttpException($exception->getMessage());
        }

        throw new UnsupportedMediaTypeHttpException(sprintf('The Content-Type "%s" is unsupported.', $contentType));
    }

    private function isCollection(array $array): bool
    {
        return array_keys($array) === range(0, \count($array) - 1);
    }

    /**
     * @param Request                 $request
     * @param Context                 $context
     * @param string|EntityDefinition $definition
     * @param array                   $primaryKey
     *
     * @throws ResourceNotFoundException
     */
    private function doDelete(Request $request, Context $context, string $definition, array $primaryKey): void
    {
        try {
            $repository = $this->getRepository($definition, $request);
        } catch (UnknownRepositoryVersionException $e) {
            throw new NotFoundHttpException($e->getMessage(), $e);
        }

        $deleteEvent = $repository->delete([$primaryKey], $context);

        if (empty($deleteEvent->getErrors())) {
            return;
        }

        throw new ResourceNotFoundException($definition::getEntityName(), $primaryKey);
    }

    /**
     * @param string|EntityDefinition $definition
     * @param Request                 $request
     *
     * @throws UnknownRepositoryVersionException
     *
     * @return RepositoryInterface
     */
    private function getRepository(string $definition, Request $request): RepositoryInterface
    {
        $repositoryClass = $definition::getEntityName() . '.repository';

        if ($this->has($repositoryClass) === false) {
            throw new UnknownRepositoryVersionException($definition::getEntityName(), (int) $request->get('version'));
        }

        /** @var RepositoryInterface $repo */
        $repo = $this->get($definition::getEntityName() . '.repository');

        return $repo;
    }

    private function hasScope(Request $request, string $scopeIdentifier): bool
    {
        $scopes = array_flip($request->attributes->get(PlatformRequest::ATTRIBUTE_OAUTH_SCOPES));

        return isset($scopes[$scopeIdentifier]);
    }
}
