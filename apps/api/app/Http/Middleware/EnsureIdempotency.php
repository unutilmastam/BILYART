<?php

namespace App\Http\Middleware;

use App\Domain\Auth\CurrentPrincipal;
use App\Domain\Idempotency\Models\IdempotencyKey;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * `idempotency` / `idempotency:required` (spec §31). The first request with a
 * key is executed and its response stored; a retry with the same key and the
 * same body gets the stored response (header Idempotent-Replayed: true); the
 * same key with a different body → 409. 5xx responses are not stored so the
 * client may retry. Keys are scoped per principal and pruned after 48 h.
 */
final class EnsureIdempotency
{
    public const HEADER = 'Idempotency-Key';

    public function __construct(private readonly CurrentPrincipal $principal) {}

    public function handle(Request $request, Closure $next, string $mode = 'optional'): Response
    {
        $key = $request->headers->get(self::HEADER);
        $principal = $this->principal->get();

        if ($key === null || $key === '') {
            if ($mode === 'required') {
                throw ApiException::of(ErrorCode::IDEMPOTENCY_KEY_REQUIRED);
            }

            return $next($request);
        }
        if (! preg_match('/^[A-Za-z0-9_-]{16,64}$/', $key) || $principal === null) {
            throw ApiException::of(ErrorCode::IDEMPOTENCY_KEY_REQUIRED);
        }

        $identity = [
            'principal_type' => $principal->type->value,
            'principal_id' => $principal->id,
            'key' => $key,
        ];
        $route = substr($request->method().' '.$request->path(), 0, 191);
        $hash = $this->requestHash($request);

        // insertOrIgnore = ON CONFLICT DO NOTHING / INSERT IGNORE: never aborts an outer PostgreSQL transaction.
        $inserted = DB::table('idempotency_keys')->insertOrIgnore($identity + [
            'route' => $route,
            'request_hash' => $hash,
            'created_at' => now(),
        ]);

        if ($inserted === 0) {
            return $this->replay(IdempotencyKey::query()->where($identity)->firstOrFail(), $route, $hash);
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            $this->storeOrRelease($identity, $this->renderForStorage($e, $request));
            throw $e;
        }

        $this->storeOrRelease($identity, $response);

        return $response;
    }

    private function replay(IdempotencyKey $stored, string $route, string $hash): Response
    {
        if ($stored->route !== $route || ! hash_equals($stored->request_hash, $hash)) {
            throw ApiException::of(ErrorCode::IDEMPOTENCY_KEY_REUSED);
        }
        if ($stored->response_code === null) {
            throw ApiException::of(ErrorCode::IDEMPOTENCY_IN_PROGRESS);
        }

        return new JsonResponse(
            json_decode((string) $stored->response_body, true),
            $stored->response_code,
            ['Idempotent-Replayed' => 'true'],
        );
    }

    private function storeOrRelease(array $identity, ?Response $response): void
    {
        $query = DB::table('idempotency_keys')->where($identity);
        if ($response === null || $response->getStatusCode() >= 500 || ! $this->isJson($response)) {
            $query->delete();

            return;
        }
        $query->update([
            'response_code' => $response->getStatusCode(),
            'response_body' => $response->getContent(),
        ]);
    }

    private function renderForStorage(\Throwable $e, Request $request): ?Response
    {
        return $e instanceof ApiException ? app(ExceptionHandler::class)->render($request, $e) : null;
    }

    private function isJson(Response $response): bool
    {
        return str_contains((string) $response->headers->get('Content-Type'), 'json');
    }

    private function requestHash(Request $request): string
    {
        $parts = [$request->method(), $request->path()];
        if ($request->files->count() > 0) {
            $parts[] = json_encode($request->except(array_keys($request->allFiles())));
            foreach ($request->allFiles() as $name => $file) {
                foreach (is_array($file) ? $file : [$file] as $f) {
                    /** @var UploadedFile $f */
                    $parts[] = $name.':'.hash_file('sha256', $f->getRealPath());
                }
            }
        } else {
            $parts[] = $request->getContent();
        }

        return hash('sha256', implode("\n", $parts));
    }
}
