<?php

use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Project\Models\Board;
use Modules\Project\Models\BoardList;
use Modules\Project\Models\Card;
use Modules\Project\Models\CardPlacement;
use Modules\Project\Models\CustomField;
use Modules\Project\Models\Label;
use Modules\Project\Services\CustomFields;
use Modules\Project\Support\CustomFieldType;
use Modules\Project\Support\Palette;
use Modules\Project\Support\Position;

// No `use RuntimeException;` here on purpose. Livewire 4 compiles this file's
// class block into a namespaced class of its own, and PHP treats importing a
// root-namespace name with no effect as a warning there — which Laravel's
// error handler promotes to an `ErrorException` and takes the whole page down
// with it. `\RuntimeException` at each throw site instead.

/**
 * Board settings, reading from the database.
 *
 * Everything that belongs to the board rather than to a card: its name and
 * description, its colour, the labels every card picks from, the order of its
 * lists, and the two ways to retire it.
 *
 * Three things are worth knowing before changing anything.
 *
 * **The route carries a slug, and the slug never changes.** `/projects/{board}`
 * is a link somebody may have bookmarked or pasted into an invoice; renaming
 * the board rewrites what it is called, not where it lives. A rename that moved
 * the URL would break every link to it and log the user out of their own page.
 *
 * **An unknown slug is an empty state, not a 404.** The smoke test walks every
 * route against an empty database, and a page that only renders when somebody
 * has seeded it is a page nobody can prove renders at all.
 *
 * **Reordering a list writes one row.** Lists share the cards' fractional
 * position column, so moving one takes the midpoint of its new neighbours. The
 * only path that writes every row is the rebalance, and it is reached when two
 * neighbours have been halved apart past `Position::MIN_GAP`.
 */
new
#[Title('Board settings — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    /** Board slug from the route. Read-only for the life of the page. */
    public string $board = '';

    #[Validate('required|min:2|max:40')]
    public string $name = '';

    #[Validate('nullable|max:2000')]
    public string $description = '';

    /** A `Palette` key, never a class string. */
    public string $colour = 'primary';

    /** Label being edited inline, by id. */
    public ?int $editingLabel = null;

    public string $labelDraft = '';

    public string $labelColourDraft = 'primary';

    public string $newLabelName = '';

    public string $newLabelColour = 'primary';

    /** List being renamed inline, by id. */
    public ?int $editingList = null;

    public string $listDraft = '';

    /** New custom field form. */
    public string $newFieldName = '';

    public string $newFieldType = 'text';

    /** Custom field being renamed inline, by id. */
    public ?int $editingCustomField = null;

    public string $customFieldDraft = '';

    /** Custom field awaiting delete confirmation, by id — set once the value count is known. */
    public ?int $confirmingCustomFieldDelete = null;

    public int $confirmingCustomFieldDeleteCount = 0;

    /** New dropdown option, one draft per field id. */
    public array $newOptionDraft = [];

    /** Dropdown option being renamed inline, as "{fieldId}:{optionId}". */
    public ?string $editingOption = null;

    public string $optionDraft = '';

    public bool $confirmingDelete = false;

    public string $deleteConfirmation = '';

    /** Per-request memo. Private, so Livewire neither ships nor rehydrates it. */
    private ?Board $resolvedBoard = null;

    public function mount(string $board): void
    {
        $this->board = $board;

        $record = $this->board();

        if ($record === null) {
            return;
        }

        $this->name = $record->name;
        $this->description = (string) $record->description;
        $this->colour = Palette::has($record->colour) ? $record->colour : 'neutral';
    }

    /* Reading the board ------------------------------------------------------ */

    /**
     * The board this page is about, archived or not.
     *
     * An archived board keeps its settings page on purpose: it is where you go
     * to read what it was, and the archive is where you go to bring it back.
     */
    private function board(): ?Board
    {
        return $this->resolvedBoard ??= Board::query()->where('slug', $this->board)->first();
    }

    private function forgetBoard(): void
    {
        $this->resolvedBoard = null;
    }

    /** @return Collection<int, Label> */
    private function labels(): Collection
    {
        $board = $this->board();

        return $board === null
            ? collect()
            : $board->labels()->withCount('cards')->orderBy('position')->orderBy('name')->get();
    }

    /** @return Collection<int, CustomField> */
    private function customFields(): Collection
    {
        $board = $this->board();

        return $board === null ? collect() : app(CustomFields::class)->fieldsFor($board);
    }

    /** @return Collection<int, BoardList> */
    private function lists(): Collection
    {
        $board = $this->board();

        if ($board === null) {
            return collect();
        }

        return BoardList::query()
            ->where('board_id', $board->id)
            ->active()
            ->orderBy('position')
            ->orderBy('id')
            ->withCount(['cards' => fn ($query) => $query->active()])
            ->get();
    }

    public function with(): array
    {
        $board = $this->board();
        $lists = $this->lists();

        return [
            'record' => $board,
            'colours' => Palette::all(),
            'labels' => $this->labels(),
            'lists' => $lists,
            'archivedLists' => $board === null
                ? 0
                : BoardList::query()->where('board_id', $board->id)->whereNotNull('archived_at')->count(),
            'cardTotal' => $lists->sum('cards_count'),
            'customFields' => $this->customFields(),
            'customFieldTypes' => CustomFieldType::cases(),
        ];
    }

    /* Name, description and colour ------------------------------------------- */

    /** Save the board's name and description. The slug is left alone. */
    public function renameBoard(): void
    {
        $board = $this->board();

        if ($board === null) {
            $this->toastError('There is no board at this address', 'Pick one from the boards page.');

            return;
        }

        $this->validate();

        $name = trim($this->name);
        $description = trim($this->description);

        $renamed = $board->name !== $name;
        $described = (string) $board->description !== $description;

        if (! $renamed && ! $described) {
            $this->toastSuccess('Nothing to save', 'The name and description already read like that.');

            return;
        }

        $was = $board->name;

        $board->forceFill([
            'name' => $name,
            'description' => $description === '' ? null : $description,
        ])->save();

        activity('board')
            ->performedOn($board)
            ->causedBy(auth()->user())
            ->event('board.updated')
            ->withProperties(['from' => $was, 'to' => $name])
            ->log($renamed ? 'renamed from '.$was.' to '.$name : 'description updated');

        $this->name = $board->name;
        $this->description = (string) $board->description;

        $this->toastSuccess(
            $renamed ? 'Board renamed' : 'Description saved',
            $renamed
                ? $was.' is now '.$name.'. The address stays /projects/'.$board->slug.'.'
                : $board->name.' reads differently in the board picker.',
        );
    }

    /** Persist the board's colour. Keys come from `Palette`, classes never do. */
    public function selectColour(string $key): void
    {
        $board = $this->board();

        if ($board === null) {
            $this->toastError('There is no board at this address', 'Pick one from the boards page.');

            return;
        }

        if (! Palette::has($key)) {
            $this->toastError('That is not a board colour', 'Pick one of the swatches.');

            return;
        }

        if ($board->colour === $key) {
            $this->colour = $key;

            $this->toastSuccess($board->name.' is already '.Palette::name($key), 'Nothing changed.');

            return;
        }

        $from = $board->colour;
        $was = Palette::name($from);

        $board->forceFill(['colour' => $key])->save();

        activity('board')
            ->performedOn($board)
            ->causedBy(auth()->user())
            ->event('board.recoloured')
            ->withProperties(['from' => $from, 'to' => $key])
            ->log('colour changed to '.$key);

        $this->colour = $key;

        $this->toastSuccess(
            'Board colour saved',
            $board->name.' went from '.$was.' to '.Palette::name($key).'.',
        );
    }

    /* Labels ------------------------------------------------------------------ */

    private function labelOnThisBoard(int $labelId): ?Label
    {
        $board = $this->board();

        return $board === null ? null : $board->labels()->find($labelId);
    }

    public function startEditLabel(int $labelId): void
    {
        $label = $this->labelOnThisBoard($labelId);

        if ($label === null) {
            $this->toastError('That label is not on this board', 'Reload the page and try again.');

            return;
        }

        $this->editingLabel = $label->id;
        $this->labelDraft = $label->name;
        $this->labelColourDraft = Palette::has($label->colour) ? $label->colour : 'neutral';
    }

    public function cancelEditLabel(): void
    {
        $this->editingLabel = null;
        $this->labelDraft = '';
    }

    /** Rename a label and change its colour. */
    public function saveLabel(int $labelId): void
    {
        $label = $this->labelOnThisBoard($labelId);

        if ($label === null) {
            $this->toastError('That label is not on this board', 'Reload the page and try again.');

            return;
        }

        $name = trim($this->labelDraft);

        if ($name === '') {
            $this->toastError('The label needs a name', 'Something like "Bug" or "Finance".');

            return;
        }

        $colour = Palette::has($this->labelColourDraft) ? $this->labelColourDraft : $label->colour;

        $renamed = $label->name !== $name;
        $recoloured = $label->colour !== $colour;

        if (! $renamed && ! $recoloured) {
            $this->editingLabel = null;

            $this->toastSuccess('Nothing to save', $label->name.' already reads like that.');

            return;
        }

        $was = $label->name;

        $label->forceFill(['name' => $name, 'colour' => $colour])->save();

        activity('board')
            ->performedOn($this->board())
            ->causedBy(auth()->user())
            ->event('board.label-updated')
            ->withProperties(['label_id' => $label->id, 'from' => $was, 'to' => $name, 'colour' => $colour])
            ->log('label '.$name.' updated');

        $this->editingLabel = null;

        $this->toastSuccess(
            $renamed ? 'Label renamed' : 'Label recoloured',
            trim(implode(' ', array_filter([
                $renamed ? $was.' is now '.$name.' on every card carrying it.' : null,
                $recoloured ? $name.' is now '.Palette::name($colour).'.' : null,
            ]))),
        );
    }

    /** Remove a label from the board and from every card carrying it. */
    public function deleteLabel(int $labelId): void
    {
        $label = $this->labelOnThisBoard($labelId);

        if ($label === null) {
            $this->toastError('That label is not on this board', 'Reload the page and try again.');

            return;
        }

        $name = $label->name;
        $detached = $label->cards()->count();

        // The pivot has a cascade, but only a real delete fires it, and a label
        // left attached to a card it no longer exists for is a broken chip.
        $label->cards()->detach();
        $label->delete();

        activity('board')
            ->performedOn($this->board())
            ->causedBy(auth()->user())
            ->event('board.label-deleted')
            ->withProperties(['label' => $name, 'detached' => $detached])
            ->log('label '.$name.' deleted');

        if ($this->editingLabel === $labelId) {
            $this->editingLabel = null;
        }

        $this->toastSuccess(
            $name.' deleted',
            $detached === 0
                ? 'No card was wearing it.'
                : 'It came off '.$detached.' '.str('card')->plural($detached).'. The cards themselves are untouched.',
        );
    }

    /** Add a label to the board. */
    public function createLabel(): void
    {
        $board = $this->board();

        if ($board === null) {
            $this->toastError('There is no board at this address', 'Pick one from the boards page.');

            return;
        }

        $name = trim($this->newLabelName);

        if ($name === '') {
            $this->toastError('The label needs a name', 'Something like "Bug" or "Finance".');

            return;
        }

        $taken = $board->labels()
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->exists();

        if ($taken) {
            $this->toastError($name.' is already a label here', 'Edit the one that exists instead of adding a second.');

            return;
        }

        $colour = Palette::has($this->newLabelColour) ? $this->newLabelColour : 'neutral';

        $label = Label::query()->create([
            'board_id' => $board->id,
            'name' => $name,
            'colour' => $colour,
            // `labels.position` is an integer column, not the fractional one the
            // cards use — labels are a short list nobody drags.
            'position' => ((int) $board->labels()->max('position')) + 1,
        ]);

        activity('board')
            ->performedOn($board)
            ->causedBy(auth()->user())
            ->event('board.label-added')
            ->withProperties(['label' => $label->name, 'colour' => $colour])
            ->log('label '.$label->name.' added');

        $this->newLabelName = '';

        $this->toastSuccess(
            $label->name.' added',
            'Every card on '.$board->name.' can wear it, in '.Palette::name($colour).'.',
        );
    }

    /* Custom fields ------------------------------------------------------------- */

    private function customFieldOnThisBoard(int $fieldId): ?CustomField
    {
        $board = $this->board();

        return $board === null ? null : CustomField::query()->where('board_id', $board->id)->find($fieldId);
    }

    /** Define a new field on this board. */
    public function createCustomField(): void
    {
        $board = $this->board();

        if ($board === null) {
            $this->toastError('There is no board at this address', 'Pick one from the boards page.');

            return;
        }

        $type = CustomFieldType::tryFrom($this->newFieldType);

        if ($type === null) {
            $this->toastError('That is not a field type', 'Pick checkbox, date, dropdown, number or text.');

            return;
        }

        try {
            $field = app(CustomFields::class)->define($board, $this->newFieldName, $type);
        } catch (\RuntimeException $e) {
            $this->toastError('Could not add the field', $e->getMessage());

            return;
        }

        activity('board')
            ->performedOn($board)
            ->causedBy(auth()->user())
            ->event('board.custom-field-added')
            ->withProperties(['field' => $field->name, 'type' => $type->value])
            ->log('custom field '.$field->name.' added');

        $this->newFieldName = '';

        $this->toastSuccess(
            $field->name.' added',
            'Every card on '.$board->name.' can carry a '.$type->label().' value for it.',
        );
    }

    public function startEditCustomField(int $fieldId): void
    {
        $field = $this->customFieldOnThisBoard($fieldId);

        if ($field === null) {
            $this->toastError('That field is not on this board', 'Reload the page and try again.');

            return;
        }

        $this->editingCustomField = $field->id;
        $this->customFieldDraft = $field->name;
    }

    public function cancelEditCustomField(): void
    {
        $this->editingCustomField = null;
        $this->customFieldDraft = '';
    }

    public function saveCustomField(int $fieldId): void
    {
        $field = $this->customFieldOnThisBoard($fieldId);

        if ($field === null) {
            $this->toastError('That field is not on this board', 'Reload the page and try again.');

            return;
        }

        $was = $field->name;

        try {
            app(CustomFields::class)->rename($field, $this->customFieldDraft);
        } catch (\RuntimeException $e) {
            $this->toastError('Could not rename the field', $e->getMessage());

            return;
        }

        $field->refresh();

        $this->editingCustomField = null;

        if ($was === $field->name) {
            $this->toastSuccess('Nothing to save', $was.' already reads like that.');

            return;
        }

        activity('board')
            ->performedOn($this->board())
            ->causedBy(auth()->user())
            ->event('board.custom-field-renamed')
            ->withProperties(['from' => $was, 'to' => $field->name])
            ->log('custom field '.$was.' renamed to '.$field->name);

        $this->toastSuccess('Field renamed', $was.' is now '.$field->name.' on every card.');
    }

    public function moveCustomFieldUp(int $fieldId): void
    {
        $this->moveCustomField($fieldId, -1);
    }

    public function moveCustomFieldDown(int $fieldId): void
    {
        $this->moveCustomField($fieldId, 1);
    }

    private function moveCustomField(int $fieldId, int $direction): void
    {
        $field = $this->customFieldOnThisBoard($fieldId);

        if ($field === null) {
            $this->toastError('That field is not on this board', 'Reload the page and try again.');

            return;
        }

        $before = $this->customFields()->pluck('position', 'id');

        app(CustomFields::class)->move($field, $direction);

        $after = $this->customFieldOnThisBoard($fieldId);

        if ($after === null || (int) $before->get($fieldId) === (int) $after->position) {
            $this->toastSuccess(
                $field->name.' is already '.($direction < 0 ? 'first' : 'last'),
                'Nothing moved.',
            );

            return;
        }

        activity('board')
            ->performedOn($this->board())
            ->causedBy(auth()->user())
            ->event('board.custom-field-moved')
            ->withProperties(['field' => $field->name])
            ->log('custom field '.$field->name.' reordered');

        $this->toastSuccess($field->name.' moved', $direction < 0 ? 'It now sits earlier.' : 'It now sits later.');
    }

    /** First click: find out how much would be lost. Second click on the same field: do it. */
    public function confirmDeleteCustomField(int $fieldId): void
    {
        $field = $this->customFieldOnThisBoard($fieldId);

        if ($field === null) {
            $this->toastError('That field is not on this board', 'Reload the page and try again.');

            return;
        }

        $this->confirmingCustomFieldDelete = $field->id;
        $this->confirmingCustomFieldDeleteCount = app(CustomFields::class)->valueCount($field);
    }

    public function cancelDeleteCustomField(): void
    {
        $this->confirmingCustomFieldDelete = null;
        $this->confirmingCustomFieldDeleteCount = 0;
    }

    /** Delete a field and every value it holds, in one transaction. Destructive by design — Trello's own behaviour. */
    public function deleteCustomField(int $fieldId): void
    {
        $field = $this->customFieldOnThisBoard($fieldId);

        if ($field === null) {
            $this->toastError('That field is not on this board', 'Reload the page and try again.');

            return;
        }

        if ($this->confirmingCustomFieldDelete !== $field->id) {
            $this->toastError('Confirm the delete first', 'Click delete once more to remove it.');

            return;
        }

        $name = $field->name;
        $wiped = app(CustomFields::class)->delete($field);

        activity('board')
            ->performedOn($this->board())
            ->causedBy(auth()->user())
            ->event('board.custom-field-deleted')
            ->withProperties(['field' => $name, 'values' => $wiped])
            ->log('custom field '.$name.' deleted');

        $this->confirmingCustomFieldDelete = null;
        $this->confirmingCustomFieldDeleteCount = 0;

        if ($this->editingCustomField === $fieldId) {
            $this->editingCustomField = null;
        }

        $this->toastSuccess(
            $name.' deleted',
            $wiped === 0
                ? 'No card had a value in it.'
                : $wiped.' card '.str('value')->plural($wiped).' went with it — that cannot be undone.',
        );
    }

    /** Add an option to a dropdown field. */
    public function addCustomFieldOption(int $fieldId): void
    {
        $field = $this->customFieldOnThisBoard($fieldId);

        if ($field === null) {
            $this->toastError('That field is not on this board', 'Reload the page and try again.');

            return;
        }

        $label = $this->newOptionDraft[$fieldId] ?? '';

        try {
            app(CustomFields::class)->addOption($field, $label);
        } catch (\RuntimeException $e) {
            $this->toastError('Could not add the option', $e->getMessage());

            return;
        }

        activity('board')
            ->performedOn($this->board())
            ->causedBy(auth()->user())
            ->event('board.custom-field-option-added')
            ->withProperties(['field' => $field->name, 'option' => trim($label)])
            ->log('option added to '.$field->name);

        $this->newOptionDraft[$fieldId] = '';

        $this->toastSuccess('Option added', trim($label).' can now be picked on '.$field->name.'.');
    }

    public function startEditOption(int $fieldId, int $optionId): void
    {
        $field = $this->customFieldOnThisBoard($fieldId);

        if ($field === null) {
            return;
        }

        $this->editingOption = $fieldId.':'.$optionId;
        $this->optionDraft = $field->optionLabel($optionId) ?? '';
    }

    public function cancelEditOption(): void
    {
        $this->editingOption = null;
        $this->optionDraft = '';
    }

    public function saveOption(int $fieldId, int $optionId): void
    {
        $field = $this->customFieldOnThisBoard($fieldId);

        if ($field === null) {
            $this->toastError('That field is not on this board', 'Reload the page and try again.');

            return;
        }

        $was = $field->optionLabel($optionId);

        try {
            app(CustomFields::class)->renameOption($field, $optionId, $this->optionDraft);
        } catch (\RuntimeException $e) {
            $this->toastError('Could not rename the option', $e->getMessage());

            return;
        }

        $field->refresh();
        $now = $field->optionLabel($optionId);

        $this->editingOption = null;

        if ($was === $now) {
            $this->toastSuccess('Nothing to save', $was.' already reads like that.');

            return;
        }

        activity('board')
            ->performedOn($this->board())
            ->causedBy(auth()->user())
            ->event('board.custom-field-option-renamed')
            ->withProperties(['field' => $field->name, 'from' => $was, 'to' => $now])
            ->log($was.' renamed to '.$now.' on '.$field->name);

        $this->toastSuccess(
            'Option renamed',
            $was.' is now '.$now.' — every card already carrying it keeps it.',
        );
    }

    public function deleteOption(int $fieldId, int $optionId): void
    {
        $field = $this->customFieldOnThisBoard($fieldId);

        if ($field === null) {
            $this->toastError('That field is not on this board', 'Reload the page and try again.');

            return;
        }

        $label = $field->optionLabel($optionId);

        app(CustomFields::class)->removeOption($field, $optionId);

        activity('board')
            ->performedOn($this->board())
            ->causedBy(auth()->user())
            ->event('board.custom-field-option-deleted')
            ->withProperties(['field' => $field->name, 'option' => $label])
            ->log(($label ?? 'an option').' removed from '.$field->name);

        if ($this->editingOption === $fieldId.':'.$optionId) {
            $this->editingOption = null;
        }

        $this->toastSuccess(
            ($label ?? 'The option').' removed',
            'Cards carrying it now show no value for '.$field->name.'.',
        );
    }

    /* Lists -------------------------------------------------------------------- */

    private function listOnThisBoard(int $listId): ?BoardList
    {
        $board = $this->board();

        if ($board === null) {
            return null;
        }

        return BoardList::query()->where('board_id', $board->id)->active()->find($listId);
    }

    public function startEditList(int $listId): void
    {
        $list = $this->listOnThisBoard($listId);

        if ($list === null) {
            $this->toastError('That list is not on this board', 'Reload the page and try again.');

            return;
        }

        $this->editingList = $list->id;
        $this->listDraft = $list->name;
    }

    public function cancelEditList(): void
    {
        $this->editingList = null;
        $this->listDraft = '';
    }

    public function saveList(int $listId): void
    {
        $list = $this->listOnThisBoard($listId);

        if ($list === null) {
            $this->toastError('That list is not on this board', 'Reload the page and try again.');

            return;
        }

        $name = trim($this->listDraft);

        if ($name === '') {
            $this->toastError('The list needs a name', 'Something like "Waiting on client".');

            return;
        }

        if ($list->name === $name) {
            $this->editingList = null;

            $this->toastSuccess('Nothing to save', $list->name.' already reads like that.');

            return;
        }

        $was = $list->name;

        $list->forceFill(['name' => $name])->save();

        activity('list')
            ->performedOn($list)
            ->causedBy(auth()->user())
            ->event('list.renamed')
            ->withProperties(['from' => $was, 'to' => $name])
            ->log('renamed from '.$was.' to '.$name);

        $this->editingList = null;

        $this->toastSuccess('List renamed', $was.' is now '.$name.' on the board.');
    }

    public function moveListUp(int $listId): void
    {
        $this->moveList($listId, -1);
    }

    public function moveListDown(int $listId): void
    {
        $this->moveList($listId, 1);
    }

    /**
     * Move a list one place along the board.
     *
     * The list lands between the two lists that will end up either side of it,
     * which is one write. `spread()` is only reached once the gap between those
     * two neighbours has been halved past what the column can tell apart.
     */
    private function moveList(int $listId, int $direction): void
    {
        $list = $this->listOnThisBoard($listId);

        if ($list === null) {
            $this->toastError('That list is not on this board', 'Reload the page and try again.');

            return;
        }

        $order = $this->lists()->values();
        $index = $order->search(fn (BoardList $candidate): bool => $candidate->id === $list->id);

        if ($index === false) {
            $this->toastError('That list is not on this board', 'Reload the page and try again.');

            return;
        }

        $target = $index + $direction;

        if ($target < 0 || $target >= $order->count()) {
            $this->toastSuccess(
                $list->name.' is already '.($direction < 0 ? 'first' : 'last'),
                'Nothing moved.',
            );

            return;
        }

        $neighbours = $this->slot($order, $index, $direction);

        if (Position::needsRebalance($neighbours['before'], $neighbours['after'])) {
            $this->spreadLists();

            $order = $this->lists()->values();
            $index = $order->search(fn (BoardList $candidate): bool => $candidate->id === $list->id);
            $neighbours = $this->slot($order, (int) $index, $direction);
        }

        $swapped = $order[$index + $direction]->name;

        $list->forceFill([
            'position' => Position::between($neighbours['before'], $neighbours['after']),
        ])->save();

        activity('list')
            ->performedOn($list)
            ->causedBy(auth()->user())
            ->event('list.moved')
            ->withProperties(['position' => (string) $list->position])
            ->log($direction < 0 ? 'moved before '.$swapped : 'moved after '.$swapped);

        $this->toastSuccess(
            $list->name.' moved',
            $direction < 0
                ? 'It now sits before '.$swapped.'.'
                : 'It now sits after '.$swapped.'.',
        );
    }

    /**
     * The two positions the list must land between once it has moved.
     *
     * @param  Collection<int, BoardList>  $order
     * @return array{before: ?string, after: ?string}
     */
    private function slot(Collection $order, int $index, int $direction): array
    {
        $at = fn (int $i): ?string => isset($order[$i])
            ? Position::format((string) $order[$i]->position)
            : null;

        $target = $index + $direction;

        return $direction < 0
            ? ['before' => $at($target - 1), 'after' => $at($target)]
            : ['before' => $at($target), 'after' => $at($target + 1)];
    }

    /** Space the board's lists evenly again. Writes every row, so it is not the usual path. */
    private function spreadLists(): void
    {
        $lists = $this->lists()->values();
        $positions = Position::spread($lists->count());

        foreach ($lists as $index => $list) {
            BoardList::query()->whereKey($list->id)->update(['position' => $positions[$index]]);
        }
    }

    public function archiveList(int $listId): void
    {
        $list = $this->listOnThisBoard($listId);

        if ($list === null) {
            $this->toastError('That list is not on this board', 'Reload the page and try again.');

            return;
        }

        // The cards that *live* in the list, not everything shown in it: a card
        // mirrored in from another board keeps living where it lives, and only
        // stops being drawn here because the list itself has gone.
        $cards = Card::query()
            ->whereIn('id', CardPlacement::query()->where('board_list_id', $list->id)->origin()->select('card_id'))
            ->active()
            ->update(['archived_at' => now()]);

        $list->forceFill(['archived_at' => now()])->save();

        activity('list')
            ->performedOn($list)
            ->causedBy(auth()->user())
            ->event('list.archived')
            ->withProperties(['cards' => $cards])
            ->log('archived from board settings');

        if ($this->editingList === $listId) {
            $this->editingList = null;
        }

        $this->toastSuccess(
            $list->name.' archived',
            $cards === 0
                ? 'It was empty. You can restore it from the archive.'
                : $cards.' '.str('card')->plural($cards).' went with it, and can be restored from the archive.',
        );
    }

    /* Danger zone -------------------------------------------------------------- */

    public function archiveBoard(): void
    {
        $board = $this->board();

        if ($board === null) {
            $this->toastError('There is no board at this address', 'Pick one from the boards page.');

            return;
        }

        if ($board->isArchived()) {
            $this->toastSuccess($board->name.' is already archived', 'Restore it from the archive.');

            return;
        }

        $lists = BoardList::query()->where('board_id', $board->id)->active()->count();
        $cards = Card::query()
            ->whereIn('id', CardPlacement::query()
                ->origin()
                ->whereIn('board_list_id', BoardList::query()->where('board_id', $board->id)->select('id'))
                ->select('card_id'))
            ->active()
            ->count();

        $board->forceFill(['archived_at' => now()])->save();

        activity('board')
            ->performedOn($board)
            ->causedBy(auth()->user())
            ->event('board.archived')
            ->withProperties(['lists' => $lists, 'cards' => $cards])
            ->log('archived from board settings');

        $this->forgetBoard();

        $this->toastSuccess(
            $board->name.' archived',
            'It has left the board picker. Its '.$lists.' '.str('list')->plural($lists).' and '
                .$cards.' '.str('card')->plural($cards).' are untouched and come back with it from the archive.',
        );
    }

    public function confirmDelete(): void
    {
        $board = $this->board();

        if ($board === null) {
            $this->toastError('There is no board at this address', 'Pick one from the boards page.');

            return;
        }

        $this->confirmingDelete = true;
        $this->deleteConfirmation = '';

        $this->toastWarning('Confirmation needed', 'Type '.$board->name.' to unlock the delete button.');
    }

    public function cancelDelete(): void
    {
        $this->confirmingDelete = false;
        $this->deleteConfirmation = '';
    }

    /**
     * Take the board out of the application.
     *
     * A soft delete, like everything else here: the rows keep their ids and
     * their history and stop being something anybody will be shown. The schema
     * cascade only fires on a real delete, so the children are written here.
     */
    public function deleteBoard(): void
    {
        $board = $this->board();

        if ($board === null) {
            $this->toastError('There is no board at this address', 'Pick one from the boards page.');

            return;
        }

        if ($this->deleteConfirmation !== $board->name) {
            $this->toastError('That is not the board name', 'Type '.$board->name.' exactly, then delete.');

            return;
        }

        $name = $board->name;
        $listIds = BoardList::query()->where('board_id', $board->id)->pluck('id');

        // The cards that live on this board go with it. A card mirrored onto it
        // from somewhere else loses the mirror and nothing more — it still
        // lives on its own board, and deleting this one must not take it.
        $cardIds = CardPlacement::query()->whereIn('board_list_id', $listIds)->origin()->pluck('card_id');
        $cards = $cardIds->count();

        Card::query()->whereIn('id', $cardIds)->delete();
        CardPlacement::query()->whereIn('board_list_id', $listIds)->delete();
        BoardList::query()->whereIn('id', $listIds)->delete();
        $board->delete();

        activity('board')
            ->performedOn($board)
            ->causedBy(auth()->user())
            ->event('board.deleted')
            ->withProperties(['lists' => $listIds->count(), 'cards' => $cards])
            ->log('deleted from board settings');

        $this->flashToast(
            'success',
            $name.' deleted',
            $listIds->count().' '.str('list')->plural($listIds->count()).' and '.$cards.' '
                .str('card')->plural($cards).' went with it.',
        );

        $this->redirect(route('projects.boards'), navigate: true);
    }
};

?>

<div class="flex flex-col gap-5">

    {{-- Heading --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <div class="flex items-center gap-2 text-sm text-muted-foreground">
                <a href="{{ route('projects.boards') }}" wire:navigate class="hover:text-primary">Boards</a>
                <i class="ki-filled ki-right text-[10px]"></i>
                <span class="text-secondary-foreground">{{ $record?->name ?? $board }}</span>
            </div>
            <h1 class="text-xl font-semibold text-mono mt-1">Board settings</h1>
            <p class="text-sm text-secondary-foreground mt-1">Set how this board reads, what its cards can be tagged with, and the order of its lists.</p>
        </div>
        <a href="{{ route('projects.boards') }}" wire:navigate class="kt-btn kt-btn-outline gap-2">
            <i class="ki-filled ki-arrow-left"></i> Back to the board
        </a>
    </div>

    @if ($record === null)

        {{-- Unknown slug. A 404 here would take the smoke test with it. --}}
        <div class="kt-card">
            <div class="kt-card-content text-center py-16 px-6">
                <i class="ki-filled ki-questionnaire-tablet text-3xl text-muted-foreground"></i>
                <h2 class="text-base font-semibold text-mono mt-3">No board answers to “{{ $board }}”</h2>
                <p class="text-sm text-secondary-foreground mt-2">
                    It was deleted, or the address was mistyped. The boards page lists everything that exists.
                </p>
                <div class="flex flex-wrap items-center justify-center gap-2 mt-5">
                    <a href="{{ route('projects.boards') }}" wire:navigate class="kt-btn kt-btn-primary gap-2">
                        <i class="ki-filled ki-element-plus"></i> Go to the boards
                    </a>
                    <a href="{{ route('projects.archive') }}" wire:navigate class="kt-btn kt-btn-outline gap-2">
                        <i class="ki-filled ki-archive"></i> Look in the archive
                    </a>
                </div>
            </div>
        </div>

    @else

        @if ($record->isArchived())
            <div class="flex flex-wrap items-center gap-3 rounded-lg border border-warning/30 bg-warning/10 px-4 py-3">
                <i class="ki-filled ki-archive text-base text-warning"></i>
                <span class="text-sm text-secondary-foreground grow">
                    This board is archived, so it is not in the board picker. Its settings still work.
                </span>
                <a href="{{ route('projects.archive') }}" wire:navigate class="kt-btn kt-btn-sm kt-btn-outline gap-2">
                    <i class="ki-filled ki-arrow-circle-left"></i> Restore it
                </a>
            </div>
        @endif

        <div class="grid grid-cols-1 xl:grid-cols-2 gap-5 items-start">

            {{-- Name and description --}}
            <div class="kt-card">
                <div class="kt-card-header">
                    <h2 class="kt-card-title">Board name</h2>
                </div>
                <div class="kt-card-content flex flex-col gap-4 p-5">
                    <div class="flex flex-col gap-1.5">
                        <label class="kt-form-label" for="board-name">Name</label>
                        <input id="board-name" type="text" class="kt-input @error('name') border-destructive @enderror"
                               wire:model="name" wire:keydown.enter.prevent="renameBoard">
                        @error('name')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                        <span class="text-xs text-muted-foreground">
                            Shown in the board switcher. The address stays /projects/{{ $record->slug }} whatever you call it.
                        </span>
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label class="kt-form-label" for="board-description">Description</label>
                        <textarea id="board-description" rows="3" class="kt-textarea @error('description') border-destructive @enderror"
                                  placeholder="What this board is for, in a sentence."
                                  wire:model="description"></textarea>
                        @error('description')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                    </div>

                    <div>
                        <button wire:click="renameBoard" wire:loading.attr="disabled" wire:target="renameBoard"
                                class="kt-btn kt-btn-primary gap-2">
                            <span wire:loading.remove wire:target="renameBoard">Save changes</span>
                            <span wire:loading wire:target="renameBoard"><i class="ki-filled ki-loading animate-spin"></i> Saving…</span>
                        </button>
                    </div>
                </div>
            </div>

            {{-- Colour --}}
            <div class="kt-card">
                <div class="kt-card-header">
                    <h2 class="kt-card-title">Colour</h2>
                    <span class="text-xs text-muted-foreground">Saved as you pick</span>
                </div>
                <div class="kt-card-content flex flex-col gap-4 p-5">
                    <p class="text-sm text-secondary-foreground">The dot beside the board in the switcher, and the tint on its links.</p>

                    <div class="grid grid-cols-3 sm:grid-cols-6 gap-3">
                        @foreach ($colours as $key => $option)
                            <button wire:click="selectColour('{{ $key }}')" wire:key="colour-{{ $key }}"
                                    wire:loading.attr="disabled" wire:target="selectColour"
                                    class="flex flex-col items-center gap-1.5 group"
                                    aria-pressed="{{ $colour === $key ? 'true' : 'false' }}"
                                    title="{{ $option['name'] }}">
                                <span class="w-full h-12 rounded-lg {{ $option['dot'] }} border-2 transition-colors
                                             {{ $colour === $key ? 'border-mono' : 'border-transparent group-hover:border-border' }}"></span>
                                <span class="text-xs {{ $colour === $key ? 'text-mono' : 'text-muted-foreground' }}">{{ $option['name'] }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Labels --}}
            <div class="kt-card xl:col-span-2">
                <div class="kt-card-header">
                    <h2 class="kt-card-title">Labels</h2>
                    <span class="text-xs text-muted-foreground">
                        {{ $labels->count() }} {{ $labels->count() === 1 ? 'label' : 'labels' }} on this board
                    </span>
                </div>
                <div class="kt-card-content flex flex-col gap-3 p-5">

                    @forelse ($labels as $label)
                        <div class="rounded-lg border border-border px-3 py-2.5" wire:key="label-{{ $label->id }}">
                            @if ($editingLabel === $label->id)
                                <div class="flex flex-wrap items-center gap-2">
                                    <input type="text" class="kt-input max-w-[240px]" aria-label="Label name"
                                           wire:model="labelDraft" wire:keydown.escape="cancelEditLabel"
                                           wire:keydown.enter.prevent="saveLabel({{ $label->id }})">

                                    <div class="flex items-center gap-1.5">
                                        @foreach ($colours as $colourKey => $option)
                                            <button wire:click="$set('labelColourDraft', '{{ $colourKey }}')"
                                                    wire:key="draft-{{ $label->id }}-{{ $colourKey }}"
                                                    class="size-6 rounded-md {{ $option['dot'] }} border-2 {{ $labelColourDraft === $colourKey ? 'border-mono' : 'border-transparent' }}"
                                                    title="{{ $option['name'] }}" aria-label="Use {{ $option['name'] }}"></button>
                                        @endforeach
                                    </div>

                                    <div class="flex items-center gap-2 ms-auto">
                                        <button wire:click="saveLabel({{ $label->id }})" wire:loading.attr="disabled" wire:target="saveLabel"
                                                class="kt-btn kt-btn-sm kt-btn-primary">
                                            <span wire:loading.remove wire:target="saveLabel">Save</span>
                                            <span wire:loading wire:target="saveLabel"><i class="ki-filled ki-loading animate-spin"></i></span>
                                        </button>
                                        <button wire:click="cancelEditLabel" class="kt-btn kt-btn-sm kt-btn-ghost">Cancel</button>
                                    </div>
                                </div>
                            @else
                                <div class="flex flex-wrap items-center gap-3">
                                    <span class="text-xs font-medium px-2 py-1 rounded {{ $label->chipClass() }}">{{ $label->name }}</span>
                                    <span class="text-xs text-muted-foreground">
                                        on {{ $label->cards_count }} {{ $label->cards_count === 1 ? 'card' : 'cards' }}
                                    </span>
                                    <div class="flex items-center gap-1 ms-auto">
                                        <button wire:click="startEditLabel({{ $label->id }})" class="kt-btn kt-btn-sm kt-btn-ghost gap-1">
                                            <i class="ki-filled ki-pencil text-sm"></i> Edit
                                        </button>
                                        <button wire:click="deleteLabel({{ $label->id }})" wire:loading.attr="disabled" wire:target="deleteLabel"
                                                class="kt-btn kt-btn-sm kt-btn-ghost text-destructive gap-1">
                                            <i class="ki-filled ki-trash text-sm"></i> Delete
                                        </button>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @empty
                        <div class="text-center py-8">
                            <i class="ki-filled ki-tag text-2xl text-muted-foreground"></i>
                            <p class="text-sm text-muted-foreground mt-2">No labels yet. Add the first one below.</p>
                        </div>
                    @endforelse

                    <div class="flex flex-wrap items-center gap-2 pt-2 border-t border-border">
                        <input type="text" class="kt-input max-w-[240px]" placeholder="New label name"
                               aria-label="New label name" wire:model="newLabelName"
                               wire:keydown.enter.prevent="createLabel">
                        <div class="flex items-center gap-1.5">
                            @foreach ($colours as $colourKey => $option)
                                <button wire:click="$set('newLabelColour', '{{ $colourKey }}')"
                                        wire:key="new-{{ $colourKey }}"
                                        class="size-6 rounded-md {{ $option['dot'] }} border-2 {{ $newLabelColour === $colourKey ? 'border-mono' : 'border-transparent' }}"
                                        title="{{ $option['name'] }}" aria-label="Use {{ $option['name'] }}"></button>
                            @endforeach
                        </div>
                        <button wire:click="createLabel" wire:loading.attr="disabled" wire:target="createLabel"
                                class="kt-btn kt-btn-outline gap-2">
                            <span wire:loading.remove wire:target="createLabel" class="inline-flex items-center gap-2">
                                <i class="ki-filled ki-plus"></i> Add label
                            </span>
                            <span wire:loading wire:target="createLabel" class="inline-flex items-center gap-2">
                                <i class="ki-filled ki-loading animate-spin"></i> Adding…
                            </span>
                        </button>
                    </div>
                </div>
            </div>

            {{-- Custom fields --}}
            <div class="kt-card xl:col-span-2">
                <div class="kt-card-header">
                    <h2 class="kt-card-title">Custom fields</h2>
                    <span class="text-xs text-muted-foreground">
                        {{ $customFields->count() }} {{ $customFields->count() === 1 ? 'field' : 'fields' }} of {{ \Modules\Project\Services\CustomFields::MAX_FIELDS_PER_BOARD }}
                    </span>
                </div>
                <div class="kt-card-content flex flex-col gap-3 p-5">

                    @forelse ($customFields as $index => $field)
                        <div class="rounded-lg border border-border px-3 py-2.5 flex flex-col gap-2.5" wire:key="custom-field-{{ $field->id }}">
                            @if ($editingCustomField === $field->id)
                                <div class="flex flex-wrap items-center gap-2">
                                    <input type="text" class="kt-input max-w-[240px]" aria-label="Field name"
                                           wire:model="customFieldDraft" wire:keydown.escape="cancelEditCustomField"
                                           wire:keydown.enter.prevent="saveCustomField({{ $field->id }})">
                                    <div class="flex items-center gap-2 ms-auto">
                                        <button wire:click="saveCustomField({{ $field->id }})" wire:loading.attr="disabled" wire:target="saveCustomField"
                                                class="kt-btn kt-btn-sm kt-btn-primary">
                                            <span wire:loading.remove wire:target="saveCustomField">Save</span>
                                            <span wire:loading wire:target="saveCustomField"><i class="ki-filled ki-loading animate-spin"></i></span>
                                        </button>
                                        <button wire:click="cancelEditCustomField" class="kt-btn kt-btn-sm kt-btn-ghost">Cancel</button>
                                    </div>
                                </div>
                            @else
                                <div class="flex flex-wrap items-center gap-3">
                                    <i class="ki-filled {{ $field->type->icon() }} text-sm text-muted-foreground"></i>
                                    <span class="text-sm font-medium text-mono">{{ $field->name }}</span>
                                    <span class="kt-badge kt-badge-sm kt-badge-outline">{{ $field->type->label() }}</span>

                                    <div class="flex flex-wrap items-center gap-1 ms-auto">
                                        <button wire:click="moveCustomFieldUp({{ $field->id }})"
                                                wire:loading.attr="disabled" wire:target="moveCustomFieldUp"
                                                class="kt-btn kt-btn-sm kt-btn-icon kt-btn-ghost"
                                                title="Move {{ $field->name }} earlier" aria-label="Move {{ $field->name }} earlier"
                                                @disabled($index === 0)>
                                            <i class="ki-filled ki-up text-sm"></i>
                                        </button>
                                        <button wire:click="moveCustomFieldDown({{ $field->id }})"
                                                wire:loading.attr="disabled" wire:target="moveCustomFieldDown"
                                                class="kt-btn kt-btn-sm kt-btn-icon kt-btn-ghost"
                                                title="Move {{ $field->name }} later" aria-label="Move {{ $field->name }} later"
                                                @disabled($index === $customFields->count() - 1)>
                                            <i class="ki-filled ki-down text-sm"></i>
                                        </button>
                                        <button wire:click="startEditCustomField({{ $field->id }})" class="kt-btn kt-btn-sm kt-btn-ghost gap-1">
                                            <i class="ki-filled ki-pencil text-sm"></i> Rename
                                        </button>

                                        @if ($confirmingCustomFieldDelete === $field->id)
                                            <span class="text-xs text-destructive">
                                                {{ $confirmingCustomFieldDeleteCount === 0
                                                    ? 'No values will be lost.'
                                                    : $confirmingCustomFieldDeleteCount.' '.($confirmingCustomFieldDeleteCount === 1 ? 'value' : 'values').' will be lost.' }}
                                            </span>
                                            <button wire:click="deleteCustomField({{ $field->id }})" wire:loading.attr="disabled" wire:target="deleteCustomField"
                                                    class="kt-btn kt-btn-sm kt-btn-destructive gap-1">
                                                <i class="ki-filled ki-trash text-sm"></i> Confirm delete
                                            </button>
                                            <button wire:click="cancelDeleteCustomField" class="kt-btn kt-btn-sm kt-btn-ghost">Cancel</button>
                                        @else
                                            <button wire:click="confirmDeleteCustomField({{ $field->id }})"
                                                    class="kt-btn kt-btn-sm kt-btn-ghost text-destructive gap-1">
                                                <i class="ki-filled ki-trash text-sm"></i> Delete
                                            </button>
                                        @endif
                                    </div>
                                </div>
                            @endif

                            @if ($field->type === CustomFieldType::Dropdown)
                                <div class="flex flex-col gap-2 pt-2 border-t border-border">
                                    <div class="flex flex-wrap items-center gap-2">
                                        @forelse ($field->options() as $option)
                                            <div class="flex items-center gap-1 rounded-md border border-border px-2 py-1"
                                                 wire:key="option-{{ $field->id }}-{{ $option['id'] }}">
                                                @if ($editingOption === $field->id.':'.$option['id'])
                                                    <input type="text" class="kt-input kt-input-sm max-w-[140px]" aria-label="Option label"
                                                           wire:model="optionDraft" wire:keydown.escape="cancelEditOption"
                                                           wire:keydown.enter.prevent="saveOption({{ $field->id }}, {{ $option['id'] }})">
                                                    <button wire:click="saveOption({{ $field->id }}, {{ $option['id'] }})"
                                                            class="kt-btn kt-btn-icon kt-btn-sm kt-btn-ghost" title="Save option" aria-label="Save option">
                                                        <i class="ki-filled ki-check text-xs"></i>
                                                    </button>
                                                @else
                                                    <button wire:click="startEditOption({{ $field->id }}, {{ $option['id'] }})"
                                                            class="text-xs text-mono hover:text-primary" title="Rename {{ $option['label'] }}">
                                                        {{ $option['label'] }}
                                                    </button>
                                                    <button wire:click="deleteOption({{ $field->id }}, {{ $option['id'] }})"
                                                            class="kt-btn kt-btn-icon kt-btn-sm kt-btn-ghost text-destructive"
                                                            title="Remove {{ $option['label'] }}" aria-label="Remove {{ $option['label'] }}">
                                                        <i class="ki-filled ki-cross text-[10px]"></i>
                                                    </button>
                                                @endif
                                            </div>
                                        @empty
                                            <span class="text-xs text-muted-foreground">No options yet.</span>
                                        @endforelse
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <input type="text" class="kt-input kt-input-sm max-w-[180px]" placeholder="New option"
                                               aria-label="New option for {{ $field->name }}"
                                               wire:model="newOptionDraft.{{ $field->id }}"
                                               wire:keydown.enter.prevent="addCustomFieldOption({{ $field->id }})">
                                        <button wire:click="addCustomFieldOption({{ $field->id }})"
                                                wire:loading.attr="disabled" wire:target="addCustomFieldOption({{ $field->id }})"
                                                class="kt-btn kt-btn-sm kt-btn-outline gap-1">
                                            <i class="ki-filled ki-plus text-xs"></i> Add option
                                        </button>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @empty
                        <div class="text-center py-8">
                            <i class="ki-filled ki-setting-4 text-2xl text-muted-foreground"></i>
                            <p class="text-sm text-muted-foreground mt-2">No custom fields yet. Add the first one below.</p>
                        </div>
                    @endforelse

                    <div class="flex flex-wrap items-center gap-2 pt-2 border-t border-border">
                        <input type="text" class="kt-input max-w-[220px]" placeholder="New field name"
                               aria-label="New field name" wire:model="newFieldName"
                               wire:keydown.enter.prevent="createCustomField">
                        <select class="kt-select max-w-[160px]" aria-label="New field type" wire:model="newFieldType">
                            @foreach ($customFieldTypes as $type)
                                <option value="{{ $type->value }}">{{ $type->label() }}</option>
                            @endforeach
                        </select>
                        <button wire:click="createCustomField" wire:loading.attr="disabled" wire:target="createCustomField"
                                class="kt-btn kt-btn-outline gap-2">
                            <span wire:loading.remove wire:target="createCustomField" class="inline-flex items-center gap-2">
                                <i class="ki-filled ki-plus"></i> Add field
                            </span>
                            <span wire:loading wire:target="createCustomField" class="inline-flex items-center gap-2">
                                <i class="ki-filled ki-loading animate-spin"></i> Adding…
                            </span>
                        </button>
                    </div>
                </div>
            </div>

            {{-- Lists --}}
            <div class="kt-card xl:col-span-2">
                <div class="kt-card-header">
                    <h2 class="kt-card-title">Lists</h2>
                    <span class="text-xs text-muted-foreground">
                        {{ $lists->count() }} {{ $lists->count() === 1 ? 'list' : 'lists' }},
                        {{ $cardTotal }} {{ $cardTotal === 1 ? 'card' : 'cards' }}
                        @if ($archivedLists > 0)
                            · {{ $archivedLists }} archived
                        @endif
                    </span>
                </div>
                <div class="kt-card-content flex flex-col gap-3 p-5">

                    @forelse ($lists as $index => $list)
                        <div class="rounded-lg border border-border px-3 py-2.5" wire:key="list-{{ $list->id }}">
                            @if ($editingList === $list->id)
                                <div class="flex flex-wrap items-center gap-2">
                                    <input type="text" class="kt-input max-w-[280px]" aria-label="List name"
                                           wire:model="listDraft" wire:keydown.escape="cancelEditList"
                                           wire:keydown.enter.prevent="saveList({{ $list->id }})">
                                    <div class="flex items-center gap-2 ms-auto">
                                        <button wire:click="saveList({{ $list->id }})" wire:loading.attr="disabled" wire:target="saveList"
                                                class="kt-btn kt-btn-sm kt-btn-primary">
                                            <span wire:loading.remove wire:target="saveList">Save</span>
                                            <span wire:loading wire:target="saveList"><i class="ki-filled ki-loading animate-spin"></i></span>
                                        </button>
                                        <button wire:click="cancelEditList" class="kt-btn kt-btn-sm kt-btn-ghost">Cancel</button>
                                    </div>
                                </div>
                            @else
                                <div class="flex flex-wrap items-center gap-3">
                                    <span class="text-xs text-muted-foreground w-5 text-center">{{ $index + 1 }}</span>
                                    <span class="text-sm font-medium text-mono">{{ $list->name }}</span>
                                    <span class="kt-badge kt-badge-sm kt-badge-outline">
                                        {{ $list->cards_count }} {{ $list->cards_count === 1 ? 'card' : 'cards' }}
                                    </span>

                                    <div class="flex items-center gap-1 ms-auto">
                                        <button wire:click="moveListUp({{ $list->id }})"
                                                wire:loading.attr="disabled" wire:target="moveListUp"
                                                class="kt-btn kt-btn-sm kt-btn-icon kt-btn-ghost"
                                                title="Move {{ $list->name }} earlier" aria-label="Move {{ $list->name }} earlier"
                                                @disabled($index === 0)>
                                            <i class="ki-filled ki-up text-sm"></i>
                                        </button>
                                        <button wire:click="moveListDown({{ $list->id }})"
                                                wire:loading.attr="disabled" wire:target="moveListDown"
                                                class="kt-btn kt-btn-sm kt-btn-icon kt-btn-ghost"
                                                title="Move {{ $list->name }} later" aria-label="Move {{ $list->name }} later"
                                                @disabled($index === $lists->count() - 1)>
                                            <i class="ki-filled ki-down text-sm"></i>
                                        </button>
                                        <button wire:click="startEditList({{ $list->id }})" class="kt-btn kt-btn-sm kt-btn-ghost gap-1">
                                            <i class="ki-filled ki-pencil text-sm"></i> Rename
                                        </button>
                                        <button wire:click="archiveList({{ $list->id }})"
                                                wire:loading.attr="disabled" wire:target="archiveList"
                                                class="kt-btn kt-btn-sm kt-btn-ghost text-destructive gap-1">
                                            <i class="ki-filled ki-archive text-sm"></i> Archive
                                        </button>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @empty
                        <div class="text-center py-8">
                            <i class="ki-filled ki-row-vertical text-2xl text-muted-foreground"></i>
                            <p class="text-sm text-muted-foreground mt-2">No lists on this board yet.</p>
                            <a href="{{ route('projects.boards', ['board' => $record->slug]) }}" wire:navigate
                               class="kt-btn kt-btn-primary gap-2 mt-4">
                                <i class="ki-filled ki-plus"></i> Add the first list
                            </a>
                        </div>
                    @endforelse

                    @if ($archivedLists > 0)
                        <p class="text-xs text-muted-foreground pt-2 border-t border-border">
                            {{ $archivedLists }} archived {{ $archivedLists === 1 ? 'list is' : 'lists are' }} not shown here.
                            <a href="{{ route('projects.archive') }}" wire:navigate class="text-primary hover:underline">Open the archive</a>
                            to bring one back.
                        </p>
                    @endif
                </div>
            </div>

            {{-- Danger zone --}}
            <div class="kt-card xl:col-span-2 border-destructive/30">
                <div class="kt-card-header">
                    <h2 class="kt-card-title text-destructive">Danger zone</h2>
                </div>
                <div class="kt-card-content flex flex-col gap-4 p-5">

                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <div class="text-sm font-medium text-mono">Archive this board</div>
                            <p class="text-xs text-muted-foreground mt-1">
                                It leaves the switcher, and every list and card stays readable from the archive.
                            </p>
                        </div>
                        <button wire:click="archiveBoard" wire:loading.attr="disabled" wire:target="archiveBoard"
                                class="kt-btn kt-btn-outline gap-2" @disabled($record->isArchived())>
                            <span wire:loading.remove wire:target="archiveBoard" class="inline-flex items-center gap-2">
                                <i class="ki-filled ki-archive"></i>
                                {{ $record->isArchived() ? 'Already archived' : 'Archive board' }}
                            </span>
                            <span wire:loading wire:target="archiveBoard" class="inline-flex items-center gap-2">
                                <i class="ki-filled ki-loading animate-spin"></i> Archiving…
                            </span>
                        </button>
                    </div>

                    <div class="flex flex-wrap items-start justify-between gap-3 pt-4 border-t border-border">
                        <div>
                            <div class="text-sm font-medium text-mono">Delete this board</div>
                            <p class="text-xs text-muted-foreground mt-1">
                                Lists, cards and comments go with it. It leaves the archive too, and nothing in the
                                application can bring it back.
                            </p>
                        </div>

                        @if ($confirmingDelete)
                            <div class="flex flex-col gap-2 w-full sm:w-auto">
                                <label class="kt-form-label text-xs" for="delete-confirm">
                                    Type <span class="text-mono">{{ $record->name }}</span> to confirm
                                </label>
                                <div class="flex items-center gap-2">
                                    <input id="delete-confirm" type="text" class="kt-input max-w-[220px]"
                                           wire:model.live="deleteConfirmation" wire:keydown.escape="cancelDelete">
                                    <button wire:click="deleteBoard" wire:loading.attr="disabled" wire:target="deleteBoard"
                                            class="kt-btn kt-btn-destructive gap-2" @disabled($deleteConfirmation !== $record->name)>
                                        <span wire:loading.remove wire:target="deleteBoard">Delete for good</span>
                                        <span wire:loading wire:target="deleteBoard"><i class="ki-filled ki-loading animate-spin"></i> Deleting…</span>
                                    </button>
                                    <button wire:click="cancelDelete" class="kt-btn kt-btn-ghost">Cancel</button>
                                </div>
                            </div>
                        @else
                            <button wire:click="confirmDelete" class="kt-btn kt-btn-destructive gap-2">
                                <i class="ki-filled ki-trash"></i> Delete board
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
