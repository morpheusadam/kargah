<?php

namespace Modules\Mailbox\Services\Delivery;

/**
 * The process itself has stopped being trustworthy.
 *
 * An `Error` rather than an `Exception`, and that distinction is load bearing.
 * `CampaignSender` contains every `Exception` a driver can raise, because one
 * provider having a bad minute must not cost the other 499 recipients their
 * chunk. It deliberately does **not** contain an `Error`: a type error, an
 * exhausted memory limit or a fatal inside a transport bridge means PHP has
 * declared the process unsound, and carrying on marking rows `sent` from inside
 * a broken process is how a campaign ends up with a hundred addresses recorded
 * as delivered that never left.
 *
 * Stopping instead leaves every unclaimed recipient on `pending`, which the
 * next cron tick picks up as ordinary outstanding work. That is the whole
 * recovery story, and it is why `CampaignSendingTest` can kill a worker
 * mid-chunk and still assert that every one of 500 recipients was sent exactly
 * once.
 *
 * Thrown in anger only by `FakeMailer::dieAfter()`, which exists so the kill
 * can be tested. Real kills — a SIGKILL from the host, an OOM — do not throw
 * anything at all, and leave exactly the same state behind.
 */
class WorkerKilled extends \Error {}
