<?php

namespace Tests\Feature\ProductionHardening;

use App\Modules\AI\Jobs\IndexDocumentJob;
use App\Modules\AI\Models\AiKbDocument;
use App\Modules\AI\Models\AiKnowledgeBase;
use App\Modules\AI\Models\AiProviderConfig;
use App\Modules\AI\Services\EmbeddingStore;
use App\Modules\AI\Services\LlmGateway;
use App\Services\StorageManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Indexing-pipeline regression tests for the knowledge base.
 *
 * These cover two production bugs:
 *  - uploaded files must be read back through the Storage disk (the stored
 *    source_ref is a disk-relative key, not an absolute local path); and
 *  - FAQ documents must be decoded from their JSON payload into clean Q&A text
 *    before they are chunked and embedded.
 */
class KbIndexingTest extends TestCase
{
    use RefreshDatabase;

    private function runIndexer(int $documentId): void
    {
        (new IndexDocumentJob($documentId))->handle(
            app(LlmGateway::class),
            app(EmbeddingStore::class),
            app(StorageManager::class),
        );
    }

    private function fakeEmbeddings(): void
    {
        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response([
                'data' => [['embedding' => [0.1, 0.2, 0.3]]],
            ], 200),
        ]);
    }

    private function seedKb(): AiKnowledgeBase
    {
        $data = $this->createWorkspaceContext();
        $workspace = $data['workspace'];

        AiProviderConfig::create([
            'workspace_id' => $workspace->id,
            'provider' => 'openai',
            'credentials' => ['api_key' => 'sk-test'],
            'default_model_chat' => 'gpt-4o-mini',
            'default_model_embed' => 'text-embedding-3-small',
            'enabled' => true,
        ]);

        return AiKnowledgeBase::create([
            'workspace_id' => $workspace->id,
            'name' => 'Test KB',
            'embedding_model' => 'text-embedding-3-small',
            'dimensions' => 3,
            'status' => 'active',
        ]);
    }

    public function test_file_document_is_read_from_the_storage_disk(): void
    {
        Storage::fake('public');
        $this->fakeEmbeddings();
        $kb = $this->seedKb();

        $storage = app(StorageManager::class);
        $path = $storage->prefixedPath('kb-docs/warranty.txt');
        $storage->disk()->put($path, 'The warranty period is 12 months from purchase.');

        $doc = AiKbDocument::create([
            'kb_id' => $kb->id,
            'title' => 'warranty.txt',
            'source_type' => 'file',
            'source_ref' => $path,
            'status' => 'pending',
        ]);

        $this->runIndexer($doc->id);
        $doc->refresh();

        $this->assertSame('indexed', $doc->status);
        $this->assertTrue(
            $doc->chunks()->where('content', 'like', '%warranty period is 12 months%')->exists(),
            'The file content should have been read from the storage disk and chunked.'
        );
    }

    public function test_faq_document_is_decoded_into_clean_qa_text(): void
    {
        $this->fakeEmbeddings();
        $kb = $this->seedKb();

        $doc = AiKbDocument::create([
            'kb_id' => $kb->id,
            'title' => 'FAQ',
            'source_type' => 'faq',
            'source_ref' => json_encode([
                ['question' => 'What is your refund policy?', 'answer' => '30 days money back.'],
                ['question' => 'Do you ship internationally?', 'answer' => 'Yes, worldwide.'],
            ]),
            'status' => 'pending',
        ]);

        $this->runIndexer($doc->id);

        $content = $doc->chunks()->orderBy('ord')->first()?->content ?? '';

        $this->assertStringContainsString('Q: What is your refund policy?', $content);
        $this->assertStringContainsString('A: 30 days money back.', $content);
        $this->assertStringContainsString('Q: Do you ship internationally?', $content);
        // The raw JSON structure must not leak into the embedded text.
        $this->assertStringNotContainsString('"question"', $content);
        $this->assertStringNotContainsString('"answer"', $content);
    }
}
