@component('mail::message')
# Weekly Report — {{ $workspace->name }}

Here's your performance summary for **{{ $period }}**.

---

## 📬 Messaging

@component('mail::table')
| Metric | Value |
|--------|-------|
| Messages Sent | {{ number_format($stats['messages_sent']) }} |
| Delivery Rate | {{ $stats['delivery_rate'] }}% |
| Conversations Opened | {{ number_format($stats['conversations_opened']) }} |
| Conversations Resolved | {{ number_format($stats['conversations_resolved']) }} |
@endcomponent

---

## 🤖 AI Usage

@component('mail::table')
| Metric | Value |
|--------|-------|
| Total Tokens | {{ number_format($stats['ai_tokens']) }} |
| Estimated Cost | ${{ number_format($stats['ai_cost_usd'], 2) }} |
| AI Runs | {{ number_format($stats['ai_runs']) }} |
@endcomponent

---

@if($stats['top_campaign'])
## 📣 Top Campaign

**{{ $stats['top_campaign']['name'] }}** — {{ $stats['top_campaign']['delivered_pct'] }}% delivered

@endif

@if($stats['top_chatbot'])
## 🧠 Top Chatbot

**{{ $stats['top_chatbot']['name'] }}** — {{ number_format($stats['top_chatbot']['runs']) }} runs

@endif

---

@component('mail::button', ['url' => $dashboardUrl, 'color' => 'indigo'])
Open Dashboard
@endcomponent

You're receiving this because weekly digest is enabled for your workspace.
To unsubscribe, visit your [report settings]({{ $settingsUrl }}).

Thanks,
{{ config('app.name') }}
@endcomponent
