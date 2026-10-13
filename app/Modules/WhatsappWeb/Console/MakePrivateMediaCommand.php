<?php

namespace App\Modules\WhatsappWeb\Console;

use App\Modules\Shared\Models\Message;
use App\Modules\WhatsappWeb\Services\WahaMediaStore;
use App\Services\StorageManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Moves old WhatsApp Web media from the public disk (served at /storage/message-media/...)
 * to the private disk, and points the message links at the login-checked route.
 *
 *   php artisan media:make-private           dry run: lists the moves, changes nothing
 *   php artisan media:make-private --apply   moves the files and rewrites the links
 *
 * See app/logic/private-media.md.
 */
class MakePrivateMediaCommand extends Command
{
    protected $signature = 'media:make-private {--apply : Move the files and rewrite the links. Without it, nothing changes.}';

    protected $description = 'Move old WhatsApp Web media from the public disk to the private disk (dry run unless --apply)';

    /** Folder name on both disks. */
    private const FOLDER = 'message-media';

    private const PUBLIC_DISK = 'public';

    /** Private disk (storage/app/private), the same disk WahaMediaStore writes to. */
    private const PRIVATE_DISK = 'local';

    /** Start of a link that points at a public copy. */
    private const PUBLIC_LINK = '/storage/message-media/';

    public function __construct(
        private readonly StorageManager $storageManager,
        private readonly WahaMediaStore $mediaStore,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $diskName = $this->storageManager->diskName();
        if ($diskName !== self::PUBLIC_DISK) {
            $this->error("The media is stored on the cloud disk '{$diskName}'. This command only moves files from the public disk, so it cannot run here.");

            return self::FAILURE;
        }

        $plan = $this->plan();

        return $this->option('apply') ? $this->applyPlan($plan) : $this->showPlan($plan);
    }

    /**
     * Works out what would move. Read-only.
     *
     * Each file is matched to one message: first the message whose id is in the file
     * name (message-media/{id}.{ext}), otherwise the message whose preview_url or link
     * points at the file. A file with no message is an orphan and keeps its own path.
     * A file linked from more than one message, or a message that would get two files,
     * is a conflict and is left in place.
     *
     * @return array{moves: list<array{from: string, to: string, message_id: ?int, local: string}>, conflicts: list<string>}
     */
    private function plan(): array
    {
        $index = $this->linkIndex();

        $candidates = [];
        foreach (Storage::disk(self::PUBLIC_DISK)->allFiles(self::FOLDER) as $from) {
            $name = Str::after($from, self::FOLDER.'/');
            $ownerId = $this->ownerId($name);
            $linked = $index[$name] ?? [];
            $ids = array_values(array_unique(array_filter(
                [$ownerId, ...$linked],
                fn ($id) => $id !== null,
            )));

            $candidates[] = ['from' => $from, 'name' => $name, 'ids' => $ids, 'owned' => $ownerId !== null];
        }

        // Files named after their message are planned first, so they keep that message.
        usort($candidates, fn (array $a, array $b) => ($b['owned'] <=> $a['owned']) ?: strcmp($a['from'], $b['from']));

        $moves = [];
        $conflicts = [];
        $claimed = [];
        $targets = [];

        foreach ($candidates as $candidate) {
            $ids = $candidate['ids'];
            $from = $candidate['from'];

            if (count($ids) > 1) {
                $conflicts[] = $from.' is linked from '.count($ids).' messages ('.implode(', ', $ids).'). Left in place.';

                continue;
            }

            $messageId = $ids[0] ?? null;
            if ($messageId !== null && isset($claimed[$messageId])) {
                $conflicts[] = "{$from}: message {$messageId} already has {$claimed[$messageId]}. Left in place.";

                continue;
            }

            $to = $messageId === null
                ? $from
                : self::FOLDER.'/'.$messageId.'.'.$this->extension($candidate['name']);

            if (isset($targets[$to])) {
                $conflicts[] = "{$from}: {$to} is already the target of {$targets[$to]}. Left in place.";

                continue;
            }

            $local = $this->localStatus($from, $to);
            if ($local === 'different') {
                $conflicts[] = "{$from}: a file of a different size already exists on the private disk at {$to}. Left in place.";

                continue;
            }

            if ($messageId !== null) {
                $claimed[$messageId] = $from;
            }
            $targets[$to] = $from;
            $moves[] = ['from' => $from, 'to' => $to, 'message_id' => $messageId, 'local' => $local];
        }

        return ['moves' => $moves, 'conflicts' => $conflicts];
    }

    /**
     * @param  array{moves: list<array{from: string, to: string, message_id: ?int, local: string}>, conflicts: list<string>}  $plan
     */
    private function showPlan(array $plan): int
    {
        foreach ($plan['moves'] as $move) {
            $owner = $move['message_id'] === null ? 'orphan' : "message {$move['message_id']}";
            $this->line("{$move['from']} -> {$move['to']} ({$owner})");
        }

        $messageIds = $this->messageIds($plan['moves']);
        foreach ($messageIds as $messageId) {
            $this->line("rewrite links of message {$messageId} to the login route");
        }

        foreach ($plan['conflicts'] as $conflict) {
            $this->error($conflict);
        }

        $this->newLine();
        $this->info(sprintf(
            'Found %d file(s): %d can move, %d conflict(s) left in place. %d message(s) will have their links rewritten. Nothing was changed. Run with --apply to move them.',
            count($plan['moves']) + count($plan['conflicts']),
            count($plan['moves']),
            count($plan['conflicts']),
            count($messageIds),
        ));

        return $plan['conflicts'] === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array{moves: list<array{from: string, to: string, message_id: ?int, local: string}>, conflicts: list<string>}  $plan
     */
    private function applyPlan(array $plan): int
    {
        foreach ($plan['conflicts'] as $conflict) {
            $this->error($conflict);
        }

        $moved = 0;
        $rewritten = 0;
        $errors = 0;

        foreach ($plan['moves'] as $move) {
            try {
                if ($move['local'] === 'missing') {
                    $this->copyToPrivate($move['from'], $move['to']);
                }

                if ($move['message_id'] !== null) {
                    $this->rewriteLinks($move['message_id'], $move['to']);
                    $rewritten++;
                }

                // Only after the copy and the link change have both succeeded.
                if (! Storage::disk(self::PUBLIC_DISK)->delete($move['from'])) {
                    throw new RuntimeException('the public copy could not be deleted');
                }

                $moved++;
                $this->info("Moved {$move['from']} -> {$move['to']}");
            } catch (Throwable $e) {
                $errors++;
                Log::error('whatsapp_web.media.make_private_failed', [
                    'from' => $move['from'],
                    'to' => $move['to'],
                    'message_id' => $move['message_id'],
                    'error' => $e->getMessage(),
                ]);
                $this->error("Could not move {$move['from']}. Its public copy was kept. Details are in the log.");
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'Done: %d file(s) moved, %d message(s) rewritten, %d conflict(s), %d error(s).',
            $moved,
            $rewritten,
            count($plan['conflicts']),
            $errors,
        ));

        return ($plan['conflicts'] === [] && $errors === 0) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Copies the public file to the private disk by stream and checks the size.
     * A copy that does not match is removed again, so the next run can retry it.
     */
    private function copyToPrivate(string $from, string $to): void
    {
        $public = Storage::disk(self::PUBLIC_DISK);
        $private = Storage::disk(self::PRIVATE_DISK);

        $stream = $public->readStream($from);
        if (! is_resource($stream)) {
            throw new RuntimeException('the public file could not be read');
        }

        try {
            $written = $private->writeStream($to, $stream);
            if ($written === false || $private->size($to) !== $public->size($from)) {
                throw new RuntimeException('the private copy does not match the public file size');
            }
        } catch (Throwable $e) {
            if ($private->exists($to)) {
                $private->delete($to);
            }

            throw $e;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Records the private path on the message and points its public links at the login route.
     * Runs in one transaction, so a failed save changes nothing.
     */
    private function rewriteLinks(int $messageId, string $to): void
    {
        DB::transaction(function () use ($messageId, $to) {
            $message = Message::query()->lockForUpdate()->find($messageId);
            if ($message === null) {
                throw new RuntimeException("message {$messageId} no longer exists");
            }

            $payload = is_array($message->getAttribute('payload')) ? $message->getAttribute('payload') : [];
            $login = $this->mediaStore->authenticatedUrl($message);

            foreach (['preview_url', 'link'] as $key) {
                if ($this->isPublicLink($payload[$key] ?? null)) {
                    $payload[$key] = $login;
                }
            }
            foreach ($payload as $key => $value) {
                if (is_array($value) && $this->isPublicLink($value['link'] ?? null)) {
                    $payload[$key]['link'] = $login;
                }
            }
            $payload['media_path'] = $to;

            $message->update(['payload' => $payload]);
        });
    }

    /**
     * Every public link in the database, keyed by the file name it points at:
     * name => message ids. One pass over the messages that mention the folder.
     *
     * @return array<string, list<int>>
     */
    private function linkIndex(): array
    {
        $index = [];

        Message::query()
            ->select(['id', 'payload'])
            ->where('payload', 'like', '%'.self::FOLDER.'%')
            ->chunkById(500, function ($messages) use (&$index) {
                foreach ($messages as $message) {
                    $payload = is_array($message->getAttribute('payload')) ? $message->getAttribute('payload') : [];
                    foreach ($this->publicLinks($payload) as $link) {
                        if (preg_match('~'.preg_quote(self::PUBLIC_LINK, '~').'([^?#]+)~', $link, $m)) {
                            $index[$m[1]][] = (int) $message->id;
                        }
                    }
                }
            });

        return array_map(fn (array $ids) => array_values(array_unique($ids)), $index);
    }

    /**
     * The public links in one payload: preview_url and link, and link under a type key (e.g. image.link).
     *
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function publicLinks(array $payload): array
    {
        $links = [];
        foreach (['preview_url', 'link'] as $key) {
            if ($this->isPublicLink($payload[$key] ?? null)) {
                $links[] = $payload[$key];
            }
        }
        foreach ($payload as $value) {
            if (is_array($value) && $this->isPublicLink($value['link'] ?? null)) {
                $links[] = $value['link'];
            }
        }

        return $links;
    }

    private function isPublicLink(mixed $value): bool
    {
        return is_string($value) && str_contains($value, self::PUBLIC_LINK);
    }

    /** The message id a file is named after (message-media/{id}.{ext}), when that message exists. */
    private function ownerId(string $name): ?int
    {
        if (! preg_match('/^([1-9]\d*)\.[A-Za-z0-9]+$/', $name, $m)) {
            return null;
        }

        $id = (int) $m[1];

        return Message::query()->whereKey($id)->exists() ? $id : null;
    }

    /** Lower-case extension of a file name, or 'bin' when it has none (as WahaMediaStore does). */
    private function extension(string $name): string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return $ext !== '' ? $ext : 'bin';
    }

    /**
     * missing: nothing at the target. same: a file of the same size is already there.
     * different: a file of another size is there, so it must not be overwritten.
     */
    private function localStatus(string $from, string $to): string
    {
        $private = Storage::disk(self::PRIVATE_DISK);
        if (! $private->exists($to)) {
            return 'missing';
        }

        return $private->size($to) === Storage::disk(self::PUBLIC_DISK)->size($from) ? 'same' : 'different';
    }

    /**
     * @param  list<array{from: string, to: string, message_id: ?int, local: string}>  $moves
     * @return list<int>
     */
    private function messageIds(array $moves): array
    {
        return array_values(array_unique(array_filter(
            array_column($moves, 'message_id'),
            fn ($id) => $id !== null,
        )));
    }
}
