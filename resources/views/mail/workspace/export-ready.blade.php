@component('mail::message')
# Your Data Export is Ready

Hi {{ $name }},

Your workspace data export has been generated and is ready for download.

@component('mail::button', ['url' => $downloadUrl])
Download Export (ZIP)
@endcomponent

**This link will expire in 72 hours.** If you need a new export after it expires, you can request one from your Settings page.

If you did not request this export, please contact our support team immediately.

Thanks,
The {{ config('app.name') }} Team
@endcomponent
