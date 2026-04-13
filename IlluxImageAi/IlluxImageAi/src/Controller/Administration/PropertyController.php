<?php declare(strict_types=1);

namespace Illux\ImageAi\Controller\Administration;

use Illux\ImageAi\Service\Property\PropertyMutationService;
use Illux\ImageAi\Trait\ControllerResponseTrait;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Throwable;

#[Route(defaults: ['_routeScope' => ['api']])]
class PropertyController extends AbstractController
{
    use ControllerResponseTrait;

    public function __construct(
        private readonly PropertyMutationService $propertyMutationService,
        private readonly LoggerInterface $logger
    ) {
    }

    protected function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    /**
     * Create a new AI-managed property group with options.
     *
     * Expected request body:
     * {
     *   "name": "Style",
     *   "displayType": "text",
     *   "sortingType": "alphanumeric",
     *   "filterable": true,
     *   "visibleOnProductDetailPage": true,
     *   "position": 1,
     *   "translations": {
     *     "<languageId>": { "name": "Translated Name" }
     *   },
     *   "options": [
     *     {
     *       "name": "Modern",
     *       "translations": {
     *         "<languageId>": { "name": "Translated Option" }
     *       }
     *     }
     *   ]
     * }
     */
    #[Route(
        path: '/api/_action/illux-ai-tools/property-group',
        name: 'api.action.illux_ai_tools.create_property_group',
        methods: ['POST']
    )]
    public function createPropertyGroup(Request $request, Context $context): JsonResponse
    {
        try {
            $data = $this->getJsonBody($request);
            $name = $this->requireStringParam($data, 'name');

            /** @var array{name: string, displayType?: string, sortingType?: string, filterable?: bool, visibleOnProductDetailPage?: bool, position?: int, translations?: array<string, array{name: string}>, options?: array<array{name: string, translations?: array<string, array{name: string}>}>} $data */
            $groupId = $this->propertyMutationService->createPropertyGroup($data, $context);

            return $this->successResponse(['groupId' => $groupId]);
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (Throwable $e) {
            return $this->handleException($e, 'Property:CreateGroup');
        }
    }
}
