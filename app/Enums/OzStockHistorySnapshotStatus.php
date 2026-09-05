<?php

namespace App\Enums;

enum OzStockHistorySnapshotStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';
}
