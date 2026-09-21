<?php

declare(strict_types=1);

namespace Spora\Todo;

enum TodoItemStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';
}
