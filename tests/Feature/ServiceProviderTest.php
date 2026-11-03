<?php

it('merges its config and exposes nothing by default', function () {
    expect(config('rest-base.enabled'))->toBeFalse()
        ->and(config('rest-base.resources'))->toBe([]);
});
