# Public API: create contact (upsert)

## What it does
`POST /api/v1/contacts` (token with `contacts:write`) creates a contact, or returns the existing one
when the phone number is already in the workspace. It never fails with a server error for a repeated number.

## Responses
- New contact → **201** with the contact (`data.id`).
- Phone already exists (or email, when no phone is sent) → **200** with the existing contact.
  Fields sent in the request overwrite the stored values; fields not sent are kept.
- Phone matches a soft-deleted contact → the contact is restored and returned with **200**.
- Invalid phone format → **422** (`phone_e164` must look like `+923216927688`).

## Where the code is
- Controller: `app/Http/Controllers/Api/V1/ContactApiController.php` → `store()`.
- Upsert logic: `app/Modules/Shared/Services/ContactService.php` → `upsert()`.
- Route: `routes/api.php` (`/contacts`, `api.ability:contacts:write`).

## Why it changed
The table has a unique index on `(workspace_id, phone_e164)`, which includes soft-deleted rows.
The endpoint used to insert directly, so a repeated number caused a database error and a
`500 Server Error` with no contact id. The endpoint now uses the same upsert as the import and sync code.

## Events
`contact.created` automations and outbound webhooks are **not** fired by this endpoint, as before
(`dispatchCreatedEvent: false`). Changing that would start automations for every API import.

## Tests
`tests/Feature/Api/V1/ContactApiTest.php` — `test_create_contact_returns_201`,
`test_create_existing_phone_returns_200_with_same_contact`,
`test_create_restores_soft_deleted_contact_with_same_phone`, `test_create_contact_invalid_phone_returns_422`.

## Unverified
- Not yet checked against the live site with a real token.
