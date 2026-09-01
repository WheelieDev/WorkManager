<?php

use App\Support\Duration;

it('formats hours as hours and minutes', function (float $hours, string $expected) {
    expect(Duration::hours($hours))->toBe($expected);
})->with([
    'zero' => [0.0, '0h'],
    'whole hours' => [7.0, '7h'],
    'hours and minutes' => [7.5, '7h 30m'],
    'minutes only' => [0.75, '45m'],
    'a third of an hour rounds to the minute' => [0.33, '20m'],
    'rounds up into the next hour' => [1.999, '2h'],
    'a negative reads as its size' => [-2.25, '2h 15m'],
]);

it('formats hours with their direction', function (float $hours, string $expected) {
    expect(Duration::signed($hours))->toBe($expected);
})->with([
    'zero carries no sign' => [0.0, '0h'],
    'banked time' => [1.25, '+1h 15m'],
    'owed time' => [-0.5, '-30m'],
    'a hair over zero is still zero' => [0.001, '0h'],
]);
