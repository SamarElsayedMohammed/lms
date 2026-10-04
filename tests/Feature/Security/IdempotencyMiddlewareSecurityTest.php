<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Http\Middleware\IdempotencyMiddleware;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class IdempotencyMiddlewareSecurityTest extends TestCase
{
    use RefreshDatabase;

    private IdempotencyMiddleware $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = new IdempotencyMiddleware();
        Cache::flush();
    }

    public function test_missing_key_in_required_mode_returns_400(): void
    {
        $request = Request::create('/api/wallet/top-up', 'POST');

        $response = $this->middleware->handle($request, function () {
            return new Response('should not run');
        }, 'replay', 'required');

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('Idempotency-Key header is required', $response->getContent());
    }

    public function test_missing_key_in_optional_mode_allows_request_to_proceed(): void
    {
        $request = Request::create('/api/wallet/withdrawal-request', 'POST');
        $executed = false;

        $response = $this->middleware->handle($request, function () use (&$executed) {
            $executed = true;
            return response()->json(['success' => true, 'message' => 'Processed without key']);
        }, 'replay', 'optional');

        $this->assertTrue($executed);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Processed without key', $response->getContent());
    }

    public function test_same_key_replayed_returns_cached_response_without_re_executing(): void
    {
        $user = User::factory()->create();
        $request = Request::create('/api/wallet/withdrawal-request', 'POST');
        $request->setUserResolver(fn () => $user);
        $request->headers->set('Idempotency-Key', 'unique-withdraw-key-123');

        $executionCount = 0;
        $handler = function () use (&$executionCount) {
            $executionCount++;
            return response()->json(['success' => true, 'request_id' => $executionCount]);
        };

        // First execution
        $response1 = $this->middleware->handle($request, $handler, 'replay', 'optional');
        $this->assertSame(200, $response1->getStatusCode());
        $this->assertSame(1, $executionCount);
        $this->assertFalse($response1->headers->has('Idempotent-Replay'));

        // Second execution with same key (replay)
        $response2 = $this->middleware->handle($request, $handler, 'replay', 'optional');
        $this->assertSame(200, $response2->getStatusCode());
        $this->assertSame(1, $executionCount); // Must NOT re-execute handler!
        $this->assertTrue($response2->headers->has('Idempotent-Replay'));
        $this->assertSame('{"success":true,"request_id":1}', $response2->getContent());
    }

    public function test_different_users_with_same_key_are_scoped_independently(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $requestA = Request::create('/api/wallet/withdrawal-request', 'POST');
        $requestA->setUserResolver(fn () => $userA);
        $requestA->headers->set('Idempotency-Key', 'shared-client-uuid-999');

        $requestB = Request::create('/api/wallet/withdrawal-request', 'POST');
        $requestB->setUserResolver(fn () => $userB);
        $requestB->headers->set('Idempotency-Key', 'shared-client-uuid-999');

        $executions = [];

        $this->middleware->handle($requestA, function () use (&$executions) {
            $executions[] = 'userA';
            return response()->json(['user' => 'A']);
        }, 'replay', 'optional');

        $this->middleware->handle($requestB, function () use (&$executions) {
            $executions[] = 'userB';
            return response()->json(['user' => 'B']);
        }, 'replay', 'optional');

        // Both users must execute independently because key is scoped to user_id
        $this->assertCount(2, $executions);
        $this->assertSame(['userA', 'userB'], $executions);
    }

    public function test_concurrent_request_with_same_key_detected_as_conflict(): void
    {
        $user = User::factory()->create();
        $request = Request::create('/api/wallet/withdrawal-request', 'POST');
        $request->setUserResolver(fn () => $user);
        $request->headers->set('Idempotency-Key', 'concurrent-key-777');

        // Simulate an in-flight request already marked as processing in cache
        $cacheKey = 'idempotency:' . hash('sha256', implode('|', [
            (string) $user->id,
            'POST',
            'api/wallet/withdrawal-request',
            'concurrent-key-777',
        ]));
        Cache::put($cacheKey, ['state' => 'processing'], now()->addMinutes(2));

        $response = $this->middleware->handle($request, function () {
            return response()->json(['success' => true]);
        }, 'replay', 'optional');

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('IDEMPOTENCY_CONFLICT', $response->getData(true)['reason'] ?? null);
    }
}
