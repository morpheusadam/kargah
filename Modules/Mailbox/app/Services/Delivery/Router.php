<?php

namespace Modules\Mailbox\Services\Delivery;

use Illuminate\Support\Collection;
use Modules\Mailbox\Models\DeliveryProvider;

/**
 * Who carries the next message.
 *
 * The rule the migration states is 'by remaining quota, then health, then
 * priority', and what that has to mean in practice is:
 *
 * 1. **Remaining quota is a filter.** A provider with none left today is not a
 *    candidate at all. This is the half that delivers the acceptance criterion:
 *    exhausting one provider's allowance moves the remainder to the next rather
 *    than failing the campaign.
 * 2. **Health decides between the ones that are left.** A provider that has
 *    started bouncing is worse than one that has not, long before it is dead,
 *    so it stops being chosen while a healthier alternative exists.
 * 3. **Priority breaks the tie.** Two equally healthy providers are ordered by
 *    the number the owner set, which is the only place their preference is
 *    expressed.
 *
 * The campaign's own provider is tried first, before any of that. A person who
 * chose Brevo for a campaign meant it, and the router's job is to keep the
 * campaign going when that choice runs out — not to overrule it while it is
 * still working. That ordering is exactly what makes the split visible in the
 * report: the first messages carry the chosen provider's id and the rest carry
 * whoever took over.
 *
 * Nothing is cached between calls. The quota counters move under this class as
 * it works, and a memoised candidate list would keep sending through a provider
 * that filled up thirty messages ago.
 */
class Router
{
    /**
     * The provider that should carry the next message, or null when none can.
     *
     * `$preferred` is the campaign's chosen provider. It is returned unchanged
     * while it is usable, which is the common case for an entire campaign.
     *
     * Every candidate has its quota window rolled first, because the counters
     * are only reset lazily — a site whose cron stopped for an afternoon must
     * not come back believing it has already spent the day.
     */
    public function pick(?DeliveryProvider $preferred = null): ?DeliveryProvider
    {
        // Re-read rather than trust the loaded row: the quota counters moved
        // under this object while the chunk was working, and a stale
        // `sent_today` is exactly how a provider carries thirty more than its
        // allowance.
        $fresh = $preferred?->fresh();

        if ($fresh !== null && $this->usable($fresh)) {
            return $fresh;
        }

        foreach ($this->candidates() as $provider) {
            if ($this->usable($provider)) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * Why nothing could carry the message.
     *
     * A sentence rather than a null, because 'no provider available' on a
     * campaign report tells the owner nothing about which of the three
     * different situations they are in — none configured, all switched off, or
     * all out of quota until midnight.
     */
    public function refusalReason(): string
    {
        $active = DeliveryProvider::query()->active()->get();

        if ($active->isEmpty()) {
            return DeliveryProvider::query()->exists()
                ? 'Every delivery provider is switched off, so there was nothing to send through.'
                : 'No delivery provider is set up, so there was nothing to send through.';
        }

        $configured = $active->filter(fn (DeliveryProvider $p): bool => $p->hasCredentials());

        if ($configured->isEmpty()) {
            return 'No delivery provider has its credentials filled in, so there was nothing to send through.';
        }

        return 'Every delivery provider has used up its quota for now. The rest will go out as the quotas reset.';
    }

    /**
     * @return Collection<int, DeliveryProvider>
     */
    private function candidates()
    {
        return DeliveryProvider::query()->active()->inRoutingOrder()->get();
    }

    /**
     * Whether this provider can take one more message right now.
     *
     * Credentials are checked here as well as in the driver, because a provider
     * nobody filled in should not consume the campaign's attempt: skipping it
     * silently and moving to the next is a better outcome than one recipient
     * failing with 'Brevo has no API key' while a working provider sits idle.
     */
    private function usable(DeliveryProvider $provider): bool
    {
        if (! $provider->is_active || ! $provider->hasCredentials()) {
            return false;
        }

        $provider->rollQuotaWindow();

        return $provider->hasQuotaLeft();
    }
}
