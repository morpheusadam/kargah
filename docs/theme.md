# Front-end theme

Kargah's Blade views are written against a Tailwind CSS admin template. The template's compiled
assets are **not committed** to this repository — they are third-party licensed work.

## Expected structure

Place your template's build output at `public/assets/` so the layouts resolve:

```
public/assets/
├── css/
│   └── styles.css              compiled Tailwind bundle
├── js/
│   ├── core.bundle.js          template core (must include Sortable)
│   └── layouts/demo1.js        layout behaviour
├── vendors/
│   ├── keenicons/styles.bundle.css
│   ├── ktui/ktui.min.js
│   └── apexcharts/…
└── media/app/                  logos, favicons
```

The layouts reference these paths in:

- `resources/views/layouts/app.blade.php` — authenticated shell
- `resources/views/layouts/guest.blade.php` — login screen

## Class conventions used by the views

The markup uses these component classes. Any template that provides equivalents will work with
minor edits:

| Purpose | Class |
| --- | --- |
| Card container | `kt-card`, `kt-card-header`, `kt-card-content`, `kt-card-table` |
| Buttons | `kt-btn`, `kt-btn-primary`, `kt-btn-outline`, `kt-btn-ghost`, `kt-btn-icon` |
| Inputs | `kt-input`, `kt-select`, `kt-textarea`, `kt-checkbox`, `kt-switch` |
| Tables | `kt-table` |
| Badges | `kt-badge`, `kt-badge-success`, `kt-badge-destructive`, … |
| Sidebar / menu | `kt-sidebar`, `kt-menu`, `kt-menu-item`, `kt-menu-link` |
| Icons | `ki-filled ki-*` |

## Free, MIT-licensed alternatives

If you do not own a commercial template, these work well and are permissively licensed:

- [Tabler](https://github.com/tabler/tabler) — MIT
- [TailAdmin](https://github.com/TailAdmin/tailadmin-free-tailwind-dashboard-template) — MIT
- [Flowbite Admin Dashboard](https://github.com/themesberg/flowbite-admin-dashboard) — MIT

Swapping template means updating the two layout files and the class names in the table above.
The Livewire component logic is untouched by the change.

## Drag and drop

The Projects board requires [SortableJS](https://github.com/SortableJS/Sortable) to be available
as a global `Sortable`. Most admin templates bundle it; if yours does not, add it:

```bash
npm install sortablejs
```

…and expose it in your bundle before `resources/views/…/⚡boards.blade.php` runs its init script.
