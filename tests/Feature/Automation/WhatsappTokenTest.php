<?php

namespace Tests\Feature\Automation;

use App\Modules\Automation\Services\AutomationEngine;
use App\Modules\Shared\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class WhatsappTokenTest extends TestCase
{
    use RefreshDatabase;

    private function render(string $tpl, Contact $contact, array $context = []): string
    {
        $engine = app(AutomationEngine::class);
        $m = new ReflectionMethod($engine, 'renderTokens');
        $m->setAccessible(true);

        return $m->invoke($engine, $tpl, $contact, $context);
    }

    #[Test]
    public function whatsapp_number_and_name_resolve_for_a_walk_in_with_only_a_push_name(): void
    {
        $c = new Contact;
        $c->phone_e164 = '+923001234567';
        $c->custom_fields = ['wa_push_name' => 'Ali Raza'];

        $out = $this->render('Hi {{whatsapp.name}}, we got your message on {{whatsapp.number}}.', $c);
        $this->assertSame('Hi Ali Raza, we got your message on +923001234567.', $out);
    }

    #[Test]
    public function whatsapp_name_falls_back_to_the_number_when_nothing_is_known(): void
    {
        $c = new Contact;
        $c->phone_e164 = '+923009999999';

        $this->assertSame('+923009999999', $this->render('{{whatsapp.name}}', $c));
    }

    #[Test]
    public function contact_name_falls_back_to_there_for_an_anonymous_walk_in(): void
    {
        $c = new Contact;
        $c->phone_e164 = '+15555550000';

        $this->assertSame('Hi there!', $this->render('Hi {{contact.name}}!', $c));
    }

    #[Test]
    public function contact_name_prefers_a_saved_name_over_the_push_name(): void
    {
        $c = new Contact;
        $c->first_name = 'Jane';
        $c->last_name = 'Doe';
        $c->phone_e164 = '+15555551111';
        $c->custom_fields = ['wa_push_name' => 'janed92'];

        $this->assertSame('Hi Jane Doe', $this->render('Hi {{contact.name}}', $c));
        $this->assertSame('janed92', $this->render('{{whatsapp.name}}', $c));
    }

    #[Test]
    public function contact_number_alias_and_customer_alias_work(): void
    {
        $c = new Contact;
        $c->phone_e164 = '+441234567890';
        $c->custom_fields = ['wa_push_name' => 'Sam'];

        $this->assertSame('+441234567890', $this->render('{{contact.number}}', $c));
        $this->assertSame('Sam / +441234567890', $this->render('{{customer.name}} / {{customer.number}}', $c));
    }

    #[Test]
    public function whitespace_inside_the_token_braces_is_tolerated(): void
    {
        $c = new Contact;
        $c->phone_e164 = '+10000000000';
        $c->custom_fields = ['wa_push_name' => 'Kim'];

        $this->assertSame('Kim', $this->render('{{ whatsapp.name }}', $c));
    }
}
