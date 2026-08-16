<?php

declare(strict_types=1);

define('PHPWG_ROOT_PATH', __DIR__ . '/../');

require_once __DIR__ . '/../plugins/ClassIdentity/src/AnonymousPresenter.php';

use ClassIdentity\AnonymousPresenter;

$assertions = 0;

/** @param mixed $actual @param mixed $expected */
function assertSameValue($actual, $expected, string $label): void
{
    global $assertions;
    ++$assertions;
    if ($actual !== $expected) {
        throw new RuntimeException($label);
    }
}

function assertTrueValue(bool $condition, string $label): void
{
    assertSameValue($condition, true, $label);
}

function expectFailure(callable $callback, string $label): void
{
    global $assertions;
    ++$assertions;
    try {
        $callback();
    } catch (Throwable) {
        return;
    }
    throw new RuntimeException($label);
}

$secret = str_repeat('p', 48);
$subject = hex2bin('00112233445566778899aabbccddeeff');
$otherSubject = hex2bin('ffeeddccbbaa99887766554433221100');
if (!is_string($subject) || !is_string($otherSubject)) {
    throw new RuntimeException('fixture');
}

$photoOne = AnonymousPresenter::deriveAlias($secret, 'PHOTO', 17, $subject);
$photoOneAgain = AnonymousPresenter::deriveAlias($secret, 'photo', '017', $subject);
$photoTwo = AnonymousPresenter::deriveAlias($secret, 'PHOTO', 18, $subject);
$otherSeat = AnonymousPresenter::deriveAlias($secret, 'PHOTO', 17, $otherSubject);
$albumOne = AnonymousPresenter::deriveAlias($secret, 'ALBUM', 17, $subject);

assertSameValue($photoOne, $photoOneAgain, 'same context must be stable and canonical');
assertTrueValue($photoOne !== $photoTwo, 'different photos must unlink aliases');
assertTrueValue($photoOne !== $albumOne, 'different context types must unlink aliases');
assertTrueValue($photoOne !== $otherSeat, 'different anonymous seats must differ');
assertTrueValue(
    preg_match('/\A匿名 [ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{8}\z/uD', $photoOne) === 1,
    'alias format must be fixed safe Base32',
);
assertTrueValue(!str_contains($photoOne, 'C25'), 'roster code must not be encoded');

$collisionMethod = new ReflectionMethod(AnonymousPresenter::class, 'uniquePrefix');
$extended = $collisionMethod->invoke(null, 'ABCDEFGHJKLM', ['ABCDEFGHZ234']);
assertSameValue($extended, 'ABCDEFGHJ', '40-bit collision must extend deterministically');

expectFailure(
    static fn() => AnonymousPresenter::deriveAlias('short', 'PHOTO', 1, $subject),
    'short secrets must fail closed',
);
expectFailure(
    static fn() => AnonymousPresenter::deriveAlias($secret, 'THREAD', 1, $subject),
    'unsupported context types must fail closed',
);
expectFailure(
    static fn() => AnonymousPresenter::deriveAlias($secret, 'PHOTO', 0, $subject),
    'invalid context ids must fail closed',
);
expectFailure(
    static fn() => AnonymousPresenter::deriveAlias($secret, 'PHOTO', 1, 'bad'),
    'invalid subjects must fail closed',
);
expectFailure(
    static fn() => AnonymousPresenter::deriveAlias(
        $secret,
        'PHOTO',
        1,
        $subject,
        1,
        [['subject' => 'bad', 'key_version' => 1]],
    ),
    'invalid collision candidates must fail closed',
);

fwrite(STDOUT, "CLASS_IDENTITY_ANONYMOUS_PRESENTER=PASS assertions={$assertions}\n");
