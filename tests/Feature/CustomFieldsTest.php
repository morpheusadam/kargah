<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Project\Models\Board;
use Modules\Project\Models\BoardList;
use Modules\Project\Models\Card;
use Modules\Project\Models\CustomField;
use Modules\Project\Models\CustomFieldValue;
use Modules\Project\Services\CardService;
use Modules\Project\Services\CustomFields;
use Modules\Project\Support\CustomFieldType;
use Modules\Project\Support\Position;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Custom fields — an EAV pair, definition per board, value per card.
 *
 * `CustomFields` (the service) is what every test here goes through rather
 * than writing rows directly, because the interesting behaviour — the type
 * guard, the two caps, the transactional delete, the idempotent write — all
 * lives there or on the model, never in a controller or a component.
 */
class CustomFieldsTest extends TestCase
{
    use RefreshDatabase;

    private Board $board;

    private BoardList $list;

    private CustomFields $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['name' => 'Nima Fazlipour']));

        $this->board = Board::factory()->create(['name' => 'Client Work', 'slug' => 'client-work']);
        $this->list = BoardList::factory()->for($this->board)->create([
            'name' => 'Backlog',
            'position' => Position::format('1024'),
        ]);

        $this->service = app(CustomFields::class);
    }

    private function card(string $title = 'Draft the Q3 expense summary'): Card
    {
        return app(CardService::class)->append($this->list, $title);
    }

    /* Every type stores and reads back ------------------------------------------ */

    #[DataProvider('typeExamples')]
    public function test_each_type_stores_and_reads_back(CustomFieldType $type, string $raw, string $expectedDisplay): void
    {
        $field = CustomField::factory()->for($this->board)->type($type)->create(['name' => 'Field under test']);
        $card = $this->card();

        $value = $this->service->setValue($card, $field, $raw);

        $this->assertNotNull($value);
        $this->assertSame($expectedDisplay, $value->fresh()->display());

        // Read back through the service too, not only the row it handed back.
        $reread = $this->service->valueFor($card, $field);
        $this->assertSame($expectedDisplay, $reread?->display());
    }

    public static function typeExamples(): array
    {
        return [
            'checkbox' => [CustomFieldType::Checkbox, '1', 'Yes'],
            'date' => [CustomFieldType::Date, '2026-09-01', '1 Sep 2026'],
            'number' => [CustomFieldType::Number, '42.5', '42.5'],
            'text' => [CustomFieldType::Text, 'Bespoke onboarding request', 'Bespoke onboarding request'],
        ];
    }

    public function test_a_dropdown_value_reads_back_the_option_label(): void
    {
        $field = CustomField::factory()->for($this->board)->withOptions(['Bronze', 'Silver', 'Gold'])->create([
            'name' => 'Client tier',
        ]);
        $card = $this->card();

        $silverId = collect($field->options())->firstWhere('label', 'Silver')['id'];

        $value = $this->service->setValue($card, $field, (string) $silverId);

        $this->assertSame('Silver', $value?->fresh()->display());
    }

    /* The type is immutable ------------------------------------------------------ */

    public function test_the_type_cannot_change_after_creation(): void
    {
        $field = CustomField::factory()->for($this->board)->type(CustomFieldType::Text)->create(['name' => 'Notes']);

        $this->expectException(RuntimeException::class);

        try {
            $field->update(['type' => CustomFieldType::Number]);
        } finally {
            $this->assertSame('text', CustomField::query()->findOrFail($field->id)->type->value, 'The row must not have moved.');
        }
    }

    public function test_a_dropdown_already_carrying_values_still_refuses_to_become_a_number(): void
    {
        $field = CustomField::factory()->for($this->board)->withOptions(['Bronze'])->create(['name' => 'Client tier']);
        $card = $this->card();
        $this->service->setValue($card, $field, (string) $field->options()[0]['id']);

        $this->expectException(RuntimeException::class);
        $field->update(['type' => CustomFieldType::Number]);
    }

    /* The 50-per-board cap -------------------------------------------------------- */

    public function test_the_fiftieth_field_is_allowed_and_the_fifty_first_is_refused(): void
    {
        for ($i = 1; $i <= CustomFields::MAX_FIELDS_PER_BOARD; $i++) {
            $this->service->define($this->board, 'Field '.$i, CustomFieldType::Text);
        }

        $this->assertSame(CustomFields::MAX_FIELDS_PER_BOARD, CustomField::query()->where('board_id', $this->board->id)->count());

        try {
            $this->service->define($this->board, 'One too many', CustomFieldType::Text);
            $this->fail('The 51st field must be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('50', $e->getMessage());
        }

        $this->assertSame(CustomFields::MAX_FIELDS_PER_BOARD, CustomField::query()->where('board_id', $this->board->id)->count());
    }

    /* The 50-option-per-dropdown cap ----------------------------------------------- */

    public function test_the_fiftieth_option_is_allowed_and_the_fifty_first_is_refused(): void
    {
        $field = $this->service->define($this->board, 'Client tier', CustomFieldType::Dropdown);

        for ($i = 1; $i <= CustomFields::MAX_OPTIONS_PER_FIELD; $i++) {
            $this->service->addOption($field, 'Tier '.$i);
        }

        $field->refresh();
        $this->assertCount(CustomFields::MAX_OPTIONS_PER_FIELD, $field->options());

        try {
            $this->service->addOption($field, 'One too many');
            $this->fail('The 51st option must be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('50', $e->getMessage());
        }

        $this->assertCount(CustomFields::MAX_OPTIONS_PER_FIELD, $field->refresh()->options());
    }

    /** Renaming an option keeps its id, so a card set to it is never orphaned. */
    public function test_renaming_a_dropdown_option_keeps_every_card_pointed_at_it(): void
    {
        $field = CustomField::factory()->for($this->board)->withOptions(['Bronze', 'Silver'])->create(['name' => 'Client tier']);
        $bronzeId = collect($field->options())->firstWhere('label', 'Bronze')['id'];
        $card = $this->card();

        $this->service->setValue($card, $field, (string) $bronzeId);

        $this->service->renameOption($field, $bronzeId, 'Starter');
        $field->refresh();

        $this->assertSame('Starter', $this->service->valueFor($card, $field)?->display());
        $this->assertSame($bronzeId, $this->service->valueFor($card, $field)?->value_option_id, 'The id must not have changed.');
    }

    /** Deleting an option clears the cards pointed at it rather than leaving a dangling id. */
    public function test_removing_a_dropdown_option_clears_the_cards_pointed_at_it(): void
    {
        $field = CustomField::factory()->for($this->board)->withOptions(['Bronze', 'Silver'])->create(['name' => 'Client tier']);
        $bronzeId = collect($field->options())->firstWhere('label', 'Bronze')['id'];
        $card = $this->card();

        $this->service->setValue($card, $field, (string) $bronzeId);

        $this->service->removeOption($field, $bronzeId);

        $this->assertNull($this->service->valueFor($card, $field)?->value_option_id);
    }

    /* Deleting a definition wipes every value, in one transaction -------------------- */

    public function test_deleting_a_definition_wipes_every_value_and_reports_the_count(): void
    {
        $field = CustomField::factory()->for($this->board)->type(CustomFieldType::Text)->create(['name' => 'Notes']);

        $cards = [$this->card('Card one'), $this->card('Card two'), $this->card('Card three')];

        foreach ($cards as $index => $card) {
            $this->service->setValue($card, $field, 'value '.$index);
        }

        $this->assertSame(3, CustomFieldValue::query()->where('custom_field_id', $field->id)->count());

        $wiped = $this->service->delete($field);

        $this->assertSame(3, $wiped);
        $this->assertSame(0, CustomFieldValue::query()->where('custom_field_id', $field->id)->count());
        $this->assertNull(CustomField::query()->find($field->id));

        // Every card survives — only the field and its values are gone.
        foreach ($cards as $card) {
            $this->assertNotNull(Card::query()->find($card->id));
        }
    }

    public function test_deleting_a_definition_with_no_values_reports_zero(): void
    {
        $field = CustomField::factory()->for($this->board)->type(CustomFieldType::Text)->create(['name' => 'Notes']);

        $this->assertSame(0, $this->service->delete($field));
        $this->assertNull(CustomField::query()->find($field->id));
    }

    /* Runs twice, changes nothing ---------------------------------------------------- */

    public function test_setting_the_same_value_twice_writes_nothing_the_second_time(): void
    {
        $field = CustomField::factory()->for($this->board)->type(CustomFieldType::Number)->create(['name' => 'Estimated hours']);
        $card = $this->card();

        $first = $this->service->setValue($card, $field, '12.5');
        $updatedAt = $first->fresh()->updated_at;

        $this->travel(5)->minutes();

        DB::enableQueryLog();
        $second = $this->service->setValue($card, $field, '12.5');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(1, CustomFieldValue::query()->where('custom_field_id', $field->id)->count());
        $this->assertSame($first->id, $second->id);
        $this->assertTrue($updatedAt->equalTo($second->fresh()->updated_at), 'updated_at moved on an unchanged write.');

        $writes = array_filter($queries, fn (array $q): bool => str_starts_with(strtolower($q['query']), 'update')
            || str_starts_with(strtolower($q['query']), 'insert'));

        $this->assertSame([], array_values($writes), 'A second identical write must not touch the database.');
    }

    public function test_clearing_a_value_removes_the_row_rather_than_saving_blanks(): void
    {
        $field = CustomField::factory()->for($this->board)->type(CustomFieldType::Text)->create(['name' => 'Notes']);
        $card = $this->card();

        $this->service->setValue($card, $field, 'Something worth recording');
        $this->assertSame(1, CustomFieldValue::query()->count());

        $this->service->setValue($card, $field, '');

        $this->assertSame(0, CustomFieldValue::query()->count());
    }

    /* The number sort — the whole point of typed columns ------------------------------ */

    public function test_number_values_sort_numerically_not_lexically(): void
    {
        $field = CustomField::factory()->for($this->board)->type(CustomFieldType::Number)->create(['name' => 'Estimated hours']);

        $two = $this->card('Two hours');
        $nine = $this->card('Nine hours');
        $ten = $this->card('Ten hours');

        $this->service->setValue($ten, $field, '10');
        $this->service->setValue($two, $field, '2');
        $this->service->setValue($nine, $field, '9');

        // A text column storing "2", "9", "10" would sort 10, 2, 9 — this is
        // the assertion that decision exists to fail.
        $ordered = CustomFieldValue::query()
            ->where('custom_field_id', $field->id)
            ->orderBy('value_number')
            ->with('card')
            ->get()
            ->pluck('card.title');

        $this->assertSame(['Two hours', 'Nine hours', 'Ten hours'], $ordered->all());
    }

    /* Migration reversibility --------------------------------------------------------- */

    public function test_the_migration_rolls_back_and_forward_cleanly(): void
    {
        $field = CustomField::factory()->for($this->board)->type(CustomFieldType::Text)->create(['name' => 'Notes']);
        $card = $this->card();
        $this->service->setValue($card, $field, 'Kept only to prove the round trip');

        $migration = require base_path('Modules/Project/database/migrations/2026_08_04_000002_create_custom_fields_tables.php');

        $migration->down();

        $this->assertFalse(DB::getSchemaBuilder()->hasTable('custom_field_values'));
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('custom_fields'));

        $migration->up();

        $this->assertTrue(DB::getSchemaBuilder()->hasTable('custom_fields'));
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('custom_field_values'));
        $this->assertSame(0, DB::table('custom_fields')->count(), 'The tables come back empty — down() drops the data with the schema.');
    }

    /* A mirrored card sees only the fields of the board it lives on ------------------- */

    public function test_a_mirrored_card_shows_only_its_origin_boards_fields(): void
    {
        $otherBoard = Board::factory()->create(['name' => 'Marketing Site', 'slug' => 'marketing-site']);
        $otherList = BoardList::factory()->for($otherBoard)->create(['name' => 'Ideas', 'position' => Position::format('1024')]);

        CustomField::factory()->for($this->board)->type(CustomFieldType::Text)->create(['name' => 'Client reference']);
        CustomField::factory()->for($otherBoard)->type(CustomFieldType::Text)->create(['name' => 'Campaign code']);

        $card = $this->card('Shared piece of work');

        // Mirror it onto the other board — the same card, shown a second place.
        app(CardService::class)->mirror($card, $otherList);

        $component = Livewire::test('project::card-custom-fields', ['cardId' => $card->id]);

        $component->assertSee('Client reference');
        $component->assertDontSee('Campaign code');
    }

    public function test_a_card_with_no_custom_fields_renders_nothing_extra(): void
    {
        $card = $this->card();

        Livewire::test('project::card-custom-fields', ['cardId' => $card->id])
            ->assertDontSee('Custom fields');
    }

    public function test_typing_a_value_in_the_card_component_persists_it(): void
    {
        $field = CustomField::factory()->for($this->board)->type(CustomFieldType::Text)->create(['name' => 'Notes']);
        $card = $this->card();

        Livewire::test('project::card-custom-fields', ['cardId' => $card->id])
            ->set("drafts.{$field->id}", 'Client wants a mid-August delivery')
            ->call('setValue', $field->id);

        $this->assertSame(
            'Client wants a mid-August delivery',
            $this->service->valueFor($card, $field)?->value_text,
        );
    }
}
