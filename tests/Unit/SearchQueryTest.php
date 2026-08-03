<?php

namespace Tests\Unit;

use Modules\Project\Support\ParsedSearch;
use Modules\Project\Support\SearchQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The Trello search language.
 *
 * Two properties matter here. The first is that nothing a user types is ever
 * silently discarded — an unknown operator or an impossible value comes back as
 * free text, so a typo shows up in the results instead of quietly narrowing them
 * to nothing. The second is that a parsed query renders back to a string that
 * parses to the same query, because that string is both what the UI echoes and
 * what a saved filter stores.
 */
class SearchQueryTest extends TestCase
{
    // ---------------------------------------------------------------- text ---

    public function test_bare_words_are_free_text_in_the_order_typed(): void
    {
        $parsed = SearchQuery::parse('widget invoice scope');

        $this->assertSame(['widget', 'invoice', 'scope'], $parsed->terms);
        $this->assertSame([], $parsed->excludedTerms);
        $this->assertSame([], $parsed->filters);
        $this->assertFalse($parsed->isEmpty());
    }

    public function test_a_leading_hyphen_excludes_a_word_and_an_inner_one_does_not(): void
    {
        $parsed = SearchQuery::parse('re-scope -widget');

        $this->assertSame(['re-scope'], $parsed->terms);
        $this->assertSame(['widget'], $parsed->excludedTerms);
    }

    public function test_case_is_preserved_in_free_text(): void
    {
        $this->assertSame(['Widget'], SearchQuery::parse('Widget')->terms);
    }

    // ----------------------------------------------------------- operators ---

    public function test_every_text_operator_is_recognised(): void
    {
        $parsed = SearchQuery::parse(
            'member:nima board:clients list:todo label:red '.
            'name:widget description:scope checklist:deploy comment:blocked'
        );

        // Keys come back alphabetical, not in the order they were typed, so that
        // two spellings of one query are one query.
        $this->assertSame([
            'board' => ['clients'],
            'checklist' => ['deploy'],
            'comment' => ['blocked'],
            'description' => ['scope'],
            'label' => ['red'],
            'list' => ['todo'],
            'member' => ['nima'],
            'name' => ['widget'],
        ], $parsed->filters);

        $this->assertSame([], $parsed->terms);
    }

    public function test_every_text_operator_can_be_negated(): void
    {
        $parsed = SearchQuery::parse('-member:nima -board:clients -label:red -comment:blocked');

        $this->assertSame([
            'board' => ['clients'],
            'comment' => ['blocked'],
            'label' => ['red'],
            'member' => ['nima'],
        ], $parsed->excludedFilters);

        $this->assertSame([], $parsed->filters);
    }

    public function test_created_and_edited_take_a_period_or_a_day_count(): void
    {
        $parsed = SearchQuery::parse('created:7 created:week created:day created:month edited:month edited:14');

        $this->assertSame(['7', 'week', 'day', 'month'], $parsed->values('created'));
        $this->assertSame(['month', '14'], $parsed->values('edited'));
    }

    public function test_due_takes_a_window_a_day_count_or_a_state(): void
    {
        $parsed = SearchQuery::parse('due:day due:week due:month due:3 due:overdue due:complete due:incomplete');

        $this->assertSame(
            ['day', 'week', 'month', '3', 'overdue', 'complete', 'incomplete'],
            $parsed->values('due')
        );
    }

    public function test_has_and_is_take_their_enums_positively_and_negatively(): void
    {
        $parsed = SearchQuery::parse(
            'has:attachments has:cover has:members has:description has:stickers '.
            'is:open is:starred -is:archived -has:cover'
        );

        $this->assertSame(['attachments', 'cover', 'members', 'description', 'stickers'], $parsed->values('has'));
        $this->assertSame(['open', 'starred'], $parsed->values('is'));
        $this->assertSame(['archived'], $parsed->excluded('is'));
        $this->assertSame(['cover'], $parsed->excluded('has'));
    }

    public function test_keys_and_enum_values_are_case_insensitive_and_stored_lowercase(): void
    {
        $parsed = SearchQuery::parse('IS:Archived Has:COVER DUE:Overdue');

        $this->assertSame(['archived'], $parsed->values('is'));
        $this->assertSame(['cover'], $parsed->values('has'));
        $this->assertSame(['overdue'], $parsed->values('due'));
    }

    public function test_an_open_ended_value_keeps_the_case_it_was_typed_in(): void
    {
        $parsed = SearchQuery::parse('Label:Red board:"Client Work"');

        $this->assertSame(['Red'], $parsed->values('label'));
        $this->assertSame(['Client Work'], $parsed->values('board'));
    }

    // --------------------------------------------------------------- quotes ---

    public function test_a_quoted_value_keeps_its_spaces_and_loses_its_quotes(): void
    {
        $parsed = SearchQuery::parse('board:"Client Work" list:"To Do"');

        $this->assertSame(['Client Work'], $parsed->values('board'));
        $this->assertSame(['To Do'], $parsed->values('list'));
    }

    public function test_a_quoted_phrase_is_one_free_text_term(): void
    {
        $parsed = SearchQuery::parse('"Northwind retainer" widget');

        $this->assertSame(['Northwind retainer', 'widget'], $parsed->terms);
    }

    /**
     * The box is typed into live. Half a quoted phrase is what every quoted
     * phrase looks like a moment before it is finished, so it runs to the end of
     * the input rather than throwing at someone mid-word.
     */
    public function test_an_unterminated_quote_runs_to_the_end_of_the_input(): void
    {
        $parsed = SearchQuery::parse('board:"Client Work is:open');

        $this->assertSame(['Client Work is:open'], $parsed->values('board'));
        $this->assertSame([], $parsed->values('is'));
    }

    public function test_a_quoted_value_is_not_mistaken_for_an_operator(): void
    {
        $parsed = SearchQuery::parse('"label:red"');

        $this->assertSame(['label:red'], $parsed->terms);
        $this->assertSame([], $parsed->filters);
    }

    // ------------------------------------------------------- OR within key ---

    public function test_repeating_a_key_collects_its_values_for_an_or(): void
    {
        $parsed = SearchQuery::parse('label:red label:blue member:nima');

        $this->assertSame(['red', 'blue'], $parsed->values('label'));
        $this->assertSame(['nima'], $parsed->values('member'));
    }

    public function test_repeating_a_negated_key_collects_every_exclusion(): void
    {
        $parsed = SearchQuery::parse('-label:red -label:blue');

        $this->assertSame(['red', 'blue'], $parsed->excluded('label'));
        $this->assertSame([], $parsed->values('label'));
    }

    public function test_a_repeated_value_is_one_condition_not_two(): void
    {
        $parsed = SearchQuery::parse('label:Red label:red widget Widget');

        $this->assertSame(['Red'], $parsed->values('label'));
        $this->assertSame(['widget'], $parsed->terms);
    }

    // ------------------------------------------------------------ fallback ---

    public function test_an_unknown_key_becomes_free_text_rather_than_being_dropped(): void
    {
        $parsed = SearchQuery::parse('colour:red widget');

        $this->assertSame(['colour:red', 'widget'], $parsed->terms);
        $this->assertSame([], $parsed->filters);
    }

    public function test_a_known_key_with_a_nonsense_value_becomes_free_text(): void
    {
        $parsed = SearchQuery::parse('created:tomorrow is:cancelled has:bananas due:soon created:0');

        $this->assertSame(
            ['created:tomorrow', 'is:cancelled', 'has:bananas', 'due:soon', 'created:0'],
            $parsed->terms
        );
        $this->assertSame([], $parsed->filters);
    }

    public function test_an_unusable_operator_keeps_its_negation(): void
    {
        $parsed = SearchQuery::parse('-colour:red');

        $this->assertSame(['colour:red'], $parsed->excludedTerms);
        $this->assertSame([], $parsed->terms);
    }

    // ---------------------------------------------------------------- sort ---

    public function test_sort_is_a_directive_and_never_a_filter(): void
    {
        $parsed = SearchQuery::parse('sort:due');

        $this->assertSame('due', $parsed->sortField);
        $this->assertFalse($parsed->sortDescending);
        $this->assertSame([], $parsed->values('sort'));
        $this->assertArrayNotHasKey('sort', $parsed->filters);
        $this->assertFalse($parsed->has('sort'));
    }

    public function test_a_leading_hyphen_on_the_sort_field_means_descending(): void
    {
        $parsed = SearchQuery::parse('sort:-edited');

        $this->assertSame('edited', $parsed->sortField);
        $this->assertTrue($parsed->sortDescending);
    }

    public function test_every_sort_field_is_accepted(): void
    {
        foreach (SearchQuery::SORT_FIELDS as $field) {
            $this->assertSame($field, SearchQuery::parse("sort:{$field}")->sortField);
        }
    }

    public function test_the_last_sort_wins(): void
    {
        $parsed = SearchQuery::parse('sort:due sort:name sort:-created');

        $this->assertSame('created', $parsed->sortField);
        $this->assertTrue($parsed->sortDescending);
    }

    public function test_an_unusable_sort_falls_through_to_free_text(): void
    {
        $parsed = SearchQuery::parse('sort:colour -sort:due');

        $this->assertNull($parsed->sortField);
        $this->assertSame(['sort:colour'], $parsed->terms);
        $this->assertSame(['sort:due'], $parsed->excludedTerms);
    }

    public function test_a_sort_only_query_is_not_empty(): void
    {
        $this->assertFalse(SearchQuery::parse('sort:due')->isEmpty());
    }

    // --------------------------------------------------------------- mixed ---

    public function test_free_text_and_operators_mix_in_either_order(): void
    {
        $before = SearchQuery::parse('widget label:red "two words" -is:archived');
        $after = SearchQuery::parse('-is:archived label:red widget "two words"');

        $this->assertSame(['widget', 'two words'], $before->terms);
        $this->assertSame(['widget', 'two words'], $after->terms);
        $this->assertSame(['red'], $before->values('label'));
        $this->assertSame(['red'], $after->values('label'));
        $this->assertSame(['archived'], $before->excluded('is'));
        $this->assertSame(['archived'], $after->excluded('is'));
    }

    public function test_a_query_a_freelancer_would_actually_type(): void
    {
        $parsed = SearchQuery::parse('label:red -is:archived due:week member:nima "Northwind retainer" sort:-edited');

        $this->assertSame(['Northwind retainer'], $parsed->terms);
        $this->assertSame([], $parsed->excludedTerms);
        $this->assertSame(
            ['due' => ['week'], 'label' => ['red'], 'member' => ['nima']],
            $parsed->filters
        );
        $this->assertSame(['is' => ['archived']], $parsed->excludedFilters);
        $this->assertSame('edited', $parsed->sortField);
        $this->assertTrue($parsed->sortDescending);

        $this->assertSame(
            '"Northwind retainer" due:week -is:archived label:red member:nima sort:-edited',
            $parsed->toString()
        );
    }

    public function test_a_second_query_a_freelancer_would_actually_type(): void
    {
        $parsed = SearchQuery::parse('list:"To Do" has:attachments due:overdue -label:blocked invoice');

        $this->assertSame(['invoice'], $parsed->terms);
        $this->assertSame(['To Do'], $parsed->values('list'));
        $this->assertSame(['attachments'], $parsed->values('has'));
        $this->assertSame(['overdue'], $parsed->values('due'));
        $this->assertSame(['blocked'], $parsed->excluded('label'));
        $this->assertNull($parsed->sortField);
    }

    // -------------------------------------------------------------- degenerate ---

    /**
     * @return array<string, array{string}>
     */
    public static function degenerateInputs(): array
    {
        return [
            'empty' => [''],
            'spaces' => ['   '],
            'tabs and newlines' => ["\t\n "],
            'a lone hyphen' => ['-'],
            'a lone colon' => [':'],
            'two hyphens' => ['--'],
            'an empty operator' => ['label:'],
            'an empty unknown operator' => ['colour:'],
            'a negated empty operator' => ['-label:'],
            'punctuation only' => ['-: :- ---'],
            'an empty quote' => ['""'],
        ];
    }

    #[DataProvider('degenerateInputs')]
    public function test_degenerate_input_parses_to_nothing_without_throwing(string $input): void
    {
        $parsed = SearchQuery::parse($input);

        $this->assertTrue($parsed->isEmpty(), "'{$input}' should have parsed to an empty query");
        $this->assertSame('', $parsed->toString());
        $this->assertSame([], $parsed->terms);
        $this->assertSame([], $parsed->excludedTerms);
        $this->assertSame([], $parsed->filters);
        $this->assertSame([], $parsed->excludedFilters);
        $this->assertNull($parsed->sortField);
        $this->assertFalse($parsed->sortDescending);
    }

    // ------------------------------------------------------------ readers ---

    public function test_the_convenience_readers_answer_for_both_directions(): void
    {
        $parsed = SearchQuery::parse('label:red -member:nima');

        $this->assertTrue($parsed->has('label'));
        $this->assertTrue($parsed->has('member'));
        $this->assertTrue($parsed->has('MEMBER'));
        $this->assertFalse($parsed->has('board'));

        $this->assertSame(['red'], $parsed->values('label'));
        $this->assertSame([], $parsed->excluded('label'));
        $this->assertSame([], $parsed->values('member'));
        $this->assertSame(['nima'], $parsed->excluded('member'));
        $this->assertSame([], $parsed->values('nothing at all'));
    }

    public function test_an_empty_query_is_empty(): void
    {
        $this->assertTrue((new ParsedSearch)->isEmpty());
        $this->assertTrue(SearchQuery::parse('')->isEmpty());
    }

    // -------------------------------------------------------- round trip ---

    /**
     * @return array<string, array{string}>
     */
    public static function roundTripInputs(): array
    {
        return [
            'nothing' => [''],
            'whitespace' => ['   '],
            'one word' => ['widget'],
            'excluded word' => ['-widget'],
            'hyphenated word' => ['re-scope'],
            'quoted phrase' => ['"Northwind retainer"'],
            'quoted phrase excluded' => ['-"Northwind retainer"'],
            'text operators' => ['member:nima name:widget checklist:deploy comment:blocked'],
            'quoted operator values' => ['board:"Client Work" list:"To Do"'],
            'dates' => ['created:7 created:week edited:month due:day due:overdue due:complete due:incomplete'],
            'has and is' => ['has:attachments has:cover has:members has:description has:stickers is:open is:starred'],
            'negation' => ['-label:red -is:archived -has:cover'],
            'or within a key' => ['label:red label:blue label:green'],
            'sort ascending' => ['sort:due'],
            'sort descending' => ['sort:-edited'],
            'sort repeated' => ['sort:due sort:-name'],
            'unknown key' => ['colour:red'],
            'unknown key negated' => ['-colour:red'],
            'nonsense value' => ['created:tomorrow is:cancelled'],
            'unterminated quote' => ['board:"Client Work'],
            'quoted operator lookalike' => ['"label:red"'],
            'quoted leading hyphen' => ['"-widget"'],
            'degenerate punctuation' => ['- : -- label:'],
            'mixed, operators first' => ['label:red -is:archived due:week member:nima "Northwind retainer" sort:-edited'],
            'mixed, text first' => ['"Northwind retainer" widget -draft label:red label:blue -member:sam sort:name'],
            'the lot' => [
                'invoice -"old draft" board:"Client Work" list:"To Do" label:red label:blue -label:done '.
                'member:nima created:7 edited:month due:overdue has:attachments -is:archived '.
                'name:widget description:scope checklist:deploy comment:blocked colour:puce sort:-due',
            ],
        ];
    }

    /**
     * The parse is idempotent through its own rendering.
     *
     * This is the property that lets the UI echo "you are searching for …" and
     * lets a saved filter be stored as a string: rendering a parsed query and
     * parsing it again has to land on the same query, not merely a similar one.
     * Anything the parser normalises has to be normalised the same way the
     * second time round, and anything that needed quoting has to come back
     * quoted.
     */
    #[DataProvider('roundTripInputs')]
    public function test_rendering_and_reparsing_changes_nothing(string $input): void
    {
        $once = SearchQuery::parse($input);
        $twice = SearchQuery::parse($once->toString());

        $this->assertSame($once->toString(), $twice->toString(), 'the rendered query did not survive a reparse');
        $this->assertSame($once->terms, $twice->terms);
        $this->assertSame($once->excludedTerms, $twice->excludedTerms);
        $this->assertSame($once->filters, $twice->filters);
        $this->assertSame($once->excludedFilters, $twice->excludedFilters);
        $this->assertSame($once->sortField, $twice->sortField);
        $this->assertSame($once->sortDescending, $twice->sortDescending);

        // And a third pass, because idempotence that only holds once is luck.
        $this->assertSame($once->toString(), SearchQuery::parse($twice->toString())->toString());
    }

    public function test_the_rendered_form_is_canonical_however_it_was_typed(): void
    {
        $one = SearchQuery::parse('member:nima label:red -is:archived');
        $two = SearchQuery::parse('-is:archived label:red member:nima');

        $this->assertSame('-is:archived label:red member:nima', $one->toString());
        $this->assertSame($one->toString(), $two->toString());
    }

    public function test_the_object_renders_itself_when_used_as_a_string(): void
    {
        $parsed = SearchQuery::parse('label:red widget');

        $this->assertSame('widget label:red', (string) $parsed);
        $this->assertSame($parsed->toString(), (string) $parsed);
    }
}
