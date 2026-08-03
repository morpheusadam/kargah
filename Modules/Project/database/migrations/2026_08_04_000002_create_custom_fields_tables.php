<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custom fields — an EAV pair, definition per board and value per card.
 *
 * Two tables, not three. Dropdown options are the one part of a definition that
 * needs a stable identity independent of its label — renaming "Bronze" to
 * "Starter" must not orphan every card already set to it — but a whole extra
 * table (plus model, plus factory) is more machinery than fifty rows per board
 * justify. `custom_fields.options` is a JSON array of `{id, label}` pairs
 * instead: `id` is a small integer assigned once and never reused within the
 * field, `label` is the only thing renaming touches, and `custom_field_values`
 * stores the id, never the label. This is the thing the spec explicitly warns
 * against doing wrong — "a JSON array of strings ... makes rename-orphans
 * inevitable" — and the fix is the pair, not dropping JSON altogether.
 *
 * `custom_field_values` carries one column per type — `value_text`,
 * `value_number`, `value_date`, `value_boolean` — rather than a single text
 * column holding everything as a string. The reason is sorting, not typing: a
 * text column holding "2", "9", "10" sorts as 10, 2, 9, because that is what a
 * lexicographic comparison of digits does. A NUMERIC-affinity column sorts by
 * value. `CustomFieldsTest::test_number_values_sort_numerically_not_lexically`
 * is the test this decision exists to pass.
 *
 * `value_number` is `decimal(20,6)`, the same column family Accounting's money
 * uses — and SQLite gives it the same double-precision storage DECISIONS.md
 * already measured at fourteen significant digits. This is **not** a money
 * column and nothing here ever sums or multiplies it in SQL or PHP; it exists
 * so a freelancer can record "estimated hours: 12.5" or "budget cap: 3000" on a
 * card and sort a list by it. If a board ever wants an actual currency amount
 * on a custom field, that value belongs in Accounting's money layer, not here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_fields', function (Blueprint $table) {
            $table->id();

            $table->foreignId('board_id')->constrained('boards')->cascadeOnDelete();

            $table->string('name', 60);

            // 'checkbox' | 'date' | 'dropdown' | 'number' | 'text' —
            // Modules\Project\Support\CustomFieldType. Immutable after creation;
            // enforced on the model, not here, because a database default has no
            // way to say "only on insert".
            $table->string('type', 20);

            // Dropdown only: [{"id": 1, "label": "Bronze"}, ...]. Null for every
            // other type. See the class doc above for why this is JSON rather
            // than a third table.
            $table->json('options')->nullable();

            // A short list a person reorders with two buttons, not a fractional
            // column: fifty rows is not the case `Position` exists for.
            $table->unsignedInteger('position')->default(0);

            $table->timestamps();

            $table->index(['board_id', 'position']);
        });

        Schema::create('custom_field_values', function (Blueprint $table) {
            $table->id();

            $table->foreignId('custom_field_id')->constrained('custom_fields')->cascadeOnDelete();
            $table->foreignId('card_id')->constrained('cards')->cascadeOnDelete();

            $table->text('value_text')->nullable();
            $table->decimal('value_number', 20, 6)->nullable();
            $table->date('value_date')->nullable();
            $table->boolean('value_boolean')->nullable();

            // References an id inside the field's own `options` JSON. Not a
            // foreign key — JSON has no rows for one to point at — validated in
            // Modules\Project\Services\CustomFields instead.
            $table->unsignedInteger('value_option_id')->nullable();

            $table->timestamps();

            // One value per field per card. `setValue()` reads this row before
            // writing it, which is what makes "runs twice, changes nothing" true
            // rather than merely intended.
            $table->unique(['custom_field_id', 'card_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_field_values');
        Schema::dropIfExists('custom_fields');
    }
};
