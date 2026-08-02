{{--
    Toast host.

    Fire from any Livewire component:
        $this->dispatch('toast', type: 'success', message: 'Invoice sent');

    Types: success | error | warning | info
--}}
<div id="kargah-toasts" class="fixed bottom-5 end-5 z-[60] flex flex-col gap-2 pointer-events-none w-[320px]"></div>

@push('scripts')
<script>
(function () {
    if (window.__kargahToastsBound) return;
    window.__kargahToastsBound = true;

    var TONES = {
        success: { icon: 'ki-check-circle',   bar: 'bg-success',     text: 'text-success' },
        error:   { icon: 'ki-cross-circle',   bar: 'bg-destructive', text: 'text-destructive' },
        warning: { icon: 'ki-information-2',  bar: 'bg-warning',     text: 'text-warning' },
        info:    { icon: 'ki-information',    bar: 'bg-info',        text: 'text-info' }
    };

    function show(detail) {
        var host = document.getElementById('kargah-toasts');
        if (!host) return;

        var type = (detail && detail.type) || 'info';
        var message = (detail && detail.message) || '';
        var tone = TONES[type] || TONES.info;

        var el = document.createElement('div');
        el.className = 'pointer-events-auto flex items-start gap-3 rounded-lg border border-border bg-background shadow-lg p-3.5 ' +
                       'translate-y-2 opacity-0 transition-all duration-200 overflow-hidden relative';
        el.innerHTML =
            '<span class="absolute inset-y-0 start-0 w-1 ' + tone.bar + '"></span>' +
            '<i class="ki-filled ' + tone.icon + ' text-lg ' + tone.text + ' shrink-0 mt-0.5"></i>' +
            '<div class="text-sm text-mono grow min-w-0 break-words"></div>' +
            '<button class="kt-btn kt-btn-icon kt-btn-ghost size-6 shrink-0" aria-label="Dismiss">' +
            '<i class="ki-filled ki-cross text-xs"></i></button>';

        el.querySelector('div').textContent = message;
        host.appendChild(el);

        requestAnimationFrame(function () {
            el.classList.remove('translate-y-2', 'opacity-0');
        });

        function dismiss() {
            el.classList.add('translate-y-2', 'opacity-0');
            setTimeout(function () { el.remove(); }, 200);
        }

        el.querySelector('button').addEventListener('click', dismiss);
        setTimeout(dismiss, 5000);
    }

    document.addEventListener('toast', function (e) { show(e.detail); });

    if (window.Livewire) {
        Livewire.on('toast', function (payload) {
            show(Array.isArray(payload) ? payload[0] : payload);
        });
    } else {
        document.addEventListener('livewire:init', function () {
            Livewire.on('toast', function (payload) {
                show(Array.isArray(payload) ? payload[0] : payload);
            });
        });
    }
})();
</script>
@endpush
