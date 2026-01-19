<?php

use Illuminate\Filesystem\Filesystem;

beforeEach(function () {
    $this->files = new Filesystem;

    // Ensure base dirs exist
    $this->networkBase = storage_path('app/networks');
    $this->tempBase = storage_path('app/hls-segments');

    $this->files->ensureDirectoryExists($this->networkBase);
    $this->files->ensureDirectoryExists($this->tempBase);
});

it('shows files in dry-run and does not delete them', function () {
    $networkDir = $this->networkBase.'/test-network-dry';
    $this->files->ensureDirectoryExists($networkDir);

    $oldFile = $networkDir.'/old0001.ts';
    file_put_contents($oldFile, 'x');
    touch($oldFile, time() - 7200 - 10);

    $this->artisan('hls:gc', ['--threshold' => 3600, '--dry-run' => true])->expectsOutputToContain('[DRY] Would delete:')->assertSuccessful();

    expect(file_exists($oldFile))->toBeTrue();
});

it('deletes old files and removes empty directories', function () {
    $networkDir = $this->networkBase.'/test-network-clean';
    $this->files->ensureDirectoryExists($networkDir);

    $oldFile = $networkDir.'/old0002.ts';
    file_put_contents($oldFile, 'x');
    touch($oldFile, time() - 9000);

    $this->artisan('hls:gc', ['--threshold' => 3600])->expectsOutputToContain('Deleting:')->assertSuccessful();

    expect(file_exists($oldFile))->toBeFalse();
    // Directory should be removed because empty
    expect(is_dir($networkDir))->toBeFalse();
});
