<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Platform\Models\FirmwareRelease;
use App\Http\Controllers\Controller;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Super Admin → firmware releases for OTA (spec §55). The sha256 is computed here, never trusted from input. */
final class FirmwareController extends Controller
{
    public function index(): array
    {
        return ['data' => FirmwareRelease::query()->orderByDesc('id')->get()->map(fn (FirmwareRelease $r) => $this->present($r))];
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'version' => ['required', 'string', 'max:32', 'regex:/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', 'unique:firmware_releases,version'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'file' => ['required', 'file', 'max:4096'],
        ]);
        $bytes = (string) file_get_contents($request->file('file')->getRealPath());
        // ESP32 app images start with magic byte 0xE9.
        if (strlen($bytes) < 1024 || $bytes[0] !== "\xE9") {
            throw new ApiException(ErrorCode::VALIDATION_FAILED, [], ['fields' => ['file' => ['Not an ESP32 firmware image.']]]);
        }
        $path = 'firmware/'.strtoupper((string) Str::ulid()).'.bin';
        Storage::disk('local')->put($path, $bytes);

        $release = new FirmwareRelease(['version' => $data['version'], 'notes' => $data['notes'] ?? null]);
        $release->forceFill(['sha256' => hash('sha256', $bytes), 'file_path' => $path, 'size' => strlen($bytes), 'is_published' => false, 'created_by' => $request->user()->id])->save();
        $audit->log('firmware.uploaded', $release, ['version' => $release->version, 'sha256' => $release->sha256], ['tenant_id' => null]);

        return response()->json(['data' => $this->present($release)], 201);
    }

    public function publish(Request $request, FirmwareRelease $release, AuditLogger $audit): array
    {
        $release->forceFill(['is_published' => true, 'published_at' => now()])->save();
        $audit->log('firmware.published', $release, ['version' => $release->version], ['tenant_id' => null]);

        return ['data' => $this->present($release)];
    }

    private function present(FirmwareRelease $r): array
    {
        return [
            'id' => $r->public_id, 'version' => $r->version, 'sha256' => $r->sha256, 'size' => $r->size,
            'notes' => $r->notes, 'isPublished' => $r->is_published, 'publishedAt' => $r->published_at?->toIso8601ZuluString(),
        ];
    }
}
