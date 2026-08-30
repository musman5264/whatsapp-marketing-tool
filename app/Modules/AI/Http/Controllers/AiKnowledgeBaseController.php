<?php

namespace App\Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AI\Jobs\IndexDocumentJob;
use App\Modules\AI\Models\AiKbDocument;
use App\Modules\AI\Models\AiKnowledgeBase;
use App\Services\StorageManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AiKnowledgeBaseController extends Controller
{
    public function __construct(private StorageManager $storage) {}

    public function index(Request $request): Response
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        $kbs = AiKnowledgeBase::where('workspace_id', $workspaceId)
            ->withCount('documents')
            ->latest()->get();

        return Inertia::render('AI/KnowledgeBases/Index', ['knowledgeBases' => $kbs]);
    }

    public function show(Request $request, AiKnowledgeBase $kb): Response
    {
        $this->authorise($request, $kb);
        $kb->load('documents');

        return Inertia::render('AI/KnowledgeBases/Show', ['kb' => $kb]);
    }

    public function store(Request $request): RedirectResponse
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        $validated = $request->validate(['name' => ['required', 'string', 'max:128']]);
        AiKnowledgeBase::create(array_merge($validated, ['workspace_id' => $workspaceId]));

        return back()->with('success', 'Knowledge base created.');
    }

    public function addDocument(Request $request, AiKnowledgeBase $kb): RedirectResponse
    {
        $this->authorise($request, $kb);

        $validated = $request->validate([
            'source_type' => ['required', 'in:file,url,text,sitemap,faq'],
            'source_ref' => ['nullable', 'string', 'max:512'],
            'title' => ['nullable', 'string', 'max:256'],
        ]);

        // Handle file upload
        if ($request->hasFile('file')) {
            $request->validate([
                'file' => [
                    'file',
                    'max:20480',
                    'mimes:pdf,txt,md,csv,docx,doc,xlsx,xls,json',
                ],
            ]);
            $file = $request->file('file');
            $diskName = $this->storage->diskName();
            $path = $this->storage->prefixedPath('kb-docs/'.$file->hashName());
            $this->storage->disk()->putFileAs(dirname($path), $file, basename($path));
            $validated['source_ref'] = $path;
            $validated['title'] = $validated['title'] ?? $file->getClientOriginalName();
        }

        $doc = AiKbDocument::create(array_merge($validated, ['kb_id' => $kb->id, 'status' => 'pending']));

        try {
            IndexDocumentJob::dispatch($doc->id)->onQueue('ai');
        } catch (\RuntimeException $e) {
            return back()->withErrors(['source_type' => $e->getMessage()]);
        }

        return back()->with('success', 'Document queued for indexing.');
    }

    public function reindex(Request $request, AiKbDocument $document): RedirectResponse
    {
        $kb = $document->load('knowledgeBase')->knowledgeBase;
        $this->authorise($request, $kb);
        $document->update(['status' => 'pending']);

        try {
            IndexDocumentJob::dispatch($document->id)->onQueue('ai');
        } catch (\RuntimeException $e) {
            return back()->withErrors(['document' => $e->getMessage()]);
        }

        return back()->with('success', 'Re-indexing queued.');
    }

    public function destroyDocument(Request $request, AiKbDocument $document): RedirectResponse
    {
        $kb = $document->load('knowledgeBase')->knowledgeBase;
        $this->authorise($request, $kb);
        $document->chunks()->delete();
        $document->delete();

        return back()->with('success', 'Document removed.');
    }

    private function authorise(Request $request, AiKnowledgeBase $kb): void
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        abort_unless((int) $kb->workspace_id === (int) $workspaceId, 403);
    }
}
