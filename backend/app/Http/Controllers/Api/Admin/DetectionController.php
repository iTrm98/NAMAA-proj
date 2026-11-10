<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\DetectionResource;
use App\Models\Detection;
use App\Models\Park;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
    public function image(Detection $detection): StreamedResponse
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

    public static function stream(Detection $detection): StreamedResponse
    {
        $disk = Storage::disk(config('namaa.camera.disk'));
        abort_unless($disk->exists($detection->photo_path), 404);

        return $disk->response($detection->photo_path, null, ['Cache-Control' => 'private, max-age=86400']);
    }
}
