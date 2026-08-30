<?php

namespace App\Modules\Broadcasting\Services;

use App\Modules\Shared\Models\Contact;

/**
 * Substitutes per-recipient variables into campaign content.
 *
 * Supported tokens:
 *  - `{{contact.first_name}}`, `{{contact.last_name}}`, `{{contact.email}}`,
 *    `{{contact.phone_e164}}`, `{{contact.country}}`, `{{contact.language}}`
 *  - `{{contact.name}}` shorthand for full name
 *  - `{{contact.custom.<key>}}` for keys inside `custom_fields`
 *  - `{{context.<key>}}` for runtime extras (e.g. unsubscribe link)
 *
 * Meta-style positional placeholders such as `{{1}}` and `{{2}}` are not
 * substituted directly; instead, the wizard saves the contact-token strings
 * inside `template_ref.components` parameters, and we re-render those.
 */
class CampaignPersonalizer
{
    /** Render a free-form string with `{{contact.*}}` and `{{context.*}}` tokens. */
    public function renderText(string $template, Contact $contact, array $context = []): string
    {
        if ($template === '' || ! str_contains($template, '{{')) {
            return $template;
        }

        // {{contact.name}} shorthand — full name
        $template = str_replace('{{contact.name}}', $contact->full_name ?: '', $template);

        // {{contact.custom.foo}}
        $template = preg_replace_callback('/\{\{\s*contact\.custom\.([a-zA-Z0-9_\-]+)\s*\}\}/', function ($matches) use ($contact) {
            $key = $matches[1];

            return (string) ($contact->custom_fields[$key] ?? '');
        }, $template);

        // {{contact.<field>}}
        $template = preg_replace_callback('/\{\{\s*contact\.([a-zA-Z0-9_]+)\s*\}\}/', function ($matches) use ($contact) {
            $field = $matches[1];

            return (string) ($contact->{$field} ?? '');
        }, $template);

        // {{context.<key>}}
        if (! empty($context)) {
            $template = preg_replace_callback('/\{\{\s*context\.([a-zA-Z0-9_]+)\s*\}\}/', function ($matches) use ($context) {
                return (string) ($context[$matches[1]] ?? '');
            }, $template);
        }

        return $template;
    }

    /**
     * Walk a Meta WhatsApp template `components` array and render any
     * placeholder strings that reference contact fields.
     *
     * Meta shape:
     *  [
     *    [
     *      "type": "header"|"body"|"button",
     *      "sub_type": "url"|"quick_reply" (for buttons),
     *      "index": "0",
     *      "parameters": [
     *        { "type": "text", "text": "Hi {{contact.first_name}}" },
     *        { "type": "image", "image": { "link": "https://..." } },
     *        { "type": "document", "document": { "link": "...", "filename": "..." } },
     *        { "type": "video", "video": { "link": "..." } },
     *        { "type": "currency", "currency": { "code": "USD", "amount_1000": 12000, "fallback_value": "$12.00" } },
     *        { "type": "date_time", "date_time": { "fallback_value": "Feb 25" } },
     *      ]
     *    ]
     *  ]
     */
    public function renderTemplateComponents(array $components, Contact $contact, array $context = []): array
    {
        return array_map(function ($component) use ($contact, $context) {
            if (! is_array($component) || ! isset($component['parameters']) || ! is_array($component['parameters'])) {
                return $component;
            }

            $component['parameters'] = array_map(
                fn ($param) => $this->renderParameter($param, $contact, $context),
                $component['parameters'],
            );

            return $component;
        }, $components);
    }

    private function renderParameter(mixed $param, Contact $contact, array $context): mixed
    {
        if (! is_array($param)) {
            return $param;
        }

        // Plain text parameter
        if (isset($param['text']) && is_string($param['text'])) {
            $param['text'] = $this->renderText($param['text'], $contact, $context);
        }

        // Media link parameters: image / video / document
        foreach (['image', 'video', 'document'] as $mediaKey) {
            if (isset($param[$mediaKey]) && is_array($param[$mediaKey])) {
                if (isset($param[$mediaKey]['link']) && is_string($param[$mediaKey]['link'])) {
                    $param[$mediaKey]['link'] = $this->renderText($param[$mediaKey]['link'], $contact, $context);
                }
                if (isset($param[$mediaKey]['filename']) && is_string($param[$mediaKey]['filename'])) {
                    $param[$mediaKey]['filename'] = $this->renderText($param[$mediaKey]['filename'], $contact, $context);
                }
            }
        }

        // Currency / date_time fallback strings can also carry tokens
        foreach (['currency', 'date_time'] as $structuredKey) {
            if (isset($param[$structuredKey]['fallback_value']) && is_string($param[$structuredKey]['fallback_value'])) {
                $param[$structuredKey]['fallback_value'] = $this->renderText($param[$structuredKey]['fallback_value'], $contact, $context);
            }
        }

        return $param;
    }

    /**
     * Available contact token keys for the UI variable picker.
     *
     * @return array<int, array{key: string, label: string}>
     */
    public static function availableContactTokens(): array
    {
        return [
            ['key' => '{{contact.first_name}}', 'label' => 'First name'],
            ['key' => '{{contact.last_name}}', 'label' => 'Last name'],
            ['key' => '{{contact.name}}', 'label' => 'Full name'],
            ['key' => '{{contact.email}}', 'label' => 'Email'],
            ['key' => '{{contact.phone_e164}}', 'label' => 'Phone (E.164)'],
            ['key' => '{{contact.country}}', 'label' => 'Country'],
            ['key' => '{{contact.language}}', 'label' => 'Language'],
        ];
    }
}
