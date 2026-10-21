<?php

namespace Tests\Fixtures\StatusViews;

use Flowra\Contracts\HasWorkflowContract;
use Flowra\Contracts\StatusConditionContract;
use Flowra\Models\Status;

final class IsStaff implements StatusConditionContract
{
    public function allows(Status $status, HasWorkflowContract $owner, mixed $viewer = null): bool
    {
        return (bool) ($viewer->staff ?? false);
    }
}
