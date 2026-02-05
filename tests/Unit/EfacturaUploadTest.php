<?php

use BeeCoded\EFactura\Enums\UploadStatus;
use BeeCoded\EFactura\Models\EfacturaToken;
use BeeCoded\EFactura\Models\EfacturaUpload;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->token = EfacturaToken::create([
        'cui' => '12345678',
        'access_token' => 'test',
        'refresh_token' => 'test',
        'expires_at' => now()->addHour(),
        'is_active' => true,
    ]);

    $this->upload = EfacturaUpload::create([
        'efactura_token_id' => $this->token->id,
        'uploadable_type' => 'App\Models\Invoice',
        'uploadable_id' => 1,
        'status' => UploadStatus::Pending,
        'standard' => 'UBL',
    ]);
});

describe('EfacturaUpload Model', function () {
    it('can be created with fillable attributes', function () {
        expect($this->upload)->toBeInstanceOf(EfacturaUpload::class);
        $this->assertDatabaseHas('efactura_uploads', [
            'efactura_token_id' => $this->token->id,
            'status' => 'pending',
        ]);
    });

    it('casts status to UploadStatus enum', function () {
        expect($this->upload->status)->toBeInstanceOf(UploadStatus::class);
        expect($this->upload->status)->toBe(UploadStatus::Pending);
    });

    it('casts errors to array', function () {
        $this->upload->update(['errors' => ['Error 1', 'Error 2']]);
        expect($this->upload->fresh()->errors)->toBeArray()->toBe(['Error 1', 'Error 2']);
    });

    it('casts boolean fields', function () {
        $this->upload->update([
            'is_extern' => 1,
            'is_self_billed' => 1,
            'is_b2c' => 1,
        ]);

        $upload = $this->upload->fresh();
        expect($upload->is_extern)->toBeBool()->toBeTrue();
        expect($upload->is_self_billed)->toBeBool()->toBeTrue();
        expect($upload->is_b2c)->toBeBool()->toBeTrue();
    });

    it('casts datetime fields', function () {
        $this->upload->update([
            'uploaded_at' => now(),
            'processed_at' => now(),
        ]);

        $upload = $this->upload->fresh();
        expect($upload->uploaded_at)->toBeInstanceOf(Carbon::class);
        expect($upload->processed_at)->toBeInstanceOf(Carbon::class);
    });

    describe('status check methods', function () {
        it('isPending returns true for pending status', function () {
            expect($this->upload->isPending())->toBeTrue();
        });

        it('isProcessing returns true for uploading and processing', function () {
            $this->upload->update(['status' => UploadStatus::Uploading]);
            expect($this->upload->isProcessing())->toBeTrue();

            $this->upload->update(['status' => UploadStatus::Processing]);
            expect($this->upload->isProcessing())->toBeTrue();
        });

        it('isCompleted returns true for completed status', function () {
            $this->upload->update(['status' => UploadStatus::Completed]);
            expect($this->upload->isCompleted())->toBeTrue();
        });

        it('isFailed returns true for failed status', function () {
            $this->upload->update(['status' => UploadStatus::Failed]);
            expect($this->upload->isFailed())->toBeTrue();
        });

        it('isTerminal returns true for completed and failed', function () {
            $this->upload->update(['status' => UploadStatus::Completed]);
            expect($this->upload->isTerminal())->toBeTrue();

            $this->upload->update(['status' => UploadStatus::Failed]);
            expect($this->upload->isTerminal())->toBeTrue();
        });
    });

    describe('scopes', function () {
        beforeEach(function () {
            EfacturaUpload::create([
                'efactura_token_id' => $this->token->id,
                'uploadable_type' => 'App\Models\Invoice',
                'uploadable_id' => 2,
                'status' => UploadStatus::Processing,
                'upload_index' => 'IDX123',
                'standard' => 'UBL',
            ]);

            EfacturaUpload::create([
                'efactura_token_id' => $this->token->id,
                'uploadable_type' => 'App\Models\Invoice',
                'uploadable_id' => 3,
                'status' => UploadStatus::Completed,
                'download_id' => 'DL123',
                'standard' => 'UBL',
            ]);
        });

        it('pending scope returns only pending uploads', function () {
            $uploads = EfacturaUpload::pending()->get();
            expect($uploads)->toHaveCount(1);
            expect($uploads->first()->status)->toBe(UploadStatus::Pending);
        });

        it('processing scope returns uploading and processing uploads', function () {
            $this->upload->update(['status' => UploadStatus::Uploading]);
            $uploads = EfacturaUpload::processing()->get();
            expect($uploads)->toHaveCount(2);
        });

        it('needsStatusCheck scope returns processing uploads with upload_index', function () {
            $uploads = EfacturaUpload::needsStatusCheck()->get();
            expect($uploads)->toHaveCount(1);
            expect($uploads->first()->upload_index)->toBe('IDX123');
        });

        it('needsResponseDownload scope returns completed uploads without response', function () {
            $uploads = EfacturaUpload::needsResponseDownload()->get();
            expect($uploads)->toHaveCount(1);
            expect($uploads->first()->download_id)->toBe('DL123');
        });

        it('forToken scope filters by token', function () {
            $otherToken = EfacturaToken::create([
                'cui' => '99999999',
                'access_token' => 'other',
                'refresh_token' => 'other',
                'expires_at' => now()->addHour(),
                'is_active' => true,
            ]);

            EfacturaUpload::create([
                'efactura_token_id' => $otherToken->id,
                'uploadable_type' => 'App\Models\Invoice',
                'uploadable_id' => 99,
                'status' => UploadStatus::Pending,
                'standard' => 'UBL',
            ]);

            $uploads = EfacturaUpload::forToken($this->token)->get();
            expect($uploads)->toHaveCount(3);
        });
    });

    describe('relationships', function () {
        it('belongs to token', function () {
            expect($this->upload->token)
                ->toBeInstanceOf(EfacturaToken::class)
                ->cui->toBe('12345678');
        });

        it('has uploadable morphTo relationship', function () {
            // Create a fresh upload without an uploadable_type set to avoid class resolution issues
            $freshUpload = new EfacturaUpload;
            $relation = $freshUpload->uploadable();

            expect($relation)->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\MorphTo::class);
            expect($relation->getMorphType())->toBe('uploadable_type');
            expect($relation->getForeignKeyName())->toBe('uploadable_id');
        });
    });
});
