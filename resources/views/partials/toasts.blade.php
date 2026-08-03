{{--
    Toast host. Bottom-right, newest on top, stacked.

    From a Livewire component (use the InteractsWithToasts trait):
        $this->toastSuccess('Invoice sent', 'INV-0041 went to Northwind Ltd.');

    From plain Blade or JS:
        window.kargahToast({ type: 'success', message: 'Saved' });

    Across a redirect:
        session()->flash('toast', ['type' => 'success', 'message' => 'Saved']);

    Types: success | error | warning | info
--}}

<div id="kargah-toasts"
     class="fixed bottom-5 end-5 z-[60] flex flex-col-reverse gap-2.5 pointer-events-none w-[min(380px,calc(100vw-2.5rem))]"
     role="region"
     aria-label="Notifications"
     aria-live="polite"></div>

@if (session()->has('toast'))
    <script type="application/json" id="kargah-flash-toast">@json(session('toast'))</script>
@endif

@push('scripts')
<script>
(function () {
    if (window.__kargahToastsBound) return;
    window.__kargahToastsBound = true;

    var TONES = {
        success: { icon: 'ki-check-circle',  accent: 'bg-success',     text: 'text-success',     ring: 'border-success/25' },
        error:   { icon: 'ki-cross-circle',  accent: 'bg-destructive', text: 'text-destructive', ring: 'border-destructive/25' },
        warning: { icon: 'ki-information-2', accent: 'bg-warning',     text: 'text-warning',     ring: 'border-warning/25' },
        info:    { icon: 'ki-information',   accent: 'bg-info',        text: 'text-info',        ring: 'border-info/25' }
    };

    var MAX_VISIBLE = 4;

    // Three seconds is long enough to notice a confirmation and short enough
    // that a run of them does not queue up in front of the page. Errors ask for
    // a longer life explicitly — see `InteractsWithToasts::toastError` — because
    // an error that vanishes in three seconds is not a warning, it is a rumour.
    var DEFAULT_DURATION = 3000;

    function host() {
        return document.getElementById('kargah-toasts');
    }

    function show(detail) {
        var box = host();
        if (!box || !detail) return;

        var type = detail.type || 'info';
        var tone = TONES[type] || TONES.info;
        var duration = detail.duration || DEFAULT_DURATION;

        // Keep the stack short; drop the oldest rather than filling the screen.
        while (box.children.length >= MAX_VISIBLE) {
            box.firstElementChild.remove();
        }

        var el = document.createElement('div');
        el.className =
            'kargah-toast pointer-events-auto relative flex items-start gap-3 overflow-hidden ' +
            'rounded-xl border ' + tone.ring + ' bg-background shadow-lg shadow-black/10 ' +
            'p-3.5 pe-10 translate-y-3 opacity-0 scale-[0.97] ' +
            'transition-all duration-300 ease-out';
        el.setAttribute('role', type === 'error' ? 'alert' : 'status');

        var accent = document.createElement('span');
        accent.className = 'absolute inset-y-0 start-0 w-[3px] ' + tone.accent;

        var icon = document.createElement('i');
        icon.className = 'ki-filled ' + tone.icon + ' text-lg ' + tone.text + ' shrink-0 mt-0.5';

        var body = document.createElement('div');
        body.className = 'min-w-0 grow';

        var title = document.createElement('div');
        title.className = 'text-sm font-medium text-mono break-words';
        title.textContent = detail.message || '';
        body.appendChild(title);

        if (detail.description) {
            var desc = document.createElement('div');
            desc.className = 'text-xs text-secondary-foreground mt-0.5 break-words';
            desc.textContent = detail.description;
            body.appendChild(desc);
        }

        var close = document.createElement('button');
        close.className = 'absolute top-2.5 end-2.5 kt-btn kt-btn-icon kt-btn-ghost size-6';
        close.setAttribute('aria-label', 'Dismiss notification');
        close.innerHTML = '<i class="ki-filled ki-cross text-xs"></i>';

        var progress = document.createElement('span');
        progress.className = 'absolute bottom-0 start-0 h-[2px] ' + tone.accent + ' opacity-40';
        progress.style.width = '100%';
        progress.style.transition = 'width ' + duration + 'ms linear';

        el.append(accent, icon, body, close, progress);
        box.appendChild(el);

        requestAnimationFrame(function () {
            el.classList.remove('translate-y-3', 'opacity-0', 'scale-[0.97]');
            requestAnimationFrame(function () { progress.style.width = '0%'; });
        });

        var timer = null;
        var dismissed = false;

        function dismiss() {
            if (dismissed) return;
            dismissed = true;
            clearTimeout(timer);
            el.classList.add('translate-y-1', 'opacity-0', 'scale-[0.97]');
            setTimeout(function () { el.remove(); }, 300);
        }

        function start() {
            timer = setTimeout(dismiss, duration);
        }

        // Reading a long message should not be interrupted by it vanishing.
        el.addEventListener('mouseenter', function () {
            clearTimeout(timer);
            var w = progress.getBoundingClientRect().width;
            progress.style.transition = 'none';
            progress.style.width = w + 'px';
        });

        el.addEventListener('mouseleave', function () {
            progress.style.transition = 'width ' + duration + 'ms linear';
            requestAnimationFrame(function () { progress.style.width = '0%'; });
            start();
        });

        close.addEventListener('click', dismiss);
        start();
    }

    window.kargahToast = show;

    // Plain DOM events, for code that is not Livewire.
    document.addEventListener('toast', function (e) { show(e.detail); });

    function bindLivewire() {
        if (!window.Livewire || window.__kargahToastLivewireBound) return;
        window.__kargahToastLivewireBound = true;

        Livewire.on('toast', function (payload) {
            show(Array.isArray(payload) ? payload[0] : payload);
        });
    }

    bindLivewire();
    document.addEventListener('livewire:init', bindLivewire);
    document.addEventListener('livewire:navigated', bindLivewire);

    // A toast queued before a redirect, replayed once the new page is up.
    function replayFlash() {
        var node = document.getElementById('kargah-flash-toast');
        if (!node) return;
        try { show(JSON.parse(node.textContent)); } catch (e) { /* malformed, ignore */ }
        node.remove();
    }

    document.addEventListener('DOMContentLoaded', replayFlash);
    document.addEventListener('livewire:navigated', replayFlash);
    replayFlash();
})();
</script>
@endpush
