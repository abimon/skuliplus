<?php

namespace App\Services;

use App\Http\Controllers\Api\AcademicsController;
use App\Http\Controllers\Api\OperationsController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Replays mutations that the mobile app captured while it had no connectivity.
 *
 * Rather than duplicating the business rules, each queued action is routed
 * back through the same API controller method the online client would call,
 * so validation, authorisation and side effects stay identical.
 */
class PendingOperationHandler
{
    /**
     * Map a queued action to the controller method that performs it.
     *
     * @var array<string, array{0: class-string, 1: string}>
     */
    private const ACTIONS = [
        'check_in_visitor' => [OperationsController::class, 'checkInVisitor'],
        'checkout_visitor' => [OperationsController::class, 'checkoutVisitor'],
        'borrow_book' => [OperationsController::class, 'borrowBook'],
        'return_book' => [OperationsController::class, 'returnBook'],
        'move_store_stock' => [OperationsController::class, 'moveStoreStock'],
        'move_lab_stock' => [OperationsController::class, 'moveLabStock'],
        'store_result' => [AcademicsController::class, 'storeResult'],
    ];

    /**
     * @return array{id: string, action: string, status: string, data?: mixed, message?: string}
     */
    public function handle(Request $request, string $operationId, string $action, array $payload): array
    {
        try {
            $response = $this->dispatch($request, $action, $payload);
        } catch (ValidationException $exception) {
            // The record will never succeed as submitted, so the client should
            // drop it rather than retry forever.
            return $this->rejected($operationId, $action, $exception->validator->errors()->first() ?: 'The record is invalid.');
        } catch (Throwable $exception) {
            Log::warning('Queued mobile operation failed.', [
                'operation_id' => $operationId,
                'action' => $action,
                'exception' => $exception->getMessage(),
            ]);

            return $this->retry($operationId, $action, $exception->getMessage());
        }

        return [
            'id' => $operationId,
            'action' => $action,
            'status' => 'applied',
            'data' => $response instanceof JsonResponse ? $response->getData(true)['data'] ?? null : null,
        ];
    }

    private function dispatch(Request $request, string $action, array $payload): mixed
    {
        [$controllerClass, $method] = self::ACTIONS[$action] ?? [null, null];

        abort_unless($controllerClass, 422, "Unsupported queued action [{$action}].");

        /** @var Request $synthetic */
        $synthetic = Request::createFrom($request);
        $synthetic->setJson(new \Symfony\Component\HttpFoundation\ParameterBag($payload));
        $synthetic->request->replace($payload);
        $synthetic->headers->set('Content-Type', 'application/json');

        // Some endpoints bind a route model; resolve it from the payload so a
        // queued "return_book" keeps pointing at the right loan.
        $synthetic->setRouteResolver(fn () => $this->routeFor($synthetic, $action, $payload));

        return app($controllerClass)->{$method}($synthetic, ...$this->extraArguments($action, $payload));
    }

    /**
     * @return array<int, mixed>
     */
    private function extraArguments(string $action, array $payload): array
    {
        return match ($action) {
            'return_book' => [$payload['loan'] ?? null],
            default => [],
        };
    }

    private function routeFor(Request $request, string $action, array $payload): \Illuminate\Routing\Route
    {
        $parameters = $action === 'return_book' ? ['loan' => $payload['loan'] ?? null] : [];

        return new \Illuminate\Routing\Route(
            ['POST'],
            'api/sync/push',
            $parameters + ['action' => $action],
        );
    }

    private function rejected(string $id, string $action, string $message): array
    {
        return ['id' => $id, 'action' => $action, 'status' => 'rejected', 'message' => $message];
    }

    private function retry(string $id, string $action, string $message): array
    {
        return ['id' => $id, 'action' => $action, 'status' => 'retry', 'message' => $message];
    }
}