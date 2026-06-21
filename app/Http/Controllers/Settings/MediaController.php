<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\MediaFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class MediaController extends Controller
{
    public function index(): Response
    {
        abort_unless(auth()->user()?->hasRole('admin'), 403);

        return Inertia::render('settings/Media', [
            'files' => MediaFile::latest()->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless(auth()->user()?->hasRole('admin'), 403);

        $request->validate([
            'file' => 'required|file|max:10240|mimes:jpg,jpeg,png,webp,gif,mp4,mov,avi,pdf,svg',
        ]);

        $uploaded = $request->file('file');
        $ext      = $uploaded->getClientOriginalExtension();
        $filename = Str::uuid() . '.' . $ext;
        $path     = 'media/' . $filename;

        Storage::disk('public')->putFileAs('media', $uploaded, $filename);

        $media = MediaFile::create([
            'original_name' => $uploaded->getClientOriginalName(),
            'filename'      => $filename,
            'mime_type'     => $uploaded->getMimeType(),
            'size'          => $uploaded->getSize(),
            'path'          => $path,
        ]);

        return response()->json([
            'id'            => $media->id,
            'original_name' => $media->original_name,
            'filename'      => $media->filename,
            'mime_type'     => $media->mime_type,
            'size'          => $media->size,
            'url'           => $media->url,
            'is_image'      => $media->is_image,
            'created_at'    => $media->created_at,
        ], 201);
    }

    public function destroy(MediaFile $media): RedirectResponse
    {
        abort_unless(auth()->user()?->hasRole('admin'), 403);

        Storage::disk('public')->delete($media->path);
        $media->delete();

        return back()->with('success', 'File deleted.');
    }
}
