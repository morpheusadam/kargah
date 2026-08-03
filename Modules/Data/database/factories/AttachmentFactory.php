<?php

namespace Modules\Data\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Models\Company;
use Modules\Data\Models\Attachment;

class AttachmentFactory extends Factory
{
    protected $model = Attachment::class;

    /** @var array<string, string> */
    private const MIMES = [
        'pdf' => 'application/pdf',
        'csv' => 'text/csv',
        'png' => 'image/png',
        'zip' => 'application/zip',
        'md' => 'text/markdown',
    ];

    public function definition(): array
    {
        $extension = $this->faker->randomElement(array_keys(self::MIMES));
        $name = $this->faker->slug(3).'.'.$extension;

        return [
            // A company by default, because Core is the one module guaranteed
            // to be present. Any real test names its own target with forTarget().
            'attachable_type' => 'company',
            'attachable_id' => Company::factory(),
            'disk' => 'local',
            'path' => 'attachments/company/'.$this->faker->uuid().'/'.$name,
            'original_name' => $name,
            'mime' => self::MIMES[$extension],
            'size_bytes' => $this->faker->numberBetween(2_048, 4_194_304),
            'checksum' => hash('sha256', $name.$this->faker->uuid()),
            'uploaded_by' => null,
        ];
    }

    /** Point this attachment at a real model, using its morph alias. */
    public function forTarget(Model $target): static
    {
        return $this->state(fn (): array => [
            'attachable_type' => $target->getMorphClass(),
            'attachable_id' => $target->getKey(),
        ]);
    }

    public function named(string $originalName): static
    {
        return $this->state(fn (): array => [
            'original_name' => $originalName,
            'mime' => self::MIMES[mb_strtolower(pathinfo($originalName, PATHINFO_EXTENSION))] ?? null,
        ]);
    }
}
