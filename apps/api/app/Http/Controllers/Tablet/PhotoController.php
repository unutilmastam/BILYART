<?php

namespace App\Http\Controllers\Tablet;

use App\Domain\Photos\Services\ImageSanitizer;
use App\Domain\Photos\Services\PhotoService;
use App\Domain\Sessions\Models\GameSession;
use App\Http\Controllers\Controller;
use App\Http\Resources\Tablet\TabletSessionResource;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

final class PhotoController extends Controller
{
    public function store(Request $request, GameSession $session, PhotoService $photos): array
    {
        $file = $request->file('photo');
        if (! $file instanceof UploadedFile || ! $file->isValid() || $file->getSize() > ImageSanitizer::MAX_BYTES) {
            throw ApiException::of(ErrorCode::PHOTO_INVALID);
        }
        $photos->store($request->attributes->get('tablet'), $session, (string) file_get_contents($file->getRealPath()));

        return TabletSessionResource::make($session->refresh());
    }
}
