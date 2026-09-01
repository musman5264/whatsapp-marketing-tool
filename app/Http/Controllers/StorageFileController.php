<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves files from the local `public` storage disk over HTTP.
 *
 * Normally the `public/storage` symlink lets the web server deliver these files
 * directly, without PHP. On hosts where symlink creation is blocked (a lot of
 * cPanel / LiteSpeed setups) that symlink is missing or a broken stub, so the
 * request falls through to Laravel and lands here instead — same URLs, served
 * by PHP. When the symlink is present this controller is never reached.
 *
 * Only local files under storage/app/public are served. Anything outside that
 * tree, or a request that resolves to a directory, is a 404.
 */
class StorageFileController extends Controller
{
    public function show(Request $request, string $path): Response
    {
        // Normalise and reject traversal / absolute paths.
        $path = ltrim(str_replace('\\', '/', $path), '/');
        if ($path === '' || str_contains($path, '..') || str_contains($path, "\0")) {
            abort(404);
        }

        $disk = Storage::disk('public');

        // exists() also returns false for directories on the local driver.
        if (! $disk->exists($path)) {
            abort(404);
        }

        $fullPath = $disk->path($path);
        if (! is_file($fullPath)) {
            abort(404);
        }

        $mime = $disk->mimeType($path) ?: 'application/octet-stream';
        $lastModified = $disk->lastModified($path);
        $etag = '"'.md5($path.'|'.$lastModified.'|'.$disk->size($path)).'"';

        // Conditional GET — let browsers/CDNs skip the transfer.
        if (trim($request->headers->get('If-None-Match', '')) === $etag) {
            return response('', 304)->setEtag(trim($etag, '"'))->header('Cache-Control', 'public, max-age=31536000');
        }

        $response = new BinaryFileResponse($fullPath);
        $response->headers->set('Content-Type', $mime);
        $response->setEtag(trim($etag, '"'));
        $response->headers->set('Cache-Control', 'public, max-age=31536000, immutable');
        $response->setPublic();
        // Inline for images/pdf/video, attachment for the rest.
        $inline = preg_match('#^(image/|video/|audio/|text/|application/pdf$)#', $mime) === 1;
        $response->setContentDisposition(
            $inline ? 'inline' : 'attachment',
            basename($path),
        );
        // Range requests (video scrubbing) work out of the box with BinaryFileResponse.
        $response->prepare($request);

        return $response;
    }
}
