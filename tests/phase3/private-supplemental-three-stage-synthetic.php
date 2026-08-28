<?php

declare(strict_types=1);

/**
 * Public-safe state-model test for the restore supplemental publication
 * protocol. It opens no Docker service, database, source image or localhost
 * endpoint. Runtime scripts remain the evidence for the concrete boundaries;
 * this model proves that their required state ordering is fail closed.
 */

final class SupplementalPublicationModel
{
    public bool $maintenance = false;
    public bool $mediaComplete = false;
    public bool $projectionPublished = false;
    public int $projectionRevision = 7;
    public int $derivativeRuns = 0;
    public int $aiRuns = 0;
    public int $getRecomputes = 0;

    /** @var array<string,true> */
    private array $delta = [];

    /** @param list<string> $delta */
    public function supplementalApply(array $delta): void
    {
        if ($delta === [] || count($delta) !== count(array_unique($delta))) {
            throw new RuntimeException('invalid_delta');
        }
        $this->maintenance = true;
        $this->projectionPublished = false;
        $this->mediaComplete = false;
        $this->delta = array_fill_keys($delta, true);
    }

    /** @param list<string> $selected */
    public function incrementalMedia(array $selected): void
    {
        if (!$this->maintenance) {
            throw new RuntimeException('maintenance_not_held');
        }
        $expected = array_keys($this->delta);
        sort($expected, SORT_STRING);
        sort($selected, SORT_STRING);
        if ($selected !== $expected) {
            throw new RuntimeException('selection_not_exact_delta');
        }
        ++$this->derivativeRuns;
        ++$this->aiRuns;
        $this->delta = [];
        $this->mediaComplete = true;
    }

    public function finalize(bool $confirmed, bool $projectionSucceeds = true, bool $releaseHealthSucceeds = true): void
    {
        if (!$confirmed) {
            throw new RuntimeException('finalize_confirmation_required');
        }
        if (!$this->maintenance || !$this->mediaComplete || $this->delta !== []) {
            throw new RuntimeException('finalize_delta_not_complete');
        }
        if (!$projectionSucceeds) {
            throw new RuntimeException('projection_finalize_failed');
        }
        ++$this->projectionRevision;
        $this->projectionPublished = true;
        $this->maintenance = false;
        if (!$releaseHealthSucceeds) {
            // Concrete orchestration re-runs the durable prepare command after
            // any ambiguous/failed release and verifies exact HTTP 503 again.
            $this->maintenance = true;
            throw new RuntimeException('maintenance_release_not_visible');
        }
    }

    public function ordinaryGet(): void
    {
        if ($this->maintenance) {
            throw new RuntimeException('maintenance_503');
        }
        // Reads consume persisted projections only.
    }
}

$assertions = 0;
$failures = [];
$assert = static function (bool $condition, string $code) use (&$assertions, &$failures): void {
    ++$assertions;
    if (!$condition) {
        $failures[] = $code;
    }
};
$throws = static function (callable $operation, string $expected) use ($assert): void {
    try {
        $operation();
        $assert(false, $expected . '_not_thrown');
    } catch (RuntimeException $error) {
        $assert($error->getMessage() === $expected, $expected . '_wrong_failure');
    }
};

$delta = ['photo-delta-a', 'photo-delta-b'];
$model = new SupplementalPublicationModel();
$model->supplementalApply($delta);
$assert($model->maintenance, 'supplemental_did_not_hold_maintenance');
$assert(!$model->projectionPublished && $model->projectionRevision === 7, 'supplemental_published_projection');
$throws(static fn () => $model->ordinaryGet(), 'maintenance_503');
$throws(static fn () => $model->incrementalMedia(['photo-baseline', ...$delta]), 'selection_not_exact_delta');
$assert($model->derivativeRuns === 0 && $model->aiRuns === 0, 'foreign_selection_started_work');
$throws(static fn () => $model->finalize(true), 'finalize_delta_not_complete');
$assert($model->maintenance && $model->projectionRevision === 7, 'early_finalize_opened_runtime');

$model->incrementalMedia($delta);
$assert($model->maintenance, 'incremental_released_maintenance');
$assert($model->derivativeRuns === 1 && $model->aiRuns === 1, 'delta_not_processed_once');
$assert(!$model->projectionPublished && $model->projectionRevision === 7, 'incremental_published_projection');
$throws(static fn () => $model->finalize(false), 'finalize_confirmation_required');
$assert($model->maintenance, 'unconfirmed_finalize_opened_runtime');
$throws(static fn () => $model->finalize(true, false), 'projection_finalize_failed');
$assert($model->maintenance && $model->projectionRevision === 7, 'projection_failure_opened_runtime');
$throws(static fn () => $model->finalize(true, true, false), 'maintenance_release_not_visible');
$assert($model->maintenance && $model->projectionPublished && $model->projectionRevision === 8,
    'ambiguous_release_not_reheld');

// A retry re-publishes deterministically before the only successful release.
$model->finalize(true);
$assert(!$model->maintenance && $model->projectionPublished && $model->projectionRevision === 9,
    'explicit_finalize_did_not_publish_and_release');
$before = [$model->derivativeRuns, $model->aiRuns, $model->getRecomputes, $model->projectionRevision];
$model->ordinaryGet();
$after = [$model->derivativeRuns, $model->aiRuns, $model->getRecomputes, $model->projectionRevision];
$assert($before === $after, 'ordinary_get_recomputed_work');

if ($failures !== []) {
    fwrite(STDERR, 'PRIVATE_SUPPLEMENTAL_THREE_STAGE=FAIL assertions=' . $assertions
        . ' failures=' . implode(';', $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "PRIVATE_SUPPLEMENTAL_THREE_STAGE=PASS assertions={$assertions} evidence=STATIC_SYNTHETIC_ONLY\n");
