<?php

namespace App\ApiPlatform\Action;

use JsonException;
use App\Entity\Debt;
use App\Entity\Account;
use App\Entity\Expense;
use App\Entity\Income;
use App\Entity\Category;
use App\Entity\Transaction;
use App\Service\AssetsManager;
use Doctrine\ORM\EntityManagerInterface;
use ApiPlatform\Api\IriConverterInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

#[AsController]
final readonly class TransactionBulkCreateAction
{
    public function __construct(
        private EntityManagerInterface $em,
        private SerializerInterface&DenormalizerInterface $serializer,
        private ValidatorInterface $validator,
        private AssetsManager $assetsManager,
        private IriConverterInterface $iriConverter,
    ) {
    }

    /**
     * @throws ExceptionInterface
     */
    public function __invoke(Request $request): Response
    {
        $raw = $request->getContent();

        try {
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new BadRequestHttpException('Invalid JSON body');
        }

        if (!is_array($payload)) {
            throw new BadRequestHttpException('Expected JSON array of transactions');
        }

        $transactions = [];
        $errors = [];

        foreach ($payload as $index => $item) {
            if (!is_array($item)) {
                $errors[$index][] = 'Each item must be an object';
                continue;
            }

            $class = $this->resolveClassFromType($item['type'] ?? null);
            if ($class === null) {
                $errors[$index][] = 'Invalid or missing "type" (expected "expense" or "income")';
                continue;
            }

            /*
             * DIRTY FIX
             * Frontend sends numeric IDs for relations but API Platform expects IRI.
             * Convert IDs → IRI using IriConverter to ensure correct URI prefix.
             *
             * TODO: replace with proper PlainIdentifierDenormalizer or DTO mapping.
             */

            try {
                if (isset($item['account']) && is_int($item['account'])) {
                    $ref = $this->em->getReference(Account::class, $item['account']);
                    $item['account'] = $this->iriConverter->getIriFromResource($ref);
                }

                if (isset($item['category']) && is_int($item['category'])) {
                    $ref = $this->em->getReference(Category::class, $item['category']);
                    $item['category'] = $this->iriConverter->getIriFromResource($ref);
                }

                if (isset($item['debt']) && is_int($item['debt'])) {
                    $ref = $this->em->getReference(Debt::class, $item['debt']);
                    $item['debt'] = $this->iriConverter->getIriFromResource($ref);
                }
            } catch (\Throwable $e) {
                $errors[$index][] = 'Failed to resolve relation IRI: '.$e->getMessage();
                continue;
            }

            /** @var Transaction $transaction */
            $transaction = $this->serializer->denormalize(
                $item,
                $class,
                'json',
                [
                    'groups' => ['transaction:write'],
                ]
            );

            $violations = $this->validator->validate($transaction);

            if (count($violations) > 0) {
                /** @var ConstraintViolationInterface $violation */
                foreach ($violations as $violation) {
                    $errors[$index][] = sprintf(
                        '%s: %s',
                        $violation->getPropertyPath(),
                        $violation->getMessage()
                    );
                }
                continue;
            }

            // At this point the transaction is valid. Now resolve and apply converted values.
            try {
                $convertedValues = $this->assetsManager->convert($transaction);
            } catch (\Throwable $e) {
                $errors[$index][] = sprintf(
                    'Failed to resolve exchange rates: %s',
                    $e->getMessage()
                );
                continue;
            }

            $transaction->setConvertedValues($convertedValues);

            $this->em->persist($transaction);
            $transactions[] = $transaction;
        }

        if ($errors) {
            return new JsonResponse(
                [
                    'detail' => 'Validation or conversion failed for one or more items',
                    'errors' => $errors,
                ],
                Response::HTTP_BAD_REQUEST
            );
        }

        if (!$transactions) {
            return new JsonResponse(
                ['detail' => 'No valid items to persist'],
                Response::HTTP_BAD_REQUEST
            );
        }

        $this->em->flush();

        $json = $this->serializer->serialize(
            $transactions,
            'json',
            ['groups' => ['transaction:collection:read']]
        );

        return new JsonResponse($json, Response::HTTP_CREATED, [], true);
    }

    private function resolveClassFromType(?string $type): ?string
    {
        return match ($type) {
            Transaction::EXPENSE => Expense::class,
            Transaction::INCOME => Income::class,
            default => null,
        };
    }
}