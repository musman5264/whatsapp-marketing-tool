# Inbox paging, scroll and live media (chat page and list)

**Code:** `app/Modules/Inbox/Http/Controllers/InboxController.php` (`index`, `show`, `conversationPage`, `latestMessagePage`, `messagesPage`, `react`), route `client.inbox.messages.index` in `app/Modules/Inbox/routes/web.php`; `resources/js/Pages/Inbox/Index.jsx`, `resources/js/Pages/Inbox/Show.jsx`; helpers `resources/js/Utils/inboxPaging.js`, `resources/js/hooks/useLoadWhenVisible.js`.

## Conversation list
- The server pages the list 30 at a time, newest activity first (`last_message_at` desc, then `id` desc so pages never repeat or skip a row).
- The same query serves the inbox page, the list on the chat page and the JSON pages (`conversationPage`).
- A request that asks for JSON (`Accept: application/json`) gets the paginator as JSON. The browser follows `next_page_url`; a sentinel at the bottom of the list loads the next page when it scrolls into view and appends it.
- `next_page_url` always points at the inbox list route (`client.inbox.index`), also on the chat page. A link to the chat URL would return the chat page instead of JSON (fixed after a browser check; covered by `the_chat_pages_list_pages_through_the_inbox_list_url`).
- Known limit: the search box filters only the conversations already loaded. Searching older conversations needs a server-side search (not built).
- Known limit: when the page's props refresh (for example after a send), the list goes back to page 1; scroll again to load more.

## Chat messages
- Opening a chat sends only the newest 50 messages (`MESSAGE_PAGE`), oldest first, with `hasMoreMessages`.
- Older messages: `GET /app/inbox/conversations/{conversation}/messages?before_sent_at=…&before_id=…` returns the 50 messages immediately older than the cursor, oldest first, with `has_more`.
- The cursor is the oldest message on screen: `(sent_at, id)`. Ties in `sent_at` are broken by `id`, so pages never repeat or skip a message. Stored times are compared in the app timezone.
- Scrolling within 120 px of the top loads the next older page. The reading position is restored after older messages are added above.
- Reactions: the endpoint returns the new reaction row (`message`); the chat appends it. The chat no longer reloads the whole thread.
- Media in the chat: fixed-size boxes (16 rem × 12 rem) so a photo that loads does not push the chat around. Stored WhatsApp Web media arrives as `MessageMediaReady` and updates the bubble (see `waha-sync-and-labels.md`, Media).
- Media links: a payload's `preview_url` is used as an image source or link only when it is `http(s)://` or a same-site path (`safeMediaUrl` in `Show.jsx`). Outbound payloads are stored as the agent sent them, so anything else falls back to the server media route. For a composer image, `reply()` sets `preview_url` itself to the login route, which is an `http(s)://` link (T-0031; see `private-media.md`). Checked in the browser with a `javascript:` document link: the rendered link points at the server route.
- Loading older messages shows a small overlay at the top of the chat, not a line inside the scroll area, so it does not move the messages (the reader's place stays put; measured in the browser).

## Scroll rules
- Opening a chat: lands at the bottom.
- A new message: followed when the reader is within 120 px of the bottom, or when the reader sent it.
- Reader scrolled up: the view does not move. A "N new message(s)" button appears at the bottom; clicking it scrolls to the newest message.
- Older messages added above: the view keeps the same message in place.

## Tests
- `tests/Feature/Inbox/ConversationListPagingTest.php` (list pages, JSON, Inertia visit).
- `tests/Feature/Inbox/MessagePagingTest.php` (newest page on open, older pages with no gaps or repeats, same-second ties, workspace check).
- `tests/Feature/Inbox/InboxReactionEndpointTest.php` (reaction returns the new row).

## Not verified on the live site
- Browser behaviour of the scroll rules, the new-messages button and the list's load-more at 360 px and 1920 px, light and dark. Needs a browser check on wa.esystematics.com.
