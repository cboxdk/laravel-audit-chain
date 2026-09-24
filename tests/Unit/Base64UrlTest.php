<?php

declare(strict_types=1);

use Cbox\AuditChain\Support\Base64Url;

it('round-trips unpadded base64url', function (): void {
    foreach (['', 'f', 'fo', 'foo', "\xff\xfe\xfd", random_bytes(64)] as $bytes) {
        $encoded = Base64Url::encode($bytes);

        expect($encoded)->not->toContain('=')->not->toContain('+')->not->toContain('/');

        if ($bytes !== '') {
            expect(Base64Url::decode($encoded))->toBe($bytes);
        }
    }
});

it('accepts only the one canonical spelling', function (string $encoded): void {
    expect(Base64Url::decode($encoded))->toBeNull();
})->with([
    'padding' => ['Zm8='],
    'standard alphabet' => ['+/8'],
    'impossible length' => ['Zm9vY'],
    'non-canonical trailing bits' => ['Zm9'],
    'empty' => [''],
    'whitespace' => ['Zm 9v'],
]);
