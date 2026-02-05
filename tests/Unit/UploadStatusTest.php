<?php

use BeeCoded\EFactura\Enums\UploadStatus;

describe('UploadStatus Enum', function () {
    it('has correct string values', function () {
        expect(UploadStatus::Pending->value)->toBe('pending');
        expect(UploadStatus::Uploading->value)->toBe('uploading');
        expect(UploadStatus::Processing->value)->toBe('processing');
        expect(UploadStatus::Completed->value)->toBe('completed');
        expect(UploadStatus::Failed->value)->toBe('failed');
    });

    describe('isTerminal', function () {
        it('returns true for Completed', function () {
            expect(UploadStatus::Completed->isTerminal())->toBeTrue();
        });

        it('returns true for Failed', function () {
            expect(UploadStatus::Failed->isTerminal())->toBeTrue();
        });

        it('returns false for non-terminal statuses', function () {
            expect(UploadStatus::Pending->isTerminal())->toBeFalse();
            expect(UploadStatus::Uploading->isTerminal())->toBeFalse();
            expect(UploadStatus::Processing->isTerminal())->toBeFalse();
        });
    });

    describe('isPending', function () {
        it('returns true only for Pending', function () {
            expect(UploadStatus::Pending->isPending())->toBeTrue();
            expect(UploadStatus::Uploading->isPending())->toBeFalse();
            expect(UploadStatus::Processing->isPending())->toBeFalse();
            expect(UploadStatus::Completed->isPending())->toBeFalse();
            expect(UploadStatus::Failed->isPending())->toBeFalse();
        });
    });

    describe('isInProgress', function () {
        it('returns true for Uploading and Processing', function () {
            expect(UploadStatus::Uploading->isInProgress())->toBeTrue();
            expect(UploadStatus::Processing->isInProgress())->toBeTrue();
        });

        it('returns false for other statuses', function () {
            expect(UploadStatus::Pending->isInProgress())->toBeFalse();
            expect(UploadStatus::Completed->isInProgress())->toBeFalse();
            expect(UploadStatus::Failed->isInProgress())->toBeFalse();
        });
    });
});
