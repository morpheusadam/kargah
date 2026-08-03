<?php

namespace Modules\Mailbox\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Modules\Mailbox\Models\Campaign;
use Modules\Mailbox\Models\CampaignRecipient;
use Modules\Mailbox\Models\Contact;
use Modules\Mailbox\Models\DeliveryProvider;
use Modules\Mailbox\Models\Suppression;
use Modules\Mailbox\Support\Senders;

/**
 * Enough of a sending setup that every page has something real to draw.
 *
 * Idempotent, because this runs from the deploy script and a deploy that
 * duplicates the contact list is a bad afternoon. Everything here is keyed on a
 * natural identifier — a provider's driver, a contact's address, a campaign's
 * name — and written with `firstOrCreate` or `updateOrCreate`, so running it
 * twice leaves the same rows.
 *
 * **No credentials are seeded.** Every provider is left unconfigured on
 * purpose: that is the honest state of a fresh install, it is what the provider
 * page has to look right in, and a seeded secret is a secret that ends up
 * committed. The consequence is that a seeded campaign cannot actually send,
 * which is correct — the pre-flight refuses it and says exactly which of SPF,
 * DKIM and credentials are missing, which is the first thing a new install
 * should be told.
 *
 * The finished campaign is seeded *as* finished — recipients already carrying a
 * provider id and a sent status — rather than by running the sender. Seeding
 * through the sender would mean the deploy script sending mail, which is the
 * one thing a seeder must never be able to do.
 */
class MailboxSendingSeeder extends Seeder
{
    public function run(): void
    {
        $providers = $this->providers();

        $this->suppressions();

        $contacts = $this->contacts();

        $this->finishedCampaign($providers, $contacts);
        $this->scheduledCampaign($providers, $contacts);
        $this->draftCampaign();
    }

    /**
     * Three providers, split the way a real setup is.
     *
     * Marketing and transactional on separate subdomains, because each provider
     * added to one sending domain costs SPF one to three DNS lookups against a
     * hard limit of ten — and because a campaign that picks up a reputation
     * problem should not take the invoices down with it.
     *
     * @return array<string, DeliveryProvider>
     */
    private function providers(): array
    {
        $rows = [
            Senders::BREVO => [
                'name' => 'Brevo',
                'sending_domain' => 'news.kargah.dev',
                'from_email' => 'nima@news.kargah.dev',
                'daily_quota' => 300,
                'hourly_quota' => 60,
                'priority' => 1,
            ],
            Senders::MAILGUN => [
                'name' => 'Mailgun',
                'sending_domain' => 'news.kargah.dev',
                'from_email' => 'nima@news.kargah.dev',
                'daily_quota' => 1000,
                'hourly_quota' => 200,
                'priority' => 2,
            ],
            Senders::POSTMARK => [
                'name' => 'Postmark',
                'sending_domain' => 'tx.kargah.dev',
                'from_email' => 'nima@tx.kargah.dev',
                'daily_quota' => 100,
                'hourly_quota' => 40,
                'priority' => 3,
            ],
        ];

        $providers = [];

        foreach ($rows as $driver => $attributes) {
            $providers[$driver] = DeliveryProvider::query()->updateOrCreate(
                ['driver' => $driver],
                $attributes + [
                    'from_name' => 'Nima Fazlipour',
                    'is_active' => true,
                    // Left false. The pre-flight refuses to send without both,
                    // and a seeder that ticked them would be seeding a lie
                    // about somebody's DNS.
                    'spf_verified' => false,
                    'dkim_verified' => false,
                ],
            );
        }

        return $providers;
    }

    /**
     * A suppression list with one of each reason on it.
     *
     * The page has four different badges and an empty table teaches nobody what
     * they mean.
     */
    private function suppressions(): void
    {
        $rows = [
            ['gone@brightlab.example', Suppression::HARD_BOUNCE, 'brevo', '550 5.1.1 The email account that you tried to reach does not exist.'],
            ['team@harbourside.example', Suppression::COMPLAINT, 'mailgun', 'Reported as spam through the feedback loop.'],
            ['info@quietfox.example', Suppression::UNSUBSCRIBE, 'one-click', 'Unsubscribed from Resume — design agencies UK.'],
            ['nobody@invalid.example', Suppression::INVALID, 'import', 'The address did not parse when the list was imported.'],
        ];

        foreach ($rows as [$email, $reason, $source, $detail]) {
            Suppression::block($email, $reason, $source, $detail);
        }
    }

    /**
     * A contact list with two tags and one address that is on the suppression
     * list.
     *
     * The overlap is the point: the contacts page has to be able to show
     * somebody who is subscribed here and blocked globally, because that is the
     * state a hard bounce leaves behind and the one people find confusing.
     *
     * @return Collection<int, Contact>
     */
    private function contacts()
    {
        $rows = [
            ['hello@studio-nord.example', 'Sam Okafor', 'Studio Nord', ['agencies-uk']],
            ['jobs@pixelforge.example', 'Helen Vasquez', 'Pixelforge', ['agencies-uk']],
            ['studio@northloop.example', 'Joris Bakker', 'Northloop', ['agencies-uk']],
            ['hi@makers-lane.example', 'Marta Lindqvist', "Makers' Lane", ['agencies-uk', 'past-clients']],
            ['contact@orbitstudio.example', 'Priya Nandakumar', 'Orbit Studio', ['startups-de']],
            ['team@harbourside.example', 'Deniz Aydın', 'Harbourside', ['startups-de']],
            ['gone@brightlab.example', 'Brightlab', 'Brightlab', ['agencies-uk']],
        ];

        foreach ($rows as [$email, $name, $company, $tags]) {
            Contact::query()->updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'company_name' => $company,
                    'tags' => $tags,
                    'source' => Contact::IMPORT,
                    // Suppression is global and separate from what the person
                    // asked for; only the unsubscribed address says so here.
                    'is_subscribed' => $email !== 'info@quietfox.example',
                ],
            );
        }

        return Contact::query()->get();
    }

    /**
     * A campaign that finished, carried by two providers.
     *
     * Seeded across two providers deliberately: the report's 'carried by'
     * table is the part of the campaign page worth reading, and a campaign that
     * went entirely through one provider renders it as a single row that shows
     * nothing.
     *
     * @param  array<string, DeliveryProvider>  $providers
     * @param  Collection<int, Contact>  $contacts
     */
    private function finishedCampaign(array $providers, $contacts): void
    {
        $campaign = Campaign::query()->firstOrCreate(
            ['name' => 'Resume — design agencies UK'],
            [
                'subject' => 'Freelance front-end capacity from mid-August',
                'preheader' => 'Two days a week, from the 18th.',
                'body_html' => $this->body(),
                'body_text' => $this->textBody(),
                'delivery_provider_id' => $providers[Senders::BREVO]->id,
                'status' => Campaign::SENT,
                'started_at' => now()->subDays(12)->setTime(9, 0),
                'finished_at' => now()->subDays(12)->setTime(11, 4),
            ],
        );

        $audience = $contacts->filter(fn (Contact $c): bool => $c->hasTag('agencies-uk'))->values();
        $carriers = [$providers[Senders::BREVO], $providers[Senders::MAILGUN]];

        foreach ($audience as $index => $contact) {
            $carrier = $carriers[$index % 2];

            // The one address on the suppression list stands as bounced, which
            // is how it got there — a campaign report that showed it as sent
            // would leave the suppression looking like it came from nowhere.
            $status = Suppression::blocks((string) $contact->email)
                ? CampaignRecipient::BOUNCED
                : CampaignRecipient::SENT;

            CampaignRecipient::query()->updateOrCreate(
                ['campaign_id' => $campaign->id, 'email' => $contact->email],
                [
                    'contact_id' => $contact->id,
                    'name' => $contact->name,
                    'status' => $status,
                    'delivery_provider_id' => $carrier->id,
                    'message_id' => '<campaign-'.$campaign->id.'-'.$contact->id.'@news.kargah.dev>',
                    'attempts' => 1,
                    'sent_at' => $campaign->started_at,
                    'error' => $status === CampaignRecipient::BOUNCED
                        ? '550 5.1.1 The email account that you tried to reach does not exist.'
                        : null,
                ],
            );
        }

        $campaign->syncCounters();
    }

    /**
     * A campaign waiting for its moment, with nothing sent yet.
     *
     * `scheduled_for` is in the future so the seeded install does not have
     * `mailbox:dispatch-sends` trying to start it on the next tick — a seeder
     * must never leave the scheduler holding work it did not ask for.
     *
     * @param  array<string, DeliveryProvider>  $providers
     * @param  Collection<int, Contact>  $contacts
     */
    private function scheduledCampaign(array $providers, $contacts): void
    {
        $campaign = Campaign::query()->firstOrCreate(
            ['name' => 'Resume — startups DE'],
            [
                'subject' => 'Front-end capacity, Berlin hours',
                'preheader' => 'Two days a week, from the 18th.',
                'body_html' => $this->body(),
                'body_text' => $this->textBody(),
                'delivery_provider_id' => $providers[Senders::MAILGUN]->id,
                'status' => Campaign::SCHEDULED,
                'scheduled_for' => now()->addDays(3)->setTime(9, 0),
            ],
        );

        foreach ($contacts->filter(fn (Contact $c): bool => $c->hasTag('startups-de')) as $contact) {
            CampaignRecipient::query()->updateOrCreate(
                ['campaign_id' => $campaign->id, 'email' => $contact->email],
                [
                    'contact_id' => $contact->id,
                    'name' => $contact->name,
                    'status' => CampaignRecipient::PENDING,
                ],
            );
        }

        $campaign->syncCounters();
    }

    /** A draft with no audience, so the campaigns page has an empty state to draw. */
    private function draftCampaign(): void
    {
        Campaign::query()->firstOrCreate(
            ['name' => 'Quarterly note to past clients'],
            [
                'subject' => 'What I have been building this quarter',
                'body_html' => $this->body(),
                'body_text' => $this->textBody(),
                'status' => Campaign::DRAFT,
            ],
        );
    }

    /**
     * The seeded body, complete with the unsubscribe placeholder.
     *
     * With it, because a seeded campaign that failed the pre-flight on the one
     * thing the person writing it controls would teach the wrong lesson — the
     * seeded campaigns are refused on DNS and credentials, which is what a
     * fresh install genuinely has to fix.
     */
    private function body(): string
    {
        return '<p>Hello {{first_name}},</p>'
            .'<p>I have two days a week free from the 18th and thought of you. '
            .'Recent work is on <a href="https://kargah.dev/portfolio">the portfolio</a> if it is useful.</p>'
            .'<p>— Nima</p>'
            .'<p style="font-size:12px"><a href="'.Campaign::UNSUBSCRIBE_PLACEHOLDER.'">Unsubscribe</a></p>';
    }

    private function textBody(): string
    {
        return "Hello {{first_name}},\n\n"
            ."I have two days a week free from the 18th and thought of you.\n"
            ."Recent work: https://kargah.dev/portfolio\n\n"
            ."— Nima\n\n"
            .'Unsubscribe: '.Campaign::UNSUBSCRIBE_PLACEHOLDER;
    }
}
