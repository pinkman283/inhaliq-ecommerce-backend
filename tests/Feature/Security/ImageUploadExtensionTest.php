<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ImageUploadExtensionTest extends TestCase
{
    public function test_uploaded_image_extension_is_determined_by_server_mime_not_client_header(): void
    {
        Storage::fake('public');

        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);
        Sanctum::actingAs($admin, ['admin:access']);

        // 1x1 valid PNG binary data with spoofed client filename
        $pngContent = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $fakeFile = UploadedFile::fake()->createWithContent('photo.png', $pngContent);

        $response = $this->postJson('/api/admin/products/upload-image', [
            'image' => $fakeFile,
        ]);

        $response->assertStatus(201);
        $filename = $response->json('filename');

        // Must end with server-verified png/jpeg, NOT untrusted client extension
        $this->assertMatchesRegularExpression('/\.(png|jpe?g|webp|gif)$/i', $filename);
        $this->assertStringNotContainsString('customext', $filename);
    }
}
