<?php

namespace Modules\Data\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Modules\Data\Contracts\AttachmentService as AttachmentServiceContract;
use Modules\Data\Models\Attachment;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The implementation of the one disk writer.
 *
 * Two things are worth knowing before changing anything here.
 *
 * **Bytes first, row second.** `Storage::put()` runs before the insert, and the
 * insert is what makes the file findable. A crash between the two leaves an
 * orphaned blob, which is wasted space; the other order would leave a row
 * pointing at nothing, which is a broken download link on a client's invoice.
 * Cheap wrong beats expensive wrong.
 *
 * **The stored name is never the uploaded name.** A browser will happily send
 * `../../.env` or a 400-character filename, and the disk driver will happily
 * use it. The path is built from a ULID and a sanitised stem, and the name the
 * person typed lives in `original_name`, where it is data rather than a path.
 */
class AttachmentService implements AttachmentServiceContract
{
    public function attach(Model $target, UploadedFile $file, ?int $uploadedBy = null): array
    {
        $path = $file->getRealPath();

        if ($path === false || ! is_readable($path)) {
            throw new RuntimeException('The uploaded file could not be read from its temporary location.');
        }

        return $this->store(
            target: $target,
            contents: (string) file_get_contents($path),
            originalName: $file->getClientOriginalName(),
            mime: $file->getClientMimeType(),
            uploadedBy: $uploadedBy,
        );
    }

    public function attachContents(
        Model $target,
        string $contents,
        string $originalName,
        ?string $mime = null,
        ?int $uploadedBy = null,
    ): array {
        return $this->store($target, $contents, $originalName, $mime, $uploadedBy);
    }

    public function forTarget(Model $target): Collection
    {
        return Attachment::query()
            ->forTarget($target->getMorphClass(), (int) $target->getKey())
            ->get()
            ->map(fn (Attachment $attachment): array => $this->toArray($attachment));
    }

    public function countForTarget(Model $target): int
    {
        return Attachment::query()
            ->forTarget($target->getMorphClass(), (int) $target->getKey())
            ->count();
    }

    public function countForTargets(iterable $targets): array
    {
        $keysByType = [];

        foreach ($targets as $target) {
            $keysByType[$target->getMorphClass()][] = (int) $target->getKey();
        }

        if ($keysByType === []) {
            return [];
        }

        $rows = Attachment::query()
            ->where(function ($query) use ($keysByType): void {
                foreach ($keysByType as $type => $ids) {
                    $query->orWhere(fn ($q) => $q
                        ->where('attachable_type', $type)
                        ->whereIn('attachable_id', array_unique($ids)));
                }
            })
            ->selectRaw('attachable_type, attachable_id, COUNT(*) as total')
            ->groupBy('attachable_type', 'attachable_id')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts[$row->attachable_type.':'.$row->attachable_id] = (int) $row->total;
        }

        return $counts;
    }

    public function targetIdsWithAttachments(string $morphAlias): array
    {
        return Attachment::query()
            ->where('attachable_type', $morphAlias)
            ->distinct()
            ->pluck('attachable_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    public function find(int $attachmentId): ?array
    {
        $attachment = Attachment::query()->find($attachmentId);

        return $attachment === null ? null : $this->toArray($attachment);
    }

    public function contents(int $attachmentId): ?string
    {
        $attachment = Attachment::query()->find($attachmentId);

        if ($attachment === null) {
            return null;
        }

        $disk = Storage::disk($attachment->disk);

        // `exists()` first rather than catching a read failure: `get()` on a
        // missing path returns null on some drivers and throws on others, and
        // the caller needs one answer.
        return $disk->exists($attachment->path) ? $disk->get($attachment->path) : null;
    }

    /**
     * The same bytes as `contents()`, without ever holding them all at once.
     *
     * `exists()` is checked first for the same reason it is there — `readStream()`
     * on a missing path answers null on one driver and throws on another, and the
     * caller needs one answer. The handle is the caller's to close; see the
     * contract.
     *
     * @return resource|null
     */
    public function readStream(int $attachmentId)
    {
        $attachment = Attachment::query()->find($attachmentId);

        if ($attachment === null) {
            return null;
        }

        $disk = Storage::disk($attachment->disk);

        if (! $disk->exists($attachment->path)) {
            return null;
        }

        $stream = $disk->readStream($attachment->path);

        return is_resource($stream) ? $stream : null;
    }

    public function publicUrl(int $attachmentId, int $minutes = 30): ?string
    {
        $attachment = Attachment::query()->find($attachmentId);

        if ($attachment === null) {
            return null;
        }

        // A signature the route already checks. `data.file-share` has sat
        // outside the auth group behind `signed` middleware since the module
        // shipped; what was missing was anything that built the URL.
        return URL::temporarySignedRoute(
            'data.file-share',
            now()->addMinutes(max(1, $minutes)),
            ['attachment' => $attachment->getKey()],
        );
    }

    public function stream(int $attachmentId, bool $inline = false): StreamedResponse
    {
        $attachment = Attachment::query()->findOrFail($attachmentId);
        $disk = Storage::disk($attachment->disk);

        if (! $disk->exists($attachment->path)) {
            throw new RuntimeException('The stored file for attachment '.$attachmentId.' is missing from disk '.$attachment->disk.'.');
        }

        $headers = $attachment->mime === null ? [] : ['Content-Type' => $attachment->mime];

        return $inline
            ? $disk->response($attachment->path, $attachment->original_name, $headers)
            : $disk->download($attachment->path, $attachment->original_name, $headers);
    }

    public function delete(int $attachmentId): bool
    {
        $attachment = Attachment::query()->find($attachmentId);

        if ($attachment === null) {
            return false;
        }

        // The row goes, the bytes stay. Restoring the row is the whole point of
        // a soft delete and it would restore a pointer to nothing otherwise.
        return (bool) $attachment->delete();
    }

    public function purge(int $attachmentId): bool
    {
        $attachment = Attachment::withTrashed()->find($attachmentId);

        if ($attachment === null) {
            return false;
        }

        Storage::disk($attachment->disk)->delete($attachment->path);

        return (bool) $attachment->forceDelete();
    }

    /* Internals ------------------------------------------------------------- */

    private function store(
        Model $target,
        string $contents,
        string $originalName,
        ?string $mime,
        ?int $uploadedBy,
    ): array {
        $disk = $this->disk();
        $alias = $target->getMorphClass();
        $path = $this->pathFor($alias, (int) $target->getKey(), $originalName);

        Storage::disk($disk)->put($path, $contents);

        $attachment = Attachment::query()->create([
            'attachable_type' => $alias,
            'attachable_id' => $target->getKey(),
            'disk' => $disk,
            'path' => $path,
            'original_name' => $this->safeOriginalName($originalName),
            'mime' => $mime,
            'size_bytes' => strlen($contents),
            // Recorded so a re-upload of identical bytes can be recognised and
            // so a backup can be verified rather than merely assumed.
            'checksum' => hash('sha256', $contents),
            'uploaded_by' => $uploadedBy,
        ]);

        return $this->toArray($attachment);
    }

    /** Which disk files land on. Configured once, in this module, for everyone. */
    private function disk(): string
    {
        return (string) config('data.disk', 'local');
    }

    /**
     * Where the bytes go.
     *
     * Grouped by morph alias and target id so the tree on disk mirrors the one
     * in the database, which makes an orphan obvious to a person with an `ls`.
     * The ULID keeps two uploads of the same filename apart without a counter
     * and sorts by time, which a UUID would not.
     */
    private function pathFor(string $alias, int $targetId, string $originalName): string
    {
        $extension = mb_strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $stem = Str::slug(pathinfo($originalName, PATHINFO_FILENAME)) ?: 'file';

        return sprintf(
            'attachments/%s/%d/%s-%s%s',
            Str::slug($alias) ?: 'unknown',
            $targetId,
            (string) Str::ulid(),
            Str::limit($stem, 60, ''),
            $extension === '' ? '' : '.'.$extension,
        );
    }

    /**
     * The name shown and offered on download.
     *
     * Directory separators are stripped rather than escaped: this value ends up
     * in a `Content-Disposition` header, and the only safe filename there is one
     * with no path in it at all.
     */
    private function safeOriginalName(string $originalName): string
    {
        return Str::limit(basename(str_replace('\\', '/', $originalName)), 250, '');
    }

    /** @return array<string, mixed> */
    private function toArray(Attachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'name' => $attachment->original_name,
            'mime' => $attachment->mime,
            'size_bytes' => (int) $attachment->size_bytes,
            'size' => $attachment->humanSize(),
            'extension' => $attachment->extension(),
            'checksum' => $attachment->checksum,
            'disk' => $attachment->disk,
            'path' => $attachment->path,
            'target_type' => $attachment->attachable_type,
            'target_id' => (int) $attachment->attachable_id,
            'uploaded_by' => $attachment->uploaded_by,
            'uploaded_at' => $attachment->created_at?->toDateTimeString(),
            'download_url' => route('data.file-download', ['attachment' => $attachment->id]),
            // The same bytes, shown rather than saved. A card cover or a board
            // background is an image on a page and wants this one; a paperclip
            // in a file list wants `download_url`.
            'inline_url' => route('data.file-inline', ['attachment' => $attachment->id]),
        ];
    }
}
