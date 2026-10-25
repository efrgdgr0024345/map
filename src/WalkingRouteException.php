<?php
declare(strict_types=1);
namespace App;

use RuntimeException;

/** Trusted classification, separate from a routing provider's raw response. */
final class WalkingRouteException extends RuntimeException
{
    public function __construct(public readonly string $reason,public readonly ?int $endpoint=null)
    {
        $message=$reason==='no_segment'
            ? ($endpoint===0 ? 'The starting point is away from a mapped walking path. Choose its public entrance on the map.'
                : ($endpoint===1 ? 'The destination is away from a mapped walking path. Choose its public entrance on the map.'
                    : 'A walking endpoint is away from a mapped path. Choose a nearby public entrance.'))
            : 'No connected mapped walking route was found between these points.';
        parent::__construct($message);
    }
}
