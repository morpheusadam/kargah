<?php

namespace Tests\Feature;

use Modules\Social\Services\Publishers\Publisher;
use Modules\Social\Services\Publishing;
use Modules\Social\Support\Networks;
use Tests\TestCase;

/**
 * Every destination in the catalogue has a driver behind it.
 *
 * Adding one is three edits in three files — an entry in `Networks`, a class in
 * a `Publishers` directory, and a line in a service provider — and the third is
 * the one that gets forgotten, because the first two are the interesting work.
 * Forgetting it produces no error anywhere: the connect page draws the network
 * from the catalogue, the composer offers it, somebody pastes a credential, and
 * the failure arrives later on a `post_targets` row reading *"Kargah has no
 * driver for Slack, so the post was not sent."* That sentence is `PostPublisher`
 * doing exactly the right thing with a registry that is missing a line, and it
 * is the only warning the install ever gets.
 *
 * So this asks the container, not the classes. Constructing a driver proves the
 * file exists and nothing at all about whether anything would ever find it —
 * which is the same distinction `BlogModuleTest` makes about
 * `callAfterResolving()`, and for the same reason.
 *
 * ⚠️ **This assumes the `Blog` module is enabled.** Three of the seventeen —
 * WordPress, DEV.to and Hashnode — are registered from
 * `Modules\Blog\Providers\BlogServiceProvider`, while their catalogue entries
 * live in Social. That split is deliberate and argued in the `Networks::WORDPRESS`
 * docblock, but it does leave one loose end: with `Blog` switched off in
 * `modules_statuses.json`, Social's catalogue would still offer three
 * destinations nothing can send to. Nobody has switched it off, so nothing has
 * gone wrong; the day somebody does, the catalogue needs to learn which module
 * owns an entry, and this test will be the thing that says so.
 */
class NetworkRegistryTest extends TestCase
{
    public function test_every_network_in_the_catalogue_resolves_a_driver(): void
    {
        $publishing = app(Publishing::class);

        $missing = [];

        foreach (Networks::keys() as $network) {
            if ($publishing->driverFor($network) === null) {
                $missing[] = $network;
            }
        }

        $this->assertSame([], $missing, implode("\n", [
            'These destinations are in the catalogue with nothing registered to send to them.',
            'Add a line to the service provider that owns them — SocialServiceProvider::register()',
            'for a social network, BlogServiceProvider::register() for an article destination.',
            '',
            ...$missing,
        ]));
    }

    /**
     * And the driver a network resolves is a driver *for that network*.
     *
     * A copy-pasted registration line that binds the wrong class is the other
     * half of the same mistake, and it is worse: the network has a driver, so
     * the check above passes, and the post goes out — to somewhere else. Every
     * publisher already declares its own key, so this simply asks each one who
     * it is.
     */
    public function test_each_driver_answers_for_the_network_it_is_registered_under(): void
    {
        $publishing = app(Publishing::class);

        $wrong = [];

        foreach (Networks::keys() as $network) {
            $driver = $publishing->driverFor($network);

            if ($driver instanceof Publisher && $driver->network() !== $network) {
                $wrong[] = $network.' resolves '.$driver::class.', which answers for '.$driver->network();
            }
        }

        $this->assertSame([], $wrong, implode("\n", ['A network is bound to a driver for a different network.', '', ...$wrong]));
    }
}
