<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Facade;
use Livewire\Livewire;
use Modules\Social\Models\SocialAccount;
use Modules\Social\Services\Publishers\Publisher;
use Modules\Social\Services\Publishing;
use Modules\Social\Support\Networks;
use Nwidart\Modules\Contracts\RepositoryInterface;
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
 * ⚠️ **The two tests above assume every module is enabled**, which is what the
 * suite runs with. Three of the seventeen — WordPress, DEV.to and Hashnode —
 * are registered from `Modules\Blog\Providers\BlogServiceProvider`, while their
 * catalogue entries live in Social. That split is deliberate and argued in the
 * `Networks::WORDPRESS` docblock; what it used to leave behind was a loose end,
 * because with `Blog` switched off in `modules_statuses.json` Social's
 * catalogue would still have offered three destinations nothing can send to.
 *
 * That is what `Networks`' `module` key and `Networks::available()` are for, and
 * the three tests after those are the ones that hold them honest. None of them
 * switches a module off: `modules_statuses.json` is real configuration on the
 * machine the suite runs on, and a test that edited it would be one crash away
 * from leaving the install broken. They stub the module repository's answer
 * instead, which is the same question asked of the same API.
 */
class NetworkRegistryTest extends TestCase
{
    use RefreshDatabase;

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

    /**
     * And the module an entry names is the module that actually registers it.
     *
     * This is the valuable half of the module key, because a wrong name is
     * exactly as silent as the missing registration line the first test exists
     * to catch — worse, in fact. An entry that claims `Social` while its driver
     * comes from Blog stays on the connect page after Blog is switched off and
     * fails at send time; an entry that claims `Blog` while its driver comes
     * from Social disappears from the page for no reason anybody can see, and
     * nothing anywhere says why.
     *
     * So this asks the **registration** rather than the class. It reads the
     * closure `Publishing::extend()` was handed and asks PHP which file that
     * closure was written in, which is literally "the service provider that
     * registers this driver". Looking at the driver's own namespace instead
     * would be a proxy, and it would pass for the one arrangement the whole
     * design forbids: a `Modules\Blog` class registered from Social's provider.
     */
    public function test_every_catalogue_entry_names_the_module_that_registers_its_driver(): void
    {
        $wrong = [];

        foreach ($this->registrationFiles() as $network => $file) {
            $declared = Networks::module($network);

            if (! preg_match('#[/\\\\]Modules[/\\\\]([^/\\\\]+)[/\\\\]#', $file, $matches)) {
                $wrong[] = $network.' is registered from '.$file.', which is not inside any module';

                continue;
            }

            if ($matches[1] !== $declared) {
                $wrong[] = $network.' names '.$declared.' but is registered from '.$matches[1].' ('.$file.')';
            }
        }

        $this->assertSame([], $wrong, implode("\n", [
            'A catalogue entry names a module that does not register its driver.',
            'Correct the `module` key in Modules\Social\Support\Networks, or move the',
            'registration to the provider of the module the entry claims — whichever of',
            'the two is the lie. Getting this wrong hides a destination that works, or',
            'offers one that does not.',
            '',
            ...$wrong,
        ]));
    }

    /**
     * Switching a module off takes its destinations out of what is *offered*,
     * and leaves what already *exists* alone.
     *
     * The repository is stubbed rather than `modules_statuses.json` edited:
     * that file is live configuration on this machine, and a test that wrote to
     * it would only have to fail once, halfway, to leave the install missing a
     * module. Stubbing `find()` is the same question asked of the same API.
     *
     * Both halves are asserted because both are failures. Offering DEV.to with
     * Blog off invites somebody to paste a credential into a form that leads
     * nowhere; hiding a *connected* DEV.to account shows them nothing at all
     * where a destination used to be, which is why `unavailableReason()` exists
     * and why `all()` still has to resolve every key.
     */
    public function test_a_disabled_module_removes_its_destinations_from_what_is_offered_only(): void
    {
        $this->pretendModuleIsMissing(Networks::MODULE_BLOG);

        $offered = Networks::availableKeys();

        foreach ([Networks::WORDPRESS, Networks::DEVTO, Networks::HASHNODE] as $network) {
            $this->assertNotContains($network, $offered);
            $this->assertFalse(Networks::isAvailable($network));

            // Still describable: an account row, a published target and a
            // notification all have to keep drawing with their own label.
            $this->assertArrayHasKey($network, Networks::all());
            $this->assertNotNull(Networks::get($network));
            $this->assertNotSame('', Networks::label($network));

            // And there is a sentence to put under it.
            $this->assertIsString(Networks::unavailableReason($network));
        }

        $this->assertContains(Networks::MASTODON, $offered);
        $this->assertTrue(Networks::isAvailable(Networks::MASTODON));
        $this->assertNull(Networks::unavailableReason(Networks::MASTODON));

        $this->assertCount(count(Networks::keys()) - 3, $offered);
    }

    /**
     * An account already connected to a destination whose module has gone still
     * appears on the accounts page, and stops being offered a connect form.
     *
     * This is the branch nobody has ever seen run — every install has all eight
     * modules on — so it is the one worth executing at least once. Both halves
     * are a decision that could have gone the other way: the row stays because
     * hiding it would leave a blank where a connection used to be and a stored
     * credential nobody can reach to withdraw, and the connect link goes because
     * `⚡account-connect` no longer offers that network either, so the button
     * would land on the picker rather than the form it promised.
     *
     * Asserted on the handle and the link rather than on any sentence: the
     * wording of `Networks::unavailableReason()` should be improvable without
     * breaking a test.
     */
    public function test_an_account_whose_module_is_off_stays_on_the_page_without_a_connect_link(): void
    {
        $this->actingAs(User::factory()->create());

        $devto = SocialAccount::factory()->onNetwork(Networks::DEVTO)->connected()->create();
        $mastodon = SocialAccount::factory()->onNetwork(Networks::MASTODON)->connected()->create();

        $this->pretendModuleIsMissing(Networks::MODULE_BLOG);

        Livewire::test('social::accounts')
            ->assertOk()
            ->assertSee($devto->handle)
            ->assertDontSee(route('social.account-connect').'?network='.Networks::DEVTO, false)
            ->assertSee(route('social.account-connect').'?network='.Networks::MASTODON, false);
    }

    /**
     * The file each network's driver factory was written in.
     *
     * Reaches into `Publishing`'s private factory list on purpose: the public
     * surface answers *which* driver, and the question here is *who registered
     * it*, which nothing else can answer. Reading the factory rather than
     * calling it also means no driver is constructed.
     *
     * @return array<string, string>
     */
    private function registrationFiles(): array
    {
        $factories = (new \ReflectionProperty(Publishing::class, 'factories'))
            ->getValue(app(Publishing::class));

        $files = [];

        foreach (Networks::keys() as $network) {
            if (isset($factories[$network])) {
                $files[$network] = (string) (new \ReflectionFunction($factories[$network]))->getFileName();
            }
        }

        return $files;
    }

    /**
     * Answer every `Module::find()` truthfully except for one name, which comes
     * back as if the module were not installed.
     *
     * Not installed rather than installed-and-disabled because `Networks`
     * treats them the same and says why: either way nothing registers the
     * drivers. Stubbing the smaller of the two shapes keeps the double from
     * having to build a `Nwidart\Modules\Module` it does not own.
     */
    private function pretendModuleIsMissing(string $module): void
    {
        $real = app('modules');

        $stub = \Mockery::mock(RepositoryInterface::class);

        $stub->shouldReceive('find')->andReturnUsing(
            fn (string $name) => strtolower($name) === strtolower($module) ? null : $real->find($name),
        );

        $this->app->instance('modules', $stub);

        // The facade caches the instance it resolved, so rebinding alone would
        // leave `Networks` asking the real repository.
        Facade::clearResolvedInstance('modules');
    }
}
