<?php

namespace Tests\Feature\Automation;

use App\Modules\Automation\Services\AutomationEngine;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * AutomationEngine::evaluateCondition() — the pure boolean used by both live
 * runs and the builder's Test simulation. Focus: the `contains` / `not_contains`
 * / `equals` operators must treat a comma-separated Value as an OR-list of
 * keywords and compare case-insensitively.
 */
class ConditionOperatorTest extends TestCase
{
    private function engine(): AutomationEngine
    {
        return app(AutomationEngine::class);
    }

    /** @param array<string,mixed> $context */
    private function evaluate(string $operator, string $value, array $context): bool
    {
        return $this->engine()->evaluateCondition(
            ['field' => 'context.user_help', 'operator' => $operator, 'value' => $value],
            null,
            $context,
        );
    }

    #[Test]
    public function contains_matches_any_one_of_a_comma_list(): void
    {
        $this->assertTrue($this->evaluate('contains', 'job,internship', ['user_help' => 'I want a job']));
        $this->assertTrue($this->evaluate('contains', 'job,internship', ['user_help' => 'looking for an internship']));
        $this->assertFalse($this->evaluate('contains', 'job,internship', ['user_help' => 'just browsing your site']));
    }

    #[Test]
    public function contains_is_case_insensitive(): void
    {
        $this->assertTrue($this->evaluate('contains', 'job,internship', ['user_help' => 'I NEED A JOB']));
        $this->assertTrue($this->evaluate('contains', 'Job', ['user_help' => 'a job please']));
    }

    #[Test]
    public function contains_without_a_comma_still_works(): void
    {
        $this->assertTrue($this->evaluate('contains', 'job', ['user_help' => 'a job here']));
        $this->assertFalse($this->evaluate('contains', 'job', ['user_help' => 'nothing relevant']));
    }

    #[Test]
    public function contains_trims_whitespace_around_each_keyword(): void
    {
        $this->assertTrue($this->evaluate('contains', 'job ,  internship', ['user_help' => 'need an internship']));
    }

    #[Test]
    public function not_contains_is_true_only_when_none_of_the_keywords_match(): void
    {
        $this->assertTrue($this->evaluate('not_contains', 'job,internship', ['user_help' => 'just browsing']));
        $this->assertFalse($this->evaluate('not_contains', 'job,internship', ['user_help' => 'need an INTERNSHIP']));
    }

    #[Test]
    public function not_contains_is_true_when_the_field_is_empty(): void
    {
        $this->assertTrue($this->evaluate('not_contains', 'job,internship', []));
        $this->assertTrue($this->evaluate('not_contains', 'job,internship', ['user_help' => '']));
    }

    #[Test]
    public function equals_is_case_insensitive(): void
    {
        $this->assertTrue($this->evaluate('equals', 'Yes', ['user_help' => 'yes']));
        $this->assertTrue($this->evaluate('not_equals', 'Yes', ['user_help' => 'no']));
        $this->assertFalse($this->evaluate('not_equals', 'Yes', ['user_help' => 'YES']));
    }
}
