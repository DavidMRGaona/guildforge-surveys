<?php

declare(strict_types=1);

namespace Modules\Surveys\Domain\ValueObjects;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * What a survey was about, frozen when it opened: the data outlives the subject and the
 * module that offered it. Organizers are kept by user id only; names are resolved on display.
 */
final readonly class SubjectSnapshot
{
    /**
     * @param  list<string>  $organizerIds
     * @param  list<array{key: string, label: string, value: string|int|float|bool|null}>  $attributes
     */
    public function __construct(
        public string $sourceType,
        public string $subjectId,
        public string $title,
        public ?DateTimeImmutable $startsAt,
        public ?DateTimeImmutable $endsAt,
        public array $organizerIds,
        public array $attributes,
        public ?string $url,
    ) {}

    /**
     * @param  array{source_type: string, subject_id: string, title: string, starts_at: ?string, ends_at: ?string, organizer_ids: list<string>, attributes: list<array{key: string, label: string, value: string|int|float|bool|null}>, url: ?string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            sourceType: $data['source_type'],
            subjectId: $data['subject_id'],
            title: $data['title'],
            startsAt: $data['starts_at'] === null ? null : new DateTimeImmutable($data['starts_at']),
            endsAt: $data['ends_at'] === null ? null : new DateTimeImmutable($data['ends_at']),
            organizerIds: $data['organizer_ids'],
            attributes: $data['attributes'],
            url: $data['url'],
        );
    }

    /**
     * @return array{source_type: string, subject_id: string, title: string, starts_at: ?string, ends_at: ?string, organizer_ids: list<string>, attributes: list<array{key: string, label: string, value: string|int|float|bool|null}>, url: ?string}
     */
    public function toArray(): array
    {
        return [
            'source_type' => $this->sourceType,
            'subject_id' => $this->subjectId,
            'title' => $this->title,
            'starts_at' => $this->startsAt?->format(DateTimeInterface::ATOM),
            'ends_at' => $this->endsAt?->format(DateTimeInterface::ATOM),
            'organizer_ids' => $this->organizerIds,
            'attributes' => $this->attributes,
            'url' => $this->url,
        ];
    }
}
