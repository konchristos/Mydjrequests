<?php

declare(strict_types=1);

function mdjr_compute_score(int $requestCount, int $voteCount, int $boostCount): int
{
    return ($requestCount * 2) + ($voteCount * 1) + ($boostCount * 10);
}
