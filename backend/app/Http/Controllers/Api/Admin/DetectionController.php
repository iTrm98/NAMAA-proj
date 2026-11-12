<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\DetectionResource;
use App\Models\Detection;
use App\Models\Park;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use App\Services\CameraImages;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

/** Camera frames of the admin's company (photos already blurred; never public URLs). */
class DetectionController extends Controller
{
    /** GET /api/admin/parks/{park}/detections?limit=6: newest frames first. */
    public function index(Request $request, Park $park): AnonymousResourceCollection
    {
        Gate::authorize('view', $park);

        return self::latest($request, $park);
    }

    /** GET /api/admin/detections/{detection}/image: the stored JPEG. */
    public function image(Detection $detection): BaseResponse
    {
        Gate::authorize('view', $detection->park);

        return self::stream($detection);
    }

    public static function latest(Request $request, Park $park): AnonymousResourceCollection
    {
        $limit = (int) ($request->validate(['limit' => ['sometimes', 'integer', 'min:1', 'max:50']])['limit'] ?? 6);

        return DetectionResource::collection(
            $park->detections()->orderByDesc('captured_at')->orderByDesc('id')->limit($limit)->get()
        );
    }

    /**
     * The stored JPEG, or its `?size=display|thumb` variant (falls back to the original
     * for frames stored before the variants existed). A frame never changes, so it is
     * cached for a year (private: it needs the Bearer token) with an ETag; a repeat
     * request with If-None-Match is answered 304 without reading the file.
     */
    public static function stream(Detection $detection): BaseResponse
    {
        $disk = CameraImages::disk();
        $size = (string) request()->query('size', 'original');
        abort_unless(in_array($size, ['original', ...array_keys(CameraImages::SIZES)], true), 422, 'size must be original, display or thumb.');

        $path = CameraImages::variantPath($detection->photo_path, $size);
        if ($path !== $detection->photo_path && ! $disk->exists($path)) {
            $path = $detection->photo_path;
        }
        abort_unless($disk->exists($path), 404);

        $headers = [
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'ETag' => '"'.md5($detection->id.'|'.$path.'|'.$disk->lastModified($path)).'"',
        ];

        if (request()->header('If-None-Match') === $headers['ETag']) {
            return response('', 304, $headers);
        }

        return $disk->response($path, null, $headers);
    }
}
