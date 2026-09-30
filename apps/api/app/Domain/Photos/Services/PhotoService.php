<?php

namespace App\Domain\Photos\Services;

use App\Domain\Audit\Enums\ActorType;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Auth\Permissions;
use App\Domain\Photos\Models\SessionPhoto;
use App\Domain\Photos\Storage\PhotoStorage;
use App\Domain\Sessions\Enums\SessionStatus;
use App\Domain\Sessions\Models\GameSession;
use App\Domain\Tablets\Models\Tablet;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\TenantSettings;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\User;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One customer photo per session (spec §8 steps 5–8, §22–23, §58–59): private
 * storage, authorized + audited viewing, audited deletion, retention.
 * The CCTV/cash camera is not part of this system and never touched here.
 */
final class PhotoService
{
    public function __construct(
        private readonly PhotoStorage $storage,
        private readonly ImageSanitizer $sanitizer,
        private readonly TenantSettings $settings,
        private readonly AuditLogger $audit,
    ) {}

    /** Stores (or, while still RESERVED, replaces) the session photo. */
    public function store(Tablet $tablet, GameSession $session, string $raw): SessionPhoto
    {
        if ($session->tablet_id !== $tablet->id) {
            throw ApiException::of(ErrorCode::NOT_FOUND);
        }
        if ($session->status !== SessionStatus::RESERVED) {
            throw ApiException::of(ErrorCode::INVALID_STATE_TRANSITION);
        }
        $clean = $this->sanitizer->sanitize($raw);
        $path = sprintf('tenants/%d/sessions/%s/%s.jpg', $session->tenant_id, $session->public_id, strtoupper((string) Str::ulid()));
        $this->storage->put($path, $clean['bytes']);

        try {
            return DB::transaction(function () use ($session, $path, $clean): SessionPhoto {
                /** @var SessionPhoto|null $existing */
                $existing = SessionPhoto::query()->where('session_id', $session->id)->lockForUpdate()->first();
                $photo = $existing ?? new SessionPhoto;
                $oldPath = $existing?->storage_path;
                $photo->forceFill([
                    'tenant_id' => $session->tenant_id,
                    'session_id' => $session->id,
                    'storage_path' => $path,
                    'mime_type' => $clean['mime'],
                    'size' => strlen($clean['bytes']),
                    'width' => $clean['width'],
                    'height' => $clean['height'],
                    'sha256' => hash('sha256', $clean['bytes']),
                    'created_at' => now(),
                    'deleted_at' => null,
                    'deleted_by' => null,
                    'delete_reason' => null,
                ])->save();
                if ($oldPath !== null && $oldPath !== $path) {
                    DB::afterCommit(fn () => $this->storage->delete($oldPath));
                }
                $this->audit->log($existing ? 'photo.replaced' : 'photo.captured', $photo, ['session' => $session->public_id, 'size' => $photo->size]);

                return $photo;
            });
        } catch (\Throwable $e) {
            $this->storage->delete($path); // no orphan files
            throw $e;
        }
    }

    /** Returns JPEG bytes if the user may see the photo; every view is audited (spec §59). */
    public function view(User $user, SessionPhoto $photo): string
    {
        $this->assertCanView($user);
        if ($photo->isDeleted()) {
            throw ApiException::of(ErrorCode::NOT_FOUND);
        }
        $bytes = $this->storage->get($photo->storage_path);
        if ($bytes === null) {
            throw ApiException::of(ErrorCode::NOT_FOUND);
        }
        $this->audit->log('photo.viewed', $photo);

        return $bytes;
    }

    public function delete(User $user, SessionPhoto $photo): void
    {
        if ($photo->isDeleted()) {
            throw ApiException::of(ErrorCode::NOT_FOUND);
        }
        $this->erase($photo, $user->id, 'MANUAL');
        $this->audit->log('photo.deleted', $photo, ['reason' => 'MANUAL']);
    }

    /** Retention job: deletes photos older than each tenant's photo_retention_days. */
    public function pruneExpired(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $count = 0;
        foreach (Tenant::query()->orderBy('id')->get() as $tenant) {
            $days = (int) $this->settings->for($tenant)['photo_retention_days'];
            $photos = SessionPhoto::query()->where('tenant_id', $tenant->id)->whereNull('deleted_at')
                ->where('created_at', '<', $now->subDays($days))->orderBy('id')->limit(1000)->get();
            foreach ($photos as $photo) {
                $this->erase($photo, null, 'RETENTION');
                $this->audit->log('photo.deleted', $photo, ['reason' => 'RETENTION', 'retentionDays' => $days], [
                    'tenant_id' => $tenant->id, 'actor_type' => ActorType::SYSTEM, 'actor_id' => null,
                ]);
                $count++;
            }
        }

        return $count;
    }

    public function canView(User $user): bool
    {
        if (Permissions::roleHas($user->role, 'photos.view')) {
            return true;
        }

        return $user->role === Role::CLIENT_OPERATOR
            && $user->tenant_id !== null
            && (bool) $this->settings->for(Tenant::query()->findOrFail($user->tenant_id))['operators_can_view_photos'];
    }

    private function assertCanView(User $user): void
    {
        if (! $this->canView($user)) {
            throw ApiException::of(ErrorCode::FORBIDDEN);
        }
    }

    private function erase(SessionPhoto $photo, ?int $userId, string $reason): void
    {
        $this->storage->delete($photo->storage_path);
        $photo->forceFill(['deleted_at' => now(), 'deleted_by' => $userId, 'delete_reason' => $reason])->save();
    }
}
