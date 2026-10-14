<?php

namespace App\Modules\WhatsappWeb\Console;

use App\Modules\Shared\Models\Message;
use App\Modules\WhatsappWeb\Services\WahaMediaStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Points WhatsApp Web media links that were built with the conversation's integer id at the
 * login route. That route binds the conversation by its uuid, so those links returned 404 and
 * the chat showed a broken image. Commit 6282223 fixed new links; this rewrites the saved ones.
 *
 *   php artisan media:fix-links           dry run: counts the messages, changes nothing
 *   php artisan media:fix-links --apply   rewrites the links
 *
 * See app/logic/private-media.md.
 */
class FixMediaLinksCommand extends Command
{
    protected $signature = 'media:fix-links {--apply : Rewrite the links. Without it, nothing changes.}';

    protected $description = 'Point WhatsApp Web media links that use the conversation id at the uuid login route (dry run unless --apply)';

    /** The login route with a numeric conversation segment: /conversations/{id}/messages/{id}/media. */
    private const BROKEN_LINK = '#/conversations/(\d+)/messages/(\d+)/media(?:\?|$)#';

    public function __construct(private readonly WahaMediaStore $mediaStore)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $ids = $this->brokenMessageIds();

        if (! $this->option('apply')) {
            $this->info(sprintf(
                'Found %d message(s) with a link that uses the conversation id. Nothing was changed. Run with --apply to fix them.',
                count($ids),
            ));

            return self::SUCCESS;
        }

        $fixed = 0;
        foreach ($ids as $id) {
            if ($this->fixMessage($id)) {
                $fixed++;
            }
        }

        $this->info("Fixed {$fixed} message(s).");

        return self::SUCCESS;
    }

    /** @return list<int> */
    private function brokenMessageIds(): array
    {
        $ids = [];

        // LIKE only narrows the rows; the link check below decides.
        Message::query()
            ->with('conversation')
            ->where('payload', 'like', '%media%')
            ->chunkById(500, function ($messages) use (&$ids) {
                foreach ($messages as $message) {
                    if ($this->fixedPayload($message) !== null) {
                        $ids[] = (int) $message->id;
                    }
                }
            });

        return $ids;
    }

    /** Re-checks the message under a row lock before writing, so a stale list never writes. */
    private function fixMessage(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            $message = Message::query()->with('conversation')->lockForUpdate()->find($id);
            if ($message === null) {
                return false;
            }

            $payload = $this->fixedPayload($message);
            if ($payload === null) {
                return false;
            }

            $message->update(['payload' => $payload]);

            return true;
        });
    }

    /**
     * The message's payload with each broken login link replaced, or null when none is broken.
     * Only links that name this message and its own conversation id are changed; the WAHA
     * engine link under the type key is never touched.
     *
     * @return array<string, mixed>|null
     */
    private function fixedPayload(Message $message): ?array
    {
        $payload = $message->getAttribute('payload');
        if (! is_array($payload)) {
            return null;
        }

        $login = $this->mediaStore->authenticatedUrl($message);
        $fixed = $payload;
        $changed = false;

        foreach (['preview_url', 'link'] as $key) {
            if ($this->isBrokenLink($payload[$key] ?? null, $message)) {
                $fixed[$key] = $login;
                $changed = true;
            }
        }

        foreach ($payload as $key => $value) {
            if (is_array($value) && $this->isBrokenLink($value['link'] ?? null, $message)) {
                $fixed[$key]['link'] = $login;
                $changed = true;
            }
        }

        return $changed ? $fixed : null;
    }

    private function isBrokenLink(mixed $link, Message $message): bool
    {
        if (! is_string($link) || ! preg_match(self::BROKEN_LINK, $link, $match)) {
            return false;
        }

        return $match[1] === (string) $message->getAttribute('conversation_id')
            && $match[2] === (string) $message->getKey();
    }
}
