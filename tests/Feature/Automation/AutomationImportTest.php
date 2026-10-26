<?php

namespace Tests\Feature\Automation;

use App\Models\User;
use App\Modules\Automation\Models\Automation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Importing an exported automation file: always a new draft in the importer's own workspace. */
class AutomationImportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $ctx = $this->createWorkspaceContext();
        $this->user = $ctx['user'];
        $this->workspace = $ctx['workspace'];
    }

    private function file(array $over = []): string
    {
        return json_encode(array_replace_recursive([
            'format' => 'whatsmine.automation',
            'version' => 1,
            'exported_at' => '2026-10-24T12:00:00Z',
            'automation' => [
                'name' => 'Menu',
                'trigger_type' => 'message.received',
                'trigger_config' => ['keywords' => ['m'], 'match_mode' => 'equals'],
                'nodes' => [
                    ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => ['triggerType' => 'message.received']],
                    ['id' => 'q1', 'type' => 'ask_question', 'position' => ['x' => 0, 'y' => 100], 'data' => ['nodeType' => 'ask_question', 'question' => 'Pick', 'variable' => 'user']],
                ],
                'edges' => [['id' => 'e1', 'source' => 'trigger-1', 'target' => 'q1']],
            ],
        ], $over));
    }

    private function import(string $payload)
    {
        return $this->actingAs($this->user)->post('/app/automations/import', ['payload' => $payload]);
    }

    public function test_the_bundled_order_alert_example_imports(): void
    {
        $this->import((string) file_get_contents(base_path('app/logic/examples/epos-order-alert.flow.json')));

        $a = Automation::where('workspace_id', $this->workspace->id)->sole();
        $this->assertSame('Order request to the shop (EPOS alert) (imported)', $a->name);
        $this->assertCount(11, $a->nodes);
        $this->assertCount(10, $a->edges);
    }

    public function test_a_valid_file_becomes_a_new_draft_in_the_importers_workspace(): void
    {
        $res = $this->import($this->file(['automation' => ['workspace_id' => 999999, 'status' => 'active', 'trigger_token' => 'stolen']]));

        $a = Automation::where('workspace_id', $this->workspace->id)->sole();
        $res->assertRedirect(route('client.automations.edit', $a->uuid));
        $this->assertSame('Menu (imported)', $a->name);
        $this->assertSame('draft', $a->status);
        $this->assertSame('message.received', $a->trigger_type);
        $this->assertSame(['m'], $a->trigger_config['keywords']);
        $this->assertCount(2, $a->nodes);
        $this->assertCount(1, $a->edges);
        $this->assertNull($a->trigger_token);
        $this->assertSame(0, Automation::where('workspace_id', 999999)->count());
    }

    public function test_edges_to_missing_steps_are_dropped(): void
    {
        $this->import($this->file(['automation' => ['edges' => [
            ['id' => 'e1', 'source' => 'trigger-1', 'target' => 'q1'],
            ['id' => 'e2', 'source' => 'q1', 'target' => 'ghost'],
        ]]]));

        $this->assertCount(1, Automation::where('workspace_id', $this->workspace->id)->sole()->edges);
    }

    #[DataProvider('badFiles')]
    public function test_bad_files_are_refused_and_nothing_is_created(string $payload): void
    {
        $this->import($payload)->assertSessionHasErrors('payload');

        $this->assertSame(0, Automation::where('workspace_id', $this->workspace->id)->count());
    }

    public static function badFiles(): array
    {
        $ok = ['format' => 'whatsmine.automation', 'version' => 1, 'automation' => ['name' => 'x', 'nodes' => [['id' => 'a', 'type' => 'wait', 'data' => []]], 'edges' => []]];

        return [
            'not json' => ['hello'],
            'a list' => ['[1,2]'],
            'wrong format' => [json_encode(['format' => 'other'] + $ok)],
            'newer version' => [json_encode(['version' => 99] + $ok)],
            'no steps' => [json_encode(['automation' => ['nodes' => []]] + $ok)],
            'step without id' => [json_encode(['automation' => ['nodes' => [['type' => 'wait', 'data' => []]]]] + $ok)],
            'odd step type' => [json_encode(['automation' => ['nodes' => [['id' => 'a', 'type' => '../etc', 'data' => []]]]] + $ok)],
            'too many steps' => [json_encode(['automation' => ['nodes' => array_map(fn ($i) => ['id' => "n{$i}", 'type' => 'wait', 'data' => []], range(1, 201))]] + $ok)],
        ];
    }
}
