<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Debt;
use App\Traits\SoftDeletableTogglerController;
use Doctrine\Persistence\ManagerRegistry;
use FOS\RestBundle\Controller\AbstractFOSRestController;
use FOS\RestBundle\Controller\Annotations as Rest;
use FOS\RestBundle\View\View;
use OpenApi\Attributes as OA;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/v2/debts', name: 'api_v2_debts_')]
#[OA\Tag(name: 'Debt')]
class DebtController extends AbstractFOSRestController
{
    use SoftDeletableTogglerController;

    #[Rest\View(serializerGroups: ['debt:collection:read'])]
    #[Rest\QueryParam(name: 'withClosed', default: false, description: 'Should fetch debts that were closed too', nullable: true, allowBlank: false)]
    #[Route('', name: 'collection_read', methods: ['get'])]
    #[OA\Get(
        path: '/api/v2/debts',
        summary: 'List debts',
        description: 'Returns all debts for the authenticated user. By default only open (non-closed) debts are returned.',
        security: [['bearerAuth' => []]],
        tags: ['Debt'],
        parameters: [
            new OA\Parameter(
                name: 'withClosed',
                in: 'query',
                required: false,
                description: 'Include closed debts in the response',
                schema: new OA\Schema(type: 'boolean', default: false),
            ),
        ],
        responses: [
            new OA\Response(response: 200, description: 'List of debts'),
            new OA\Response(response: 401, description: 'Unauthorized'),
        ],
    )]
    /**
     * @see \App\Tests\Controller\DebtCrudTest
     *
     * @tested testListDebts_returnsCorrectShape
     * @tested testListDebts_fixtureValues
     * @tested testListDebts_withClosedFalse_excludesClosedDebts
     * @tested testListDebts_withClosedTrue_includesClosedDebts
     * @tested testListDebts_withoutAuth_returns401
     * @tested testListDebts_convertedValuesPresent
     * @tested testApiPlatformDebtList_alsoWorks
     */
    public function list(ManagerRegistry $doctrine, bool $withClosed = false): View
    {
        if ($withClosed) {
            $this->disableSoftDeletable();
        }

        return $this->view(
            $doctrine->getRepository(Debt::class)->findAll(),
        );
    }
}
