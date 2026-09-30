<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Branches\Services\BranchAccess;
use App\Domain\Photos\Models\SessionPhoto;
use App\Domain\Photos\Services\PhotoService;
use App\Domain\Sessions\Models\GameSession;
use App\Http\Controllers\Controller;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Authenticated, audited photo access (spec §59). Never a public URL. */
final class PhotoController extends Controller
{
    public function __construct(
        private readonly PhotoService $photos,
        private readonly BranchAccess $access,
    ) {}

    public function show(Request $request, SessionPhoto $photo): Response
    {
        $this->authorizeBranch($request, $photo);
        $bytes = $this->photos->view($request->user(), $photo);

        return response($bytes, 200, [
            'Content-Type' => 'image/jpeg',
            'Content-Length' => (string) strlen($bytes),
            'Content-Disposition' => 'inline; filename="session-photo.jpg"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(Request $request, SessionPhoto $photo): Response
    {
        $this->authorizeBranch($request, $photo);
        $this->photos->delete($request->user(), $photo);

        return response()->noContent();
    }

    private function authorizeBranch(Request $request, SessionPhoto $photo): void
    {
        $branchId = GameSession::query()->whereKey($photo->session_id)->value('branch_id');
        if ($branchId === null || ! $this->access->canAccess($request->user(), (int) $branchId)) {
            throw ApiException::of(ErrorCode::NOT_FOUND);
        }
    }
}
