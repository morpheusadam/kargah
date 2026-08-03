<?php

namespace Modules\Data\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Data\Contracts\AttachmentService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Getting the bytes back out.
 *
 * A controller rather than a Livewire action, because a download is a plain HTTP
 * response with its own headers and a Livewire round trip cannot produce one.
 * Neither method opens a file handle itself — both ask the service, which is the
 * only thing in the application that touches a disk.
 */
class AttachmentController extends Controller
{
    /** Behind `auth`: the router has already established who is asking. */
    public function download(AttachmentService $attachments, int $attachment): StreamedResponse
    {
        return $attachments->stream($attachment);
    }

    /**
     * The same bytes as `download`, shown rather than saved.
     *
     * Behind `auth` and permanent, which is what an `<img src>` on a card cover
     * needs: `download` asks the browser to save the file, and the signed share
     * link expires, so neither suits a picture that should just be there.
     */
    public function inline(AttachmentService $attachments, int $attachment): StreamedResponse
    {
        return $attachments->stream($attachment, inline: true);
    }

    /**
     * A shared file, opened in the tab rather than downloaded.
     *
     * Behind `signed` and outside the auth group, which is the whole point: the
     * signature *is* the authorisation, and it expires. Making the storage disk
     * public to achieve the same thing would grant permanent access to every
     * file on it, including the ones nobody meant to share.
     */
    public function share(AttachmentService $attachments, int $attachment): StreamedResponse
    {
        return $attachments->stream($attachment, inline: true);
    }
}
