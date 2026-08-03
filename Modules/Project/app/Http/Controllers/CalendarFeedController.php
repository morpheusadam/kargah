<?php

namespace Modules\Project\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Project\Models\Board;
use Modules\Project\Services\BoardCalendar;

/**
 * The `.ics` subscription endpoint for one board.
 *
 * Outside the `auth` group on purpose — the whole point is that a calendar
 * client with no session and no cookie jar can poll it. `signed` is the
 * router's half of the authorisation; the `token` query parameter compared
 * below is the revocable half, because a signed URL alone stays valid for
 * ever and cannot be taken back. See the migration that adds
 * `boards.feed_token` for the full reasoning.
 *
 * A holder of a valid URL can read, for this one board: its name, and — for
 * every active card with a due date — the card's title and due date. Nothing
 * else: no description, no comments, no labels, no members, no other board.
 */
class CalendarFeedController extends Controller
{
    public function __invoke(Request $request, Board $board, BoardCalendar $calendar): Response
    {
        $token = (string) $request->query('token', '');

        if ($board->feed_token === null || $token === '' || ! hash_equals($board->feed_token, $token)) {
            abort(403, 'This calendar link has been revoked. Ask for a fresh one from the board\'s calendar view.');
        }

        $body = $calendar->build($board);
        $etag = '"'.hash('sha256', $body).'"';

        if (trim((string) $request->headers->get('If-None-Match', '')) === $etag) {
            return response('', 304)->header('ETag', $etag);
        }

        return response($body)
            ->header('Content-Type', 'text/calendar; charset=utf-8')
            ->header('Content-Disposition', 'inline; filename="'.$board->slug.'.ics"')
            ->header('ETag', $etag);
    }
}
