<?php

namespace App\Http\Controllers;

use App\Models\CmsPage;
use App\Support\Brand;
use Inertia\Inertia;
use Inertia\Response;

class CmsPageController extends Controller
{
    public function show(string $slug): Response
    {
        $page = CmsPage::where('slug', $slug)
            ->where('published', true)
            ->firstOrFail();

        // Pages are seeded/edited with the `:brand` token so their copy follows a
        // rename; resolve it here, on the way out to the public page.
        return Inertia::render('marketing/CmsPage', [
            'page' => [
                'title' => Brand::apply($page->title),
                'content' => Brand::apply($page->content),
                'meta_title' => Brand::apply($page->meta_title),
                'meta_description' => Brand::apply($page->meta_description),
                'layout' => $page->layout,
            ],
        ]);
    }
}
