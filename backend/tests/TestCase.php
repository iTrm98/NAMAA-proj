<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\UploadedFile;

abstract class TestCase extends BaseTestCase
{
    /**
     * Send the next requests with a fresh user token. Forgets the resolved
     * guard first: otherwise Sanctum keeps the first user of the test.
     */
    protected function asUser(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('test', ['user'])->plainTextToken);
    }

    /** A real 1x1 image (the PHP image has no GD, so UploadedFile::fake()->image() is not available). */
    protected function fakeImage(string $name = 'photo.png', int $padBytes = 0): UploadedFile
    {
        $bytes = str_ends_with($name, '.webp')
            ? base64_decode('UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEADsD+JaQAA3AAAAAA')
            : base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');

        return UploadedFile::fake()->createWithContent($name, $bytes.str_repeat("\0", $padBytes));
    }
}
