<?php

use App\Models\User;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->createdNetworkPaths = [];
});

afterEach(function () {
    // Clean up only the directories created by this test
    foreach ($this->createdNetworkPaths ?? [] as $path) {
        if (File::isDirectory($path)) {
            File::deleteDirectory($path);
        }
    }
});
