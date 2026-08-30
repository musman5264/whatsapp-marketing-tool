@component('mail::message')
# Payment Failed – Action Required

Hi {{ $name }},

A payment of **{{ $amount }} {{ $currency }}** for your {{ config('app.name') }} subscription has failed.

Please update your billing information as soon as possible to avoid interruption to your service.

@component('mail::button', ['url' => $actionUrl])
Update Billing Information
@endcomponent

If you have already updated your payment method, this email may have been sent before the retry was processed.

**Need help?** Reply to this email and our support team will be happy to assist you.

Thanks,
The {{ config('app.name') }} Team
@endcomponent
