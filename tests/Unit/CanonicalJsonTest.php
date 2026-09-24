<?php

declare(strict_types=1);

use Cbox\AuditChain\Codec\CanonicalJson;

it('sorts map keys as byte strings at every depth', function (): void {
    expect(CanonicalJson::encode(['b' => 1, 'a' => ['z' => 1, 'B' => 2, 'a' => 3], '10' => 'x', '9' => 'y']))
        ->toBe('{"10":"x","9":"y","a":{"B":2,"a":3,"z":1},"b":1}');
});

it('keeps list order, including lists of maps', function (): void {
    expect(CanonicalJson::encode(['list' => [3, 1, 2], 'rows' => [['b' => 1, 'a' => 2], ['d' => 3, 'c' => 4]]]))
        ->toBe('{"list":[3,1,2],"rows":[{"a":2,"b":1},{"c":4,"d":3}]}');
});

it('encodes a sparse integer-keyed array as an object, sorted', function (): void {
    expect(CanonicalJson::encode(['sparse' => [2 => 'two', 0 => 'zero']]))
        ->toBe('{"sparse":{"0":"zero","2":"two"}}');
});

it('leaves slashes and unicode unescaped, and escapes what JSON requires', function (): void {
    expect(CanonicalJson::encode(['u' => 'https://a.test/x', 'n' => 'Æ 東京 🔐', 'q' => "\"\n\u{0000}"]))
        ->toBe('{"n":"Æ 東京 🔐","q":"\"\n\u0000","u":"https://a.test/x"}');
});

it('refuses input JSON cannot represent rather than hashing something lossy', function (): void {
    expect(fn () => CanonicalJson::encode(['bad' => "\xB1\x31"]))->toThrow(JsonException::class);
});
